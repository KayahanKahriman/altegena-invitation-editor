<?php
/**
 * Font registry for print: which font file draws a given CSS font request.
 *
 * Built from the same @font-face rules the customer's browser uses
 * (assets/css/fonts.css) and applies CSS font matching within one family.
 * There is deliberately NO fallback font: an unknown family or a missing file
 * is an error, never a silent substitution.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Altegena_Print_Font_Registry
{
    private static $instance = null;
    private static $sha1_cache = array();

    /** lowercase family => list of faces */
    private $faces = array();

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->parse_css(ALTEGENA_PLUGIN_DIR . 'assets/css/fonts.css');
    }

    private function parse_css($css_path)
    {
        $css = is_readable($css_path) ? file_get_contents($css_path) : '';
        if (!$css) {
            return;
        }
        $css = preg_replace('#/\*.*?\*/#s', '', $css);
        if (!preg_match_all('/@font-face\s*\{([^}]*)\}/i', $css, $blocks)) {
            return;
        }

        $css_dir = dirname($css_path) . '/';
        foreach ($blocks[1] as $block) {
            $family = self::clean_family(self::css_property($block, 'font-family'));
            if ($family === '' || !preg_match('/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/i', $block, $url)) {
                continue;
            }

            $file = $css_dir . $url[1];
            $real = realpath($file);
            $this->faces[strtolower($family)][] = array(
                'family' => $family,
                'weight' => self::normalize_weight(self::css_property($block, 'font-weight')),
                'style' => self::normalize_style(self::css_property($block, 'font-style')),
                'file' => $real !== false ? $real : $file,
                'basename' => basename($url[1]),
                'exists' => $real !== false,
            );
        }
    }

    private static function css_property($block, $name)
    {
        if (preg_match('/(?:^|;)\s*' . preg_quote($name, '/') . '\s*:\s*([^;]+)/i', $block, $match)) {
            return trim($match[1]);
        }
        return '';
    }

    /** First family of a CSS font-family value, without quotes. */
    public static function clean_family($value)
    {
        $parts = explode(',', (string) $value);
        return trim(trim($parts[0]), " \t\n\r\0\x0B'\"");
    }

    public static function normalize_weight($value)
    {
        $value = strtolower(trim((string) $value));
        if ($value === '' || $value === 'normal') {
            return 400;
        }
        if ($value === 'bold' || $value === 'bolder') {
            return 700;
        }
        if ($value === 'lighter') {
            return 300;
        }
        if (is_numeric($value)) {
            $weight = (int) round((float) $value);
            return ($weight >= 1 && $weight <= 1000) ? $weight : 400;
        }
        return 400;
    }

    public static function normalize_style($value)
    {
        $value = strtolower(trim((string) $value));
        return (strpos($value, 'italic') === 0 || strpos($value, 'oblique') === 0) ? 'italic' : 'normal';
    }

    /** Display names of all registered families. */
    public function families()
    {
        $names = array();
        foreach ($this->faces as $faces) {
            $names[] = $faces[0]['family'];
        }
        return $names;
    }

    public function all_faces()
    {
        $all = array();
        foreach ($this->faces as $faces) {
            foreach ($faces as $face) {
                $all[] = $face;
            }
        }
        return $all;
    }

    public function faces($family)
    {
        $key = strtolower(self::clean_family($family));
        return isset($this->faces[$key]) ? $this->faces[$key] : array();
    }

    /**
     * Resolve a CSS font request to a face of the SAME family.
     *
     * @return array|WP_Error {face, synthetic_bold, synthetic_italic}
     */
    public function match($family, $weight = 400, $style = 'normal')
    {
        $family = self::clean_family($family);
        $faces = $this->faces($family);
        if ($family === '' || !$faces) {
            return new WP_Error('font_missing', sprintf('Font dosyası yok: %s', $family !== '' ? $family : '(boş)'), array('family' => $family));
        }

        $weight = self::normalize_weight($weight);
        $style = self::normalize_style($style);

        $pool = array();
        foreach ($faces as $face) {
            if ($face['style'] === $style) {
                $pool[] = $face;
            }
        }
        if (!$pool) {
            $pool = $faces;
        }

        $face = self::pick_weight($pool, $weight);
        if (!$face['exists']) {
            return new WP_Error('font_missing', sprintf('Font dosyası bulunamadı: %s', $face['basename']), array('family' => $family));
        }

        return array(
            'face' => $face,
            // Browsers synthesize bold when >=600 is requested and the face is lighter than 600.
            'synthetic_bold' => $weight >= 600 && $face['weight'] < 600,
            'synthetic_italic' => $style === 'italic' && $face['style'] !== 'italic',
        );
    }

    /** CSS Fonts weight matching. */
    private static function pick_weight($faces, $desired)
    {
        $by_weight = array();
        foreach ($faces as $face) {
            if (!isset($by_weight[$face['weight']])) {
                $by_weight[$face['weight']] = $face;
            }
        }
        if (isset($by_weight[$desired])) {
            return $by_weight[$desired];
        }

        $weights = array_keys($by_weight);
        sort($weights);
        $descending = array_reverse($weights);
        $order = array();

        if ($desired >= 400 && $desired <= 500) {
            foreach ($weights as $w) {
                if ($w > $desired && $w <= 500) {
                    $order[] = $w;
                }
            }
            foreach ($descending as $w) {
                if ($w < $desired) {
                    $order[] = $w;
                }
            }
            foreach ($weights as $w) {
                if ($w > 500) {
                    $order[] = $w;
                }
            }
        } elseif ($desired < 400) {
            foreach ($descending as $w) {
                if ($w < $desired) {
                    $order[] = $w;
                }
            }
            foreach ($weights as $w) {
                if ($w > $desired) {
                    $order[] = $w;
                }
            }
        } else {
            foreach ($weights as $w) {
                if ($w > $desired) {
                    $order[] = $w;
                }
            }
            foreach ($descending as $w) {
                if ($w < $desired) {
                    $order[] = $w;
                }
            }
        }

        return $by_weight[$order[0]];
    }

    /**
     * File the print engine reads for a face. CFF-outline fonts have an offline
     * TrueType conversion (same glyph ids/metrics) in assets/fonts/print/.
     */
    public static function print_file($face)
    {
        $converted = ALTEGENA_PLUGIN_DIR . 'assets/fonts/print/' . pathinfo($face['basename'], PATHINFO_FILENAME) . '.ttf';
        return is_readable($converted) ? $converted : $face['file'];
    }

    public static function file_sha1($path)
    {
        if (!isset(self::$sha1_cache[$path])) {
            self::$sha1_cache[$path] = is_readable($path) ? (string) sha1_file($path) : '';
        }
        return self::$sha1_cache[$path];
    }
}
