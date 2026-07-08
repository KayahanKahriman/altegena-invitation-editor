<?php
/**
 * Handles Product Page modifications for Altegena Invitation Editor
 * Hides default add-to-cart button for invitation products,
 * while keeping the variation form intact.
 */

if (!defined('ABSPATH')) {
  exit;
}

class Altegena_Product_Page_Handler
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
    // Add custom CSS to hide default elements
    add_action('wp_head', array($this, 'add_custom_css'));
  }

  /**
   * Check if current product has invitation editor enabled
   */
  private function is_invitation_product()
  {
    if (!is_product()) {
      return false;
    }

    global $post;
    $config = get_post_meta($post->ID, '_invitation_json_config', true);

    return !empty($config);
  }

  /**
   * Add CSS to hide default WooCommerce add-to-cart button.
   * We use CSS instead of removing template actions so the variation form stays intact.
   */

  public function add_custom_css()
  {
    if (!$this->is_invitation_product()) {
      return;
    }
    ?>
    <style type="text/css">
      /* Hide default WooCommerce add to cart button */
      .single-product .product form.cart .single_add_to_cart_button {
        display: none !important;
      }

      /* Hide quantity input */
      .single-product .product form.cart .quantity {
        display: none !important;
      }



      /* Style the custom "Davetiye Tasarla" button */
      /* Styles removed to use theme's is-style-white-cta class */

      /* Ensure proper spacing */
      .altegena-custom-cart-section {
        margin-top: 20px;
      }
    </style>
    <?php
  }
}
