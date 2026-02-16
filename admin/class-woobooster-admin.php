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
        add_action('wp_ajax_woobooster_check_update', array($this, 'ajax_check_update'));
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
    /**
     * Render the Settings page.
     */
    private function render_settings_page()
    {
        $options = get_option('woobooster_settings', array());

        // Show save notice.
        if (isset($_GET['settings-updated']) && 'true' === $_GET['settings-updated']) {
            $this->render_notice('success', __('Settings saved.', 'woobooster'));
        }

        echo '<form method="post" action="">';
        wp_nonce_field('woobooster_save_settings', 'woobooster_settings_nonce');

        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h2>' . esc_html__('General Settings', 'woobooster') . '</h2></div>';
        echo '<div class="wb-card__body">';

        $this->render_toggle_field(
            __('Enable Recommendations', 'woobooster'),
            'woobooster_enabled',
            isset($options['enabled']) ? $options['enabled'] : '1',
            __('Enable or disable the entire recommendation system.', 'woobooster')
        );

        $this->render_text_field(
            __('Section Title', 'woobooster'),
            'woobooster_section_title',
            isset($options['section_title']) ? $options['section_title'] : __('You May Also Like', 'woobooster'),
            __('Heading displayed above the recommended products.', 'woobooster')
        );

        $this->render_select_field(
            __('Rendering Method', 'woobooster'),
            'woobooster_render_method',
            isset($options['render_method']) ? $options['render_method'] : 'bricks',
            array(
                'bricks' => __('Bricks Query Loop (recommended)', 'woobooster'),
                'woo_hook' => __('WooCommerce Hook (fallback)', 'woobooster'),
            ),
            __('Choose how recommendations are rendered on the frontend.', 'woobooster')
        );

        $this->render_toggle_field(
            __('Exclude Out of Stock', 'woobooster'),
            'woobooster_exclude_outofstock',
            isset($options['exclude_outofstock']) ? $options['exclude_outofstock'] : '1',
            __('Globally exclude out-of-stock products from recommendations.', 'woobooster')
        );

        $this->render_toggle_field(
            __('Debug Mode', 'woobooster'),
            'woobooster_debug_mode',
            isset($options['debug_mode']) ? $options['debug_mode'] : '0',
            __('Log rule matching details to WooCommerce → Status → Logs.', 'woobooster')
        );

        $this->render_toggle_field(
            __('Delete Data on Uninstall', 'woobooster'),
            'woobooster_delete_data',
            isset($options['delete_data_uninstall']) ? $options['delete_data_uninstall'] : '0',
            __('Remove all WooBooster data (rules, settings) when the plugin is uninstalled.', 'woobooster')
        );

        echo '</div></div>';

        echo '<div class="wb-actions-bar">';
        echo '<button type="submit" class="wb-btn wb-btn--primary">' . esc_html__('Save Settings', 'woobooster') . '</button>';
        echo '</div>';

        echo '</form>';

        $this->render_updates_section();
    }

    /**
     * Render the updates section card.
     */
    private function render_updates_section()
    {
        ?>
        <div class="wb-card" style="margin-top:24px;">
            <div class="wb-card__header">
                <h2><?php esc_html_e('Plugin Updates', 'woobooster'); ?></h2>
            </div>
            <div class="wb-card__body">
                <p><?php esc_html_e('Current version:', 'woobooster'); ?>
                    <strong>v<?php echo esc_html(WOOBOOSTER_VERSION); ?></strong></p>
                <p class="wb-field__desc">
                    <?php esc_html_e('Click below to check GitHub for new releases. WordPress checks automatically every 12 hours.', 'woobooster'); ?>
                </p>
                <div style="margin-top:12px;">
                    <button type="button" id="wb-check-update" class="wb-btn wb-btn--secondary">
                        <?php esc_html_e('Check for Updates Now', 'woobooster'); ?>
                    </button>
                    <span id="wb-update-result" style="margin-left:12px;"></span>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render a standard text field.
     */
    private function render_text_field($label, $name, $value, $desc)
    {
        ?>
        <div class="wb-field">
            <label class="wb-field__label" for="<?php echo esc_attr($name); ?>"><?php echo esc_html($label); ?></label>
            <div class="wb-field__control">
                <input type="text" id="<?php echo esc_attr($name); ?>" name="<?php echo esc_attr($name); ?>"
                    value="<?php echo esc_attr($value); ?>" class="wb-input">
                <p class="wb-field__desc"><?php echo esc_html($desc); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Render a toggle switch field.
     */
    private function render_toggle_field($label, $name, $value, $desc)
    {
        ?>
        <div class="wb-field">
            <label class="wb-field__label"><?php echo esc_html($label); ?></label>
            <div class="wb-field__control">
                <label class="wb-toggle">
                    <input type="checkbox" name="<?php echo esc_attr($name); ?>" value="1" <?php checked($value, '1'); ?>>
                    <span class="wb-toggle__slider"></span>
                </label>
                <p class="wb-field__desc"><?php echo esc_html($desc); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Render a select dropdown field.
     */
    private function render_select_field($label, $name, $value, $options, $desc)
    {
        ?>
        <div class="wb-field">
            <label class="wb-field__label" for="<?php echo esc_attr($name); ?>"><?php echo esc_html($label); ?></label>
            <div class="wb-field__control">
                <select id="<?php echo esc_attr($name); ?>" name="<?php echo esc_attr($name); ?>" class="wb-select">
                    <?php foreach ($options as $opt_val => $opt_label): ?>
                        <option value="<?php echo esc_attr($opt_val); ?>" <?php selected($value, $opt_val); ?>>
                            <?php echo esc_html($opt_label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="wb-field__desc"><?php echo esc_html($desc); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Render an admin notice.
     */
    private function render_notice($type, $message)
    {
        $icon = ($type === 'success') ? WooBooster_Icons::get('check') : '';
        echo '<div class="wb-message wb-message--' . esc_attr($type) . '">';
        echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<span>' . esc_html($message) . '</span>';
        echo '</div>';
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

    /**
     * AJAX: Force-check for plugin updates.
     */
    public function ajax_check_update()
    {
        check_ajax_referer('woobooster_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'woobooster')));
        }

        // Clear cached GitHub response.
        delete_transient('woobooster_github_release');

        // Clear WordPress update transient to force re-check.
        delete_site_transient('update_plugins');

        // Trigger a fresh update check.
        wp_update_plugins();

        // Read the result.
        $update_transient = get_site_transient('update_plugins');
        $basename = WOOBOOSTER_BASENAME;

        if (isset($update_transient->response[$basename])) {
            $new_version = $update_transient->response[$basename]->new_version;
            wp_send_json_success(array(
                'message' => sprintf(
                    /* translators: %s: new version number */
                    __('Update available: v%s. Go to Plugins page to update.', 'woobooster'),
                    $new_version
                ),
                'has_update' => true,
                'new_version' => $new_version,
            ));
        } else {
            wp_send_json_success(array(
                'message' => sprintf(
                    /* translators: %s: current version */
                    __('You are running the latest version (v%s).', 'woobooster'),
                    WOOBOOSTER_VERSION
                ),
                'has_update' => false,
            ));
        }
    }
}
