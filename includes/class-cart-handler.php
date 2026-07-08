<?php
/**
 * Handles Cart and Order integration for Altegena Invitation Editor
 */

if (!defined('ABSPATH')) {
    exit;
}

class Altegena_Cart_Handler
{

    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        // Add design data to cart item
        add_filter('woocommerce_add_cart_item_data', array($this, 'add_cart_item_data'), 10, 3);

        // Display design data in cart and checkout
        add_filter('woocommerce_get_item_data', array($this, 'get_item_data'), 10, 2);

        // Save design data to order line item
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'checkout_create_order_line_item'), 10, 4);

        // Add editor button and modal to single product page
        // Note: Using woocommerce_single_variation hook (not woocommerce_single_product_summary)
        // because block themes (FSE) don't fire woocommerce_single_product_summary.
        // This hook fires inside the variation form rendered by the woocommerce/add-to-cart-form block.
        add_action('woocommerce_single_variation', array($this, 'render_editor_container'), 15);
    }

    public function render_editor_container()
    {
        global $post;
        $config = get_post_meta($post->ID, '_invitation_json_config', true);
        if (empty($config)) {
            return;
        }

        echo '<div class="is-style-add-to-cart-button">';
        echo '<button type="button" id="open-card-designer" disabled>Davetiyeni Özelleştir</button>';
        echo '</div>';

        // Modal Container (Hidden by default)
        echo '<div id="card-designer" class="altegena-modal" style="display:none;">';
        echo '<div class="altegena-modal-header">';
        echo '<h2>Tasarımcı</h2>';
        echo '<button type="button" id="altegena-add-to-cart-btn" class="altegena-add-to-cart-btn">Sepete Ekle</button>';
        echo '<button type="button" id="altegena-share-btn" class="altegena-share-btn">WhatsApp\'ta Paylaş</button>';
        echo '<button type="button" id="close-card-designer" class="altegena-close-btn">&times;</button>';
        echo '</div>';
        echo '<div id="altegena-editor-app"></div>';
        echo '</div>';

        echo '<input type="hidden" name="altegena_custom_data" id="altegena-custom-data" value="">';

        // Inline JS for Modal
        ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var modal = document.getElementById('card-designer');
                var btn = document.getElementById('open-card-designer');
                var closeBtn = document.getElementById('close-card-designer');

                if (btn && modal) {
                    // Move modal to body end immediately to fix z-index issues
                    document.body.appendChild(modal);

                    btn.onclick = function () {
                        modal.style.display = "flex";
                        document.body.style.overflow = "hidden"; // Prevent scrolling behind modal
                    }
                }

                if (closeBtn && modal) {
                    closeBtn.onclick = function () {
                        modal.style.display = "none";
                        document.body.style.overflow = "auto";
                    }
                }

                // Enable button when variation is selected
                var variationForm = document.querySelector('.variations_form');
                if (variationForm && btn) {
                    // Check if product has variations
                    var hasVariations = document.querySelector('.variations');

                    if (hasVariations) {
                        // Listen for variation selection
                        jQuery(variationForm).on('found_variation', function (event, variation) {
                            // Variation selected - enable button
                            btn.disabled = false;
                            btn.style.opacity = '1';
                            btn.style.cursor = 'pointer';
                        });

                        jQuery(variationForm).on('reset_data', function () {
                            // Variation cleared - disable button
                            btn.disabled = true;
                            btn.style.opacity = '0.5';
                            btn.style.cursor = 'not-allowed';
                        });

                        // Set initial disabled state styling
                        btn.style.opacity = '0.5';
                        btn.style.cursor = 'not-allowed';
                    } else {
                        // No variations - enable button immediately
                        btn.disabled = false;
                    }
                }
            });
        </script>
        <?php
    }

    public function add_cart_item_data($cart_item_data, $product_id, $variation_id)
    {
        if (isset($_POST['altegena_custom_data']) && !empty($_POST['altegena_custom_data'])) {
            $custom_data = json_decode(stripslashes($_POST['altegena_custom_data']), true);
            if ($custom_data) {
                $cart_item_data['altegena_design_data'] = $custom_data;
            }
        }
        return $cart_item_data;
    }

    public function get_item_data($item_data, $cart_item)
    {
        // Only show in Checkout, not in the Cart to keep it clean
        if (is_cart()) {
            return $item_data;
        }

        if (isset($cart_item['altegena_design_data'])) {
            foreach ($cart_item['altegena_design_data'] as $layer_id => $layer_info) {
                if (isset($layer_info['label']) && isset($layer_info['text'])) {
                    $value = $layer_info['text'];
                    if (!empty($layer_info['fontFamily'])) {
                        // Just a clean way to show font if it differs from default, 
                        // though usually users just want to see the text.
                        // $value .= ' (' . $layer_info['fontFamily'] . ')'; 
                    }
                    $item_data[] = array(
                        'key' => $layer_info['label'],
                        'value' => $value,
                    );
                }
            }
        }
        return $item_data;
    }

    public function checkout_create_order_line_item($item, $cart_item_key, $values, $order)
    {
        if (isset($values['altegena_design_data'])) {
            $item->add_meta_data('_altegena_design_data', $values['altegena_design_data']);

            foreach ($values['altegena_design_data'] as $layer_id => $layer_info) {
                if (isset($layer_info['label']) && isset($layer_info['text'])) {
                    $item->add_meta_data($layer_info['label'], $layer_info['text']);
                }
            }
        }
    }
}
