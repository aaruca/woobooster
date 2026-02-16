<?php
/**
 * Plugin Name:       WooBooster
 * Plugin URI:        https://woobooster.com
 * Description:       A rule-based product recommendation engine for WooCommerce with native Bricks Builder integration.
 * Version:           2.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Alejandro Ruca
 * Author URI:        https://woobooster.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woobooster
 * Domain Path:       /languages
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Define plugin constants.
define('WOOBOOSTER_VERSION', '2.1.0');
define('WOOBOOSTER_DB_VERSION', '1.4.0');
define('WOOBOOSTER_FILE', __FILE__);
define('WOOBOOSTER_PATH', plugin_dir_path(__FILE__));
define('WOOBOOSTER_URL', plugin_dir_url(__FILE__));
define('WOOBOOSTER_BASENAME', plugin_basename(__FILE__));

/**
 * Main WooBooster Class.
 *
 * @since 1.0.0
 */
final class WooBooster
{
    /**
     * Single instance of the class.
     *
     * @var WooBooster
     */
    protected static $_instance = null;

    /**
     * Main WooBooster Instance.
     *
     * Ensures only one instance of WooBooster is loaded or can be loaded.
     *
     * @return WooBooster - Main instance.
     */
    public static function instance()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->includes();
        $this->init_hooks();
    }

    /**
     * Include required core files used in admin and on the frontend.
     */
    public function includes()
    {
        require_once WOOBOOSTER_PATH . 'includes/class-woobooster-rule.php';
        require_once WOOBOOSTER_PATH . 'includes/class-woobooster-matcher.php';
        require_once WOOBOOSTER_PATH . 'includes/class-woobooster-shortcode.php';
        require_once WOOBOOSTER_PATH . 'includes/class-woobooster-cron.php';

        if (is_admin()) {
            require_once WOOBOOSTER_PATH . 'includes/class-woobooster-activator.php';
            require_once WOOBOOSTER_PATH . 'includes/class-woobooster-updater.php';
            require_once WOOBOOSTER_PATH . 'admin/class-woobooster-admin.php';
            require_once WOOBOOSTER_PATH . 'admin/class-woobooster-rule-list.php';
            require_once WOOBOOSTER_PATH . 'admin/class-woobooster-rule-form.php';
            require_once WOOBOOSTER_PATH . 'admin/class-woobooster-rule-tester.php';
            require_once WOOBOOSTER_PATH . 'admin/class-woobooster-ajax.php';
            require_once WOOBOOSTER_PATH . 'admin/class-woobooster-icons.php';
        }

        if ($this->is_request('frontend')) {
            require_once WOOBOOSTER_PATH . 'frontend/class-woobooster-frontend.php';
        }
    }

    /**
     * Hook into actions and filters.
     */
    private function init_hooks()
    {
        register_activation_hook(WOOBOOSTER_FILE, array('WooBooster_Activator', 'activate'));
        register_deactivation_hook(WOOBOOSTER_FILE, array($this, 'deactivate'));

        add_action('plugins_loaded', array($this, 'on_plugins_loaded'));
        add_action('init', array($this, 'init'), 0);
        add_action('admin_notices', array($this, 'dependencies_notices'));

        // Bricks Integration.
        add_filter('bricks/setup/control_options', array($this, 'register_bricks_options'));
        add_action('init', array($this, 'init_bricks'), 11);

        // HPOS Compatibility.
        add_action('before_woocommerce_init', function () {
            if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', WOOBOOSTER_FILE, true);
            }
        });
    }

    /**
     * Actions to run on plugins_loaded.
     */
    public function on_plugins_loaded()
    {
        $this->check_dependencies();
        $this->load_textdomain();

        if ($this->dependencies_met) {
            $this->maybe_update_db();
        }
    }

    /**
     * Init constants and classes.
     */
    public function init()
    {
        if (!$this->dependencies_met) {
            return;
        }

        if (is_admin()) {
            $admin = new WooBooster_Admin();
            $admin->init();

            $ajax = new WooBooster_Ajax();
            $ajax->init();

            // Initialize GitHub Updater.
            $updater = new WooBooster_Updater('aaruca', 'woobooster', WOOBOOSTER_BASENAME, WOOBOOSTER_VERSION);
            $updater->init();
        }

        if ($this->is_request('frontend')) {
            $frontend = new WooBooster_Frontend();
            $frontend->init();
        }

        WooBooster_Shortcode::init();

        // Initialize Smart Recommendations cron.
        $cron = new WooBooster_Cron();
        $cron->init();
        WooBooster_Cron::schedule();
    }

    /**
     * Load Bricks Builder integration if available.
     */
    public function init_bricks()
    {
        if (!class_exists('WooCommerce')) {
            return;
        }

        // Detect if Bricks is active via constant, class, or current theme template.
        $is_bricks_active = defined('BRICKS_VERSION') || class_exists('\\Bricks\\Elements') || (function_exists('wp_get_theme') && wp_get_theme()->get_template() === 'bricks');

        if (!$is_bricks_active) {
            return;
        }

        require_once WOOBOOSTER_PATH . 'frontend/class-woobooster-bricks.php';
        $bricks = new WooBooster_Bricks();
        $bricks->init();
    }

    /**
     * Register WooBooster options in Bricks.
     *
     * @param array $control_options Bricks control options.
     * @return array
     */
    public function register_bricks_options($control_options)
    {
        $control_options['queryTypes']['woobooster_recommendations'] = esc_html__('WooBooster Recommendations', 'woobooster');
        return $control_options;
    }

    /**
     * Whether all required dependencies are met.
     *
     * @var bool
     */
    private $dependencies_met = false;

    /**
     * Check if WooCommerce is active.
     */
    private function check_dependencies()
    {
        $this->dependencies_met = class_exists('WooCommerce');
    }

    /**
     * Display admin notice if WooCommerce is missing.
     */
    public function dependencies_notices()
    {
        if (!class_exists('WooCommerce')) {
            $message = sprintf(
                /* translators: %s: Plugin name */
                esc_html__('%s requires WooCommerce to be installed and active.', 'woobooster'),
                '<strong>WooBooster</strong>'
            );

            echo '<div class="notice notice-error"><p>' . wp_kses_post($message) . '</p></div>';
        }
    }

    /**
     * Handle database updates.
     */
    private function maybe_update_db()
    {
        $current_db_version = get_option('woobooster_db_version');

        if (version_compare($current_db_version, WOOBOOSTER_DB_VERSION, '<')) {
            require_once WOOBOOSTER_PATH . 'includes/class-woobooster-activator.php';
            WooBooster_Activator::migrate_tables();
            update_option('woobooster_db_version', WOOBOOSTER_DB_VERSION);
        }
    }

    /**
     * Load plugin textdomain.
     */
    public function load_textdomain()
    {
        load_plugin_textdomain('woobooster', false, dirname(WOOBOOSTER_BASENAME) . '/languages/');
    }

    /**
     * Placeholder for deactivation logic.
     */
    public function deactivate()
    {
        // Unschedule cron events on deactivation.
        WooBooster_Cron::unschedule();
    }

    /**
     * What type of request is this?
     *
     * @param string $type admin, ajax, cron or frontend.
     * @return bool
     */
    private function is_request($type)
    {
        switch ($type) {
            case 'admin':
                return is_admin();
            case 'ajax':
                return defined('DOING_AJAX');
            case 'cron':
                return defined('DOING_CRON');
            case 'frontend':
                return (!is_admin() || defined('DOING_AJAX')) && !defined('DOING_CRON');
        }
        return false;
    }
}

/**
 * Main instance of WooBooster.
 */
function woobooster()
{
    return WooBooster::instance();
}

// Global for backwards compatibility.
$GLOBALS['woobooster'] = woobooster();

// Helper function to get options.
if (!function_exists('woobooster_get_option')) {
    function woobooster_get_option($key, $default = '')
    {
        $options = get_option('woobooster_settings', array());
        return isset($options[$key]) ? $options[$key] : $default;
    }
}
