<?php
/**
 * Builds print PDFs from a design snapshot.
 *
 * One layout feeds every output; this class resolves the background (print
 * background first, then the site background), checks its resolution and
 * assembles the PDF.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Altegena_Print_Generator
{
    const MIN_DPI = 300;

    /**
     * Both print PDFs from a single layout: 'outline' (text converted to vector
     * paths, no fonts) and 'text' (selectable text with embedded fonts).
     *
     * @return array|WP_Error {outline, text, scene, background, warnings}
     */
    public static function render($snapshot, $texts = array())
    {
        $scene = Altegena_Print_Layout::layout($snapshot, $texts);
        if (is_wp_error($scene)) {
            return $scene;
        }

        $background = self::resolve_background($snapshot['canvas']);
        if (is_wp_error($background)) {
            return $background;
        }

        $width = $scene['page']['width_pt'];
        $height = $scene['page']['height_pt'];

        $writer = new Altegena_Print_Pdf_Writer();
        $image = $writer->add_image($background['path']);
        if (is_wp_error($image)) {
            return $image;
        }
        $outline = new Altegena_Print_Outline_Emitter();
        $writer->add_page($width, $height, $outline->content($scene, '/Bg'), sprintf('/XObject << /Bg %d 0 R >>', $image['id']));
        $outline_pdf = $writer->output('Davetiye (konturlu)');
        unset($writer);

        $writer = new Altegena_Print_Pdf_Writer();
        $image = $writer->add_image($background['path']);
        if (is_wp_error($image)) {
            return $image;
        }
        $text = new Altegena_Print_Text_Emitter($writer);
        $content = $text->content($scene, '/Bg');
        $text->finalize();
        $writer->add_page($width, $height, $content, trim(sprintf('/XObject << /Bg %d 0 R >> %s', $image['id'], $text->font_resources())));

        return array(
            'outline' => $outline_pdf,
            'text' => $writer->output('Davetiye (metinli)'),
            'scene' => $scene,
            'background' => $background,
            'warnings' => array_values(array_unique(array_merge($scene['warnings'], $background['warnings'], $text->warnings()))),
        );
    }

    /**
     * @return array|WP_Error {path, source, px: [w, h], dpi, warnings}
     */
    public static function resolve_background($canvas)
    {
        $path = null;
        $source = 'bg_image';
        $reference = isset($canvas['bg_image']) ? (string) $canvas['bg_image'] : '';

        if (!empty($canvas['print_bg_image_id'])) {
            $attached = get_attached_file((int) $canvas['print_bg_image_id']);
            if ($attached && is_readable($attached)) {
                $path = $attached;
                $source = 'print_bg';
            }
        }
        if ($path === null && !empty($canvas['print_bg_image'])) {
            $path = self::url_to_path((string) $canvas['print_bg_image']);
            $source = 'print_bg';
            $reference = (string) $canvas['print_bg_image'];
        }
        if ($path === null && $source !== 'print_bg') {
            $path = self::url_to_path($reference);
        }

        if ($path === null) {
            return new WP_Error('background_missing', sprintf('Arka plan görseli bulunamadı: %s', $reference !== '' ? $reference : '(boş)'));
        }

        $size = @getimagesize($path);
        if (!$size) {
            return new WP_Error('background_unreadable', sprintf('Arka plan görseli okunamadı: %s', basename($path)));
        }

        $warnings = array();
        $dpi = 0;
        if (!empty($canvas['print_width_mm']) && !empty($canvas['print_height_mm'])) {
            $dpi = min($size[0] / ((float) $canvas['print_width_mm'] / 25.4), $size[1] / ((float) $canvas['print_height_mm'] / 25.4));
            if ($dpi < self::MIN_DPI) {
                $warnings[] = sprintf('Arka plan çözünürlüğü düşük: %d DPI (önerilen ≥ %d)%s.', round($dpi), self::MIN_DPI, $source === 'print_bg' ? '' : ' — ürüne yüksek çözünürlüklü baskı arka planı ekleyin');
            }
        }
        if (!empty($canvas['width']) && !empty($canvas['height'])) {
            $delta = abs(($size[0] / $size[1]) / ((float) $canvas['width'] / (float) $canvas['height']) - 1);
            if ($delta > 0.01) {
                $warnings[] = sprintf('Arka plan oranı tuvalle uyuşmuyor (%%%.1f); görsel sayfaya esnetilir.', $delta * 100);
            }
        }

        return array(
            'path' => $path,
            'source' => $source,
            'px' => array($size[0], $size[1]),
            'dpi' => $dpi,
            'warnings' => $warnings,
        );
    }

    /**
     * Map an uploads or plugin URL to a local file. Only the path part is used,
     * so templates saved on another host (dev/live) still resolve; remote files
     * are never downloaded.
     */
    public static function url_to_path($url)
    {
        $url_path = rawurldecode((string) wp_parse_url($url, PHP_URL_PATH));
        if ($url_path === '') {
            return null;
        }

        $candidates = array();
        $uploads = wp_get_upload_dir();
        if (preg_match('#/wp-content/uploads/(.+)$#', $url_path, $match)) {
            $candidates[] = array($uploads['basedir'], $uploads['basedir'] . '/' . $match[1]);
        }
        if (preg_match('#/wp-content/plugins/altegena-invitation-editor/(.+)$#', $url_path, $match)) {
            $candidates[] = array(ALTEGENA_PLUGIN_DIR, ALTEGENA_PLUGIN_DIR . $match[1]);
        }

        foreach ($candidates as $candidate) {
            list($base, $file) = $candidate;
            $real = realpath($file);
            $real_base = realpath($base);
            if ($real !== false && $real_base !== false && strpos($real, rtrim($real_base, '/') . '/') === 0 && is_readable($real)) {
                return $real;
            }
        }
        return null;
    }

    /** Compact layout description for comparing against the browser DOM. */
    public static function layout_debug($scene)
    {
        $layers = array();
        foreach ($scene['layers'] as $layer) {
            $lines = array();
            foreach ($layer['lines'] as $line) {
                $lines[] = array(
                    'text' => $line['text'],
                    'x' => round($line['x'], 3),
                    'width' => round($line['width'], 3),
                    'baseline' => $line['baseline'],
                    'glyphs' => count($line['glyphs']),
                );
            }
            $layers[] = array(
                'id' => $layer['id'],
                'family' => $layer['family'],
                'file' => $layer['file'],
                'size' => $layer['size'],
                'line_height' => $layer['line_height'],
                'ascent' => $layer['ascent'],
                'descent' => $layer['descent'],
                'synthetic_bold' => $layer['synthetic_bold'],
                'synthetic_italic' => $layer['synthetic_italic'],
                'box' => array_map(function ($v) {
                    return round($v, 3);
                }, $layer['box']),
                'lines' => $lines,
            );
        }
        return array('canvas' => $scene['canvas'], 'page' => $scene['page'], 'layers' => $layers);
    }
}
