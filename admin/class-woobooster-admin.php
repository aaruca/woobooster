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
        add_action('wp_ajax_woobooster_export_rules', array($this, 'ajax_export_rules'));
        add_action('wp_ajax_woobooster_import_rules', array($this, 'ajax_import_rules'));
        add_action('wp_ajax_woobooster_rebuild_index', array($this, 'ajax_rebuild_index'));
        add_action('wp_ajax_woobooster_purge_index', array($this, 'ajax_purge_index'));
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

        add_submenu_page(
            'woobooster',
            __('Documentation', 'woobooster'),
            __('Documentation', 'woobooster'),
            'manage_woocommerce',
            'woobooster-documentation',
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
            'woobooster_page_woobooster-documentation',
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
            case 'woobooster-documentation':
                $this->render_documentation_page();
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

            'woobooster-documentation' => array(
                'label' => __('Documentation', 'woobooster'),
                'icon' => WooBooster_Icons::get('docs'),
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
            __('Frontend Section Title', 'woobooster'),
            'woobooster_section_title',
            isset($options['section_title']) ? $options['section_title'] : __('You May Also Like', 'woobooster'),
            __('The heading displayed above the recommended products on the product page.', 'woobooster')
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

        $this->render_smart_recommendations_section();
        $this->render_updates_section();
    }

    /**
     * Render the Smart Recommendations settings card.
     */
    private function render_smart_recommendations_section()
    {
        $options = get_option('woobooster_settings', array());
        $last_build = get_option('woobooster_last_build', array());
        ?>
        <div class="wb-card" style="margin-top:24px;">
            <div class="wb-card__header">
                <h2><?php esc_html_e('Smart Recommendations', 'woobooster'); ?></h2>
            </div>
            <div class="wb-card__body">
                <p class="wb-field__desc" style="margin-bottom:20px;">
                    <?php esc_html_e('Enable intelligent recommendation strategies. These work as new Action Sources in your rules. Zero extra database tables — all data is stored in product meta and transients.', 'woobooster'); ?>
                </p>

                <form method="post" action="" id="wb-smart-settings-form">
                    <?php wp_nonce_field('woobooster_save_settings', 'woobooster_settings_nonce'); ?>
                    <input type="hidden" name="woobooster_smart_save" value="1">

                    <div class="wb-field">
                        <label class="wb-field__label"><?php esc_html_e('Bought Together', 'woobooster'); ?></label>
                        <div class="wb-field__control">
                            <label class="wb-toggle">
                                <input type="checkbox" name="woobooster_smart_copurchase" value="1" <?php checked(!empty($options['smart_copurchase']), true); ?>>
                                <span class="wb-toggle__slider"></span>
                            </label>
                            <p class="wb-field__desc"><?php esc_html_e('Analyze orders to find products frequently purchased together. Runs nightly via WP-Cron.', 'woobooster'); ?></p>
                        </div>
                    </div>

                    <div class="wb-field">
                        <label class="wb-field__label"><?php esc_html_e('Trending Products', 'woobooster'); ?></label>
                        <div class="wb-field__control">
                            <label class="wb-toggle">
                                <input type="checkbox" name="woobooster_smart_trending" value="1" <?php checked(!empty($options['smart_trending']), true); ?>>
                                <span class="wb-toggle__slider"></span>
                            </label>
                            <p class="wb-field__desc"><?php esc_html_e('Track bestselling products per category. Updates every 6 hours via WP-Cron.', 'woobooster'); ?></p>
                        </div>
                    </div>

                    <div class="wb-field">
                        <label class="wb-field__label"><?php esc_html_e('Recently Viewed', 'woobooster'); ?></label>
                        <div class="wb-field__control">
                            <label class="wb-toggle">
                                <input type="checkbox" name="woobooster_smart_recently_viewed" value="1" <?php checked(!empty($options['smart_recently_viewed']), true); ?>>
                                <span class="wb-toggle__slider"></span>
                            </label>
                            <p class="wb-field__desc"><?php esc_html_e('Show products the visitor recently viewed. Uses a browser cookie — zero database queries.', 'woobooster'); ?></p>
                        </div>
                    </div>

                    <div class="wb-field">
                        <label class="wb-field__label"><?php esc_html_e('Similar Products', 'woobooster'); ?></label>
                        <div class="wb-field__control">
                            <label class="wb-toggle">
                                <input type="checkbox" name="woobooster_smart_similar" value="1" <?php checked(!empty($options['smart_similar']), true); ?>>
                                <span class="wb-toggle__slider"></span>
                            </label>
                            <p class="wb-field__desc"><?php esc_html_e('Find products with similar price range and category, ordered by sales. No pre-computation needed.', 'woobooster'); ?></p>
                        </div>
                    </div>

                    <hr style="border:none; border-top:1px solid #eee; margin:20px 0;">

                    <?php
                    $smart_days = isset($options['smart_days']) ? $options['smart_days'] : '90';
                    $smart_max = isset($options['smart_max_relations']) ? $options['smart_max_relations'] : '20';
                    ?>
                    <div class="wb-field">
                        <label class="wb-field__label" for="wb-smart-days"><?php esc_html_e('Days to Analyze', 'woobooster'); ?></label>
                        <div class="wb-field__control">
                            <input type="number" id="wb-smart-days" name="woobooster_smart_days" value="<?php echo esc_attr($smart_days); ?>" min="7" max="365" class="wb-input wb-input--sm" style="width:100px;">
                            <p class="wb-field__desc"><?php esc_html_e('How many days of order history to scan for co-purchase and trending data.', 'woobooster'); ?></p>
                        </div>
                    </div>

                    <div class="wb-field">
                        <label class="wb-field__label" for="wb-smart-max"><?php esc_html_e('Max Relations Per Product', 'woobooster'); ?></label>
                        <div class="wb-field__control">
                            <input type="number" id="wb-smart-max" name="woobooster_smart_max_relations" value="<?php echo esc_attr($smart_max); ?>" min="5" max="50" class="wb-input wb-input--sm" style="width:100px;">
                            <p class="wb-field__desc"><?php esc_html_e('Maximum number of related products to store per product in co-purchase index.', 'woobooster'); ?></p>
                        </div>
                    </div>

                    <div class="wb-actions-bar" style="margin-top:16px;">
                        <button type="submit" class="wb-btn wb-btn--primary"><?php esc_html_e('Save Smart Settings', 'woobooster'); ?></button>
                    </div>
                </form>

                <hr style="border:none; border-top:1px solid #eee; margin:20px 0;">

                <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                    <button type="button" id="wb-rebuild-index" class="wb-btn wb-btn--subtle">
                        <?php esc_html_e('Rebuild Now', 'woobooster'); ?>
                    </button>
                    <button type="button" id="wb-purge-index" class="wb-btn wb-btn--subtle wb-btn--danger">
                        <?php esc_html_e('Clear All Data', 'woobooster'); ?>
                    </button>
                    <span id="wb-smart-status" style="color: var(--wb-color-neutral-foreground-2); font-size:13px;">
                    <?php
                    if (!empty($last_build)) {
                        $parts = array();
                        if (!empty($last_build['copurchase'])) {
                            $cp = $last_build['copurchase'];
                            $parts[] = sprintf(
                                /* translators: 1: product count, 2: seconds, 3: date */
                                __('Co-purchase: %1$d products in %2$ss (%3$s)', 'woobooster'),
                                $cp['products'],
                                $cp['time'],
                                $cp['date']
                            );
                        }
                        if (!empty($last_build['trending'])) {
                            $tr = $last_build['trending'];
                            $parts[] = sprintf(
                                /* translators: 1: category count, 2: seconds, 3: date */
                                __('Trending: %1$d categories in %2$ss (%3$s)', 'woobooster'),
                                $tr['categories'],
                                $tr['time'],
                                $tr['date']
                            );
                        }
                        if (!empty($parts)) {
                            echo esc_html(implode(' · ', $parts));
                        }
                    }
                    ?>
                    </span>
                </div>
            </div>
        </div>
        <?php
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
                    <strong>v<?php echo esc_html(WOOBOOSTER_VERSION); ?></strong>
                </p>
                <p class="wb-field__desc">
                    <?php esc_html_e('Click below to check GitHub for new releases. WordPress checks automatically every 12 hours.', 'woobooster'); ?>
                </p>
                <div style="margin-top:12px;">
                    <button type="button" id="wb-check-update" class="wb-btn wb-btn--subtle">
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

        // Merge with existing options to preserve smart settings when saving general form and vice versa.
        $existing = get_option('woobooster_settings', array());

        if (isset($_POST['woobooster_smart_save'])) {
            // Smart Recommendations form save.
            $existing['smart_copurchase'] = isset($_POST['woobooster_smart_copurchase']) ? '1' : '0';
            $existing['smart_trending'] = isset($_POST['woobooster_smart_trending']) ? '1' : '0';
            $existing['smart_recently_viewed'] = isset($_POST['woobooster_smart_recently_viewed']) ? '1' : '0';
            $existing['smart_similar'] = isset($_POST['woobooster_smart_similar']) ? '1' : '0';
            $existing['smart_days'] = isset($_POST['woobooster_smart_days']) ? absint($_POST['woobooster_smart_days']) : 90;
            $existing['smart_max_relations'] = isset($_POST['woobooster_smart_max_relations']) ? absint($_POST['woobooster_smart_max_relations']) : 20;

            update_option('woobooster_settings', $existing);

            // Reschedule cron based on new settings.
            WooBooster_Cron::schedule();

            wp_safe_redirect(add_query_arg('settings-updated', 'true', admin_url('admin.php?page=woobooster')));
            exit;
        }

        $options = array_merge($existing, array(
            'enabled' => isset($_POST['woobooster_enabled']) ? '1' : '0',
            'section_title' => isset($_POST['woobooster_section_title']) ? sanitize_text_field(wp_unslash($_POST['woobooster_section_title'])) : '',
            'render_method' => isset($_POST['woobooster_render_method']) ? sanitize_key($_POST['woobooster_render_method']) : 'bricks',
            'exclude_outofstock' => isset($_POST['woobooster_exclude_outofstock']) ? '1' : '0',
            'debug_mode' => isset($_POST['woobooster_debug_mode']) ? '1' : '0',
            'delete_data_uninstall' => isset($_POST['woobooster_delete_data']) ? '1' : '0',
        ));

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
                echo '<div class="wb-card__actions">';
                echo '<button type="button" id="wb-export-rules" class="wb-btn wb-btn--subtle wb-btn--sm" style="margin-right: 8px;">' . esc_html__('Export', 'woobooster') . '</button>';
                echo '<button type="button" id="wb-import-rules-btn" class="wb-btn wb-btn--subtle wb-btn--sm" style="margin-right: 8px;">' . esc_html__('Import', 'woobooster') . '</button>';
                echo '<input type="file" id="wb-import-file" style="display:none;" accept=".json">';
                echo '<a href="' . esc_url($add_url) . '" class="wb-btn wb-btn--primary wb-btn--sm">';
                echo WooBooster_Icons::get('plus'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo esc_html__('Add Rule', 'woobooster');
                echo '</a>';
                echo '</div>';
                echo '</div>';
                echo '<div class="wb-card__body wb-card__body--table">';
                $list->display();
                echo '</div></div>';
                break;
        }
    }

    /**
     * Render the Documentation page.
     */
    private function render_documentation_page()
    {
        ?>
        <div class="wb-card">
            <div class="wb-card__header">
                <h2><?php esc_html_e('Documentation', 'woobooster'); ?></h2>
            </div>
            <div class="wb-card__body">
                <h3><?php esc_html_e('Getting Started', 'woobooster'); ?></h3>
                <p><?php esc_html_e('WooBooster automatically displays recommended products based on your rules. By default, it replaces the standard WooCommerce "Related Products" section.', 'woobooster'); ?>
                </p>

                <hr class="wb-hr">

                <h3><?php esc_html_e('Shortcode Usage', 'woobooster'); ?></h3>
                <p><?php esc_html_e('Use the shortcode to display recommendations anywhere on your site:', 'woobooster'); ?></p>
                <code class="wb-code">[woobooster product_id="123" limit="4"]</code>
                <ul class="wb-list">
                    <li><strong>product_id</strong>:
                        <?php esc_html_e('(Optional) ID of the product to base recommendations on. Defaults to current product.', 'woobooster'); ?>
                    </li>
                    <li><strong>limit</strong>:
                        <?php esc_html_e('(Optional) Number of products to show. Default: 4.', 'woobooster'); ?>
                    </li>
                </ul>

                <hr class="wb-hr">

                <h3><?php esc_html_e('Bricks Builder Integration', 'woobooster'); ?></h3>
                <p><?php esc_html_e('WooBooster is fully compatible with Bricks Builder.', 'woobooster'); ?></p>
                <ol class="wb-list">
                    <li><?php esc_html_e('Add a "Query Loop" element to your template.', 'woobooster'); ?></li>
                    <li><?php esc_html_e('Set the Query Type to "WooBooster Recommendations".', 'woobooster'); ?></li>
                    <li><?php esc_html_e('Customize your layout using standard Bricks elements.', 'woobooster'); ?></li>
                </ol>
                <p><em><?php esc_html_e('Note: In Bricks, you must manually add a Heading element above your loop if you want a section title.', 'woobooster'); ?></em>
                </p>

                <hr class="wb-hr">

                <h3><?php esc_html_e('Rules Engine', 'woobooster'); ?></h3>
                <p><?php esc_html_e('Rules are processed in order from top to bottom. The first rule that matches the current product will be used to generate recommendations. If no rules match, the global fallback (Successor/Interchangeable/Category) is used.', 'woobooster'); ?>
                </p>

                <hr class="wb-hr">

                <h3><?php esc_html_e('Smart Recommendations', 'woobooster'); ?></h3>
                <p><?php esc_html_e('WooBooster 2.0 introduces four intelligent recommendation strategies that go beyond simple taxonomy matching. Enable them in Settings → Smart Recommendations.', 'woobooster'); ?>
                </p>

                <ul class="wb-list">
                    <li>
                        <strong><?php esc_html_e('Bought Together', 'woobooster'); ?></strong>:
                        <?php esc_html_e('Analyzes completed orders to find products frequently purchased together. A nightly WP-Cron job scans your order history and stores the top related products in each product\'s metadata. Use the "copurchase" action source in your rules.', 'woobooster'); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Trending', 'woobooster'); ?></strong>:
                        <?php esc_html_e('Tracks bestselling products per category based on recent sales data. Updated every 6 hours. Products are ranked by sales velocity, not total lifetime sales. Use the "trending" action source.', 'woobooster'); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Recently Viewed', 'woobooster'); ?></strong>:
                        <?php esc_html_e('Shows products the visitor has recently browsed. Uses a lightweight browser cookie — zero database queries. The last 20 viewed products are tracked. Use the "recently_viewed" action source.', 'woobooster'); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Similar Products', 'woobooster'); ?></strong>:
                        <?php esc_html_e('Finds products with a similar price (±25%) in the same category, ordered by total sales. No pre-computation needed — results are cached in transients for 24 hours. Use the "similar" action source.', 'woobooster'); ?>
                    </li>
                </ul>

                <h4><?php esc_html_e('How it works', 'woobooster'); ?></h4>
                <p><?php esc_html_e('Smart strategies are added as new Action Sources in your rules. You can combine them with traditional taxonomy-based actions. Example: show 3 "Bought Together" products and 3 from a specific category in a single rule.', 'woobooster'); ?></p>
                <p><?php esc_html_e('No new database tables are created. Co-purchase data is stored in product postmeta, trending data in WordPress transients, and recently viewed in a browser cookie.', 'woobooster'); ?></p>

                <h4><?php esc_html_e('Manual Controls', 'woobooster'); ?></h4>
                <p><?php esc_html_e('Use the "Rebuild Now" button in Settings to manually trigger the co-purchase and trending index builds. Use "Clear All Data" to purge all computed recommendation data.', 'woobooster'); ?></p>
            </div>
        </div>
        <?php
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

    /**
     * AJAX: Export rules to JSON.
     */
    public function ajax_export_rules()
    {
        check_ajax_referer('woobooster_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'woobooster')));
        }

        $rules = WooBooster_Rule::get_all();
        $export_rules = array();

        foreach ($rules as $rule) {
            $rule_data = (array) $rule;
            $rule_data['conditions'] = WooBooster_Rule::get_conditions($rule->id);
            $rule_data['actions'] = WooBooster_Rule::get_actions($rule->id);
            $export_rules[] = $rule_data;
        }

        $export_data = array(
            'version' => WOOBOOSTER_VERSION,
            'date' => gmdate('Y-m-d H:i:s'),
            'rules' => $export_rules,
        );

        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="woobooster-rules-' . gmdate('Y-m-d') . '.json"');
        echo wp_json_encode($export_data, JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * AJAX: Import rules from JSON.
     */
    public function ajax_import_rules()
    {
        check_ajax_referer('woobooster_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'woobooster')));
        }

        $json = isset($_POST['json']) ? wp_unslash($_POST['json']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $data = json_decode($json, true);

        if (!$data || !isset($data['rules']) || !is_array($data['rules'])) {
            wp_send_json_error(array('message' => __('Invalid JSON file.', 'woobooster')));
        }

        $count = 0;
        foreach ($data['rules'] as $rule_data) {
            $conditions = isset($rule_data['conditions']) ? $rule_data['conditions'] : array();
            $actions = isset($rule_data['actions']) ? $rule_data['actions'] : array();
            unset($rule_data['id'], $rule_data['conditions'], $rule_data['actions'], $rule_data['created_at'], $rule_data['updated_at']);

            if (empty($rule_data['name'])) {
                continue;
            }

            $rule_id = WooBooster_Rule::create($rule_data);
            if ($rule_id) {
                if (!empty($conditions)) {
                    $clean_conditions = array();
                    foreach ($conditions as $group_id => $group) {
                        $group_arr = array();
                        foreach ($group as $cond) {
                            $cond = (array) $cond;
                            $group_arr[] = array(
                                'condition_attribute' => sanitize_key($cond['condition_attribute'] ?? ''),
                                'condition_operator' => 'equals',
                                'condition_value' => sanitize_text_field($cond['condition_value'] ?? ''),
                                'include_children' => absint($cond['include_children'] ?? 0),
                            );
                        }
                        if (!empty($group_arr)) {
                            $clean_conditions[absint($group_id)] = $group_arr;
                        }
                    }
                    if (!empty($clean_conditions)) {
                        WooBooster_Rule::save_conditions($rule_id, $clean_conditions);
                    }
                }

                if (!empty($actions)) {
                    $clean_actions = array();
                    foreach ($actions as $action) {
                        $action = (array) $action;
                        $clean_actions[] = array(
                            'action_source' => sanitize_key($action['action_source'] ?? 'category'),
                            'action_value' => sanitize_text_field($action['action_value'] ?? ''),
                            'action_limit' => absint($action['action_limit'] ?? 4),
                            'action_orderby' => sanitize_key($action['action_orderby'] ?? 'rand'),
                            'include_children' => absint($action['include_children'] ?? 0),
                        );
                    }
                    if (!empty($clean_actions)) {
                        WooBooster_Rule::save_actions($rule_id, $clean_actions);
                    }
                }

                $count++;
            }
        }

        wp_send_json_success(array(
            'message' => sprintf(
                /* translators: %d: number of rules imported */
                __('%d rules imported successfully.', 'woobooster'),
                $count
            ),
            'count' => $count,
        ));
    }

    /**
     * AJAX: Rebuild Smart Recommendations index.
     */
    public function ajax_rebuild_index()
    {
        check_ajax_referer('woobooster_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'woobooster')));
        }

        $cron = new WooBooster_Cron();
        $results = array();

        $options = get_option('woobooster_settings', array());

        if (!empty($options['smart_copurchase'])) {
            $results['copurchase'] = $cron->run_copurchase();
        }

        if (!empty($options['smart_trending'])) {
            $results['trending'] = $cron->run_trending();
        }

        $parts = array();
        if (!empty($results['copurchase'])) {
            $cp = $results['copurchase'];
            $parts[] = sprintf(
                __('Co-purchase: %1$d products in %2$ss', 'woobooster'),
                $cp['products'],
                $cp['time']
            );
        }
        if (!empty($results['trending'])) {
            $tr = $results['trending'];
            $parts[] = sprintf(
                __('Trending: %1$d categories in %2$ss', 'woobooster'),
                $tr['categories'],
                $tr['time']
            );
        }

        $message = !empty($parts) ? implode(' · ', $parts) : __('No strategies enabled. Enable at least one above.', 'woobooster');

        wp_send_json_success(array(
            'message' => $message,
            'results' => $results,
        ));
    }

    /**
     * AJAX: Purge all Smart Recommendations data.
     */
    public function ajax_purge_index()
    {
        check_ajax_referer('woobooster_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'woobooster')));
        }

        $counts = WooBooster_Cron::purge_all();
        $total = $counts['copurchase'] + $counts['trending'] + $counts['similar'];

        wp_send_json_success(array(
            'message' => sprintf(
                /* translators: %d: total items deleted */
                __('Cleared %d items.', 'woobooster'),
                $total
            ),
            'counts' => $counts,
        ));
    }
}