<?php
/**
 * Plugin Name: Altegena Invitation Editor
 * Plugin URI: https://github.com/KayahanKahriman/altegena-invitation-editor
 * Description: A lightweight, DOM-based invitation editor for WooCommerce with WhatsApp sharing.
 * Version: 1.4.2
 * Author: Kayahan
 * Author URI: https://github.com/KayahanKahriman
 * Text Domain: altegena-invitation-editor
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: MIT
 */

if (!defined('ABSPATH')) {
	exit;
}

class Altegena_Invitation_Editor
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
		$this->define_constants();
		$this->includes();
		$this->init_hooks();
	}

	private function define_constants()
	{
		define('ALTEGENA_PLUGIN_DIR', plugin_dir_path(__FILE__));
		define('ALTEGENA_PLUGIN_URL', plugin_dir_url(__FILE__));
		define('ALTEGENA_PLUGIN_FILE', __FILE__);
		define('ALTEGENA_VERSION', '1.4.2');

		// Share feature payload limits
		define('ALTEGENA_SHARE_MAX_CONFIG_BYTES', 262144);   // 256 KB of layer text JSON
		define('ALTEGENA_SHARE_MAX_IMAGE_BYTES', 5242880);   // 5 MB decoded PNG/JPEG
	}

	private function includes()
	{
		require_once ALTEGENA_PLUGIN_DIR . 'includes/class-settings.php';
		require_once ALTEGENA_PLUGIN_DIR . 'includes/class-design-config.php';
		require_once ALTEGENA_PLUGIN_DIR . 'includes/print/class-print-text.php';
		require_once ALTEGENA_PLUGIN_DIR . 'includes/print/class-print-font.php';
		require_once ALTEGENA_PLUGIN_DIR . 'includes/print/class-print-font-registry.php';
		require_once ALTEGENA_PLUGIN_DIR . 'includes/print/class-print-shaper.php';
		require_once ALTEGENA_PLUGIN_DIR . 'includes/print/class-print-layout.php';
		require_once ALTEGENA_PLUGIN_DIR . 'includes/print/class-print-pdf-writer.php';
		require_once ALTEGENA_PLUGIN_DIR . 'includes/print/class-print-outline-emitter.php';
		require_once ALTEGENA_PLUGIN_DIR . 'includes/print/class-print-text-emitter.php';
		require_once ALTEGENA_PLUGIN_DIR . 'includes/print/class-print-generator.php';
		if (defined('WP_CLI') && WP_CLI) {
			require_once ALTEGENA_PLUGIN_DIR . 'includes/print/class-print-cli.php';
		}
		require_once ALTEGENA_PLUGIN_DIR . 'includes/class-product-meta.php';
		require_once ALTEGENA_PLUGIN_DIR . 'includes/class-cart-handler.php';
		require_once ALTEGENA_PLUGIN_DIR . 'includes/class-product-page-handler.php';
		require_once ALTEGENA_PLUGIN_DIR . 'includes/class-share-handler.php';
		require_once ALTEGENA_PLUGIN_DIR . 'includes/class-print-storage.php';
		require_once ALTEGENA_PLUGIN_DIR . 'includes/class-print-order-handler.php';
		require_once ALTEGENA_PLUGIN_DIR . 'includes/class-print-admin.php';
		require_once ALTEGENA_PLUGIN_DIR . 'includes/class-print-font-audit.php';
	}

	private function init_hooks()
	{
		add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

		// Initialize classes
		Altegena_Settings::get_instance();
		Altegena_Product_Meta::get_instance();
		Altegena_Cart_Handler::get_instance();
		Altegena_Product_Page_Handler::get_instance();
		Altegena_Share_Handler::get_instance();
		Altegena_Print_Order_Handler::get_instance();
		Altegena_Print_Admin::get_instance();
		Altegena_Print_Font_Audit::get_instance();
	}

	public function enqueue_scripts()
	{
		if (!is_product()) {
			return;
		}

		global $post;
		$config = get_post_meta($post->ID, '_invitation_json_config', true);

		if (empty($config)) {
			return;
		}

		$fonts_ver = filemtime(ALTEGENA_PLUGIN_DIR . 'assets/css/fonts.css');
		$editor_css_ver = filemtime(ALTEGENA_PLUGIN_DIR . 'assets/css/editor.css');
		$editor_js_ver = filemtime(ALTEGENA_PLUGIN_DIR . 'assets/js/editor.js');
		$html2canvas_ver = filemtime(ALTEGENA_PLUGIN_DIR . 'assets/js/vendor/html2canvas.min.js');

		// Load custom fonts first
		wp_enqueue_style('altegena-fonts-css', ALTEGENA_PLUGIN_URL . 'assets/css/fonts.css', array(), $fonts_ver);
		wp_enqueue_style('altegena-editor-css', ALTEGENA_PLUGIN_URL . 'assets/css/editor.css', array('altegena-fonts-css'), $editor_css_ver);

		// Theme-overridable color variables (settings + `altegena_colors` filter).
		wp_add_inline_style('altegena-editor-css', Altegena_Settings::css_vars());

		// html2canvas is bundled locally (not a CDN) so the same-origin canvas is not tainted.
		wp_enqueue_script('altegena-html2canvas', ALTEGENA_PLUGIN_URL . 'assets/js/vendor/html2canvas.min.js', array(), '1.4.1', true);
		wp_enqueue_script('altegena-editor-js', ALTEGENA_PLUGIN_URL . 'assets/js/editor.js', array('jquery', 'wc-add-to-cart', 'altegena-html2canvas'), $editor_js_ver, true);

		wp_localize_script('altegena-editor-js', 'altegena_config', array(
			'raw_config' => $config,
			'is_admin_mode' => current_user_can('manage_options') && isset($_GET['mode']) && $_GET['mode'] === 'admin',
			'ajax_url' => admin_url('admin-ajax.php'),
			'share_nonce' => wp_create_nonce('altegena_share'),
			'share_action' => 'altegena_save_share',
			'product_id' => $post->ID,
			'share_message' => Altegena_Settings::text('share_message'),
		));
	}
}

// Flush rewrite rules on activation so the /davetiye/{token} route works immediately.
register_activation_hook(__FILE__, function () {
	require_once plugin_dir_path(__FILE__) . 'includes/class-share-handler.php';
	Altegena_Share_Handler::get_instance()->register_cpt();
	flush_rewrite_rules();
});

Altegena_Invitation_Editor::get_instance();

// Declare WooCommerce HPOS (custom order tables) compatibility: order data is only touched through WC CRUD.
add_action('before_woocommerce_init', function () {
	if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', ALTEGENA_PLUGIN_FILE, true);
	}
});

// GitHub-based automatic updates: WordPress checks the plugin repo's releases
// and offers a one-click update when a newer tagged release is published.
require_once ALTEGENA_PLUGIN_DIR . 'includes/plugin-update-checker/plugin-update-checker.php';
$altegena_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/KayahanKahriman/altegena-invitation-editor/',
	ALTEGENA_PLUGIN_FILE,
	'altegena-invitation-editor'
);
// Prefer stable GitHub releases; fall back to tags, then the main branch.
$altegena_update_checker->setBranch('main');
