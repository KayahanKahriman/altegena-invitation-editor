<?php
/**
 * Plugin Name: Simple Invitation Editor
 * Description: A lightweight, DOM-based invitation editor for WooCommerce.
 * Version: 1.0.0
 * Author: Kayahan
 * Text Domain: simple-invitation-editor
 */

if (!defined('ABSPATH')) {
	exit;
}

class Simple_Invitation_Editor
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
		define('SIE_PLUGIN_DIR', plugin_dir_path(__FILE__));
		define('SIE_PLUGIN_URL', plugin_dir_url(__FILE__));
		define('SIE_PLUGIN_FILE', __FILE__);
		define('SIE_VERSION', '1.1.0');

		// Share feature payload limits
		define('SIE_SHARE_MAX_CONFIG_BYTES', 262144);   // 256 KB of layer text JSON
		define('SIE_SHARE_MAX_IMAGE_BYTES', 5242880);   // 5 MB decoded PNG/JPEG
	}

	private function includes()
	{
		require_once SIE_PLUGIN_DIR . 'includes/class-product-meta.php';
		require_once SIE_PLUGIN_DIR . 'includes/class-cart-handler.php';
		require_once SIE_PLUGIN_DIR . 'includes/class-product-page-handler.php';
		require_once SIE_PLUGIN_DIR . 'includes/class-share-handler.php';
	}

	private function init_hooks()
	{
		add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

		// Initialize classes
		SIE_Product_Meta::get_instance();
		SIE_Cart_Handler::get_instance();
		SIE_Product_Page_Handler::get_instance();
		SIE_Share_Handler::get_instance();
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

		$fonts_ver = filemtime(SIE_PLUGIN_DIR . 'assets/css/fonts.css');
		$editor_css_ver = filemtime(SIE_PLUGIN_DIR . 'assets/css/editor.css');
		$editor_js_ver = filemtime(SIE_PLUGIN_DIR . 'assets/js/editor.js');
		$html2canvas_ver = filemtime(SIE_PLUGIN_DIR . 'assets/js/vendor/html2canvas.min.js');

		// Load custom fonts first
		wp_enqueue_style('sie-fonts-css', SIE_PLUGIN_URL . 'assets/css/fonts.css', array(), $fonts_ver);
		wp_enqueue_style('sie-editor-css', SIE_PLUGIN_URL . 'assets/css/editor.css', array('sie-fonts-css'), $editor_css_ver);

		// html2canvas is bundled locally (not a CDN) so the same-origin canvas is not tainted.
		wp_enqueue_script('sie-html2canvas', SIE_PLUGIN_URL . 'assets/js/vendor/html2canvas.min.js', array(), '1.4.1', true);
		wp_enqueue_script('sie-editor-js', SIE_PLUGIN_URL . 'assets/js/editor.js', array('jquery', 'wc-add-to-cart', 'sie-html2canvas'), $editor_js_ver, true);

		wp_localize_script('sie-editor-js', 'sie_config', array(
			'raw_config' => $config,
			'is_admin_mode' => current_user_can('manage_options') && isset($_GET['mode']) && $_GET['mode'] === 'admin',
			'ajax_url' => admin_url('admin-ajax.php'),
			'share_nonce' => wp_create_nonce('sie_share'),
			'share_action' => 'sie_save_share',
			'product_id' => $post->ID,
		));
	}
}

// Flush rewrite rules on activation so the /davetiye/{token} route works immediately.
register_activation_hook(__FILE__, function () {
	require_once plugin_dir_path(__FILE__) . 'includes/class-share-handler.php';
	SIE_Share_Handler::get_instance()->register_cpt();
	flush_rewrite_rules();
});

Simple_Invitation_Editor::get_instance();
