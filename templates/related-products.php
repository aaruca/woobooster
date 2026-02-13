<?php
/**
 * WooBooster Related Products Template.
 *
 * This template can be overridden by copying it to:
 * yourtheme/woobooster/related-products.php
 *
 * @package WooBooster
 */

if (!defined('ABSPATH')) {
    exit;
}

if (empty($product_ids)) {
    return;
}

$section_title = woobooster_get_option('section_title', __('You May Also Like', 'woobooster'));
$columns = min(count($product_ids), 4);
?>

<section class="woobooster-related products">
    <h2>
        <?php echo esc_html($section_title); ?>
    </h2>

    <ul class="products columns-<?php echo esc_attr($columns); ?>">
        <?php
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
        ?>
    </ul>
</section>