<?php
/**
 * Plugin Name:       WooBooster
 * Plugin URI:        https://example.com/woobooster
 * Description:       A rule-based product recommendation engine for WooCommerce with full Bricks Builder Query Loop integration.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Ale Aruca, Muhammad Adeel
 * Author URI:        https://example.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woobooster
 * Domain Path:       /languages
 *
 * WC requires at least: 6.0
 * WC tested up to:      9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('WOOBOOSTER_VERSION', '1.2.0');
define('WOOBOOSTER_FILE', __FILE__);
define('WOOBOOSTER_PATH', plugin_dir_path(__FILE__));
define('WOOBOOSTER_URL', plugin_dir_url(__FILE__));
define('WOOBOOSTER_BASENAME', plugin_basename(__FILE__));

// Database schema version — bump when schema changes.
define('WOOBOOSTER_DB_VERSION', '1.2.0');

/**
 * Run any pending database upgrades.
 * Fires early on plugins_loaded so tables are ready before anything else.
 */
function woobooster_maybe_upgrade_db()
{
    $installed = get_option('woobooster_db_version', '1.0.0');

    if (version_compare($installed, '1.1.0', '<')) {
        global $wpdb;
        $table = $wpdb->prefix . 'woobooster_rules';

        // Add include_children column if it doesn't exist.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $col = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'include_children'");
        if (empty($col)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN include_children TINYINT NOT NULL DEFAULT 0 AFTER condition_operator");
        }

        update_option('woobooster_db_version', '1.1.0');
    }

    // 1.2.0: Add conditions table and migrate single-condition data.
    if (version_compare($installed, '1.2.0', '<')) {
        // Re-run create_tables to ensure conditions table exists.
        require_once WOOBOOSTER_PATH . 'includes/class-woobooster-activator.php';
        WooBooster_Activator::activate();

        // Migrate existing rules that have condition_attribute set
        // into the new conditions table (group_id = 0).
        global $wpdb;
        $rules_table = $wpdb->prefix . 'woobooster_rules';
        $cond_table = $wpdb->prefix . 'woobooster_rule_conditions';

        // Only migrate rules that have conditions and haven't been migrated yet.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $existing = $wpdb->get_var("SELECT COUNT(*) FROM {$cond_table}");
        if (0 === (int) $existing) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $rules = $wpdb->get_results(
                "SELECT id, condition_attribute, condition_operator, condition_value, include_children
                 FROM {$rules_table}
                 WHERE condition_attribute != ''"
            );

            if ($rules) {
                foreach ($rules as $r) {
                    $wpdb->insert(
                        $cond_table,
                        array(
                            'rule_id' => absint($r->id),
                            'group_id' => 0,
                            'condition_attribute' => sanitize_key($r->condition_attribute),
                            'condition_operator' => sanitize_key($r->condition_operator),
                            'condition_value' => sanitize_text_field($r->condition_value),
                            'include_children' => absint($r->include_children),
                        ),
                        array('%d', '%d', '%s', '%s', '%s', '%d')
                    );
                }
            }
        }

        update_option('woobooster_db_version', '1.2.0');
    }
}
add_action('plugins_loaded', 'woobooster_maybe_upgrade_db', 5);

/**
 * Check WooCommerce dependency on activation.
 */
function woobooster_activate()
{
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(WOOBOOSTER_BASENAME);
        wp_die(
            esc_html__('WooBooster requires WooCommerce to be installed and active.', 'woobooster'),
            esc_html__('Plugin Activation Error', 'woobooster'),
            array('back_link' => true)
        );
    }

    require_once WOOBOOSTER_PATH . 'includes/class-woobooster-activator.php';
    WooBooster_Activator::activate();
}
register_activation_hook(__FILE__, 'woobooster_activate');

/**
 * Deactivation hook — nothing to clean.
 */
function woobooster_deactivate()
{
    // Rules persist across deactivation.
}
register_deactivation_hook(__FILE__, 'woobooster_deactivate');

/**
 * Admin notice if WooCommerce is not active.
 */
function woobooster_admin_notice_wc_missing()
{
    if (!class_exists('WooCommerce')) {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('WooBooster requires WooCommerce to be installed and active. The plugin has been deactivated.', 'woobooster');
        echo '</p></div>';
        deactivate_plugins(WOOBOOSTER_BASENAME);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['activate'])) {
            unset($_GET['activate']);
        }
    }
}
add_action('admin_notices', 'woobooster_admin_notice_wc_missing');

/**
 * Diagnostic notice: Bricks detection status.
 * Only visible when WooBooster debug_mode is '1'.
 */
function woobooster_bricks_diagnostic_notice()
{
    // Only show on WooBooster admin pages or when debug is on.
    $settings = get_option('woobooster_settings', array());
    if (empty($settings['debug_mode']) || $settings['debug_mode'] !== '1') {
        return;
    }

    $checks = array(
        'BRICKS_VERSION defined' => defined('BRICKS_VERSION') ? 'YES (' . BRICKS_VERSION . ')' : 'NO',
        'Bricks\Elements class' => class_exists('\Bricks\Elements') ? 'YES' : 'NO',
        'Theme template' => wp_get_theme()->get_template(),
        'WooCommerce active' => class_exists('WooCommerce') ? 'YES' : 'NO',
        'WooBooster version' => WOOBOOSTER_VERSION,
    );

    echo '<div class="notice notice-info"><p><strong>WooBooster Bricks Diagnostic:</strong> ';
    $parts = array();
    foreach ($checks as $label => $value) {
        $parts[] = esc_html($label) . ': <code>' . esc_html($value) . '</code>';
    }
    echo implode(' | ', $parts);
    echo '</p></div>';
}
add_action('admin_notices', 'woobooster_bricks_diagnostic_notice');

/**
 * Initialize the plugin after WooCommerce is loaded.
 */
function woobooster_init()
{
    if (!class_exists('WooCommerce')) {
        return;
    }

    // Core includes.
    require_once WOOBOOSTER_PATH . 'includes/class-woobooster-rule.php';
    require_once WOOBOOSTER_PATH . 'includes/class-woobooster-matcher.php';
    require_once WOOBOOSTER_PATH . 'includes/class-woobooster-shortcode.php';

    // Admin includes.
    if (is_admin()) {
        require_once WOOBOOSTER_PATH . 'admin/class-woobooster-admin.php';
        require_once WOOBOOSTER_PATH . 'admin/class-woobooster-rule-list.php';
        require_once WOOBOOSTER_PATH . 'admin/class-woobooster-rule-form.php';
        require_once WOOBOOSTER_PATH . 'admin/class-woobooster-rule-tester.php';
        require_once WOOBOOSTER_PATH . 'admin/class-woobooster-ajax.php';
        require_once WOOBOOSTER_PATH . 'admin/class-woobooster-icons.php';

        $admin = new WooBooster_Admin();
        $admin->init();

        $ajax = new WooBooster_Ajax();
        $ajax->init();

        // GitHub auto-updater.
        require_once WOOBOOSTER_PATH . 'includes/class-woobooster-updater.php';
        $updater = new WooBooster_Updater(
            'aaruca',           // GitHub username
            'woobooster',       // GitHub repo name
            WOOBOOSTER_BASENAME,
            WOOBOOSTER_VERSION
        );
        $updater->init();
    }

    // Frontend includes.
    require_once WOOBOOSTER_PATH . 'frontend/class-woobooster-frontend.php';
    $frontend = new WooBooster_Frontend();
    $frontend->init();

    // Shortcode.
    WooBooster_Shortcode::init();
}
add_action('plugins_loaded', 'woobooster_init', 20);

/**
 * Bricks Builder integration — Phase 1: Register query type.
 *
 * CRITICAL: This filter MUST be registered at file-load time (no hook wrapper).
 * Bricks calls apply_filters('bricks/setup/control_options') during
 * after_setup_theme when its Setup class initializes. Since plugins load
 * BEFORE after_setup_theme, registering the filter here guarantees Bricks
 * sees our query type in its dropdown.
 *
 * If Bricks is not active, the filter simply never fires — zero overhead.
 */
add_filter('bricks/setup/control_options', function ($control_options) {
    $control_options['queryTypes']['woobooster_recommendations'] = esc_html__('WooBooster Recommendations', 'woobooster');
    return $control_options;
});

/**
 * Bricks Builder integration — Phase 2: Register runtime query hooks.
 *
 * These hooks fire during page rendering (not during Bricks' setup), so
 * registering them on 'init' is fine. We still need Bricks + WooCommerce
 * to be active before loading the class.
 */
function woobooster_init_bricks_runtime()
{
    if (!class_exists('WooCommerce')) {
        return;
    }

    // Detect Bricks via constant, class, or theme.
    $bricks_active = defined('BRICKS_VERSION')
        || class_exists('\\Bricks\\Elements')
        || (wp_get_theme()->get_template() === 'bricks');

    if (!$bricks_active) {
        return;
    }

    require_once WOOBOOSTER_PATH . 'frontend/class-woobooster-bricks.php';
    $bricks = new WooBooster_Bricks();
    $bricks->init();
}
add_action('init', 'woobooster_init_bricks_runtime', 11);

/**
 * Declare compatibility with WooCommerce HPOS.
 */
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/**
 * Load plugin textdomain.
 */
function woobooster_load_textdomain()
{
    load_plugin_textdomain('woobooster', false, dirname(WOOBOOSTER_BASENAME) . '/languages/');
}
add_action('init', 'woobooster_load_textdomain');

/**
 * Helper: Get plugin option.
 *
 * @param string $key     Option key.
 * @param mixed  $default Default value.
 * @return mixed
 */
function woobooster_get_option($key, $default = '')
{
    $options = get_option('woobooster_settings', array());
    return isset($options[$key]) ? $options[$key] : $default;
}
