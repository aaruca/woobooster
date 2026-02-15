<?php
/**
 * WooBooster Activator.
 *
 * Handles plugin activation — database table creation and default options.
 *
 * @package WooBooster
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooBooster_Activator
{

    /**
     * Run activation tasks.
     */
    public static function activate()
    {
        self::create_tables();
        self::set_default_options();
        update_option('woobooster_version', WOOBOOSTER_VERSION);
    }

    /**
     * Create custom database tables.
     */
    private static function create_tables()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $rules_table = $wpdb->prefix . 'woobooster_rules';
        $index_table = $wpdb->prefix . 'woobooster_rule_index';
        $conditions_table = $wpdb->prefix . 'woobooster_rule_conditions';
        $actions_table = $wpdb->prefix . 'woobooster_rule_actions';

        $sql = "CREATE TABLE {$rules_table} (
			id INT AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL,
			priority INT NOT NULL DEFAULT 10,
			status TINYINT NOT NULL DEFAULT 1,
			condition_attribute VARCHAR(100) NOT NULL DEFAULT '',
			condition_value VARCHAR(255) NOT NULL DEFAULT '',
			condition_operator VARCHAR(20) NOT NULL DEFAULT 'equals',
			include_children TINYINT NOT NULL DEFAULT 0,
			action_source VARCHAR(50) NOT NULL,
			action_value VARCHAR(255) NOT NULL DEFAULT '',
			action_orderby VARCHAR(50) NOT NULL DEFAULT 'rand',
			action_limit INT NOT NULL DEFAULT 4,
			exclude_outofstock TINYINT NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY priority (priority),
			KEY status (status)
		) {$charset_collate};

		CREATE TABLE {$conditions_table} (
			id INT AUTO_INCREMENT,
			rule_id INT NOT NULL,
			group_id INT NOT NULL DEFAULT 0,
			condition_attribute VARCHAR(100) NOT NULL,
			condition_operator VARCHAR(20) NOT NULL DEFAULT 'equals',
			condition_value VARCHAR(255) NOT NULL,
			include_children TINYINT NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY rule_id (rule_id),
			KEY group_id (group_id)
		) {$charset_collate};

		CREATE TABLE {$index_table} (
			id INT AUTO_INCREMENT,
			condition_key VARCHAR(355) NOT NULL,
			rule_id INT NOT NULL,
			priority INT NOT NULL DEFAULT 10,
			PRIMARY KEY (id),
			KEY condition_key (condition_key),
			KEY rule_id (rule_id)
		) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Add FOREIGN KEY constraints (dbDelta doesn't support FKs).
        // Wrapped in silent calls — will no-op if the constraint already exists.
        $wpdb->suppress_errors(true);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            "ALTER TABLE {$index_table}
             ADD CONSTRAINT fk_woobooster_rule_id
             FOREIGN KEY (rule_id) REFERENCES {$rules_table}(id)
             ON DELETE CASCADE"
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            "ALTER TABLE {$conditions_table}
             ADD CONSTRAINT fk_woobooster_cond_rule_id
             FOREIGN KEY (rule_id) REFERENCES {$rules_table}(id)
             ON DELETE CASCADE"
        );
        $wpdb->suppress_errors(false);
    }

    /**
     * Set default plugin options.
     */
    private static function set_default_options()
    {
        $defaults = array(
            'enabled' => '1',
            'section_title' => __('You May Also Like', 'woobooster'),
            'render_method' => 'bricks',
            'exclude_outofstock' => '1',
            'debug_mode' => '0',
            'delete_data_uninstall' => '0',
        );

        $existing = get_option('woobooster_settings', array());

        if (empty($existing)) {
            update_option('woobooster_settings', $defaults);
        }
    }
}
