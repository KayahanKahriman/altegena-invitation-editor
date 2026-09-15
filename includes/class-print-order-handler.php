<?php
/**
 * Order-side print integration.
 *
 * - Captures an immutable design snapshot for every invitation order line item
 *   at checkout, so print PDFs can be rebuilt exactly later.
 * - When an order is paid (payment_complete, or processing/completed as a
 *   safety net) queues one Action Scheduler job per invitation item that renders
 *   both print PDFs into protected storage.
 * - Keeps per-item state and admin text corrections. The original customer text
 *   in the snapshot is never changed.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Altegena_Print_Order_Handler
{
    const SNAPSHOT_KEY = '_altegena_print_snapshot';
    const OVERRIDES_KEY = '_altegena_print_text_overrides';
    const STATE_KEY = '_altegena_print_state';
    const SUMMARY_KEY = '_altegena_print_summary';
    const JOB_HOOK = 'altegena_print_generate_item';
    const JOB_GROUP = 'altegena-print';
    const ENGINE_VERSION = '1';
    const MAX_ATTEMPTS = 3;
    const LOCK_TTL = 300;

    /** Errors retrying can't fix: they need a template, font, image or text change. */
    const PERMANENT_ERRORS = array(
        'no_design', 'bad_config', 'bad_canvas', 'print_size_missing', 'print_ratio_mismatch',
        'font_missing', 'font_unsupported', 'missing_glyph',
        'background_missing', 'background_unreadable', 'background_unsupported', 'background_too_large',
    );

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

        add_action('woocommerce_payment_complete', array($this, 'enqueue_order'));
        add_action('woocommerce_order_status_processing', array($this, 'enqueue_order'));
        add_action('woocommerce_order_status_completed', array($this, 'enqueue_order'));
        add_action(self::JOB_HOOK, array($this, 'run_job'), 10, 2);

        add_action('woocommerce_before_delete_order', array($this, 'delete_order_files'));
        add_action('before_delete_post', array($this, 'delete_legacy_order_files'));
    }

    /* ---------------------------------------------------------------------
     * Checkout snapshot
     * ------------------------------------------------------------------- */

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

    /* ---------------------------------------------------------------------
     * Item helpers and stored data
     * ------------------------------------------------------------------- */

    public static function is_invitation_item($item)
    {
        return $item instanceof WC_Order_Item_Product
            && ($item->get_meta(self::SNAPSHOT_KEY) || $item->get_meta('_altegena_design_data'));
    }

    /** @return WC_Order_Item_Product[] */
    public static function invitation_items($order)
    {
        $items = array();
        foreach ($order->get_items() as $item_id => $item) {
            if (self::is_invitation_item($item)) {
                $items[$item_id] = $item;
            }
        }
        return $items;
    }

    public static function get_state($item)
    {
        return self::read_json_meta($item, self::STATE_KEY);
    }

    public static function get_overrides($item)
    {
        $data = self::read_json_meta($item, self::OVERRIDES_KEY);
        return array(
            'rev' => isset($data['rev']) ? (int) $data['rev'] : 0,
            'layers' => isset($data['layers']) && is_array($data['layers']) ? $data['layers'] : array(),
            'history' => isset($data['history']) && is_array($data['history']) ? $data['history'] : array(),
        );
    }

    private static function read_json_meta($item, $key)
    {
        $raw = $item->get_meta($key);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($data) ? $data : array();
    }

    /**
     * State and overrides are stored as JSON strings through delete + add: WooCommerce
     * slashes strings on add, so the value round-trips unchanged (backslashes included)
     * and the in-memory copy stays unslashed.
     */
    private static function write_json_meta($item, $key, $data)
    {
        $item->delete_meta_data($key);
        $item->add_meta_data($key, wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true);
        $item->save_meta_data();
    }

    private function save_state($order, $item, $state)
    {
        self::write_json_meta($item, self::STATE_KEY, $state);
        self::update_summary($order);
    }

    /** Order-level status for the orders list: failed > queued > warning > done. */
    public static function update_summary($order)
    {
        $rank = array('' => 0, 'done' => 1, 'warning' => 2, 'queued' => 3, 'failed' => 4);
        $summary = '';
        foreach (array_keys(self::invitation_items($order)) as $item_id) {
            // Read from the database: WC_Order::get_item() returns a freshly loaded item,
            // so the instances cached in $order->get_items() can hold an outdated state.
            $raw = wc_get_order_item_meta($item_id, self::STATE_KEY, true);
            $state = is_string($raw) ? json_decode($raw, true) : null;
            $state = is_array($state) ? $state : array();
            $status = isset($state['status']) ? $state['status'] : '';
            if ($status === 'running') {
                $status = 'queued';
            } elseif ($status === 'done' && !empty($state['warnings'])) {
                $status = 'warning';
            }
            if (isset($rank[$status]) && $rank[$status] > $rank[$summary]) {
                $summary = $status;
            }
        }
        $order->update_meta_data(self::SUMMARY_KEY, $summary);
        $order->save_meta_data();
    }

    /** Snapshot for an item; builds one from the current template for orders placed before snapshots existed. */
    public function ensure_snapshot($order, $item)
    {
        $snapshot = $item->get_meta(self::SNAPSHOT_KEY);
        if (is_array($snapshot) && !empty($snapshot['layers'])) {
            return $snapshot;
        }

        $design = $item->get_meta('_altegena_design_data');
        if (!is_array($design)) {
            return new WP_Error('no_design', 'Bu kalemde davetiye tasarım verisi yok.');
        }

        $snapshot = Altegena_Design_Config::build_snapshot($item->get_product_id(), $item->get_variation_id(), $design, true);
        if (is_wp_error($snapshot)) {
            return $snapshot;
        }
        $item->update_meta_data(self::SNAPSHOT_KEY, wp_slash($snapshot));
        $item->save_meta_data();
        return $snapshot;
    }

    /* ---------------------------------------------------------------------
     * Queueing and jobs
     * ------------------------------------------------------------------- */

    public function enqueue_order($order_id)
    {
        $order = $order_id instanceof WC_Order ? $order_id : wc_get_order($order_id);
        if (!$order) {
            return;
        }

        foreach (self::invitation_items($order) as $item) {
            $state = self::get_state($item);
            // Finished items are only regenerated on demand from the order screen.
            if (isset($state['status']) && in_array($state['status'], array('queued', 'running', 'done'), true)) {
                continue;
            }
            $this->enqueue_item($order, $item);
        }
    }

    public function enqueue_item($order, $item)
    {
        if (!function_exists('as_enqueue_async_action')) {
            $this->generate_item($order, $item, false);
            return;
        }

        as_enqueue_async_action(self::JOB_HOOK, self::job_args($order, $item), self::JOB_GROUP, true);
        $state = self::get_state($item);
        $state['status'] = 'queued';
        $state['queued_at'] = gmdate('c');
        $this->save_state($order, $item, $state);
    }

    private static function job_args($order, $item)
    {
        return array('order_id' => (int) $order->get_id(), 'item_id' => (int) $item->get_id());
    }

    public function run_job($order_id, $item_id)
    {
        $order = wc_get_order($order_id);
        $item = $order ? $order->get_item($item_id) : false;
        if (!$item || !self::is_invitation_item($item)) {
            return;
        }

        $result = $this->generate_item($order, $item, false);
        if (!is_wp_error($result) || !function_exists('as_schedule_single_action')) {
            return;
        }

        if ($result->get_error_code() === 'locked') {
            as_schedule_single_action(time() + 60, self::JOB_HOOK, self::job_args($order, $item), self::JOB_GROUP);
            return;
        }

        $state = self::get_state($item);
        $attempts = isset($state['attempts']) ? (int) $state['attempts'] : 0;
        if (!in_array($result->get_error_code(), self::PERMANENT_ERRORS, true) && $attempts < self::MAX_ATTEMPTS) {
            as_schedule_single_action(time() + 120 * max(1, $attempts), self::JOB_HOOK, self::job_args($order, $item), self::JOB_GROUP);
        }
    }

    /* ---------------------------------------------------------------------
     * Generation
     * ------------------------------------------------------------------- */

    /**
     * Render both PDFs for one order item into storage and record the state.
     * Skips work when nothing that affects the output changed, unless $force.
     *
     * @return array|WP_Error state
     */
    public function generate_item($order, $item, $force = false)
    {
        $lock = 'altegena_print_lock_' . $item->get_id();
        if (!add_option($lock, time(), '', 'no')) {
            if (time() - (int) get_option($lock) < self::LOCK_TTL) {
                return new WP_Error('locked', 'Bu kalemin PDF\'leri şu anda oluşturuluyor.');
            }
            update_option($lock, time(), false);
        }

        $previous = self::get_state($item);
        try {
            $snapshot = $this->ensure_snapshot($order, $item);
            if (is_wp_error($snapshot)) {
                return $this->fail($order, $item, $previous, $snapshot);
            }

            $notes = array();
            if (!empty($snapshot['backfilled'])) {
                $notes[] = 'Sipariş eski: tasarım, siparişteki metinler ile ürünün güncel şablonundan oluşturuldu.';
            }
            $snapshot = self::with_current_print_settings($snapshot, $notes);
            $overrides = self::get_overrides($item);
            $hash = self::input_hash($snapshot, $overrides);

            if (!$force && isset($previous['status'], $previous['input_hash']) && $previous['status'] === 'done'
                && $previous['input_hash'] === $hash && $this->files_exist($order, $previous)) {
                return $previous;
            }

            $running = $previous;
            $running['status'] = 'running';
            $running['attempts'] = (isset($previous['attempts']) ? (int) $previous['attempts'] : 0) + 1;
            $this->save_state($order, $item, $running);

            if (function_exists('set_time_limit')) {
                @set_time_limit(120);
            }
            wp_raise_memory_limit('admin');
            $started = microtime(true);

            $result = Altegena_Print_Generator::render($snapshot, self::override_texts($overrides));
            if (is_wp_error($result)) {
                return $this->fail($order, $item, $running, $result);
            }

            $base = preg_replace('/[^A-Za-z0-9-]/', '', sprintf('siparis-%s-kalem-%d-r%d', $order->get_order_number(), $item->get_id(), $overrides['rev']))
                . '-' . strtolower(wp_generate_password(6, false, false));
            $files = array();
            foreach (array('outline' => 'konturlu', 'text' => 'metinli') as $kind => $suffix) {
                $name = $base . '-' . $suffix . '.pdf';
                $written = Altegena_Print_Storage::write($order, $name, $result[$kind]);
                if (is_wp_error($written)) {
                    return $this->fail($order, $item, $running, $written);
                }
                $files[$kind] = array('file' => $name, 'bytes' => strlen($result[$kind]));
            }

            // The new files are in place, so the previous revision's files can go.
            if (!empty($previous['files']) && is_array($previous['files'])) {
                foreach ($previous['files'] as $old) {
                    if (!empty($old['file'])) {
                        Altegena_Print_Storage::delete_file($order, $old['file']);
                    }
                }
            }

            $state = array(
                'status' => 'done',
                'engine' => self::ENGINE_VERSION,
                'attempts' => 0,
                'rev' => $overrides['rev'],
                'input_hash' => $hash,
                'generated_at' => gmdate('c'),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'page_mm' => array(
                    round($result['scene']['page']['width_pt'] / 72 * 25.4, 1),
                    round($result['scene']['page']['height_pt'] / 72 * 25.4, 1),
                ),
                'files' => $files,
                'background' => array(
                    'source' => $result['background']['source'],
                    'px' => $result['background']['px'],
                    'dpi' => (int) round($result['background']['dpi']),
                ),
                'warnings' => array_values(array_unique(array_merge($notes, $result['warnings']))),
                'error' => null,
            );
            $this->save_state($order, $item, $state);
            $order->add_order_note(sprintf(
                'Baskı PDF\'leri oluşturuldu: %s (rev %d)%s.',
                $item->get_name(),
                $overrides['rev'],
                $state['warnings'] ? sprintf(' — %d uyarı', count($state['warnings'])) : ''
            ));

            return $state;
        } catch (Throwable $e) {
            return $this->fail($order, $item, $previous, new WP_Error('exception', $e->getMessage()));
        } finally {
            delete_option($lock);
        }
    }

    private function fail($order, $item, $base_state, $error)
    {
        $state = is_array($base_state) ? $base_state : array();
        $message = $error->get_error_message();
        $is_new = !isset($state['error']['message']) || $state['error']['message'] !== $message;

        $state['status'] = 'failed';
        $state['error'] = array('code' => $error->get_error_code(), 'message' => $message, 'at' => gmdate('c'));
        $this->save_state($order, $item, $state);

        if ($is_new) {
            $order->add_order_note(sprintf('Baskı PDF\'i oluşturulamadı (%s): %s', $item->get_name(), $message));
        }
        self::log(sprintf('Sipariş %d, kalem %d: %s', $order->get_id(), $item->get_id(), $message), in_array($error->get_error_code(), self::PERMANENT_ERRORS, true) ? 'warning' : 'error');

        return $error;
    }

    public function files_exist($order, $state)
    {
        foreach (array('outline', 'text') as $kind) {
            if (empty($state['files'][$kind]['file']) || !Altegena_Print_Storage::path($order, $state['files'][$kind]['file'])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Print size and print background are product settings, not customer design:
     * when the snapshot has none (e.g. the admin added them after the order), take
     * them from the current template — only if the canvas pixel size is unchanged.
     */
    private static function with_current_print_settings($snapshot, &$notes)
    {
        $canvas = $snapshot['canvas'];
        $needs_size = empty($canvas['print_width_mm']) || empty($canvas['print_height_mm']);
        $needs_background = empty($canvas['print_bg_image']);
        if (!$needs_size && !$needs_background) {
            return $snapshot;
        }

        $template = Altegena_Design_Config::get_template($snapshot['product_id']);
        if (is_wp_error($template)) {
            return $snapshot;
        }
        $current = Altegena_Design_Config::clean_print_keys($template['config']['canvas']);
        if (!isset($current['width'], $current['height']) || (float) $current['width'] !== (float) $canvas['width'] || (float) $current['height'] !== (float) $canvas['height']) {
            return $snapshot;
        }

        if ($needs_size && !empty($current['print_width_mm']) && !empty($current['print_height_mm'])) {
            $canvas['print_width_mm'] = $current['print_width_mm'];
            $canvas['print_height_mm'] = $current['print_height_mm'];
            $notes[] = 'Baskı ölçüsü siparişten sonra üründen alındı.';
        }
        if ($needs_background && !empty($current['print_bg_image'])) {
            foreach (array('print_bg_image', 'print_bg_image_id', 'print_bg_px') as $key) {
                if (isset($current[$key])) {
                    $canvas[$key] = $current[$key];
                }
            }
            $notes[] = 'Baskı arka planı siparişten sonra üründen alındı.';
        }

        $snapshot['canvas'] = $canvas;
        return $snapshot;
    }

    /** Everything that changes the output: design, corrections, engine, default line-height, font files, background file. */
    private static function input_hash($snapshot, $overrides)
    {
        $parts = array(
            wp_json_encode($snapshot),
            wp_json_encode($overrides['layers']),
            self::ENGINE_VERSION,
            (string) Altegena_Settings::line_height(),
        );

        $registry = Altegena_Print_Font_Registry::get_instance();
        foreach ((array) $snapshot['layers'] as $layer) {
            $style = isset($layer['style']) && is_array($layer['style']) ? $layer['style'] : array();
            $match = $registry->match(
                isset($style['fontFamily']) ? $style['fontFamily'] : '',
                isset($style['fontWeight']) ? $style['fontWeight'] : '',
                isset($style['fontStyle']) ? $style['fontStyle'] : ''
            );
            if (!is_wp_error($match)) {
                $parts[] = Altegena_Print_Font_Registry::file_sha1(Altegena_Print_Font_Registry::print_file($match['face']));
            }
        }

        $background = Altegena_Print_Generator::resolve_background($snapshot['canvas']);
        if (!is_wp_error($background)) {
            $parts[] = $background['path'] . '|' . filesize($background['path']) . '|' . filemtime($background['path']);
        }

        return sha1(implode("\n", $parts));
    }

    private static function override_texts($overrides)
    {
        $texts = array();
        foreach ($overrides['layers'] as $id => $override) {
            if (isset($override['text'])) {
                $texts[(string) $id] = (string) $override['text'];
            }
        }
        return $texts;
    }

    /* ---------------------------------------------------------------------
     * Admin text corrections
     * ------------------------------------------------------------------- */

    /**
     * Store admin corrections as overrides (the snapshot keeps the customer's text).
     *
     * @param array $texts {layer_id: text}
     * @return string[]|WP_Error ids of changed layers
     */
    public function save_texts($order, $item, $texts, $user_id)
    {
        $snapshot = $this->ensure_snapshot($order, $item);
        if (is_wp_error($snapshot)) {
            return $snapshot;
        }

        $layers = array();
        foreach ($snapshot['layers'] as $layer) {
            $layers[(string) $layer['id']] = $layer;
        }

        $overrides = self::get_overrides($item);
        $current = $overrides['layers'];
        $changed = array();
        $now = gmdate('c');

        foreach ((array) $texts as $id => $text) {
            $id = (string) $id;
            if (!isset($layers[$id]) || !is_scalar($text)) {
                continue;
            }
            $text = Altegena_Print_Text::sanitize((string) $text);
            $original = (string) $layers[$id]['text'];
            $effective = isset($current[$id]['text']) ? (string) $current[$id]['text'] : $original;
            if ($text === $effective) {
                continue;
            }

            $changed[$id] = array('from' => $effective, 'to' => $text);
            if ($text === $original) {
                unset($current[$id]);
            } else {
                $current[$id] = array('text' => $text, 'by' => (int) $user_id, 'at' => $now);
            }
        }

        if (!$changed) {
            return array();
        }

        $overrides['rev']++;
        $overrides['layers'] = $current;
        $overrides['history'][] = array('rev' => $overrides['rev'], 'by' => (int) $user_id, 'at' => $now, 'changed' => $changed);
        $overrides['history'] = array_slice($overrides['history'], -50);
        self::write_json_meta($item, self::OVERRIDES_KEY, $overrides);

        $labels = array();
        foreach (array_keys($changed) as $id) {
            $labels[] = $layers[$id]['label'] !== '' ? $layers[$id]['label'] : $id;
        }
        $order->add_order_note(sprintf('Baskı metni düzenlendi (%s, rev %d): %s', $item->get_name(), $overrides['rev'], implode(', ', $labels)), 0, true);

        return array_keys($changed);
    }

    /* ---------------------------------------------------------------------
     * Cleanup
     * ------------------------------------------------------------------- */

    public function delete_order_files($order_id)
    {
        $order = $order_id instanceof WC_Order ? $order_id : wc_get_order($order_id);
        if ($order) {
            Altegena_Print_Storage::delete_order($order);
        }
    }

    public function delete_legacy_order_files($post_id)
    {
        if (get_post_type($post_id) === 'shop_order') {
            $this->delete_order_files($post_id);
        }
    }

    public static function log($message, $level = 'info')
    {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->log($level, $message, array('source' => 'altegena-print'));
        }
    }
}
