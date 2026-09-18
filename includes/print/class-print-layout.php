<?php
/**
 * Print layout: reproduces the browser's placement of every glyph.
 *
 * Mirrors editor.js + CSS exactly:
 * - layer div `position:absolute; white-space:pre` → no wrapping, box width = widest line;
 * - CSS left = (left + width/2)% when both are set (else left as given), top = top%;
 * - transform translateX(-50%) rotate(θ) around the box center;
 * - line-height from style (number × font-size, px, %, normal) or the pinned default;
 * - Blink metrics: ascent/descent rounded to px, half-leading floored;
 * - letter-spacing after every glyph cluster (and ligatures off when it's non-zero).
 *
 * Output coordinates are canvas pixels (y down). The PDF page is the canvas at
 * CSS size (1 px = 0.75 pt); the output is vector, so the print shop scales it
 * freely. No fallback font: a missing font file or glyph is an error.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Altegena_Print_Layout
{
    const LANGUAGE = 'TRK ';
    const DEFAULT_FONT_SIZE = 16.0;
    /** CSS reference pixel: 1px = 1/96 in = 0.75 pt. */
    const PX_TO_PT = 0.75;

    /**
     * @param array $snapshot _altegena_print_snapshot (canvas; layers with text)
     * @param array $texts    optional {layer_id: text} overriding snapshot texts
     * @return array|WP_Error scene
     */
    public static function layout($snapshot, $texts = array())
    {
        $canvas = isset($snapshot['canvas']) && is_array($snapshot['canvas']) ? $snapshot['canvas'] : array();
        $width = isset($canvas['width']) ? (float) $canvas['width'] : 0.0;
        $height = isset($canvas['height']) ? (float) $canvas['height'] : 0.0;
        if ($width <= 0 || $height <= 0) {
            return new WP_Error('bad_canvas', 'Tuval ölçüsü geçersiz.');
        }

        $registry = Altegena_Print_Font_Registry::get_instance();
        $default_line_height = Altegena_Settings::line_height();
        $texts = is_array($texts) ? $texts : array();
        $layers = array();
        $missing = array();
        $warnings = array();
        $equivalents = array();

        foreach ((array) $snapshot['layers'] as $layer) {
            if (!is_array($layer) || !isset($layer['id'])) {
                continue;
            }
            $id = (string) $layer['id'];
            $label = isset($layer['label']) && $layer['label'] !== '' ? (string) $layer['label'] : $id;
            $style = isset($layer['style']) && is_array($layer['style']) ? $layer['style'] : array();
            $text = array_key_exists($id, $texts) ? (string) $texts[$id] : (isset($layer['text']) ? (string) $layer['text'] : '');

            $match = $registry->resolve(self::prop($style, 'fontFamily'), self::prop($style, 'fontWeight'), self::prop($style, 'fontStyle'));
            if (is_wp_error($match)) {
                return new WP_Error('font_missing', sprintf('"%s" katmanı: %s', $label, $match->get_error_message()), array('layer' => $id));
            }
            if (isset($match['equivalent_of'])) {
                $equivalents[$match['equivalent_of'] . '|' . $match['face']['family']][] = $label;
            }
            $font = Altegena_Print_Font::load(Altegena_Print_Font_Registry::print_file($match['face']));
            if (is_wp_error($font)) {
                return $font;
            }
            if (!$font->has_truetype_outlines()) {
                return new WP_Error('font_unsupported', sprintf('"%s" fontunun baskı için TrueType sürümü yok (%s).', $match['face']['family'], $match['face']['basename']), array('layer' => $id));
            }

            // A single trailing line feed doesn't create an extra line box.
            $body = preg_replace("/\n\z/", '', $text);
            $line_texts = ($text === '') ? array() : explode("\n", $body);

            $missing_chars = $font->missing_codepoints(implode('', $line_texts));
            if ($missing_chars) {
                $missing[] = array('layer' => $id, 'label' => $label, 'font' => $match['face']['family'], 'chars' => $missing_chars);
                continue;
            }

            $size = self::css_px(self::prop($style, 'fontSize'), self::DEFAULT_FONT_SIZE);
            $upm = $font->units_per_em();
            $scale = $size / $upm;
            $letter_spacing = self::css_px(self::prop($style, 'letterSpacing'), 0.0);
            $line_height = self::line_height(self::prop($style, 'lineHeight'), $size, $default_line_height, $font);

            $metrics = $font->vertical_metrics();
            $ascent = round($metrics['ascent'] * $scale);
            $descent = round($metrics['descent'] * $scale);
            $half_leading = floor(($line_height - ($ascent + $descent)) / 2);

            $shaper = Altegena_Print_Shaper::for_font($font);
            $options = array(
                'language' => self::LANGUAGE,
                'disable' => ($letter_spacing != 0.0) ? Altegena_Print_Shaper::LETTER_SPACING_DISABLED : array(),
            );

            $lines = array();
            $box_width = 0.0;
            foreach ($line_texts as $index => $line_text) {
                $glyphs = $shaper->shape(Altegena_Print_Text::codepoints($line_text), $options);
                $pen = 0.0;
                $placed = array();
                $count = count($glyphs);
                foreach ($glyphs as $g => $glyph) {
                    $placed[] = array(
                        'gid' => $glyph['gid'],
                        'x' => $pen + $glyph['dx'] * $scale,
                        'y' => $glyph['dy'] * $scale,
                        'start' => $glyph['start'],
                        'end' => $glyph['end'],
                    );
                    $pen += $glyph['advance'] * $scale;
                    $cluster_end = ($g === $count - 1) || $glyphs[$g + 1]['start'] !== $glyph['start'];
                    if ($letter_spacing != 0.0 && $cluster_end) {
                        $pen += $letter_spacing;
                    }
                }
                $lines[] = array(
                    'text' => $line_text,
                    'width' => $pen,
                    'baseline' => $index * $line_height + $half_leading + $ascent,
                    'glyphs' => $placed,
                );
                $box_width = max($box_width, $pen);
            }

            // Blink sizes the shrink-to-fit box in 1/64 px (LayoutUnit), rounding up.
            $box_width = ceil($box_width * 64) / 64;
            $box_height = count($lines) * $line_height;

            $align = strtolower(trim(self::prop($style, 'textAlign')));
            foreach ($lines as &$line) {
                if ($align === 'center') {
                    $line['x'] = ($box_width - $line['width']) / 2;
                } elseif ($align === 'right' || $align === 'end') {
                    $line['x'] = $box_width - $line['width'];
                } else {
                    $line['x'] = 0.0;
                }
            }
            unset($line);

            $left = self::prop($style, 'left');
            $width_value = self::prop($style, 'width');
            if ($left !== '' && $width_value !== '') {
                // editor.js: style.left = (left + width/2) + '%'
                $x0 = (self::css_number($left) + self::css_number($width_value) / 2) / 100 * $width;
            } elseif ($left !== '') {
                $x0 = self::css_length($left, $width);
            } else {
                $x0 = 0.0;
            }
            $top = self::css_length(self::prop($style, 'top'), $height);

            $theta = deg2rad(self::css_number(self::prop($style, 'rotate')));
            $cos = cos($theta);
            $sin = sin($theta);
            $matrix = self::multiply(
                self::multiply(array(1, 0, 0, 1, -$box_width / 2, -$box_height / 2), array($cos, $sin, -$sin, $cos, 0, 0)),
                array(1, 0, 0, 1, $x0, $top + $box_height / 2)
            );

            $color = self::color(self::prop($style, 'color'));
            if ($color === null) {
                $color = array(0.0, 0.0, 0.0);
                if (self::prop($style, 'color') !== '') {
                    $warnings[] = sprintf('"%s" katmanının rengi okunamadı; siyah kullanıldı.', $label);
                }
            }

            $layers[] = array(
                'id' => $id,
                'label' => $label,
                'font' => $font,
                'family' => $match['face']['family'],
                'file' => basename($font->path()),
                'size' => $size,
                'upm' => $upm,
                'color' => $color,
                'synthetic_bold' => $match['synthetic_bold'],
                'synthetic_italic' => $match['synthetic_italic'],
                'embeddable' => $font->embedding_allowed(),
                'letter_spacing' => $letter_spacing,
                'line_height' => $line_height,
                'ascent' => $ascent,
                'descent' => $descent,
                'box' => array('x' => $x0, 'top' => $top, 'width' => $box_width, 'height' => $box_height),
                'matrix' => $matrix,
                'lines' => $lines,
            );
        }

        // Orders placed before a template's Font denetimi fix still name the old family.
        foreach ($equivalents as $pair => $labels) {
            list($from, $to) = explode('|', $pair, 2);
            $warnings[] = sprintf('Şablondaki "%s" fontu yerine Font denetimindeki eşdeğeri "%s" kullanıldı (%s).', $from, $to, implode(', ', array_unique($labels)));
        }

        if ($missing) {
            $parts = array();
            foreach ($missing as $entry) {
                $parts[] = sprintf('%s (%s): %s', $entry['label'], $entry['font'], implode(' ', array_map(array('Altegena_Print_Text', 'chr_utf8'), $entry['chars'])));
            }
            return new WP_Error('missing_glyph', 'Fontta olmayan karakterler: ' . implode('; ', $parts), $missing);
        }

        return array(
            'canvas' => array('width' => $width, 'height' => $height),
            'page' => array('width_pt' => $width * self::PX_TO_PT, 'height_pt' => $height * self::PX_TO_PT, 'k' => self::PX_TO_PT),
            'layers' => $layers,
            'warnings' => $warnings,
        );
    }

    /** Page matrix: canvas px (y down) → PDF points (y up). */
    public static function page_matrix($scene)
    {
        $k = $scene['page']['k'];
        return array($k, 0, 0, -$k, 0, $scene['page']['height_pt']);
    }

    /** Compose affine matrices [a b c d e f]: apply $m first, then $n. */
    public static function multiply($m, $n)
    {
        return array(
            $m[0] * $n[0] + $m[1] * $n[2],
            $m[0] * $n[1] + $m[1] * $n[3],
            $m[2] * $n[0] + $m[3] * $n[2],
            $m[2] * $n[1] + $m[3] * $n[3],
            $m[4] * $n[0] + $m[5] * $n[2] + $n[4],
            $m[4] * $n[1] + $m[5] * $n[3] + $n[5],
        );
    }

    private static function prop($style, $key)
    {
        return isset($style[$key]) && is_scalar($style[$key]) ? trim((string) $style[$key]) : '';
    }

    private static function css_number($value)
    {
        return preg_match('/^\s*([-+]?\d*\.?\d+)/', (string) $value, $match) ? (float) $match[1] : 0.0;
    }

    private static function css_px($value, $default)
    {
        return preg_match('/^\s*([-+]?\d*\.?\d+)/', (string) $value, $match) ? (float) $match[1] : $default;
    }

    /** A length that is either px or a percentage of $reference (templates use %). */
    private static function css_length($value, $reference)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return 0.0;
        }
        if (substr($value, -2) === 'px') {
            return self::css_number($value);
        }
        return self::css_number($value) / 100 * $reference;
    }

    private static function line_height($value, $size, $default, $font)
    {
        $value = strtolower(trim((string) $value));
        $px = null;

        if ($value === 'normal') {
            $metrics = $font->vertical_metrics();
            $scale = $size / $font->units_per_em();
            $px = round($metrics['ascent'] * $scale) + round($metrics['descent'] * $scale) + round($metrics['line_gap'] * $scale);
        } elseif ($value !== '' && preg_match('/^([-+]?\d*\.?\d+)(px|%)?$/', $value, $match) && (float) $match[1] >= 0) {
            $number = (float) $match[1];
            if (!isset($match[2]) || $match[2] === '') {
                $px = $number * $size;
            } elseif ($match[2] === 'px') {
                $px = $number;
            } else {
                $px = $number / 100 * $size;
            }
        }

        if ($px === null) {
            $px = $default * $size; // inherited from the canvas' pinned line-height
        }
        return round($px * 64) / 64; // LayoutUnit precision
    }

    /** CSS color → RGB floats 0..1, or null when unparseable. */
    public static function color($value)
    {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return null;
        }

        $named = array('black' => '000000', 'white' => 'ffffff', 'red' => 'ff0000', 'gray' => '808080', 'grey' => '808080');
        if (isset($named[$value])) {
            $value = '#' . $named[$value];
        }

        if (preg_match('/^#([0-9a-f]{3,8})$/', $value, $match)) {
            $hex = $match[1];
            if (strlen($hex) === 3 || strlen($hex) === 4) {
                $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
            }
            if (strlen($hex) === 6 || strlen($hex) === 8) {
                return array(hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255);
            }
            return null;
        }

        if (preg_match('/^rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)/', $value, $match)) {
            return array(min(255, (float) $match[1]) / 255, min(255, (float) $match[2]) / 255, min(255, (float) $match[3]) / 255);
        }

        return null;
    }
}
