<?php
/**
 * Order-side print integration.
 *
 * Captures an immutable design snapshot for every invitation order line item
 * at checkout, so a print PDF can be rebuilt exactly even if the product
 * template changes later.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Altegena_Print_Order_Handler
{
    const SNAPSHOT_KEY = '_altegena_print_snapshot';

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
        // Runs after Altegena_Cart_Handler (priority 10). Fires for the classic
        // checkout and for the Checkout block (Store API) alike.
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'snapshot_for_item'), 20, 4);
    }

    public function snapshot_for_item($item, $cart_item_key, $values, $order)
    {
        if (empty($values['altegena_design_data']) || !is_array($values['altegena_design_data'])) {
            return;
        }

        $product_id = !empty($values['product_id']) ? absint($values['product_id']) : $item->get_product_id();
        $variation_id = !empty($values['variation_id']) ? absint($values['variation_id']) : $item->get_variation_id();

        $snapshot = Altegena_Design_Config::build_snapshot($product_id, $variation_id, $values['altegena_design_data']);
        if (is_wp_error($snapshot)) {
            self::log(sprintf('Baskı snapshot\'ı oluşturulamadı (ürün %d): %s', $product_id, $snapshot->get_error_message()), 'error');
            return;
        }

        // Meta arrays are unslashed on save (add_metadata / update_metadata_by_mid),
        // so slash them to keep backslashes inside customer text intact.
        $item->update_meta_data(self::SNAPSHOT_KEY, wp_slash($snapshot));
    }

    public static function log($message, $level = 'info')
    {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->log($level, $message, array('source' => 'altegena-print'));
        }
    }
}
