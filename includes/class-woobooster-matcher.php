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
        $cache_key = 'woobooster_rec_' . $product_id;
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
        $rule = $this->find_matching_rule($condition_keys);

        if (!$rule) {
            $this->debug_log("No matching rule for product {$product_id}");
            return array();
        }

        $this->debug_log("Matched rule #{$rule->id} ({$rule->name}) for product {$product_id}");

        // Step 4: Execute the product query.
        $product_ids = $this->execute_query($product_id, $rule, $args, $terms);

        // Step 5: Cache the result.
        wp_cache_set($cache_key, $product_ids, 'woobooster', HOUR_IN_SECONDS);

        $elapsed = round((microtime(true) - $start_time) * 1000, 2);
        $this->debug_log("Recommendation query for product {$product_id}: {$elapsed}ms, returned " . count($product_ids) . ' products');

        return $product_ids;
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
                "SELECT tt.taxonomy, t.slug
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
     * @param array $condition_keys Composite keys (e.g. ['pa_brand:glock', 'pa_caliber:9mm']).
     * @return object|null The winning rule or null.
     */
    private function find_matching_rule($condition_keys)
    {
        global $wpdb;

        $index_table = $wpdb->prefix . 'woobooster_rule_index';
        $rules_table = $wpdb->prefix . 'woobooster_rules';

        if (empty($condition_keys)) {
            return null;
        }

        // Build the IN clause safely.
        $placeholders = implode(', ', array_fill(0, count($condition_keys), '%s'));

        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $rule_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT rule_id FROM {$index_table}
				WHERE condition_key IN ({$placeholders})
				ORDER BY priority ASC
				LIMIT 1",
                ...$condition_keys
            )
        );

        if (!$rule_id) {
            return null;
        }

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$rules_table} WHERE id = %d AND status = 1",
                $rule_id
            )
        );
    }

    /**
     * Execute the product query based on the matched rule.
     *
     * @param int    $product_id Current product ID (excluded from results).
     * @param object $rule       The matched rule.
     * @param array  $args       Override args (limit, exclude_outofstock).
     * @param array  $terms      Product terms for "same attribute" resolution.
     * @return array Array of product IDs.
     */
    private function execute_query($product_id, $rule, $args, $terms)
    {
        // Determine limit.
        $limit = isset($args['limit']) && $args['limit'] ? absint($args['limit']) : absint($rule->action_limit);
        $limit = min($limit, 8);

        // Determine exclude outofstock.
        $global_exclude = '1' === woobooster_get_option('exclude_outofstock', '1');
        if (isset($args['exclude_outofstock'])) {
            $exclude_outofstock = (bool) $args['exclude_outofstock'];
        } else {
            $exclude_outofstock = $rule->exclude_outofstock ? true : $global_exclude;
        }

        // Resolve taxonomy and term for the query.
        $resolved = $this->resolve_action($rule, $terms);

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
        switch ($rule->action_orderby) {
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

        $this->debug_log('Query args: ' . wp_json_encode($query_args));

        $query = new WP_Query($query_args);
        $result_ids = $query->posts;

        $this->debug_log('Result product IDs: ' . implode(', ', $result_ids));

        return $result_ids;
    }

    /**
     * Resolve the action taxonomy and term.
     *
     * @param object $rule  The matched rule.
     * @param array  $terms Product terms.
     * @return array|null ['taxonomy' => ..., 'term' => ...] or null.
     */
    private function resolve_action($rule, $terms)
    {
        switch ($rule->action_source) {
            case 'category':
                return array(
                    'taxonomy' => 'product_cat',
                    'term' => $rule->action_value,
                );

            case 'tag':
                return array(
                    'taxonomy' => 'product_tag',
                    'term' => $rule->action_value,
                );

            case 'attribute':
                // "Same Attribute" — use the condition's attribute and value.
                return array(
                    'taxonomy' => $rule->condition_attribute,
                    'term' => $rule->condition_value,
                );

            default:
                return null;
        }
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
            'query_args' => array(),
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
        $rule = $this->find_matching_rule($condition_keys);

        if ($rule) {
            $result['matched_rule'] = array(
                'id' => $rule->id,
                'name' => $rule->name,
                'priority' => $rule->priority,
                'condition_attribute' => $rule->condition_attribute,
                'condition_operator' => $rule->condition_operator,
                'condition_value' => $rule->condition_value,
                'action_source' => $rule->action_source,
                'action_value' => $rule->action_value,
                'action_orderby' => $rule->action_orderby,
                'action_limit' => $rule->action_limit,
            );

            // Step 4: Execute query.
            $resolved = $this->resolve_action($rule, $terms);

            if ($resolved) {
                $limit = absint($rule->action_limit);
                $limit = min($limit, 8);

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
                );

                if ($rule->exclude_outofstock) {
                    $query_args['meta_query'] = array(
                        array(
                            'key' => '_stock_status',
                            'value' => 'instock',
                            'compare' => '=',
                        ),
                    );
                }

                $result['query_args'] = $query_args;

                $query = new WP_Query($query_args);
                $result['product_ids'] = $query->posts;

                foreach ($query->posts as $pid) {
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
        }

        $result['time_ms'] = round((microtime(true) - $start_time) * 1000, 2);

        return $result;
    }
}
