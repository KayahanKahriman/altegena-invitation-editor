<?php
/**
 * Draws a laid-out scene with selectable text: TrueType fonts embedded as
 * Type0 / CIDFontType2 (Identity-H, CID = glyph id), so the exact shaped
 * glyphs (ligatures, Turkish locl forms) are placed at the computed positions.
 * ToUnicode maps glyphs back to text and every line carries /ActualText, so
 * copy/paste returns the customer's text.
 *
 * Fonts whose license forbids embedding are drawn as outlines instead.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Altegena_Print_Text_Emitter
{
    private $writer;
    private $outline;
    private $fonts = array();
    private $warnings = array();

    public function __construct(Altegena_Print_Pdf_Writer $writer)
    {
        $this->writer = $writer;
        $this->outline = new Altegena_Print_Outline_Emitter();
    }

    public function warnings()
    {
        return array_values(array_unique($this->warnings));
    }

    /**
     * @param array  $scene      Altegena_Print_Layout::layout() result
     * @param string $background XObject resource name or ''
     */
    public function content($scene, $background = '')
    {
        $num = array('Altegena_Print_Pdf_Writer', 'num');
        $out = '';
        if ($background !== '') {
            $out .= sprintf("q %s 0 0 %s 0 0 cm %s Do Q\n", call_user_func($num, $scene['page']['width_pt']), call_user_func($num, $scene['page']['height_pt']), $background);
        }

        $page = Altegena_Print_Layout::page_matrix($scene);
        foreach ($scene['layers'] as $layer) {
            if (!$layer['lines']) {
                continue;
            }
            if (!$layer['embeddable']) {
                $this->warnings[] = sprintf('"%s" fontunun lisansı gömmeye izin vermiyor; bu katmanlar metinli PDF\'te kontur olarak basıldı (seçilemez).', $layer['family']);
                $out .= $this->outline->layer($layer, $page);
                continue;
            }
            $out .= $this->layer($layer, $page);
        }

        return $out;
    }

    private function layer($layer, $page)
    {
        $num = array('Altegena_Print_Pdf_Writer', 'num');
        $key = $this->register_font($layer['font']);
        $font = $layer['font'];
        $size = $layer['size'];
        $upm = $layer['upm'];
        $color = implode(' ', array_map($num, $layer['color']));
        $skew = $layer['synthetic_italic'] ? Altegena_Print_Outline_Emitter::ITALIC_SKEW * $size : 0;

        $out = 'q ' . implode(' ', array_map($num, Altegena_Print_Layout::multiply($layer['matrix'], $page))) . " cm\n" . $color . ' rg';
        if ($layer['synthetic_bold']) {
            // Same outline growth as the outlined PDF: size/24 total stroke, round joins.
            $out .= ' ' . $color . ' RG 1 j ' . call_user_func($num, $size / 24) . ' w';
        }
        $out .= "\nBT " . $this->fonts[$key]['name'] . ' 1 Tf' . ($layer['synthetic_bold'] ? ' 2 Tr' : '') . "\n";

        foreach ($layer['lines'] as $line) {
            if (!$line['glyphs']) {
                continue;
            }
            $out .= '/Span << /ActualText ' . Altegena_Print_Pdf_Writer::text_string($line['text']) . " >> BDC\n";

            $run = '';
            $pen = null;
            $run_y = null;
            foreach ($line['glyphs'] as $glyph) {
                $x = $line['x'] + $glyph['x'];
                $y = $line['baseline'] - $glyph['y'];
                $this->fonts[$key]['glyphs'] += array($glyph['gid'] => self::substr($line['text'], $glyph['start'], $glyph['end'] - $glyph['start']));
                $hex = sprintf('<%04X>', $glyph['gid']);

                if ($pen === null || $y !== $run_y) {
                    if ($run !== '') {
                        $out .= '[' . $run . "] TJ\n";
                    }
                    // Text space: 1 unit = font size px, y up; skew for synthetic italic.
                    $out .= implode(' ', array_map($num, array($size, 0, $skew, -$size, $x, $y))) . " Tm\n";
                    $run = $hex;
                    $run_y = $y;
                } else {
                    // TJ adjustment (1/1000 em, positive moves left): from where the viewer's
                    // advance would put this glyph to where the layout put it.
                    $adjust = ($pen - $x) / $size * 1000;
                    $run .= (abs($adjust) >= 0.001 ? ' ' . call_user_func($num, $adjust) . ' ' : '') . $hex;
                }
                $pen = $x + $font->advance_width($glyph['gid']) * $size / $upm;
            }
            if ($run !== '') {
                $out .= '[' . $run . "] TJ\n";
            }
            $out .= "EMC\n";
        }

        return $out . "ET\nQ\n";
    }

    private function register_font($font)
    {
        $key = $font->path();
        if (!isset($this->fonts[$key])) {
            $this->fonts[$key] = array(
                'name' => '/F' . (count($this->fonts) + 1),
                'id' => $this->writer->reserve_id(),
                'font' => $font,
                'glyphs' => array(),
            );
        }
        return $key;
    }

    /** Write the font objects; call after content() and before the page is added. */
    public function finalize()
    {
        $num = array('Altegena_Print_Pdf_Writer', 'num');
        foreach ($this->fonts as $key => $entry) {
            $font = $entry['font'];
            $scale = 1000 / $font->units_per_em();
            $base_font = strtr(substr(sha1($key), 0, 6), '0123456789abcdef', 'ABCDEFGHIJKLMNOP') . '+' . $font->postscript_name();
            $bytes = $font->bytes();

            $file_id = $this->writer->add_stream('/Length1 ' . strlen($bytes), $bytes);

            $bbox = array();
            foreach ($font->bbox() as $value) {
                $bbox[] = call_user_func($num, $value * $scale);
            }
            $metrics = $font->vertical_metrics();
            $descriptor_id = $this->writer->add_object(sprintf(
                '<< /Type /FontDescriptor /FontName /%s /Flags 4 /FontBBox [%s] /ItalicAngle %s /Ascent %s /Descent %s /CapHeight %s /StemV 80 /FontFile2 %d 0 R >>',
                $base_font,
                implode(' ', $bbox),
                call_user_func($num, $font->italic_angle()),
                call_user_func($num, $metrics['ascent'] * $scale),
                call_user_func($num, -$metrics['descent'] * $scale),
                call_user_func($num, $font->cap_height() * $scale),
                $file_id
            ));

            $glyphs = $entry['glyphs'];
            ksort($glyphs);
            $widths = array();
            foreach (array_keys($glyphs) as $gid) {
                $widths[] = $gid . ' [' . call_user_func($num, $font->advance_width($gid) * $scale) . ']';
            }

            $cid_id = $this->writer->add_object(sprintf(
                '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /%s /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor %d 0 R /DW %s /W [%s] /CIDToGIDMap /Identity >>',
                $base_font,
                $descriptor_id,
                call_user_func($num, $font->advance_width(0) * $scale),
                implode(' ', $widths)
            ));

            $to_unicode_id = $this->writer->add_stream('', self::to_unicode($glyphs));
            $this->writer->set_object($entry['id'], sprintf(
                '<< /Type /Font /Subtype /Type0 /BaseFont /%s /Encoding /Identity-H /DescendantFonts [%d 0 R] /ToUnicode %d 0 R >>',
                $base_font,
                $cid_id,
                $to_unicode_id
            ));
        }
    }

    /** Resource dictionary entry for the fonts used ('' when none). */
    public function font_resources()
    {
        if (!$this->fonts) {
            return '';
        }
        $entries = array();
        foreach ($this->fonts as $entry) {
            $entries[] = $entry['name'] . ' ' . $entry['id'] . ' 0 R';
        }
        return '/Font << ' . implode(' ', $entries) . ' >>';
    }

    private static function to_unicode($glyphs)
    {
        $entries = array();
        foreach ($glyphs as $gid => $text) {
            if ($text === '') {
                continue;
            }
            $utf16 = function_exists('mb_convert_encoding') ? mb_convert_encoding($text, 'UTF-16BE', 'UTF-8') : $text;
            $entries[] = sprintf('<%04X> <%s>', $gid, strtoupper(bin2hex($utf16)));
        }

        $cmap = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
            . "/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
            . "/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n"
            . "1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";
        foreach (array_chunk($entries, 100) as $chunk) {
            $cmap .= count($chunk) . " beginbfchar\n" . implode("\n", $chunk) . "\nendbfchar\n";
        }
        return $cmap . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";
    }

    private static function substr($text, $start, $length)
    {
        return function_exists('mb_substr') ? mb_substr($text, $start, $length, 'UTF-8') : substr($text, $start, $length);
    }
}
