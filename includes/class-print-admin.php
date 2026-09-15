<?php
/**
 * Print PDFs in wp-admin: order meta box (status, downloads, regenerate, text
 * corrections), capability-checked downloads and an orders list column.
 * Works with HPOS and the legacy posts-based order screens.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Altegena_Print_Admin
{
    const CAPABILITY = 'edit_shop_orders';

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
        add_action('add_meta_boxes', array($this, 'add_meta_box'), 10, 2);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));

        add_action('wp_ajax_altegena_print_regenerate', array($this, 'ajax_regenerate'));
        add_action('wp_ajax_altegena_print_save_texts', array($this, 'ajax_save_texts'));
        add_action('admin_post_altegena_print_download', array($this, 'download'));

        add_filter('manage_woocommerce_page_wc-orders_columns', array($this, 'add_column'));
        add_action('manage_woocommerce_page_wc-orders_custom_column', array($this, 'render_column'), 10, 2);
        add_filter('manage_edit-shop_order_columns', array($this, 'add_column'));
        add_action('manage_shop_order_posts_custom_column', array($this, 'render_legacy_column'), 10, 2);
    }

    private static function order_screen_id()
    {
        return function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'shop_order';
    }

    private static function order_from($post_or_order)
    {
        if ($post_or_order instanceof WC_Order) {
            return $post_or_order;
        }
        if ($post_or_order instanceof WP_Post) {
            return wc_get_order($post_or_order->ID);
        }
        return $post_or_order ? wc_get_order($post_or_order) : false;
    }

    /* ---------------------------------------------------------------------
     * Assets
     * ------------------------------------------------------------------- */

    public function enqueue_assets()
    {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, array(self::order_screen_id(), 'shop_order', 'edit-shop_order'), true)) {
            return;
        }

        wp_enqueue_style('altegena-admin-print', ALTEGENA_PLUGIN_URL . 'assets/css/admin-print.css', array(), filemtime(ALTEGENA_PLUGIN_DIR . 'assets/css/admin-print.css'));
        wp_enqueue_script('altegena-admin-print', ALTEGENA_PLUGIN_URL . 'assets/js/admin-print.js', array('jquery'), filemtime(ALTEGENA_PLUGIN_DIR . 'assets/js/admin-print.js'), true);
        wp_localize_script('altegena-admin-print', 'altegena_print', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'i18n' => array('error' => 'İşlem tamamlanamadı. Sayfayı yenileyip tekrar deneyin.'),
        ));
    }

    /* ---------------------------------------------------------------------
     * Meta box
     * ------------------------------------------------------------------- */

    public function add_meta_box($screen_id, $post_or_order)
    {
        if (!in_array($screen_id, array(self::order_screen_id(), 'shop_order'), true)) {
            return;
        }
        $order = self::order_from($post_or_order);
        if (!$order || !Altegena_Print_Order_Handler::invitation_items($order)) {
            return;
        }
        add_meta_box('altegena-print', 'Davetiye baskı PDF\'leri', array($this, 'render_meta_box'), $screen_id, 'normal', 'high');
    }

    public function render_meta_box($post_or_order)
    {
        $order = self::order_from($post_or_order);
        if (!$order) {
            return;
        }
        echo '<div class="altegena-print-box">';
        if (Altegena_Print_Storage::is_publicly_accessible()) {
            echo '<div class="notice notice-warning inline"><p>Baskı PDF klasörü web üzerinden doğrudan erişilebilir görünüyor (sunucu .htaccess kurallarını uygulamıyor olabilir). '
                . 'Klasör adı tahmin edilemez olsa da kişisel veriler için <code>wp-config.php</code> içinde <code>ALTEGENA_PRINT_DIR</code> ile web kökü dışında bir klasör tanımlamanız önerilir.</p></div>';
        }
        foreach (Altegena_Print_Order_Handler::invitation_items($order) as $item) {
            echo $this->item_panel($order, $item); // escaped inside
        }
        echo '</div>';
    }

    public function item_panel($order, $item)
    {
        $order_id = $order->get_id();
        $item_id = $item->get_id();
        $state = Altegena_Print_Order_Handler::get_state($item);
        $overrides = Altegena_Print_Order_Handler::get_overrides($item);
        $snapshot = $item->get_meta(Altegena_Print_Order_Handler::SNAPSHOT_KEY);
        $status = isset($state['status']) ? $state['status'] : '';

        $labels = array('' => 'Henüz oluşturulmadı', 'queued' => 'Sırada', 'running' => 'Oluşturuluyor', 'done' => 'Hazır', 'failed' => 'Hata');
        $badge = ($status === 'done' && !empty($state['warnings'])) ? 'warning' : ($status !== '' ? $status : 'none');
        $label = isset($labels[$status]) ? $labels[$status] : $status;
        if ($badge === 'warning') {
            $label = 'Hazır (uyarılı)';
        }

        $meta = array();
        if (!empty($state['page_px'])) {
            $meta[] = sprintf('%d × %d px', $state['page_px'][0], $state['page_px'][1]);
        }
        if (!empty($state['generated_at'])) {
            $meta[] = wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($state['generated_at']));
        }
        if (!empty($state['files'])) {
            $meta[] = 'rev ' . (int) $state['rev'];
        }

        $has_files = !empty($state['files']['outline']['file']) && !empty($state['files']['text']['file']);
        $nonce = wp_create_nonce('altegena_print_' . $order_id);

        ob_start();
        ?>
        <div class="altegena-print-item" data-order="<?php echo (int) $order_id; ?>" data-item="<?php echo (int) $item_id; ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
            <div class="altegena-print-head">
                <strong><?php echo esc_html($item->get_name()); ?></strong>
                <span class="altegena-print-badge is-<?php echo esc_attr($badge); ?>"><?php echo esc_html($label); ?></span>
                <?php if ($meta) : ?>
                    <span class="altegena-print-meta"><?php echo esc_html(implode(' · ', $meta)); ?></span>
                <?php endif; ?>
            </div>

            <p class="altegena-print-actions">
                <?php if ($has_files) : ?>
                    <a class="button button-primary" href="<?php echo esc_url(self::download_url($order_id, $item_id, 'outline')); ?>">Konturlu PDF indir</a>
                    <a class="button" href="<?php echo esc_url(self::download_url($order_id, $item_id, 'text')); ?>">Metinli PDF indir</a>
                <?php endif; ?>
                <button type="button" class="button altegena-print-regenerate"><?php echo $has_files ? 'Yeniden oluştur' : 'PDF\'leri şimdi oluştur'; ?></button>
                <span class="spinner"></span>
            </p>

            <?php if ($status === 'failed' && !empty($state['error']['message'])) : ?>
                <div class="notice notice-error inline"><p>
                    <?php echo esc_html($state['error']['message']); ?>
                    <?php if ($has_files) : ?><br><em>İndirme bağlantıları önceki başarılı sürüme aittir.</em><?php endif; ?>
                </p></div>
            <?php endif; ?>

            <?php if (!empty($state['warnings'])) : ?>
                <div class="notice notice-warning inline"><ul>
                    <?php foreach ((array) $state['warnings'] as $warning) : ?>
                        <li><?php echo esc_html($warning); ?></li>
                    <?php endforeach; ?>
                </ul></div>
            <?php endif; ?>

            <?php if (is_array($snapshot) && !empty($snapshot['layers'])) : ?>
                <?php echo $this->texts_editor($snapshot, $overrides); // escaped inside ?>
            <?php else : ?>
                <p class="description">Sipariş anlık görüntüsü yok (eski sipariş). PDF oluşturulunca tasarım ürünün güncel şablonundan kurulur ve metinler düzenlenebilir hale gelir.</p>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function texts_editor($snapshot, $overrides)
    {
        $groups = array('editable' => array(), 'fixed' => array());
        foreach ($snapshot['layers'] as $layer) {
            $groups[!empty($layer['hidden_on_frontend']) ? 'fixed' : 'editable'][] = $layer;
        }

        ob_start();
        ?>
        <details class="altegena-print-texts">
            <summary>Metinleri düzenle<?php echo $overrides['layers'] ? ' (' . count($overrides['layers']) . ' düzeltme)' : ''; ?></summary>
            <table class="altegena-print-text-table">
                <?php foreach ($groups as $group => $layers) : ?>
                    <?php if (!$layers) {
                        continue;
                    } ?>
                    <?php if ($group === 'fixed') : ?>
                        <tr class="altegena-print-section"><th colspan="2">Sabit metinler (müşteri düzenleyemez)</th></tr>
                    <?php endif; ?>
                    <?php foreach ($layers as $layer) : ?>
                        <?php
                        $id = (string) $layer['id'];
                        $original = (string) $layer['text'];
                        $effective = isset($overrides['layers'][$id]['text']) ? (string) $overrides['layers'][$id]['text'] : $original;
                        $rows = max(1, min(8, substr_count($effective, "\n") + 1));
                        ?>
                        <tr>
                            <th><?php echo esc_html($layer['label'] !== '' ? $layer['label'] : $id); ?></th>
                            <td>
                                <textarea rows="<?php echo (int) $rows; ?>" data-layer="<?php echo esc_attr($id); ?>" data-original="<?php echo esc_attr($original); ?>"><?php echo "\n" . esc_textarea($effective); ?></textarea>
                                <?php if ($effective !== $original) : ?>
                                    <p class="altegena-print-original">Müşteri: <?php echo esc_html($original); ?>
                                        <button type="button" class="button-link altegena-print-revert">Orijinale dön</button></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </table>
            <p class="altegena-print-save-row">
                <button type="button" class="button button-primary altegena-print-save">Kaydet ve PDF'leri yeniden oluştur</button>
                <span class="description">Müşterinin orijinal metni ayrıca saklanır; sipariş satırları ve e-postalar değişmez.</span>
            </p>
        </details>
        <?php
        return ob_get_clean();
    }

    private static function download_url($order_id, $item_id, $kind)
    {
        return add_query_arg(array(
            'action' => 'altegena_print_download',
            'order_id' => $order_id,
            'item_id' => $item_id,
            'kind' => $kind,
            '_wpnonce' => wp_create_nonce('altegena_print_download_' . $order_id),
        ), admin_url('admin-post.php'));
    }

    /* ---------------------------------------------------------------------
     * AJAX
     * ------------------------------------------------------------------- */

    private function ajax_context()
    {
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $item_id = isset($_POST['item_id']) ? absint($_POST['item_id']) : 0;

        if (!check_ajax_referer('altegena_print_' . $order_id, 'nonce', false)) {
            wp_send_json_error(array('message' => 'Güvenlik doğrulaması başarısız. Sayfayı yenileyin.'), 403);
        }
        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(array('message' => 'Bu işlem için yetkiniz yok.'), 403);
        }

        $order = wc_get_order($order_id);
        $item = $order ? $order->get_item($item_id) : false;
        if (!$item || !Altegena_Print_Order_Handler::is_invitation_item($item)) {
            wp_send_json_error(array('message' => 'Sipariş kalemi bulunamadı.'), 404);
        }
        return array($order, $item);
    }

    public function ajax_regenerate()
    {
        list($order, $item) = $this->ajax_context();
        // Errors are recorded in the item state and shown in the returned panel.
        Altegena_Print_Order_Handler::get_instance()->generate_item($order, $item, true);
        wp_send_json_success(array('html' => $this->item_panel($order, $item)));
    }

    public function ajax_save_texts()
    {
        list($order, $item) = $this->ajax_context();

        $texts = isset($_POST['texts']) && is_string($_POST['texts']) ? json_decode(wp_unslash($_POST['texts']), true) : null;
        if (!is_array($texts)) {
            wp_send_json_error(array('message' => 'Geçersiz metin verisi.'), 400);
        }

        $handler = Altegena_Print_Order_Handler::get_instance();
        $changed = $handler->save_texts($order, $item, $texts, get_current_user_id());
        if (is_wp_error($changed)) {
            wp_send_json_error(array('message' => $changed->get_error_message()), 400);
        }

        $handler->generate_item($order, $item, true);
        wp_send_json_success(array('html' => $this->item_panel($order, $item), 'changed' => count($changed)));
    }

    /* ---------------------------------------------------------------------
     * Download
     * ------------------------------------------------------------------- */

    public function download()
    {
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $item_id = isset($_GET['item_id']) ? absint($_GET['item_id']) : 0;
        $kind = (isset($_GET['kind']) && $_GET['kind'] === 'text') ? 'text' : 'outline';

        check_admin_referer('altegena_print_download_' . $order_id);
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('Bu dosyayı indirmek için yetkiniz yok.', '', array('response' => 403));
        }

        $order = wc_get_order($order_id);
        $item = $order ? $order->get_item($item_id) : false;
        $state = $item ? Altegena_Print_Order_Handler::get_state($item) : array();
        $path = ($order && !empty($state['files'][$kind]['file'])) ? Altegena_Print_Storage::path($order, $state['files'][$kind]['file']) : null;
        if (!$path) {
            wp_die('Dosya bulunamadı. Sipariş ekranından PDF\'leri yeniden oluşturun.', '', array('response' => 404));
        }

        $filename = sprintf(
            'siparis-%s-kalem-%d-%s.pdf',
            preg_replace('/[^A-Za-z0-9-]/', '', (string) $order->get_order_number()),
            $item_id,
            $kind === 'text' ? 'metinli' : 'konturlu'
        );

        do_action('litespeed_control_set_nocache', 'altegena print download');
        while (ob_get_level()) {
            ob_end_clean();
        }
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    /* ---------------------------------------------------------------------
     * Orders list column
     * ------------------------------------------------------------------- */

    public function add_column($columns)
    {
        $result = array();
        foreach ($columns as $key => $label) {
            $result[$key] = $label;
            if ($key === 'order_status') {
                $result['altegena_print'] = 'Baskı PDF';
            }
        }
        if (!isset($result['altegena_print'])) {
            $result['altegena_print'] = 'Baskı PDF';
        }
        return $result;
    }

    public function render_column($column, $order)
    {
        if ($column !== 'altegena_print') {
            return;
        }
        $order = self::order_from($order);
        $summary = $order ? (string) $order->get_meta(Altegena_Print_Order_Handler::SUMMARY_KEY) : '';
        $labels = array('done' => 'Hazır', 'warning' => 'Uyarılı', 'queued' => 'Sırada', 'failed' => 'Hata');
        if (!isset($labels[$summary])) {
            echo '<span class="altegena-print-column">—</span>';
            return;
        }
        printf('<span class="altegena-print-badge is-%s">%s</span>', esc_attr($summary), esc_html($labels[$summary]));
    }

    public function render_legacy_column($column, $post_id)
    {
        $this->render_column($column, $post_id);
    }
}
