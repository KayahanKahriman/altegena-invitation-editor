<?php
/**
 * WP-CLI development tools for the print engine.
 */

if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) {
    return;
}

class Altegena_Print_CLI
{
    /**
     * Render a print proof PDF for a product template.
     *
     * ## OPTIONS
     *
     * <product_id>
     * : Invitation product ID.
     *
     * [--texts=<json>]
     * : JSON object {layer_id: text} replacing the template texts.
     *
     * [--width-mm=<mm>]
     * : Print width to use when the template has no print size (height follows the canvas ratio).
     *
     * [--out=<dir>]
     * : Output directory. Default: uploads/altegena-print-proof
     *
     * [--layout]
     * : Also write the computed layout as JSON (for comparing with the browser DOM).
     *
     * ## EXAMPLES
     *
     *     wp altegena print-proof 3906 --width-mm=160 --layout
     *
     * @subcommand print-proof
     */
    public function print_proof($args, $assoc_args)
    {
        $product_id = absint($args[0]);

        $text_map = array();
        if (isset($assoc_args['texts'])) {
            $decoded = json_decode($assoc_args['texts'], true);
            if (!is_array($decoded)) {
                WP_CLI::error('--texts geçerli bir JSON nesnesi değil.');
            }
            foreach ($decoded as $id => $text) {
                $text_map[$id] = array('text' => (string) $text);
            }
        }

        $snapshot = Altegena_Design_Config::build_snapshot($product_id, 0, $text_map);
        if (is_wp_error($snapshot)) {
            WP_CLI::error($snapshot->get_error_message());
        }

        if (isset($assoc_args['width-mm'])) {
            $mm = (float) $assoc_args['width-mm'];
            if ($mm <= 0) {
                WP_CLI::error('--width-mm pozitif olmalı.');
            }
            $snapshot['canvas']['print_width_mm'] = $mm;
            $snapshot['canvas']['print_height_mm'] = round($mm * $snapshot['canvas']['height'] / $snapshot['canvas']['width'], 3);
        }

        $uploads = wp_upload_dir();
        $dir = isset($assoc_args['out']) ? rtrim($assoc_args['out'], '/') : $uploads['basedir'] . '/altegena-print-proof';
        if (!wp_mkdir_p($dir)) {
            WP_CLI::error('Çıktı klasörü oluşturulamadı: ' . $dir);
        }

        $started = microtime(true);
        $result = Altegena_Print_Generator::render($snapshot);
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_code() . ': ' . $result->get_error_message());
        }

        $file = sprintf('%s/proof-%d-konturlu.pdf', $dir, $product_id);
        $text_file = sprintf('%s/proof-%d-metinli.pdf', $dir, $product_id);
        file_put_contents($file, $result['outline']);
        file_put_contents($text_file, $result['text']);

        WP_CLI::log(sprintf(
            'Sayfa: %.2f × %.2f mm | arka plan: %s %d×%d (%d DPI) | %d katman | konturlu %s KB, metinli %s KB | %.2f sn | tepe bellek %.1f MB',
            $snapshot['canvas']['print_width_mm'],
            $snapshot['canvas']['print_height_mm'],
            $result['background']['source'],
            $result['background']['px'][0],
            $result['background']['px'][1],
            round($result['background']['dpi']),
            count($result['scene']['layers']),
            number_format(strlen($result['outline']) / 1024, 1),
            number_format(strlen($result['text']) / 1024, 1),
            microtime(true) - $started,
            memory_get_peak_usage(true) / 1048576
        ));
        foreach ($result['warnings'] as $warning) {
            WP_CLI::warning($warning);
        }

        if (isset($assoc_args['layout'])) {
            $layout_file = sprintf('%s/proof-%d-layout.json', $dir, $product_id);
            file_put_contents($layout_file, wp_json_encode(Altegena_Print_Generator::layout_debug($result['scene']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            WP_CLI::log('Yerleşim: ' . $layout_file);
        }

        WP_CLI::success($file);
    }
}

WP_CLI::add_command('altegena', 'Altegena_Print_CLI');
