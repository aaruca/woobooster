<?php
/**
 * WooBooster Rule List Table.
 *
 * Extends WP_List_Table for displaying rules in the admin.
 *
 * @package WooBooster
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class WooBooster_Rule_List extends WP_List_Table
{

    /**
     * Constructor.
     */
    public function __construct()
    {
        parent::__construct(array(
            'singular' => 'rule',
            'plural' => 'rules',
            'ajax' => false,
        ));
    }

    /**
     * Define table columns.
     *
     * @return array
     */
    public function get_columns()
    {
        return array(
            'cb' => '<input type="checkbox">',
            'name' => __('Name', 'woobooster'),
            'priority' => __('Priority', 'woobooster'),
            'condition' => __('Condition', 'woobooster'),
            'action' => __('Action', 'woobooster'),
            'status' => __('Status', 'woobooster'),
            'actions' => __('Actions', 'woobooster'),
        );
    }

    /**
     * Sortable columns.
     *
     * @return array
     */
    protected function get_sortable_columns()
    {
        return array(
            'name' => array('name', false),
            'priority' => array('priority', true),
            'status' => array('status', false),
        );
    }

    /**
     * Prepare items for display.
     */
    public function prepare_items()
    {
        $per_page = 20;
        $current_page = $this->get_pagenum();

        // Handle bulk actions.
        $this->process_bulk_action();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $orderby = isset($_GET['orderby']) ? sanitize_key($_GET['orderby']) : 'priority';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order = isset($_GET['order']) ? sanitize_key($_GET['order']) : 'ASC';

        $total_items = WooBooster_Rule::count();

        $this->items = WooBooster_Rule::get_all(array(
            'orderby' => $orderby,
            'order' => $order,
            'limit' => $per_page,
            'offset' => ($current_page - 1) * $per_page,
        ));

        $this->set_pagination_args(array(
            'total_items' => $total_items,
            'per_page' => $per_page,
            'total_pages' => ceil($total_items / $per_page),
        ));

        $this->_column_headers = array(
            $this->get_columns(),
            array(),
            $this->get_sortable_columns(),
        );
    }

    /**
     * Checkbox column.
     *
     * @param object $item Rule object.
     * @return string
     */
    protected function column_cb($item)
    {
        return '<input type="checkbox" name="rule_ids[]" value="' . esc_attr($item->id) . '">';
    }

    /**
     * Name column.
     *
     * @param object $item Rule object.
     * @return string
     */
    protected function column_name($item)
    {
        $edit_url = admin_url('admin.php?page=woobooster-rules&action=edit&rule_id=' . $item->id);
        return '<a href="' . esc_url($edit_url) . '" class="wb-link--strong">' . esc_html($item->name) . '</a>';
    }

    /**
     * Priority column.
     *
     * @param object $item Rule object.
     * @return string
     */
    protected function column_priority($item)
    {
        return '<span class="wb-badge wb-badge--neutral">' . esc_html($item->priority) . '</span>';
    }

    /**
     * Condition column.
     *
     * @param object $item Rule object.
     * @return string
     */
    protected function column_condition($item)
    {
        return '<code>' . esc_html($item->condition_attribute) . '</code> = '
            . '<code>' . esc_html($item->condition_value) . '</code>';
    }

    /**
     * Action column.
     *
     * @param object $item Rule object.
     * @return string
     */
    protected function column_action($item)
    {
        $source_labels = array(
            'category' => __('Category', 'woobooster'),
            'tag' => __('Tag', 'woobooster'),
            'attribute' => __('Same Attribute', 'woobooster'),
        );

        $source = isset($source_labels[$item->action_source]) ? $source_labels[$item->action_source] : $item->action_source;
        $value = 'attribute' === $item->action_source ? '—' : $item->action_value;

        return esc_html($source) . ': <code>' . esc_html($value) . '</code>'
            . ' <span class="wb-text--muted">(' . esc_html($item->action_orderby) . ', '
            . esc_html($item->action_limit) . ')</span>';
    }

    /**
     * Status column.
     *
     * @param object $item Rule object.
     * @return string
     */
    protected function column_status($item)
    {
        if ($item->status) {
            return '<span class="wb-status wb-status--active">' . esc_html__('Active', 'woobooster') . '</span>';
        }
        return '<span class="wb-status wb-status--inactive">' . esc_html__('Inactive', 'woobooster') . '</span>';
    }

    /**
     * Actions column.
     *
     * @param object $item Rule object.
     * @return string
     */
    protected function column_actions($item)
    {
        $edit_url = admin_url('admin.php?page=woobooster-rules&action=edit&rule_id=' . $item->id);
        $delete_url = wp_nonce_url(
            admin_url('admin.php?page=woobooster-rules&action=delete&rule_id=' . $item->id),
            'woobooster_delete_rule_' . $item->id
        );

        $toggle_label = $item->status
            ? __('Deactivate', 'woobooster')
            : __('Activate', 'woobooster');

        $html = '<div class="wb-row-actions">';
        $html .= '<a href="' . esc_url($edit_url) . '" class="wb-btn wb-btn--subtle wb-btn--xs" title="' . esc_attr__('Edit', 'woobooster') . '">';
        $html .= WooBooster_Icons::get('edit');
        $html .= '</a>';
        $html .= '<button type="button" class="wb-btn wb-btn--subtle wb-btn--xs wb-toggle-rule" data-rule-id="' . esc_attr($item->id) . '" title="' . esc_attr($toggle_label) . '">';
        $html .= WooBooster_Icons::get('toggle');
        $html .= '</button>';
        $html .= '<a href="' . esc_url($delete_url) . '" class="wb-btn wb-btn--subtle wb-btn--xs wb-btn--danger wb-delete-rule" title="' . esc_attr__('Delete', 'woobooster') . '">';
        $html .= WooBooster_Icons::get('delete');
        $html .= '</a>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Bulk action options.
     *
     * @return array
     */
    protected function get_bulk_actions()
    {
        return array(
            'bulk_delete' => __('Delete', 'woobooster'),
            'bulk_activate' => __('Activate', 'woobooster'),
            'bulk_deactivate' => __('Deactivate', 'woobooster'),
        );
    }

    /**
     * Process bulk actions.
     */
    private function process_bulk_action()
    {
        // Single delete.
        if ('delete' === $this->current_action()) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $rule_id = isset($_GET['rule_id']) ? absint($_GET['rule_id']) : 0;
            if ($rule_id && check_admin_referer('woobooster_delete_rule_' . $rule_id)) {
                WooBooster_Rule::delete($rule_id);
                wp_safe_redirect(admin_url('admin.php?page=woobooster-rules'));
                exit;
            }
        }

        // Bulk actions.
        $action = $this->current_action();
        if (in_array($action, array('bulk_delete', 'bulk_activate', 'bulk_deactivate'), true)) {
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_key($_POST['_wpnonce']), 'bulk-rules')) {
                return;
            }

            $rule_ids = isset($_POST['rule_ids']) ? array_map('absint', $_POST['rule_ids']) : array();

            foreach ($rule_ids as $rid) {
                switch ($action) {
                    case 'bulk_delete':
                        WooBooster_Rule::delete($rid);
                        break;
                    case 'bulk_activate':
                        WooBooster_Rule::update($rid, array('status' => 1));
                        break;
                    case 'bulk_deactivate':
                        WooBooster_Rule::update($rid, array('status' => 0));
                        break;
                }
            }

            wp_safe_redirect(admin_url('admin.php?page=woobooster-rules'));
            exit;
        }
    }

    /**
     * Empty table message.
     */
    public function no_items()
    {
        echo '<div class="wb-empty-state">';
        echo WooBooster_Icons::get('rules'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<p>' . esc_html__('No rules created yet.', 'woobooster') . '</p>';
        $add_url = admin_url('admin.php?page=woobooster-rules&action=add');
        echo '<a href="' . esc_url($add_url) . '" class="wb-btn wb-btn--primary">' . esc_html__('Create Your First Rule', 'woobooster') . '</a>';
        echo '</div>';
    }
}
