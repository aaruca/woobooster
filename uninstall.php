<?php
/**
 * WooBooster Uninstall.
 *
 * Runs when the plugin is deleted from the WordPress admin.
 * Drops custom tables and removes all options if user opted in.
 *
 * @package WooBooster
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$options = get_option('woobooster_settings', array());

// Only delete data if the user explicitly enabled this setting.
if (!empty($options['delete_data_uninstall']) && '1' === $options['delete_data_uninstall']) {
    global $wpdb;

    // Drop custom tables.
    $rules_table = $wpdb->prefix . 'woobooster_rules';
    $index_table = $wpdb->prefix . 'woobooster_rule_index';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
    $wpdb->query("DROP TABLE IF EXISTS {$index_table}");
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
    $wpdb->query("DROP TABLE IF EXISTS {$rules_table}");

    // Delete all options.
    delete_option('woobooster_settings');
    delete_option('woobooster_version');

    // Clear any transients.
    $wpdb->query(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_woobooster_%' OR option_name LIKE '_transient_timeout_woobooster_%'"
    );
}
