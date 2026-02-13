<?php
/**
 * WooBooster Admin.
 *
 * Handles admin menu, settings page, and asset enqueueing.
 *
 * @package WooBooster
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooBooster_Admin
{

    /**
     * Initialize admin hooks.
     */
    public function init()
    {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_init', array($this, 'handle_settings_save'));
    }

    /**
     * Add admin menu pages.
     */
    public function add_menu()
    {
        add_menu_page(
            __('WooBooster', 'woobooster'),
            __('WooBooster', 'woobooster'),
            'manage_woocommerce',
            'woobooster',
            array($this, 'render_page'),
            'dashicons-thumbs-up',
            56
        );

        add_submenu_page(
            'woobooster',
            __('Settings', 'woobooster'),
            __('Settings', 'woobooster'),
            'manage_woocommerce',
            'woobooster',
            array($this, 'render_page')
        );

        add_submenu_page(
            'woobooster',
            __('Rule Manager', 'woobooster'),
            __('Rule Manager', 'woobooster'),
            'manage_woocommerce',
            'woobooster-rules',
            array($this, 'render_page')
        );

        add_submenu_page(
            'woobooster',
            __('Diagnostics', 'woobooster'),
            __('Diagnostics', 'woobooster'),
            'manage_woocommerce',
            'woobooster-diagnostics',
            array($this, 'render_page')
        );
    }

    /**
     * Enqueue admin assets.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_assets($hook)
    {
        $allowed_hooks = array(
            'toplevel_page_woobooster',
            'woobooster_page_woobooster-rules',
            'woobooster_page_woobooster-diagnostics',
        );

        if (!in_array($hook, $allowed_hooks, true)) {
            return;
        }

        wp_enqueue_style(
            'woobooster-admin',
            WOOBOOSTER_URL . 'admin/css/woobooster-admin.css',
            array(),
            WOOBOOSTER_VERSION
        );

        wp_enqueue_script(
            'woobooster-admin',
            WOOBOOSTER_URL . 'admin/js/woobooster-admin.js',
            array(),
            WOOBOOSTER_VERSION,
            true
        );

        wp_localize_script('woobooster-admin', 'wooboosterAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('woobooster_admin'),
            'i18n' => array(
                'confirmDelete' => __('Are you sure you want to delete this rule?', 'woobooster'),
                'searching' => __('Searching…', 'woobooster'),
                'noResults' => __('No results found.', 'woobooster'),
                'loading' => __('Loading…', 'woobooster'),
                'testing' => __('Testing…', 'woobooster'),
            ),
        ));
    }

    /**
     * Route to the correct page renderer.
     */
    public function render_page()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'woobooster'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : 'woobooster';

        echo '<div class="woobooster-admin">';
        $this->render_header();
        echo '<div class="wb-layout">';
        $this->render_sidebar($page);
        echo '<div class="wb-content">';

        switch ($page) {
            case 'woobooster-rules':
                $this->render_rules_page();
                break;
            case 'woobooster-diagnostics':
                $this->render_diagnostics_page();
                break;
            default:
                $this->render_settings_page();
                break;
        }

        echo '</div>'; // .wb-content
        echo '</div>'; // .wb-layout
        $this->render_footer();
        echo '</div>'; // .woobooster-admin
    }

    /**
     * Render the page header.
     */
    private function render_header()
    {
        echo '<header class="wb-header">';
        echo '<div class="wb-header__title">';
        echo '<h1>' . esc_html__('WooBooster', 'woobooster') . '</h1>';
        echo '</div>';
        echo '<div class="wb-header__version">v' . esc_html(WOOBOOSTER_VERSION) . '</div>';
        echo '</header>';
    }

    /**
     * Render the sidebar navigation.
     *
     * @param string $current_page Current page slug.
     */
    private function render_sidebar($current_page)
    {
        $nav_items = array(
            'woobooster' => array(
                'label' => __('Settings', 'woobooster'),
                'icon' => WooBooster_Icons::get('settings'),
            ),
            'woobooster-rules' => array(
                'label' => __('Rule Manager', 'woobooster'),
                'icon' => WooBooster_Icons::get('rules'),
            ),
            'woobooster-diagnostics' => array(
                'label' => __('Diagnostics', 'woobooster'),
                'icon' => WooBooster_Icons::get('search'),
            ),
        );

        echo '<nav class="wb-sidebar">';
        echo '<ul class="wb-sidebar__nav">';
        foreach ($nav_items as $slug => $item) {
            $active = ($slug === $current_page) ? ' wb-sidebar__item--active' : '';
            $url = admin_url('admin.php?page=' . $slug);
            echo '<li class="wb-sidebar__item' . esc_attr($active) . '">';
            echo '<a href="' . esc_url($url) . '">';
            echo '<span class="wb-sidebar__icon">' . $item['icon'] . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG icon
            echo '<span class="wb-sidebar__label">' . esc_html($item['label']) . '</span>';
            echo '</a></li>';
        }
        echo '</ul>';
        echo '</nav>';
    }

    /**
     * Render the page footer.
     */
    private function render_footer()
    {
        echo '<footer class="wb-footer">';
        echo '<span>' . esc_html__('WooBooster', 'woobooster') . ' v' . esc_html(WOOBOOSTER_VERSION) . '</span>';
        echo '<span class="wb-footer__sep">·</span>';
        echo '<span>' . esc_html__('Rule-based product recommendations for WooCommerce', 'woobooster') . '</span>';
        echo '</footer>';
    }

    /**
     * Render the Settings page.
     */
    private function render_settings_page()
    {
        $options = get_option('woobooster_settings', array());

        $enabled = isset($options['enabled']) ? $options['enabled'] : '1';
        $section_title = isset($options['section_title']) ? $options['section_title'] : __('You May Also Like', 'woobooster');
        $render_method = isset($options['render_method']) ? $options['render_method'] : 'bricks';
        $exclude_outofstock = isset($options['exclude_outofstock']) ? $options['exclude_outofstock'] : '1';
        $debug_mode = isset($options['debug_mode']) ? $options['debug_mode'] : '0';
        $delete_data = isset($options['delete_data_uninstall']) ? $options['delete_data_uninstall'] : '0';

        // Show save notice.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['settings-updated']) && 'true' === $_GET['settings-updated']) {
            echo '<div class="wb-message wb-message--success">';
            echo WooBooster_Icons::get('check'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '<span>' . esc_html__('Settings saved.', 'woobooster') . '</span>';
            echo '</div>';
        }

        echo '<form method="post" action="">';
        wp_nonce_field('woobooster_save_settings', 'woobooster_settings_nonce');

        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h2>' . esc_html__('General Settings', 'woobooster') . '</h2></div>';
        echo '<div class="wb-card__body">';

        // Enable/Disable.
        echo '<div class="wb-field">';
        echo '<label class="wb-field__label">' . esc_html__('Enable Recommendations', 'woobooster') . '</label>';
        echo '<div class="wb-field__control">';
        echo '<label class="wb-toggle">';
        echo '<input type="checkbox" name="woobooster_enabled" value="1"' . checked($enabled, '1', false) . '>';
        echo '<span class="wb-toggle__slider"></span>';
        echo '</label>';
        echo '<p class="wb-field__desc">' . esc_html__('Enable or disable the entire recommendation system.', 'woobooster') . '</p>';
        echo '</div></div>';

        // Section Title.
        echo '<div class="wb-field">';
        echo '<label class="wb-field__label" for="wb-section-title">' . esc_html__('Section Title', 'woobooster') . '</label>';
        echo '<div class="wb-field__control">';
        echo '<input type="text" id="wb-section-title" name="woobooster_section_title" value="' . esc_attr($section_title) . '" class="wb-input">';
        echo '<p class="wb-field__desc">' . esc_html__('Heading displayed above the recommended products.', 'woobooster') . '</p>';
        echo '</div></div>';

        // Render Method.
        echo '<div class="wb-field">';
        echo '<label class="wb-field__label" for="wb-render-method">' . esc_html__('Rendering Method', 'woobooster') . '</label>';
        echo '<div class="wb-field__control">';
        echo '<select id="wb-render-method" name="woobooster_render_method" class="wb-select">';
        echo '<option value="bricks"' . selected($render_method, 'bricks', false) . '>' . esc_html__('Bricks Query Loop (recommended)', 'woobooster') . '</option>';
        echo '<option value="woo_hook"' . selected($render_method, 'woo_hook', false) . '>' . esc_html__('WooCommerce Hook (fallback)', 'woobooster') . '</option>';
        echo '</select>';
        echo '<p class="wb-field__desc">' . esc_html__('Choose how recommendations are rendered on the frontend.', 'woobooster') . '</p>';
        echo '</div></div>';

        // Exclude Out of Stock.
        echo '<div class="wb-field">';
        echo '<label class="wb-field__label">' . esc_html__('Exclude Out of Stock', 'woobooster') . '</label>';
        echo '<div class="wb-field__control">';
        echo '<label class="wb-toggle">';
        echo '<input type="checkbox" name="woobooster_exclude_outofstock" value="1"' . checked($exclude_outofstock, '1', false) . '>';
        echo '<span class="wb-toggle__slider"></span>';
        echo '</label>';
        echo '<p class="wb-field__desc">' . esc_html__('Globally exclude out-of-stock products from recommendations.', 'woobooster') . '</p>';
        echo '</div></div>';

        // Debug Mode.
        echo '<div class="wb-field">';
        echo '<label class="wb-field__label">' . esc_html__('Debug Mode', 'woobooster') . '</label>';
        echo '<div class="wb-field__control">';
        echo '<label class="wb-toggle">';
        echo '<input type="checkbox" name="woobooster_debug_mode" value="1"' . checked($debug_mode, '1', false) . '>';
        echo '<span class="wb-toggle__slider"></span>';
        echo '</label>';
        echo '<p class="wb-field__desc">' . esc_html__('Log rule matching details to WooCommerce → Status → Logs.', 'woobooster') . '</p>';
        echo '</div></div>';

        // Delete on Uninstall.
        echo '<div class="wb-field">';
        echo '<label class="wb-field__label">' . esc_html__('Delete Data on Uninstall', 'woobooster') . '</label>';
        echo '<div class="wb-field__control">';
        echo '<label class="wb-toggle">';
        echo '<input type="checkbox" name="woobooster_delete_data" value="1"' . checked($delete_data, '1', false) . '>';
        echo '<span class="wb-toggle__slider"></span>';
        echo '</label>';
        echo '<p class="wb-field__desc">' . esc_html__('Remove all WooBooster data (rules, settings) when the plugin is uninstalled.', 'woobooster') . '</p>';
        echo '</div></div>';

        echo '</div>'; // .wb-card__body
        echo '</div>'; // .wb-card

        echo '<div class="wb-actions-bar">';
        echo '<button type="submit" class="wb-btn wb-btn--primary">' . esc_html__('Save Settings', 'woobooster') . '</button>';
        echo '</div>';

        echo '</form>';
    }

    /**
     * Handle settings form submission.
     */
    public function handle_settings_save()
    {
        if (!isset($_POST['woobooster_settings_nonce'])) {
            return;
        }

        if (!wp_verify_nonce(sanitize_key($_POST['woobooster_settings_nonce']), 'woobooster_save_settings')) {
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $options = array(
            'enabled' => isset($_POST['woobooster_enabled']) ? '1' : '0',
            'section_title' => isset($_POST['woobooster_section_title']) ? sanitize_text_field(wp_unslash($_POST['woobooster_section_title'])) : '',
            'render_method' => isset($_POST['woobooster_render_method']) ? sanitize_key($_POST['woobooster_render_method']) : 'bricks',
            'exclude_outofstock' => isset($_POST['woobooster_exclude_outofstock']) ? '1' : '0',
            'debug_mode' => isset($_POST['woobooster_debug_mode']) ? '1' : '0',
            'delete_data_uninstall' => isset($_POST['woobooster_delete_data']) ? '1' : '0',
        );

        update_option('woobooster_settings', $options);

        wp_safe_redirect(add_query_arg('settings-updated', 'true', admin_url('admin.php?page=woobooster')));
        exit;
    }

    /**
     * Render the Rule Manager page.
     */
    private function render_rules_page()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : 'list';

        switch ($action) {
            case 'add':
            case 'edit':
                $form = new WooBooster_Rule_Form();
                $form->render();
                break;
            default:
                $list = new WooBooster_Rule_List();
                $list->prepare_items();

                echo '<div class="wb-card">';
                echo '<div class="wb-card__header">';
                echo '<h2>' . esc_html__('Rules', 'woobooster') . '</h2>';
                $add_url = admin_url('admin.php?page=woobooster-rules&action=add');
                echo '<a href="' . esc_url($add_url) . '" class="wb-btn wb-btn--primary wb-btn--sm">';
                echo WooBooster_Icons::get('plus'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo esc_html__('Add Rule', 'woobooster');
                echo '</a>';
                echo '</div>';
                echo '<div class="wb-card__body wb-card__body--table">';
                $list->display();
                echo '</div></div>';
                break;
        }
    }

    /**
     * Render the Diagnostics page.
     */
    private function render_diagnostics_page()
    {
        $tester = new WooBooster_Rule_Tester();
        $tester->render();
    }
}
