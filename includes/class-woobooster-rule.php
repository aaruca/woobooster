<?php
/**
 * WooBooster Rule Model.
 *
 * Handles CRUD operations for recommendation rules and lookup index management.
 *
 * @package WooBooster
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooBooster_Rule
{

    /**
     * Rules table name.
     *
     * @var string
     */
    private static $table;

    /**
     * Index table name.
     *
     * @var string
     */
    private static $index_table;

    /**
     * Initialize table names.
     */
    private static function init_tables()
    {
        global $wpdb;
        self::$table = $wpdb->prefix . 'woobooster_rules';
        self::$index_table = $wpdb->prefix . 'woobooster_rule_index';
    }

    /**
     * Get a single rule by ID.
     *
     * @param int $id Rule ID.
     * @return object|null
     */
    public static function get($id)
    {
        global $wpdb;
        self::init_tables();

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM %i WHERE id = %d", self::$table, $id)
        );
    }

    /**
     * Get all rules with optional sorting and filtering.
     *
     * @param array $args Query arguments.
     * @return array
     */
    public static function get_all($args = array())
    {
        global $wpdb;
        self::init_tables();

        $defaults = array(
            'orderby' => 'priority',
            'order' => 'ASC',
            'status' => null,
            'limit' => 100,
            'offset' => 0,
        );

        $args = wp_parse_args($args, $defaults);

        $sql = "SELECT * FROM %i";
        $params = array(self::$table);

        if (null !== $args['status']) {
            $sql .= " WHERE status = %d";
            $params[] = absint($args['status']);
        }

        // Whitelist orderby columns.
        $allowed_orderby = array('id', 'name', 'priority', 'status', 'created_at', 'updated_at');
        $orderby = in_array($args['orderby'], $allowed_orderby, true) ? $args['orderby'] : 'priority';

        $order = strtoupper($args['order']) === 'DESC' ? 'DESC' : 'ASC';

        $sql .= " ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $params[] = absint($args['limit']);
        $params[] = absint($args['offset']);

        return $wpdb->get_results(
            $wpdb->prepare($sql, ...$params)
        );
    }

    /**
     * Count total rules.
     *
     * @param array $args Filter arguments.
     * @return int
     */
    public static function count($args = array())
    {
        global $wpdb;
        self::init_tables();

        if (isset($args['status'])) {
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM %i WHERE status = %d",
                    self::$table,
                    absint($args['status'])
                )
            );
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM %i", self::$table)
        );
    }

    /**
     * Create a new rule.
     *
     * @param array $data Rule data.
     * @return int|false Rule ID on success, false on failure.
     */
    public static function create($data)
    {
        global $wpdb;
        self::init_tables();

        $sanitized = self::sanitize_rule_data($data);

        $result = $wpdb->insert(
            self::$table,
            $sanitized,
            self::get_format($sanitized)
        );

        if (false === $result) {
            return false;
        }

        $rule_id = $wpdb->insert_id;
        self::rebuild_index_for_rule($rule_id);

        return $rule_id;
    }

    /**
     * Update an existing rule.
     *
     * @param int   $id   Rule ID.
     * @param array $data Rule data.
     * @return bool
     */
    public static function update($id, $data)
    {
        global $wpdb;
        self::init_tables();

        $sanitized = self::sanitize_rule_data($data);

        $result = $wpdb->update(
            self::$table,
            $sanitized,
            array('id' => absint($id)),
            self::get_format($sanitized),
            array('%d')
        );

        if (false !== $result) {
            self::rebuild_index_for_rule($id);
        }

        return false !== $result;
    }

    /**
     * Delete a rule.
     *
     * @param int $id Rule ID.
     * @return bool
     */
    public static function delete($id)
    {
        global $wpdb;
        self::init_tables();

        // Remove from index first.
        $wpdb->delete(
            self::$index_table,
            array('rule_id' => absint($id)),
            array('%d')
        );

        return (bool) $wpdb->delete(
            self::$table,
            array('id' => absint($id)),
            array('%d')
        );
    }

    /**
     * Toggle rule status.
     *
     * @param int $id Rule ID.
     * @return bool
     */
    public static function toggle_status($id)
    {
        global $wpdb;
        self::init_tables();

        $rule = self::get($id);
        if (!$rule) {
            return false;
        }

        $new_status = $rule->status ? 0 : 1;

        return (bool) $wpdb->update(
            self::$table,
            array('status' => $new_status),
            array('id' => absint($id)),
            array('%d'),
            array('%d')
        );
    }

    /**
     * Rebuild the lookup index for a specific rule.
     *
     * @param int $id Rule ID.
     */
    public static function rebuild_index_for_rule($id)
    {
        global $wpdb;
        self::init_tables();

        // Delete existing index entries for this rule.
        $wpdb->delete(
            self::$index_table,
            array('rule_id' => absint($id)),
            array('%d')
        );

        // Get the rule.
        $rule = self::get($id);
        if (!$rule || !$rule->status) {
            return;
        }

        // Build the composite key.
        $condition_key = sanitize_key($rule->condition_attribute) . ':' . sanitize_text_field($rule->condition_value);

        $wpdb->insert(
            self::$index_table,
            array(
                'condition_key' => $condition_key,
                'rule_id' => absint($id),
                'priority' => absint($rule->priority),
            ),
            array('%s', '%d', '%d')
        );
    }

    /**
     * Rebuild the entire lookup index.
     */
    public static function rebuild_full_index()
    {
        global $wpdb;
        self::init_tables();

        // Truncate the index table.
        $wpdb->query($wpdb->prepare("TRUNCATE TABLE %i", self::$index_table));

        // Get all active rules.
        $rules = self::get_all(array('status' => 1, 'limit' => 10000));

        foreach ($rules as $rule) {
            $condition_key = sanitize_key($rule->condition_attribute) . ':' . sanitize_text_field($rule->condition_value);

            $wpdb->insert(
                self::$index_table,
                array(
                    'condition_key' => $condition_key,
                    'rule_id' => absint($rule->id),
                    'priority' => absint($rule->priority),
                ),
                array('%s', '%d', '%d')
            );
        }
    }

    /**
     * Sanitize rule data before insert/update.
     *
     * @param array $data Raw data.
     * @return array Sanitized data.
     */
    private static function sanitize_rule_data($data)
    {
        $sanitized = array();

        if (isset($data['name'])) {
            $sanitized['name'] = sanitize_text_field($data['name']);
        }

        if (isset($data['priority'])) {
            $sanitized['priority'] = absint($data['priority']);
        }

        if (isset($data['status'])) {
            $sanitized['status'] = absint($data['status']) ? 1 : 0;
        }

        if (isset($data['condition_attribute'])) {
            $sanitized['condition_attribute'] = sanitize_key($data['condition_attribute']);
        }

        if (isset($data['condition_value'])) {
            $sanitized['condition_value'] = sanitize_text_field($data['condition_value']);
        }

        if (isset($data['condition_operator'])) {
            $allowed_ops = array('equals', 'not_equals', 'contains');
            $sanitized['condition_operator'] = in_array($data['condition_operator'], $allowed_ops, true)
                ? $data['condition_operator']
                : 'equals';
        }

        if (isset($data['action_source'])) {
            $allowed_sources = array('category', 'tag', 'attribute');
            $sanitized['action_source'] = in_array($data['action_source'], $allowed_sources, true)
                ? $data['action_source']
                : 'category';
        }

        if (isset($data['action_value'])) {
            $sanitized['action_value'] = sanitize_text_field($data['action_value']);
        }

        if (isset($data['action_orderby'])) {
            $allowed_orderby = array('rand', 'date', 'price', 'price_desc', 'bestselling', 'rating');
            $sanitized['action_orderby'] = in_array($data['action_orderby'], $allowed_orderby, true)
                ? $data['action_orderby']
                : 'rand';
        }

        if (isset($data['action_limit'])) {
            $limit = absint($data['action_limit']);
            $sanitized['action_limit'] = min(max($limit, 1), 8);
        }

        if (isset($data['exclude_outofstock'])) {
            $sanitized['exclude_outofstock'] = absint($data['exclude_outofstock']) ? 1 : 0;
        }

        return $sanitized;
    }

    /**
     * Get format array for wpdb operations.
     *
     * @param array $data Data array.
     * @return array
     */
    private static function get_format($data)
    {
        $format_map = array(
            'name' => '%s',
            'priority' => '%d',
            'status' => '%d',
            'condition_attribute' => '%s',
            'condition_value' => '%s',
            'condition_operator' => '%s',
            'action_source' => '%s',
            'action_value' => '%s',
            'action_orderby' => '%s',
            'action_limit' => '%d',
            'exclude_outofstock' => '%d',
        );

        $format = array();
        foreach (array_keys($data) as $key) {
            $format[] = isset($format_map[$key]) ? $format_map[$key] : '%s';
        }

        return $format;
    }

    /**
     * Get all registered product taxonomies for dropdowns.
     *
     * @return array Associative array of taxonomy slug => label.
     */
    public static function get_product_taxonomies()
    {
        $taxonomies = array();

        // Product categories & tags.
        $taxonomies['product_cat'] = __('Product Category', 'woobooster');
        $taxonomies['product_tag'] = __('Product Tag', 'woobooster');

        // Product attributes.
        $attribute_taxonomies = wc_get_attribute_taxonomies();
        if ($attribute_taxonomies) {
            foreach ($attribute_taxonomies as $attribute) {
                $tax_name = wc_attribute_taxonomy_name($attribute->attribute_name);
                $taxonomies[$tax_name] = $attribute->attribute_label;
            }
        }

        return $taxonomies;
    }
}
