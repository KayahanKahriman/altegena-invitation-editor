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
    /**
     * Fonts templates name but the plugin doesn't ship, mapped to a bundled
     * metric-compatible replacement (same advance widths and vertical metrics,
     * so the layout doesn't move). Keys are normalize_name() forms.
     */
    const METRIC_COMPATIBLE = array(
        'timesnewroman' => 'Liberation Serif',
        'timesroman' => 'Liberation Serif',
        'times' => 'Liberation Serif',
        'arial' => 'Liberation Sans',
        'arialmt' => 'Liberation Sans',
    );

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

            // A cache-busting query (e.g. "Font-TR.ttf?v=2") is part of the URL, not the file name.
            $src = preg_replace('/[?#].*$/', '', $url[1]);
            $file = $css_dir . $src;
            $real = realpath($file);
            $this->faces[strtolower($family)][] = array(
                'family' => $family,
                'weight' => self::normalize_weight(self::css_property($block, 'font-weight')),
                'style' => self::normalize_style(self::css_property($block, 'font-style')),
                'file' => $real !== false ? $real : $file,
                'basename' => basename($src),
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

    /**
     * The bundled equivalent of an unknown family, only if that exact
     * weight/style file exists:
     * - spelling: an existing family with the same name once spaces/hyphens
     *   are ignored (e.g. "TrajanPro-Bold" -> "Trajan Pro" + bold);
     * - PostScript name: a bundled file whose PostScript name is the family
     *   (e.g. "NeutrafaceCondensed-Medium" -> "Neutraface Condensed");
     * - metric-compatible replacement (METRIC_COMPATIBLE, e.g. "Times New
     *   Roman" -> "Liberation Serif").
     *
     * Font denetimi's safe fix applies it to templates and resolve() to orders
     * placed before that fix. It is not a general fallback: any other unknown
     * family stays an error.
     *
     * @param string $family from clean_family()
     * @param int    $weight from normalize_weight()
     * @param string $style  from normalize_style()
     * @return array|null style properties to set (null value = remove the property)
     */
    public function equivalent($family, $weight, $style)
    {
        $suffixes = array(
            'thin' => array(100, null), 'extralight' => array(200, null), 'light' => array(300, null),
            'regular' => array(400, null), 'book' => array(400, null), 'medium' => array(500, null),
            'semibold' => array(600, null), 'demibold' => array(600, null), 'bold' => array(700, null),
            'extrabold' => array(800, null), 'black' => array(900, null),
            'italic' => array(null, 'italic'), 'bolditalic' => array(700, 'italic'),
        );

        $normalized = self::normalize_name($family);
        $candidates = array(array($normalized, $weight, $style));
        if (preg_match('/^(.+?)[\s_-]*(' . implode('|', array_keys($suffixes)) . ')$/i', $family, $match)) {
            list($suffix_weight, $suffix_style) = $suffixes[strtolower($match[2])];
            $candidates[] = array(
                self::normalize_name($match[1]),
                $suffix_weight !== null ? $suffix_weight : $weight,
                $suffix_style !== null ? $suffix_style : $style,
            );
        }

        // The same font under its PostScript name, unless the layer asks for another weight/style.
        $default_request = $weight === 400 && $style === 'normal';
        foreach ($this->all_faces() as $face) {
            if (!$face['exists'] || !($default_request || ($face['weight'] === $weight && $face['style'] === $style))) {
                continue;
            }
            $font = Altegena_Print_Font::load(self::print_file($face));
            if (!is_wp_error($font) && self::normalize_name($font->postscript_name()) === $normalized) {
                $candidates[] = array(self::normalize_name($face['family']), $face['weight'], $face['style']);
                break;
            }
        }

        $replacements = self::METRIC_COMPATIBLE;
        if (isset($replacements[$normalized])) {
            $candidates[] = array(self::normalize_name($replacements[$normalized]), $weight, $style);
        }

        foreach ($candidates as $candidate) {
            list($name, $target_weight, $target_style) = $candidate;
            foreach ($this->families() as $known) {
                if (self::normalize_name($known) !== $name) {
                    continue;
                }
                foreach ($this->faces($known) as $face) {
                    if ($face['exists'] && $face['weight'] === $target_weight && $face['style'] === $target_style) {
                        return array(
                            'fontFamily' => $known,
                            'fontWeight' => $target_weight === 400 ? null : ($target_weight === 700 ? 'bold' : (string) $target_weight),
                            'fontStyle' => $target_style === 'italic' ? 'italic' : null,
                        );
                    }
                }
            }
        }

        return null;
    }

    /**
     * match(), or for an unknown family its equivalent(). A match found
     * through the equivalent carries 'equivalent_of' (the requested family)
     * so the caller can report it.
     *
     * @return array|WP_Error
     */
    public function resolve($family, $weight = 400, $style = 'normal')
    {
        $match = $this->match($family, $weight, $style);
        if (!is_wp_error($match)) {
            return $match;
        }

        $family = self::clean_family($family);
        $fix = $family === '' ? null : $this->equivalent($family, self::normalize_weight($weight), self::normalize_style($style));
        if (!$fix) {
            return $match;
        }
        $resolved = $this->match(
            $fix['fontFamily'],
            $fix['fontWeight'] !== null ? $fix['fontWeight'] : 400,
            $fix['fontStyle'] !== null ? $fix['fontStyle'] : 'normal'
        );
        if (is_wp_error($resolved)) {
            return $match;
        }
        $resolved['equivalent_of'] = $family;
        return $resolved;
    }

    /** Lowercase letters and digits only ("TrajanPro-Bold" -> "trajanprobold"). */
    private static function normalize_name($name)
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower((string) $name));
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
