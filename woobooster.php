<?php
/**
 * Plugin Name:       WooBooster
 * Plugin URI:        https://example.com/woobooster
 * Description:       A rule-based product recommendation engine for WooCommerce with full Bricks Builder Query Loop integration.
 * Version:           1.3.1
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
define('WOOBOOSTER_VERSION', '1.3.1');
define('WOOBOOSTER_FILE', __FILE__);
define('WOOBOOSTER_PATH', plugin_dir_path(__FILE__));
define('WOOBOOSTER_URL', plugin_dir_url(__FILE__));
define('WOOBOOSTER_BASENAME', plugin_basename(__FILE__));

// Database schema version — bump when schema changes.
define('WOOBOOSTER_DB_VERSION', '1.3.0');

/**
 * Run database updates on plugin load if versions mismatch.
 */
function woobooster_maybe_upgrade_db()
{
    $current_db_version = get_option('woobooster_db_version');

    if (version_compare($current_db_version, WOOBOOSTER_DB_VERSION, '<')) {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        // 1. Ensure `include_children` column exists (v1.1.0).
        $rules_table = $wpdb->prefix . 'woobooster_rules';
        $row = $wpdb->get_results("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = '$rules_table' AND column_name = 'include_children'");
        if (empty($row)) {
            $wpdb->query("ALTER TABLE $rules_table ADD include_children tinyint(1) NOT NULL DEFAULT 0");
        }

        // 2. Create Conditions Table (v1.2.0).
        $conditions_table = $wpdb->prefix . 'woobooster_rule_conditions';
        $sql_conditions = "CREATE TABLE $conditions_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            rule_id bigint(20) NOT NULL,
            group_id int(11) NOT NULL DEFAULT 0,
            condition_attribute varchar(255) NOT NULL,
            condition_operator varchar(50) NOT NULL DEFAULT 'equals',
            condition_value longtext NOT NULL,
            include_children tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY rule_id (rule_id)
        ) $charset_collate;";
        dbDelta($sql_conditions);

        // Migrate v1.1 single conditions to v1.2 table.
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $conditions_table");
        if (0 == $count) {
            $legacy_rules = $wpdb->get_results("SELECT id, condition_attribute, condition_value, include_children FROM $rules_table WHERE condition_attribute != ''");
            foreach ($legacy_rules as $rule) {
                $wpdb->insert(
                    $conditions_table,
                    array(
                        'rule_id' => $rule->id,
                        'group_id' => 0,
                        'condition_attribute' => $rule->condition_attribute,
                        'condition_operator' => 'equals',
                        'condition_value' => $rule->condition_value,
                        'include_children' => $rule->include_children,
                    )
                );
            }
        }

        // 3. Create Actions Table (v1.3.0).
        $actions_table = $wpdb->prefix . 'woobooster_rule_actions';
        $sql_actions = "CREATE TABLE $actions_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            rule_id bigint(20) NOT NULL,
            action_source varchar(50) NOT NULL,
            action_value longtext NOT NULL,
            action_limit int(11) NOT NULL DEFAULT 4,
            action_orderby varchar(50) NOT NULL DEFAULT 'rand',
            PRIMARY KEY  (id),
            KEY rule_id (rule_id)
        ) $charset_collate;";
        dbDelta($sql_actions);

        // Migrate v1.2 single actions to v1.3 table.
        $action_count = $wpdb->get_var("SELECT COUNT(*) FROM $actions_table");
        if (0 == $action_count) {
            // Check if legacy columns exist before selecting.
            $action_cols = $wpdb->get_results("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = '$rules_table' AND column_name = 'action_source'");
            if (!empty($action_cols)) {
                $legacy_actions = $wpdb->get_results("SELECT id, action_source, action_value, action_limit, action_orderby FROM $rules_table");
                foreach ($legacy_actions as $rule) {
                    $wpdb->insert(
                        $actions_table,
                        array(
                            'rule_id' => $rule->id,
                            'action_source' => $rule->action_source,
                            'action_value' => $rule->action_value,
                            'action_limit' => $rule->action_limit,
                            'action_orderby' => $rule->action_orderby,
                        )
                    );
                }
            }
        }

        update_option('woobooster_db_version', WOOBOOSTER_DB_VERSION);
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
