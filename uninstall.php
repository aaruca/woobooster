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
    $conditions_table = $wpdb->prefix . 'woobooster_rule_conditions';
    $actions_table = $wpdb->prefix . 'woobooster_rule_actions';
    $index_table = $wpdb->prefix . 'woobooster_rule_index';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
    $wpdb->query("DROP TABLE IF EXISTS {$index_table}");
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
    $wpdb->query("DROP TABLE IF EXISTS {$actions_table}");
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
    $wpdb->query("DROP TABLE IF EXISTS {$conditions_table}");
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
    $wpdb->query("DROP TABLE IF EXISTS {$rules_table}");

    // Delete Smart Recommendations postmeta.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoDB
    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_woobooster_copurchased'");

    // Delete all options.
    delete_option('woobooster_settings');
    delete_option('woobooster_version');
    delete_option('woobooster_db_version');
    delete_option('woobooster_last_build');

    // Clear plugin transients.
    $wpdb->query(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_woobooster_%' OR option_name LIKE '_transient_timeout_woobooster_%'"
    );
    // Clear Smart Recommendations transients.
    $wpdb->query(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wb_trending_%' OR option_name LIKE '_transient_timeout_wb_trending_%'"
    );
    $wpdb->query(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wb_similar_%' OR option_name LIKE '_transient_timeout_wb_similar_%'"
    );
}
