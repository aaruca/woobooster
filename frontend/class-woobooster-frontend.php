<?php
/**
 * WooBooster Frontend — WooCommerce Hook Fallback.
 *
 * Renders recommendations via standard WooCommerce hooks for non-Bricks themes.
 *
 * @package WooBooster
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooBooster_Frontend
{

    /**
     * Initialize frontend hooks.
     */
    public function init()
    {
        // Always replace WooCommerce's default related products with
        // WooBooster's engine. If Bricks renders the product template,
        // this hook simply never fires. If a classic/block theme is used,
        // WooBooster takes over and falls back to WooCommerce related
        // products when no rule matches.
        remove_action('woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20);
        add_action('woocommerce_after_single_product_summary', array($this, 'render_recommendations'), 20);
    }

    /**
     * Render recommendations on the single product page.
     */
    public function render_recommendations()
    {
        if ('1' !== woobooster_get_option('enabled', '1')) {
            $this->render_woo_fallback();
            return;
        }

        global $product;
        $product_id = 0;

        if ($product && is_a($product, 'WC_Product')) {
            $product_id = $product->get_id();
        } elseif (is_singular('product')) {
            $product_id = get_the_ID();
        }

        if (!$product_id) {
            return;
        }

        $matcher = new WooBooster_Matcher();
        $product_ids = $matcher->get_recommendations($product_id);

        if (empty($product_ids)) {
            $this->render_woo_fallback();
            return;
        }

        $section_title = woobooster_get_option('section_title', __('You May Also Like', 'woobooster'));
        $columns = min(count($product_ids), 4);

        echo '<section class="woobooster-related products">';
        echo '<h2>' . esc_html($section_title) . '</h2>';
        echo '<ul class="products columns-' . esc_attr($columns) . '">';

        $original_post = isset($GLOBALS['post']) ? $GLOBALS['post'] : null;

        foreach ($product_ids as $pid) {
            $post_obj = get_post($pid);
            if (!$post_obj) {
                continue;
            }
            $GLOBALS['post'] = $post_obj;
            setup_postdata($post_obj);
            wc_get_template_part('content', 'product');
        }

        wp_reset_postdata();
        if ($original_post) {
            $GLOBALS['post'] = $original_post;
        }

        echo '</ul></section>';
    }

    /**
     * Render WooCommerce default related products as fallback.
     */
    private function render_woo_fallback()
    {
        woocommerce_related_products(array(
            'posts_per_page' => 4,
            'columns' => 4,
            'orderby' => 'rand',
        ));
    }
}
