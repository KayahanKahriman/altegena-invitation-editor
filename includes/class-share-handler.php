<?php
/**
 * Handles the "share invitation via WhatsApp" feature.
 *
 * Persists a customer's finished design as a public, self-contained
 * `altegena_invitation` post reachable at /davetiye/{token}, generates the
 * Open Graph preview image, and renders a read-only public page.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Altegena_Share_Handler
{

    private static $instance = null;

    const POST_TYPE = 'altegena_invitation';
    const REWRITE_SLUG = 'davetiye';

    // Abuse limits (fall back to sane defaults if constants are undefined).
    const RATE_LIMIT_MAX = 10;      // saves allowed per window, per IP
    const RATE_LIMIT_WINDOW = 600;  // window in seconds (10 minutes)
    const MAX_TEXT_LENGTH = 2000;   // per layer text cap
    const MAX_IMAGE_W = 3000;
    const MAX_IMAGE_H = 4500;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', array($this, 'maybe_migrate'), 5);
        add_action('init', array($this, 'register_cpt'), 10);
        add_action('init', array($this, 'maybe_flush_rewrite'), 20);

        add_action('wp_ajax_altegena_save_share', array($this, 'ajax_save_share'));
        add_action('wp_ajax_nopriv_altegena_save_share', array($this, 'ajax_save_share'));

        add_filter('template_include', array($this, 'load_single_template'), 99);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_public_assets'), 10);
        add_action('wp_head', array($this, 'output_og_tags'), 1);
    }

    /* ---------------------------------------------------------------------
     * Custom Post Type + rewrite
     * ------------------------------------------------------------------- */

    public function register_cpt()
    {
        register_post_type(self::POST_TYPE, array(
            'labels' => array(
                'name' => 'Davetiye Paylaşımları',
                'singular_name' => 'Davetiye Paylaşımı',
                'menu_name' => 'Davetiye Paylaşımları',
            ),
            'public' => true,
            'publicly_queryable' => true,
            'exclude_from_search' => true,
            'has_archive' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_nav_menus' => false,
            'show_in_admin_bar' => false,
            'menu_icon' => 'dashicons-share',
            'rewrite' => array('slug' => self::REWRITE_SLUG, 'with_front' => false),
            'query_var' => true,
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'hierarchical' => false,
            'supports' => array('title'),
        ));
    }

    /**
     * The plugin is usually updated (not reactivated), so a version-gated flush
     * guarantees the /davetiye/{token} rules exist after a deploy.
     */
    public function maybe_flush_rewrite()
    {
        if (get_option('altegena_rewrite_version') !== ALTEGENA_VERSION) {
            flush_rewrite_rules(false);
            update_option('altegena_rewrite_version', ALTEGENA_VERSION);
        }
    }

    /**
     * One-time data migration from the old "sie" prefix to "altegena".
     * Runs once per version (option-gated). Renames the persisted identifiers
     * so existing shares, orders and product configs keep working after the
     * rebrand. The old "sie_*" strings below are intentional literals.
     */
    public function maybe_migrate()
    {
        if (get_option('altegena_migrated') === ALTEGENA_VERSION) {
            return;
        }

        global $wpdb;

        // 1. Share post meta: _sie_share_* -> _altegena_share_*
        $share_keys = array(
            '_sie_share_config'     => '_altegena_share_config',
            '_sie_share_image_id'   => '_altegena_share_image_id',
            '_sie_share_image_url'  => '_altegena_share_image_url',
            '_sie_share_image_dims' => '_altegena_share_image_dims',
            '_sie_share_product_id' => '_altegena_share_product_id',
            '_sie_share_ip'         => '_altegena_share_ip',
        );
        foreach ($share_keys as $old => $new) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->postmeta} SET meta_key = %s WHERE meta_key = %s",
                $new,
                $old
            ));
        }

        // 2. Order item meta: _sie_design_data -> _altegena_design_data
        $order_itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $order_itemmeta)) === $order_itemmeta) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$order_itemmeta} SET meta_key = %s WHERE meta_key = %s",
                '_altegena_design_data',
                '_sie_design_data'
            ));
        }

        // 3. Custom post type slug: sie_invitation -> altegena_invitation
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->posts} SET post_type = %s WHERE post_type = %s",
            'altegena_invitation',
            'sie_invitation'
        ));

        // 4. Drop the stale rewrite-version option (altegena_rewrite_version re-flushes).
        delete_option('sie_rewrite_version');

        flush_rewrite_rules(false);
        update_option('altegena_migrated', ALTEGENA_VERSION);
    }

    /* ---------------------------------------------------------------------
     * AJAX: save a shared invitation
     * ------------------------------------------------------------------- */

    public function ajax_save_share()
    {
        // 1. CSRF
        if (!check_ajax_referer('altegena_share', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Güvenlik doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.', 'code' => 'invalid_nonce'), 403);
        }

        // 2. Rate limit
        if (!$this->check_rate_limit()) {
            wp_send_json_error(array('message' => 'Çok fazla istek. Lütfen birkaç dakika sonra tekrar deneyin.', 'code' => 'rate_limited'), 429);
        }

        // 3. Required fields
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $raw_config = isset($_POST['custom_data']) ? wp_unslash($_POST['custom_data']) : '';
        $raw_image  = isset($_POST['image']) ? $_POST['image'] : '';

        if (!$product_id || $raw_config === '' || $raw_image === '') {
            wp_send_json_error(array('message' => 'Eksik veri.', 'code' => 'missing_data'), 400);
        }

        // 4. Size cap on the text payload (before decoding)
        $max_config = defined('ALTEGENA_SHARE_MAX_CONFIG_BYTES') ? ALTEGENA_SHARE_MAX_CONFIG_BYTES : 262144;
        if (strlen($raw_config) > $max_config) {
            wp_send_json_error(array('message' => 'Veri boyutu çok büyük.', 'code' => 'payload_too_large'), 413);
        }

        $text_map = json_decode($raw_config, true);
        if (!is_array($text_map)) {
            wp_send_json_error(array('message' => 'Geçersiz veri.', 'code' => 'bad_config'), 400);
        }

        // 5. Build the self-contained merged config from the trusted template.
        $merged = $this->build_merged_config($product_id, $text_map);
        if (is_wp_error($merged)) {
            wp_send_json_error(array('message' => $merged->get_error_message(), 'code' => $merged->get_error_code()), 400);
        }

        // 6. Validate + sideload the PNG.
        $image = $this->sideload_data_url_image($raw_image);
        if (is_wp_error($image)) {
            wp_send_json_error(array('message' => $image->get_error_message(), 'code' => $image->get_error_code()), 400);
        }

        // 7. Persist.
        $post_id = $this->create_share_post($merged, $image, $product_id);
        if (is_wp_error($post_id)) {
            wp_send_json_error(array('message' => 'Paylaşım kaydedilemedi.', 'code' => 'save_failed'), 500);
        }

        // 8. Bump rate counter only on success.
        $this->bump_rate_limit();

        wp_send_json_success(array(
            'url'       => get_permalink($post_id),
            'image_url' => $image['url'],
        ));
    }

    /**
     * Merge the authoritative product template with the user's (sanitized) text.
     * Styles, positions, fonts and canvas come only from the trusted template —
     * never from the client — which closes off style/XSS/SSRF injection.
     */
    private function build_merged_config($product_id, $text_map)
    {
        $raw = get_post_meta($product_id, '_invitation_json_config', true);
        if (empty($raw)) {
            return new WP_Error('bad_config', 'Şablon bulunamadı.');
        }

        $config = json_decode($raw, true);
        if (!is_array($config) || !isset($config['canvas']) || !isset($config['layers']) || !is_array($config['layers'])) {
            return new WP_Error('bad_config', 'Şablon geçersiz.');
        }

        foreach ($config['layers'] as &$layer) {
            if (!isset($layer['id'])) {
                continue;
            }
            $id = $layer['id'];
            if (isset($text_map[$id]) && is_array($text_map[$id]) && isset($text_map[$id]['text'])) {
                $text = (string) $text_map[$id]['text'];
                $text = sanitize_textarea_field($text);
                if (function_exists('mb_substr')) {
                    $text = mb_substr($text, 0, self::MAX_TEXT_LENGTH);
                } else {
                    $text = substr($text, 0, self::MAX_TEXT_LENGTH);
                }
                $layer['default_text'] = $text;
            }
        }
        unset($layer);

        return $config;
    }

    /* ---------------------------------------------------------------------
     * Image handling
     * ------------------------------------------------------------------- */

    /**
     * Validate a base64 data-URL image and sideload it into the media library.
     *
     * @return array|WP_Error [attachment_id, url, width, height]
     */
    private function sideload_data_url_image($data_url)
    {
        if (!preg_match('#^data:image/(png|jpeg);base64,#', $data_url, $m)) {
            return new WP_Error('bad_image', 'Geçersiz görsel biçimi.');
        }
        $ext = ($m[1] === 'jpeg') ? 'jpg' : 'png';

        $base64 = substr($data_url, strpos($data_url, ',') + 1);
        $binary = base64_decode($base64, true);
        if ($binary === false) {
            return new WP_Error('bad_image', 'Görsel çözülemedi.');
        }

        // Magic-byte check — never trust the mime prefix.
        $is_png  = (substr($binary, 0, 8) === "\x89PNG\r\n\x1a\n");
        $is_jpeg = (substr($binary, 0, 3) === "\xFF\xD8\xFF");
        if (!$is_png && !$is_jpeg) {
            return new WP_Error('bad_image', 'Görsel tipi doğrulanamadı.');
        }

        $max_bytes = defined('ALTEGENA_SHARE_MAX_IMAGE_BYTES') ? ALTEGENA_SHARE_MAX_IMAGE_BYTES : 5242880;
        if (strlen($binary) > $max_bytes) {
            return new WP_Error('bad_image', 'Görsel boyutu çok büyük.');
        }

        $size = @getimagesizefromstring($binary);
        if ($size === false) {
            return new WP_Error('bad_image', 'Görsel okunamadı.');
        }
        list($width, $height) = $size;
        if ($width < 1 || $height < 1 || $width > self::MAX_IMAGE_W || $height > self::MAX_IMAGE_H) {
            return new WP_Error('bad_image', 'Görsel boyutları geçersiz.');
        }

        $filename = 'altegena-davetiye-' . wp_generate_password(8, false, false) . '.' . $ext;
        $upload = wp_upload_bits($filename, null, $binary);
        if (!empty($upload['error'])) {
            return new WP_Error('bad_image', 'Görsel yüklenemedi.');
        }

        $filetype = wp_check_filetype($upload['file'], null);
        $attachment = array(
            'post_mime_type' => $filetype['type'],
            'post_title'     => sanitize_file_name($filename),
            'post_content'   => '',
            'post_status'    => 'inherit',
        );
        $attach_id = wp_insert_attachment($attachment, $upload['file']);
        if (is_wp_error($attach_id) || !$attach_id) {
            return new WP_Error('bad_image', 'Görsel kaydedilemedi.');
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $meta = wp_generate_attachment_metadata($attach_id, $upload['file']);
        wp_update_attachment_metadata($attach_id, $meta);

        return array(
            'id'     => $attach_id,
            'url'    => $upload['url'],
            'width'  => $width,
            'height' => $height,
        );
    }

    /* ---------------------------------------------------------------------
     * Persistence
     * ------------------------------------------------------------------- */

    private function create_share_post($config, $image, $product_id)
    {
        // Unguessable, collision-free token used as the pretty slug.
        do {
            $token = wp_generate_password(12, false, false);
        } while (get_page_by_path($token, OBJECT, self::POST_TYPE));

        $post_id = wp_insert_post(array(
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
            'post_name'      => $token,
            'post_title'     => 'Davetiye ' . $token,
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ), true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        // wp_slash is mandatory: update_post_meta unslashes internally, which would
        // otherwise strip the \n escapes inside the JSON (same gotcha as the template meta).
        update_post_meta($post_id, '_altegena_share_config', wp_slash(wp_json_encode($config)));
        update_post_meta($post_id, '_altegena_share_image_id', $image['id']);
        update_post_meta($post_id, '_altegena_share_image_url', esc_url_raw($image['url']));
        update_post_meta($post_id, '_altegena_share_image_dims', array('w' => $image['width'], 'h' => $image['height']));
        update_post_meta($post_id, '_altegena_share_product_id', $product_id);
        update_post_meta($post_id, '_altegena_share_ip', md5($this->get_client_ip() . wp_salt()));

        // Parent the attachment so it is removed when the share post is deleted.
        wp_update_post(array('ID' => $image['id'], 'post_parent' => $post_id));

        return $post_id;
    }

    /* ---------------------------------------------------------------------
     * Public page: template, assets, Open Graph
     * ------------------------------------------------------------------- */

    public function load_single_template($template)
    {
        if (is_singular(self::POST_TYPE)) {
            $custom = ALTEGENA_PLUGIN_DIR . 'templates/single-invitation.php';
            if (file_exists($custom)) {
                return $custom;
            }
        }
        return $template;
    }

    public function enqueue_public_assets()
    {
        if (!is_singular(self::POST_TYPE)) {
            return;
        }

        $post_id = get_queried_object_id();
        $raw_config = get_post_meta($post_id, '_altegena_share_config', true);
        $image_url = get_post_meta($post_id, '_altegena_share_image_url', true);
        if (empty($raw_config)) {
            return;
        }

        $fonts_ver = filemtime(ALTEGENA_PLUGIN_DIR . 'assets/css/fonts.css');
        $css_ver = filemtime(ALTEGENA_PLUGIN_DIR . 'assets/css/public-invitation.css');
        $js_ver = filemtime(ALTEGENA_PLUGIN_DIR . 'assets/js/public-invitation.js');

        wp_enqueue_style('altegena-fonts-css', ALTEGENA_PLUGIN_URL . 'assets/css/fonts.css', array(), $fonts_ver);
        wp_enqueue_style('altegena-public-css', ALTEGENA_PLUGIN_URL . 'assets/css/public-invitation.css', array('altegena-fonts-css'), $css_ver);
        wp_enqueue_script('altegena-public-js', ALTEGENA_PLUGIN_URL . 'assets/js/public-invitation.js', array('jquery'), $js_ver, true);

        wp_localize_script('altegena-public-js', 'altegena_share', array(
            'config'    => $raw_config, // raw JSON string; JS parses + normalizes \n like editor.js
            'image_url' => $image_url,
            'page_url'  => get_permalink($post_id),
            'wa_text'   => 'Davetiyemize göz atın:',
        ));
    }

    public function output_og_tags()
    {
        if (!is_singular(self::POST_TYPE)) {
            return;
        }

        // Suppress other Open Graph sources so WhatsApp uses OUR invitation image,
        // not the theme logo or an SEO plugin's default. This runs at wp_head
        // priority 1, before the theme's (4/5/6) callbacks execute.
        $this->suppress_other_seo();

        $post_id = get_queried_object_id();
        $image_url = get_post_meta($post_id, '_altegena_share_image_url', true);
        $dims = get_post_meta($post_id, '_altegena_share_image_dims', true);
        $page_url = get_permalink($post_id);

        $title = 'Davetiyemize Davetlisiniz';
        $desc = 'Özel günümüz için hazırladığımız davetiyeyi görüntüleyin.';

        echo "\n<!-- Altegena Invitation Editor: Open Graph -->\n";
        echo '<meta name="robots" content="noindex,nofollow" />' . "\n";
        echo '<meta property="og:type" content="website" />' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($title) . '" />' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($desc) . '" />' . "\n";
        echo '<meta property="og:url" content="' . esc_url($page_url) . '" />' . "\n";
        if (!empty($image_url)) {
            echo '<meta property="og:image" content="' . esc_url($image_url) . '" />' . "\n";
            echo '<meta property="og:image:secure_url" content="' . esc_url($image_url) . '" />' . "\n";
            if (is_array($dims) && !empty($dims['w']) && !empty($dims['h'])) {
                echo '<meta property="og:image:width" content="' . esc_attr($dims['w']) . '" />' . "\n";
                echo '<meta property="og:image:height" content="' . esc_attr($dims['h']) . '" />' . "\n";
            }
            echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
            echo '<meta name="twitter:image" content="' . esc_url($image_url) . '" />' . "\n";
        }
        echo '<meta name="twitter:title" content="' . esc_attr($title) . '" />' . "\n";
        echo '<meta name="twitter:description" content="' . esc_attr($desc) . '" />' . "\n";
        echo "<!-- /Altegena Invitation Editor -->\n";
    }

    /**
     * Remove duplicate Open Graph / SEO output from the active theme and common
     * SEO plugins on our share pages, so only our tags remain.
     */
    private function suppress_other_seo()
    {
        // Active theme (dm-omni) prints its own OG/canonical/JSON-LD on wp_head.
        remove_action('wp_head', 'dm_seo_print_meta', 6);
        remove_action('wp_head', 'dm_seo_print_canonical', 4);
        remove_action('wp_head', 'dm_seo_print_jsonld', 5);

        // Yoast SEO (if ever activated): drop its OG image/tags on these pages.
        add_filter('wpseo_opengraph_image', '__return_false', 99);
        add_filter('wpseo_opengraph_title', '__return_false', 99);
        add_filter('wpseo_opengraph_desc', '__return_false', 99);

        // Rank Math (if ever activated): disable its frontend head output.
        add_filter('rank_math/frontend/disable_integration', '__return_true', 99);
    }

    /* ---------------------------------------------------------------------
     * Rate limiting helpers
     * ------------------------------------------------------------------- */

    private function rate_limit_key()
    {
        return 'altegena_share_rl_' . md5($this->get_client_ip() . wp_salt());
    }

    private function check_rate_limit()
    {
        $count = (int) get_transient($this->rate_limit_key());
        return $count < self::RATE_LIMIT_MAX;
    }

    private function bump_rate_limit()
    {
        $key = $this->rate_limit_key();
        $count = (int) get_transient($key);
        set_transient($key, $count + 1, self::RATE_LIMIT_WINDOW);
    }

    private function get_client_ip()
    {
        // Only trust REMOTE_ADDR; ignore spoofable forwarded headers.
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }
}
