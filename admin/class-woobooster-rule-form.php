<?php
/**
 * WooBooster Rule Form.
 *
 * Handles rendering and processing of the Add/Edit rule form.
 *
 * @package WooBooster
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooBooster_Rule_Form
{

    /**
     * Render the form.
     */
    public function render()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $rule_id = isset($_GET['rule_id']) ? absint($_GET['rule_id']) : 0;
        $rule = $rule_id ? WooBooster_Rule::get($rule_id) : null;
        $is_edit = !empty($rule);

        // Handle save.
        $this->handle_save();

        $title = $is_edit
            ? __('Edit Rule', 'woobooster')
            : __('Add New Rule', 'woobooster');

        // Default values.
        $name = $rule ? $rule->name : '';
        $priority = $rule ? $rule->priority : 10;
        $status = $rule ? $rule->status : 1;
        $condition_attribute = $rule ? $rule->condition_attribute : '';
        $condition_operator = $rule ? $rule->condition_operator : 'equals';
        $condition_value = $rule ? $rule->condition_value : '';
        $action_source = $rule ? $rule->action_source : 'category';
        $action_value = $rule ? $rule->action_value : '';
        $action_orderby = $rule ? $rule->action_orderby : 'rand';
        $action_limit = $rule ? $rule->action_limit : 4;
        $exclude_outofstock = $rule ? $rule->exclude_outofstock : 1;

        $taxonomies = WooBooster_Rule::get_product_taxonomies();

        // Get saved term label for display.
        $condition_value_label = '';
        if ($condition_value && $condition_attribute) {
            $term = get_term_by('slug', $condition_value, $condition_attribute);
            if ($term && !is_wp_error($term)) {
                $condition_value_label = $term->name;
            }
        }

        $action_value_label = '';
        if ($action_value) {
            $action_tax = 'category' === $action_source ? 'product_cat' : ('tag' === $action_source ? 'product_tag' : '');
            if ($action_tax) {
                $term = get_term_by('slug', $action_value, $action_tax);
                if ($term && !is_wp_error($term)) {
                    $action_value_label = $term->name;
                }
            }
        }

        echo '<div class="wb-card">';
        echo '<div class="wb-card__header">';
        echo '<h2>' . esc_html($title) . '</h2>';
        $back_url = admin_url('admin.php?page=woobooster-rules');
        echo '<a href="' . esc_url($back_url) . '" class="wb-btn wb-btn--subtle wb-btn--sm">';
        echo WooBooster_Icons::get('chevron-left'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo esc_html__('Back to Rules', 'woobooster');
        echo '</a>';
        echo '</div>';

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['saved']) && '1' === $_GET['saved']) {
            echo '<div class="wb-message wb-message--success">';
            echo WooBooster_Icons::get('check'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '<span>' . esc_html__('Rule saved successfully.', 'woobooster') . '</span>';
            echo '</div>';
        }


        echo '<form method="post" action="" class="wb-form">';
        wp_nonce_field('woobooster_save_rule', 'woobooster_rule_nonce');

        if ($rule_id) {
            echo '<input type="hidden" name="rule_id" value="' . esc_attr($rule_id) . '">';
        }

        // ── Basic Settings ──────────────────────────────────────────────────

        echo '<div class="wb-card__section">';
        echo '<h3>' . esc_html__('Basic Settings', 'woobooster') . '</h3>';

        // Name.
        echo '<div class="wb-field">';
        echo '<label class="wb-field__label" for="wb-rule-name">' . esc_html__('Rule Name', 'woobooster') . '</label>';
        echo '<div class="wb-field__control">';
        echo '<input type="text" id="wb-rule-name" name="rule_name" value="' . esc_attr($name) . '" class="wb-input" required>';
        echo '</div></div>';

        // Priority.
        echo '<div class="wb-field">';
        echo '<label class="wb-field__label" for="wb-rule-priority">' . esc_html__('Priority', 'woobooster') . '</label>';
        echo '<div class="wb-field__control">';
        echo '<input type="number" id="wb-rule-priority" name="rule_priority" value="' . esc_attr($priority) . '" min="1" max="999" class="wb-input wb-input--sm">';
        echo '<p class="wb-field__desc">' . esc_html__('Lower number = higher priority. If multiple rules match, the lowest priority wins.', 'woobooster') . '</p>';
        echo '</div></div>';

        // Status.
        echo '<div class="wb-field">';
        echo '<label class="wb-field__label">' . esc_html__('Status', 'woobooster') . '</label>';
        echo '<div class="wb-field__control">';
        echo '<label class="wb-toggle">';
        echo '<input type="checkbox" name="rule_status" value="1"' . checked($status, 1, false) . '>';
        echo '<span class="wb-toggle__slider"></span>';
        echo '</label>';
        echo '</div></div>';

        echo '</div>'; // .wb-card__section

        // ── Condition ───────────────────────────────────────────────────────

        echo '<div class="wb-card__section">';
        echo '<h3>' . esc_html__('Condition', 'woobooster') . '</h3>';
        echo '<p class="wb-section-desc">' . esc_html__('When a product matches this condition, the action below will be used to find recommendations.', 'woobooster') . '</p>';

        // Attribute.
        echo '<div class="wb-field">';
        echo '<label class="wb-field__label" for="wb-condition-attr">' . esc_html__('Attribute', 'woobooster') . '</label>';
        echo '<div class="wb-field__control">';
        echo '<select id="wb-condition-attr" name="condition_attribute" class="wb-select" required>';
        echo '<option value="">' . esc_html__('Select attribute…', 'woobooster') . '</option>';
        foreach ($taxonomies as $tax_slug => $tax_label) {
            echo '<option value="' . esc_attr($tax_slug) . '"' . selected($condition_attribute, $tax_slug, false) . '>';
            echo esc_html($tax_label) . ' (' . esc_html($tax_slug) . ')';
            echo '</option>';
        }
        echo '</select>';
        echo '</div></div>';

        // Operator — hardcoded to equals. Column kept in schema for future extensibility.
        echo '<input type="hidden" name="condition_operator" value="equals">';

        // Value (AJAX autocomplete).
        echo '<div class="wb-field">';
        echo '<label class="wb-field__label" for="wb-condition-value">' . esc_html__('Value', 'woobooster') . '</label>';
        echo '<div class="wb-field__control">';
        echo '<div class="wb-autocomplete" id="wb-condition-autocomplete">';
        echo '<input type="text" id="wb-condition-value-display" class="wb-input wb-autocomplete__input" placeholder="' . esc_attr__('Search terms…', 'woobooster') . '" value="' . esc_attr($condition_value_label) . '" autocomplete="off">';
        echo '<input type="hidden" id="wb-condition-value" name="condition_value" value="' . esc_attr($condition_value) . '">';
        echo '<div class="wb-autocomplete__dropdown" id="wb-condition-dropdown"></div>';
        echo '</div></div>';

        // Include Children (conditional — shown via JS only for hierarchical taxonomies).
        $include_children = isset($rule->include_children) ? absint($rule->include_children) : 0;
        echo '<div class="wb-field" id="wb-include-children-field" style="display:none;">';
        echo '<label class="wb-field__label">' . esc_html__('Child Categories', 'woobooster') . '</label>';
        echo '<div class="wb-field__control">';
        echo '<label class="wb-checkbox">';
        echo '<input type="checkbox" name="include_children" value="1"' . checked($include_children, 1, false) . '> ';
        echo esc_html__('Apply this rule to all child (sub) categories as well', 'woobooster');
        echo '</label>';
        echo '<p class="wb-field__desc">' . esc_html__('When enabled, the rule will also match products in any descendant category.', 'woobooster') . '</p>';
        echo '</div></div>';

        echo '</div>'; // .wb-card__section

        // ── Action ──────────────────────────────────────────────────────────

        echo '<div class="wb-card__section">';
        echo '<h3>' . esc_html__('Action', 'woobooster') . '</h3>';
        echo '<p class="wb-section-desc">' . esc_html__('Define which products to recommend when the condition matches.', 'woobooster') . '</p>';

        // Source Type.
        echo '<div class="wb-field">';
        echo '<label class="wb-field__label" for="wb-action-source">' . esc_html__('Source Type', 'woobooster') . '</label>';
        echo '<div class="wb-field__control">';
        echo '<select id="wb-action-source" name="action_source" class="wb-select">';
        echo '<option value="category"' . selected($action_source, 'category', false) . '>' . esc_html__('Category', 'woobooster') . '</option>';
        echo '<option value="tag"' . selected($action_source, 'tag', false) . '>' . esc_html__('Tag', 'woobooster') . '</option>';
        echo '<option value="attribute"' . selected($action_source, 'attribute', false) . '>' . esc_html__('Same Attribute', 'woobooster') . '</option>';
        echo '</select>';
        echo '<p class="wb-field__desc">' . esc_html__('"Same Attribute" uses the condition\'s attribute and value to find products with matching terms.', 'woobooster') . '</p>';
        echo '</div></div>';

        // Source Value (visible for category/tag).
        echo '<div class="wb-field" id="wb-action-value-field">';
        echo '<label class="wb-field__label" for="wb-action-value">' . esc_html__('Source Value', 'woobooster') . '</label>';
        echo '<div class="wb-field__control">';
        echo '<div class="wb-autocomplete" id="wb-action-autocomplete">';
        echo '<input type="text" id="wb-action-value-display" class="wb-input wb-autocomplete__input" placeholder="' . esc_attr__('Search terms…', 'woobooster') . '" value="' . esc_attr($action_value_label) . '" autocomplete="off">';
        echo '<input type="hidden" id="wb-action-value" name="action_value" value="' . esc_attr($action_value) . '">';
        echo '<div class="wb-autocomplete__dropdown" id="wb-action-dropdown"></div>';
        echo '</div>';
        echo '</div></div>';

        // Order By.
        echo '<div class="wb-field">';
        echo '<label class="wb-field__label" for="wb-action-orderby">' . esc_html__('Order By', 'woobooster') . '</label>';
        echo '<div class="wb-field__control">';
        echo '<select id="wb-action-orderby" name="action_orderby" class="wb-select">';
        $orderbys = array(
            'rand' => __('Random', 'woobooster'),
            'date' => __('Newest', 'woobooster'),
            'price' => __('Price (Low to High)', 'woobooster'),
            'price_desc' => __('Price (High to Low)', 'woobooster'),
            'bestselling' => __('Bestselling', 'woobooster'),
            'rating' => __('Rating', 'woobooster'),
        );
        foreach ($orderbys as $key => $label) {
            echo '<option value="' . esc_attr($key) . '"' . selected($action_orderby, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '</div></div>';

        // Limit.
        echo '<div class="wb-field">';
        echo '<label class="wb-field__label" for="wb-action-limit">' . esc_html__('Limit', 'woobooster') . '</label>';
        echo '<div class="wb-field__control">';
        echo '<input type="number" id="wb-action-limit" name="action_limit" value="' . esc_attr($action_limit) . '" min="1" class="wb-input wb-input--sm">';
        echo '<p class="wb-field__desc"><strong>' . esc_html__('⚠️ High values (50+) may slow page load on large catalogs.', 'woobooster') . '</strong></p>';
        echo '</div></div>';

        // Exclude out of stock.
        echo '<div class="wb-field">';
        echo '<label class="wb-field__label">' . esc_html__('Exclude Out of Stock', 'woobooster') . '</label>';
        echo '<div class="wb-field__control">';
        echo '<label class="wb-toggle">';
        echo '<input type="checkbox" name="exclude_outofstock" value="1"' . checked($exclude_outofstock, 1, false) . '>';
        echo '<span class="wb-toggle__slider"></span>';
        echo '</label>';
        echo '<p class="wb-field__desc">' . esc_html__('Override global setting for this rule.', 'woobooster') . '</p>';
        echo '</div></div>';

        echo '</div>'; // .wb-card__section

        // ── Save Bar ────────────────────────────────────────────────────────

        echo '<div class="wb-actions-bar">';
        echo '<button type="submit" class="wb-btn wb-btn--primary">';
        echo $is_edit ? esc_html__('Update Rule', 'woobooster') : esc_html__('Create Rule', 'woobooster');
        echo '</button>';
        echo '<a href="' . esc_url($back_url) . '" class="wb-btn wb-btn--subtle">' . esc_html__('Cancel', 'woobooster') . '</a>';
        echo '</div>';

        echo '</form>';
        echo '</div>'; // .wb-card
    }

    /**
     * Handle form save.
     */
    private function handle_save()
    {
        if (!isset($_POST['woobooster_rule_nonce'])) {
            return;
        }

        if (!wp_verify_nonce(sanitize_key($_POST['woobooster_rule_nonce']), 'woobooster_save_rule')) {
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $rule_id = isset($_POST['rule_id']) ? absint($_POST['rule_id']) : 0;

        $data = array(
            'name' => isset($_POST['rule_name']) ? sanitize_text_field(wp_unslash($_POST['rule_name'])) : '',
            'priority' => isset($_POST['rule_priority']) ? absint($_POST['rule_priority']) : 10,
            'status' => isset($_POST['rule_status']) ? 1 : 0,
            'condition_attribute' => isset($_POST['condition_attribute']) ? sanitize_key($_POST['condition_attribute']) : '',
            'condition_operator' => isset($_POST['condition_operator']) ? sanitize_key($_POST['condition_operator']) : 'equals',
            'condition_value' => isset($_POST['condition_value']) ? sanitize_text_field(wp_unslash($_POST['condition_value'])) : '',
            'action_source' => isset($_POST['action_source']) ? sanitize_key($_POST['action_source']) : 'category',
            'action_value' => isset($_POST['action_value']) ? sanitize_text_field(wp_unslash($_POST['action_value'])) : '',
            'action_orderby' => isset($_POST['action_orderby']) ? sanitize_key($_POST['action_orderby']) : 'rand',
            'action_limit' => isset($_POST['action_limit']) ? absint($_POST['action_limit']) : 4,
            'include_children' => isset($_POST['include_children']) ? 1 : 0,
            'exclude_outofstock' => isset($_POST['exclude_outofstock']) ? 1 : 0,
        );

        if ($rule_id) {
            WooBooster_Rule::update($rule_id, $data);
        } else {
            $rule_id = WooBooster_Rule::create($data);
        }

        wp_safe_redirect(admin_url('admin.php?page=woobooster-rules&action=edit&rule_id=' . $rule_id . '&saved=1'));
        exit;
    }
}
