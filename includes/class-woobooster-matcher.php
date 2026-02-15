<?php
/**
 * WooBooster Matcher — Core Matching Engine.
 *
 * Resolves the winning rule for a given product and executes the product query.
 *
 * @package WooBooster
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooBooster_Matcher
{

    /**
     * Get recommended product IDs for a given product.
     *
     * @param int   $product_id The source product ID.
     * @param array $args       Optional overrides: limit, exclude_outofstock.
     * @return array Array of product IDs.
     */
    public function get_recommendations($product_id, $args = array())
    {
        $product_id = absint($product_id);

        if (!$product_id) {
            return array();
        }

        // Check if the system is enabled.
        if ('1' !== woobooster_get_option('enabled', '1')) {
            return array();
        }

        // Try object cache first.
        $args_hash = md5(wp_json_encode($args));
        $cache_key = 'woobooster_rec_' . $product_id . '_' . $args_hash;
        $cached = wp_cache_get($cache_key, 'woobooster');

        if (false !== $cached) {
            $this->debug_log("Cache hit for product {$product_id}");
            return $cached;
        }

        $start_time = microtime(true);

        // Step 1: Get all taxonomy terms for this product.
        $terms = $this->get_product_terms($product_id);

        if (empty($terms)) {
            $this->debug_log("No terms found for product {$product_id}");
            return array();
        }

        // Step 2: Build composite keys.
        $condition_keys = array();
        foreach ($terms as $term) {
            $condition_keys[] = $term['taxonomy'] . ':' . $term['slug'];
        }

        // Step 3: Find the winning rule via the lookup index.
        $rule = $this->find_matching_rule($condition_keys, $terms);

        if (!$rule) {
            $this->debug_log("No matching rule for product {$product_id}");
            return array();
        }

        $this->debug_log("Matched rule #{$rule->id} ({$rule->name}) for product {$product_id}");

        // Step 4: Execute actions.
        $all_product_ids = array();
        $actions = WooBooster_Rule::get_actions($rule->id);

        if (!empty($actions)) {
            foreach ($actions as $action) {
                $ids = $this->execute_query($product_id, $action, $args, $terms);
                if (!empty($ids)) {
                    $all_product_ids = array_merge($all_product_ids, $ids);
                }
            }
        }

        // Limit global results if override is set, otherwise deduplicate.
        $all_product_ids = array_unique($all_product_ids);

        // If a global hard limit was requested, apply it here too.
        if (isset($args['limit']) && $args['limit'] > 0) {
            $all_product_ids = array_slice($all_product_ids, 0, absint($args['limit']));
        }

        // Step 5: Cache the result.
        wp_cache_set($cache_key, $all_product_ids, 'woobooster', HOUR_IN_SECONDS);

        $elapsed = round((microtime(true) - $start_time) * 1000, 2);
        $this->debug_log("Recommendation query for product {$product_id}: {$elapsed}ms, returned " . count($all_product_ids) . ' products from ' . count($actions) . ' actions');

        return $all_product_ids;
    }

    /**
     * Get all taxonomy terms for a product in a single query.
     *
     * @param int $product_id Product ID.
     * @return array Array of ['taxonomy' => ..., 'slug' => ...].
     */
    private function get_product_terms($product_id)
    {
        global $wpdb;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT tt.taxonomy, t.slug, t.term_id
				FROM {$wpdb->term_relationships} tr
				JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
				WHERE tr.object_id = %d",
                $product_id
            ),
            ARRAY_A
        );

        return $results ? $results : array();
    }

    /**
     * Find the matching rule from the lookup index.
     *
     * Uses the fast index table to find candidate rules, then verifies
     * multi-condition groups (AND within group, OR between groups).
     *
     * @param array $condition_keys Composite keys (e.g. ['pa_brand:glock', 'pa_caliber:9mm']).
     * @param array $terms          Full term data [['taxonomy' => '...', 'slug' => '...', 'term_id' => ...]].
     * @return object|null The winning rule or null.
     */
    private function find_matching_rule($condition_keys, $terms)
    {
        global $wpdb;

        $index_table = $wpdb->prefix . 'woobooster_rule_index';
        $rules_table = $wpdb->prefix . 'woobooster_rules';

        if (empty($condition_keys)) {
            return null;
        }

        // Sanitize all keys before use.
        $condition_keys = array_map('sanitize_text_field', $condition_keys);

        // Build the IN clause safely.
        $placeholders = implode(', ', array_fill(0, count($condition_keys), '%s'));

        // Get ALL candidate rule IDs (distinct, ordered by priority).
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $candidate_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT rule_id FROM {$index_table}
                WHERE condition_key IN ({$placeholders})
                ORDER BY priority ASC",
                ...$condition_keys
            )
        );

        if (empty($candidate_ids)) {
            return null;
        }

        // Build a set of product keys for fast lookup.
        $product_keys_set = array_flip($condition_keys);

        // Verify each candidate rule against the product's condition keys.
        foreach ($candidate_ids as $rule_id) {
            $rule = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$rules_table} WHERE id = %d AND status = 1",
                    absint($rule_id)
                )
            );

            if (!$rule) {
                continue;
            }

            // Get condition groups for this rule.
            $groups = WooBooster_Rule::get_conditions($rule_id);

            if (empty($groups)) {
                continue;
            }

            // Check if ANY group is fully satisfied (OR between groups).
            foreach ($groups as $conditions) {
                $group_satisfied = true;

                // ALL conditions in this group must match (AND within group).
                foreach ($conditions as $cond) {
                    $cond_key = sanitize_key($cond->condition_attribute) . ':' . sanitize_text_field($cond->condition_value);

                    // Direct key match.
                    if (isset($product_keys_set[$cond_key])) {
                        continue; // This condition is satisfied.
                    }

                    // If include_children is enabled, check if any product term
                    // is a descendant of this condition's term.
                    if (!empty($cond->include_children)) {
                        $found_child = false;
                        $attr = sanitize_key($cond->condition_attribute);
                        $parent_term = get_term_by('slug', $cond->condition_value, $attr);

                        if ($parent_term && !is_wp_error($parent_term)) {
                            foreach ($terms as $term) {
                                if (
                                    $term['taxonomy'] === $attr &&
                                    term_is_ancestor_of((int) $parent_term->term_id, (int) $term['term_id'], $attr)
                                ) {
                                    $found_child = true;
                                    break;
                                }
                            }
                        }

                        if ($found_child) {
                            continue; // This condition is satisfied via child match.
                        }
                    }

                    // This condition is NOT satisfied.
                    $group_satisfied = false;
                    break;
                }

                if ($group_satisfied) {
                    return $rule; // This rule matches!
                }
            }
        }

        return null;
    }

    /**
     * Execute the product query based on the action configuration.
     *
     * @param int    $product_id Current product ID (excluded from results).
     * @param object $action     The action object.
     * @param array  $args       Override args (limit, exclude_outofstock).
     * @param array  $terms      Product terms for "same attribute" resolution.
     * @return array Array of product IDs.
     */
    private function execute_query($product_id, $action, $args, $terms)
    {
        // Determine limit.
        $limit = isset($args['limit']) && $args['limit'] ? absint($args['limit']) : absint($action->action_limit);

        // Determine exclude outofstock.
        $global_exclude = '1' === woobooster_get_option('exclude_outofstock', '1');
        $exclude_outofstock = isset($args['exclude_outofstock']) ? (bool) $args['exclude_outofstock'] : $global_exclude;

        // Resolve taxonomy and term for the query.
        $resolved = $this->resolve_action($action, $terms);

        if (!$resolved) {
            return array();
        }

        $query_args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'post__not_in' => array($product_id),
            'fields' => 'ids',
            'tax_query' => array(
                array(
                    'taxonomy' => $resolved['taxonomy'],
                    'field' => 'slug',
                    'terms' => $resolved['term'],
                ),
            ),
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        );

        // Exclude out of stock.
        if ($exclude_outofstock) {
            $query_args['meta_query'] = array(
                array(
                    'key' => '_stock_status',
                    'value' => 'instock',
                    'compare' => '=',
                ),
            );
        }

        // Order by.
        switch ($action->action_orderby) {
            case 'bestselling':
                $query_args['meta_key'] = 'total_sales';
                $query_args['orderby'] = 'meta_value_num';
                $query_args['order'] = 'DESC';
                break;

            case 'price':
                $query_args['meta_key'] = '_price';
                $query_args['orderby'] = 'meta_value_num';
                $query_args['order'] = 'ASC';
                break;

            case 'price_desc':
                $query_args['meta_key'] = '_price';
                $query_args['orderby'] = 'meta_value_num';
                $query_args['order'] = 'DESC';
                break;

            case 'rating':
                $query_args['meta_key'] = '_wc_average_rating';
                $query_args['orderby'] = 'meta_value_num';
                $query_args['order'] = 'DESC';
                break;

            case 'date':
                $query_args['orderby'] = 'date';
                $query_args['order'] = 'DESC';
                break;

            case 'rand':
            default:
                $query_args['orderby'] = 'rand';
                break;
        }

        $this->debug_log('Query args for action: ' . wp_json_encode($query_args));

        $query = new WP_Query($query_args);
        $result_ids = $query->posts;

        return $result_ids;
    }

    /**
     * Resolve the action taxonomy and term.
     *
     * @param object $action The action object.
     * @param array  $terms  Product terms.
     * @return array|null ['taxonomy' => ..., 'term' => ...] or null.
     */
    private function resolve_action($action, $terms)
    {
        switch ($action->action_source) {
            case 'category':
                return array(
                    'taxonomy' => 'product_cat',
                    'term' => $action->action_value,
                );

            case 'tag':
                return array(
                    'taxonomy' => 'product_tag',
                    'term' => $action->action_value,
                );

            case 'attribute':
                return array(
                    // If source is 'attribute', action_value contains property name (e.g., 'pa_brand').
                    'taxonomy' => $action->action_value,
                    // We need to find the term slug from the current product's terms.
                    'term' => $this->find_term_slug_from_product($action->action_value, $terms),
                );

            default:
                return null;
        }
    }

    /**
     * Find a term slug for a specific taxonomy from the product's terms.
     *
     * @param string $taxonomy Taxonomy name.
     * @param array  $terms    Product terms.
     * @return string|array Term slug(s).
     */
    private function find_term_slug_from_product($taxonomy, $terms)
    {
        $slugs = array();
        foreach ($terms as $term) {
            if ($term['taxonomy'] === $taxonomy) {
                $slugs[] = $term['slug'];
            }
        }
        return empty($slugs) ? '' : $slugs;
    }

    /**
     * Log debug information.
     *
     * @param string $message Log message.
     */
    private function debug_log($message)
    {
        if ('1' !== woobooster_get_option('debug_mode', '0')) {
            return;
        }

        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->debug($message, array('source' => 'woobooster'));
        }
    }

    /**
     * Get matching details for diagnostics (Rule Tester).
     *
     * @param int $product_id Product ID.
     * @return array Diagnostic data.
     */
    public function get_diagnostics($product_id)
    {
        $product_id = absint($product_id);
        $start_time = microtime(true);

        $result = array(
            'product_id' => $product_id,
            'product_name' => '',
            'terms' => array(),
            'keys' => array(),
            'matched_rule' => null,
            'actions' => array(),
            'product_ids' => array(),
            'products' => array(),
            'time_ms' => 0,
        );

        $product = wc_get_product($product_id);
        if (!$product) {
            $result['error'] = __('Product not found.', 'woobooster');
            return $result;
        }

        $result['product_name'] = $product->get_name();

        // Step 1: Get terms.
        $terms = $this->get_product_terms($product_id);
        $result['terms'] = $terms;

        // Step 2: Build keys.
        $condition_keys = array();
        foreach ($terms as $term) {
            $condition_keys[] = $term['taxonomy'] . ':' . $term['slug'];
        }
        $result['keys'] = $condition_keys;

        // Step 3: Find rule.
        $rule = $this->find_matching_rule($condition_keys, $terms);

        if ($rule) {
            $result['matched_rule'] = array(
                'id' => $rule->id,
                'name' => $rule->name,
                'priority' => $rule->priority,
            );

            // Step 4: Execute actions.
            $actions = WooBooster_Rule::get_actions($rule->id);
            foreach ($actions as $action) {
                $resolved = $this->resolve_action($action, $terms);

                $action_debug = array(
                    'source' => $action->action_source,
                    'value' => $action->action_value,
                    'limit' => $action->action_limit,
                    'orderby' => $action->action_orderby,
                    'resolved_query' => $resolved,
                    'results' => array()
                );

                if ($resolved) {
                    $ids = $this->execute_query($product_id, $action, array(), $terms);
                    $action_debug['results'] = $ids;
                    $result['product_ids'] = array_merge($result['product_ids'], $ids); // Accumulate all
                }

                $result['actions'][] = $action_debug;
            }

            $result['product_ids'] = array_unique($result['product_ids']);

            foreach ($result['product_ids'] as $pid) {
                $p = wc_get_product($pid);
                if ($p) {
                    $result['products'][] = array(
                        'id' => $pid,
                        'name' => $p->get_name(),
                        'price' => $p->get_price_html(),
                        'stock' => $p->get_stock_status(),
                    );
                }
            }
        }

        $result['time_ms'] = round((microtime(true) - $start_time) * 1000, 2);

        return $result;
    }
}
