<?php
/**
 * Minimal sfnt (TrueType / OpenType) reader for the print engine.
 *
 * Current scope: table directory, cmap (Unicode -> glyph id) and OS/2 fsType,
 * which the font audit and the snapshot need. The PDF phases add metrics,
 * glyph outlines and OpenType layout tables on top of this same reader.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Altegena_Print_Font
{
    /** Cap on cmap entries so a malformed font can't exhaust memory. */
    const MAX_CMAP_ENTRIES = 200000;

    private static $cache = array();

    private $path;
    private $data;
    private $size;
    private $tables = array();
    private $cmap = null;
    private $symbol_cmap = false;

    /**
     * @return Altegena_Print_Font|WP_Error
     */
    public static function load($path)
    {
        $real = realpath($path);
        if ($real === false || !is_readable($real)) {
            return new WP_Error('font_unreadable', sprintf('Font dosyası okunamadı: %s', basename($path)));
        }

        if (!isset(self::$cache[$real])) {
            $font = new self($real);
            $result = $font->parse_directory();
            if (is_wp_error($result)) {
                return $result;
            }
            self::$cache[$real] = $font;
        }

        return self::$cache[$real];
    }

    private function __construct($path)
    {
        $this->path = $path;
        $this->data = (string) file_get_contents($path);
        $this->size = strlen($this->data);
    }

    public function path()
    {
        return $this->path;
    }

    private function parse_directory()
    {
        $name = basename($this->path);
        if ($this->size < 12) {
            return new WP_Error('font_unsupported', sprintf('Geçersiz font dosyası: %s', $name));
        }

        // 0x00010000 = TrueType, 'OTTO' = CFF OpenType, 'true' = Apple TrueType.
        $version = $this->u32(0);
        if (!in_array($version, array(0x00010000, 0x4F54544F, 0x74727565), true)) {
            return new WP_Error('font_unsupported', sprintf('Desteklenmeyen font biçimi: %s', $name));
        }

        $count = $this->u16(4);
        for ($i = 0; $i < $count; $i++) {
            $record = 12 + $i * 16;
            if ($record + 16 > $this->size) {
                break;
            }
            $offset = $this->u32($record + 8);
            $length = $this->u32($record + 12);
            if ($offset + $length <= $this->size) {
                $this->tables[substr($this->data, $record, 4)] = array('offset' => $offset, 'length' => $length);
            }
        }

        if (!isset($this->tables['cmap'])) {
            return new WP_Error('font_unsupported', sprintf('Font dosyasında cmap tablosu yok: %s', $name));
        }

        return true;
    }

    public function has_table($tag)
    {
        return isset($this->tables[$tag]);
    }

    /** True for CFF-outline OpenType fonts (.otf with 'CFF ' table). */
    public function is_cff()
    {
        return isset($this->tables['CFF ']) || isset($this->tables['CFF2']);
    }

    public function glyph_id($codepoint)
    {
        $this->load_cmap();

        if (isset($this->cmap[$codepoint])) {
            return $this->cmap[$codepoint];
        }
        // Symbol-encoded fonts map Latin-1 into the U+F000 private-use block (as browsers do).
        if ($this->symbol_cmap && $codepoint <= 0xFF && isset($this->cmap[0xF000 + $codepoint])) {
            return $this->cmap[0xF000 + $codepoint];
        }
        return 0;
    }

    public function has_glyph($codepoint)
    {
        return $this->glyph_id($codepoint) > 0;
    }

    /**
     * Code points of $text that this font has no glyph for (line feeds ignored).
     *
     * @return int[]
     */
    public function missing_codepoints($text)
    {
        $missing = array();
        foreach (Altegena_Print_Text::codepoints($text) as $cp) {
            if ($cp !== 0x0A && !isset($missing[$cp]) && !$this->has_glyph($cp)) {
                $missing[$cp] = true;
            }
        }
        return array_keys($missing);
    }

    /** OS/2 fsType embedding flags (0 when the font has no OS/2 table). */
    public function fs_type()
    {
        if (!isset($this->tables['OS/2']) || $this->tables['OS/2']['length'] < 10) {
            return 0;
        }
        return $this->u16($this->tables['OS/2']['offset'] + 8);
    }

    /**
     * Whether the font license bits allow embedding it in a PDF.
     * Restricted (0x0002) without a less restrictive bit, or bitmap-only (0x0200), is not allowed.
     */
    public function embedding_allowed()
    {
        $fs_type = $this->fs_type();
        return (($fs_type & 0x000E) !== 0x0002) && !($fs_type & 0x0200);
    }

    private function load_cmap()
    {
        if ($this->cmap !== null) {
            return;
        }
        $this->cmap = array();

        $base = $this->tables['cmap']['offset'];
        $count = $this->u16($base + 2);

        // Preference: full Unicode (format 12) > BMP Unicode (format 4) > symbol (3,0).
        $candidates = array();
        for ($i = 0; $i < $count; $i++) {
            $record = $base + 4 + $i * 8;
            $platform = $this->u16($record);
            $encoding = $this->u16($record + 2);
            $offset = $base + $this->u32($record + 4);
            if ($offset + 4 > $this->size) {
                continue;
            }
            $format = $this->u16($offset);

            $score = 0;
            if ($format === 12 && (($platform === 3 && $encoding === 10) || $platform === 0)) {
                $score = 4;
            } elseif ($format === 4 && $platform === 3 && $encoding === 1) {
                $score = 3;
            } elseif ($format === 4 && $platform === 0) {
                $score = 2;
            } elseif ($format === 4 && $platform === 3 && $encoding === 0) {
                $score = 1;
            }
            if ($score && !isset($candidates[$score])) {
                $candidates[$score] = $offset;
            }
        }

        if (!$candidates) {
            return;
        }
        krsort($candidates);
        $score = key($candidates);
        $offset = current($candidates);
        $this->symbol_cmap = ($score === 1);

        if ($this->u16($offset) === 12) {
            $this->parse_cmap_format12($offset);
        } else {
            $this->parse_cmap_format4($offset);
        }
    }

    private function parse_cmap_format4($offset)
    {
        $seg_count_x2 = $this->u16($offset + 6);
        $seg_count = $seg_count_x2 >> 1;
        $end_codes = $offset + 14;
        $start_codes = $end_codes + $seg_count_x2 + 2;
        $id_deltas = $start_codes + $seg_count_x2;
        $id_range_offsets = $id_deltas + $seg_count_x2;

        for ($s = 0; $s < $seg_count; $s++) {
            $end = $this->u16($end_codes + 2 * $s);
            $start = $this->u16($start_codes + 2 * $s);
            $delta = $this->u16($id_deltas + 2 * $s);
            $range_offset = $this->u16($id_range_offsets + 2 * $s);
            if ($start > $end || $start === 0xFFFF) {
                continue;
            }

            for ($cp = $start; $cp <= $end; $cp++) {
                if ($range_offset === 0) {
                    $glyph = ($cp + $delta) & 0xFFFF;
                } else {
                    $position = $id_range_offsets + 2 * $s + $range_offset + 2 * ($cp - $start);
                    if ($position + 2 > $this->size) {
                        continue;
                    }
                    $glyph = $this->u16($position);
                    if ($glyph !== 0) {
                        $glyph = ($glyph + $delta) & 0xFFFF;
                    }
                }
                if ($glyph !== 0) {
                    $this->cmap[$cp] = $glyph;
                }
            }
        }
    }

    private function parse_cmap_format12($offset)
    {
        $groups = $this->u32($offset + 12);
        $entries = 0;
        for ($i = 0; $i < $groups; $i++) {
            $record = $offset + 16 + 12 * $i;
            if ($record + 12 > $this->size) {
                break;
            }
            $start = $this->u32($record);
            $end = $this->u32($record + 4);
            $glyph = $this->u32($record + 8);
            if ($end < $start || $end > 0x10FFFF) {
                continue;
            }
            $entries += $end - $start + 1;
            if ($entries > self::MAX_CMAP_ENTRIES) {
                break;
            }
            for ($cp = $start; $cp <= $end; $cp++) {
                $this->cmap[$cp] = $glyph + ($cp - $start);
            }
        }
    }

    private function u16($offset)
    {
        if ($offset + 2 > $this->size) {
            return 0;
        }
        return (ord($this->data[$offset]) << 8) | ord($this->data[$offset + 1]);
    }

    private function u32($offset)
    {
        if ($offset + 4 > $this->size) {
            return 0;
        }
        return (ord($this->data[$offset]) << 24) | (ord($this->data[$offset + 1]) << 16)
            | (ord($this->data[$offset + 2]) << 8) | ord($this->data[$offset + 3]);
    }
}
