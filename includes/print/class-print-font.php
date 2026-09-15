<?php
/**
 * Minimal sfnt (TrueType / OpenType) reader for the print engine.
 *
 * Reads what print needs and nothing more: table directory, cmap, OS/2 fsType,
 * head/hhea/OS/2/maxp metrics, hmtx advances, TrueType glyf outlines (simple
 * and composite), the legacy kern table and GDEF glyph classes. GSUB/GPOS are
 * parsed by Altegena_Print_Shaper through table_offset() + the byte readers.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Altegena_Print_Font
{
    /** Cap on cmap entries so a malformed font can't exhaust memory. */
    const MAX_CMAP_ENTRIES = 200000;
    const MAX_COMPOSITE_DEPTH = 8;

    private static $cache = array();

    private $path;
    private $data;
    private $size;
    private $tables = array();
    private $cmap = null;
    private $symbol_cmap = false;
    private $outlines = array();
    private $kern_pairs = null;
    private $glyph_classes = null;
    private $mark_attach_classes = null;

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

    /** Raw font bytes (for embedding and for the shaper's table parsing). */
    public function bytes()
    {
        return $this->data;
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

    /** Absolute offset of a table, or null when the font has no such table. */
    public function table_offset($tag)
    {
        return isset($this->tables[$tag]) ? $this->tables[$tag]['offset'] : null;
    }

    /** True for CFF-outline OpenType fonts (.otf with 'CFF ' table). */
    public function is_cff()
    {
        return isset($this->tables['CFF ']) || isset($this->tables['CFF2']);
    }

    /** True when glyph outlines can be read (TrueType glyf/loca present). */
    public function has_truetype_outlines()
    {
        return isset($this->tables['glyf']) && isset($this->tables['loca']) && isset($this->tables['head']);
    }

    /* ---------------------------------------------------------------------
     * cmap
     * ------------------------------------------------------------------- */

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

    /* ---------------------------------------------------------------------
     * OS/2 embedding flags
     * ------------------------------------------------------------------- */

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

    /* ---------------------------------------------------------------------
     * Metrics
     * ------------------------------------------------------------------- */

    public function units_per_em()
    {
        $head = $this->table_offset('head');
        $upm = $head !== null ? $this->u16($head + 18) : 0;
        return $upm > 0 ? $upm : 1000;
    }

    public function num_glyphs()
    {
        $maxp = $this->table_offset('maxp');
        return $maxp !== null ? $this->u16($maxp + 4) : 0;
    }

    /**
     * Vertical metrics in font units, chosen the way FreeType/Skia do (Chrome on
     * Linux/Android/ChromeOS): OS/2 typo metrics when USE_TYPO_METRICS (fsSelection
     * bit 7) is set, otherwise hhea; hhea zeros fall back to typo, then win metrics.
     *
     * @return array {ascent, descent (positive), line_gap}
     */
    public function vertical_metrics()
    {
        $ascent = 0;
        $descent = 0;
        $line_gap = 0;

        $hhea = $this->table_offset('hhea');
        if ($hhea !== null) {
            $ascent = $this->i16($hhea + 4);
            $descent = -$this->i16($hhea + 6);
            $line_gap = $this->i16($hhea + 8);
        }

        $os2 = $this->table_offset('OS/2');
        if ($os2 !== null && $this->tables['OS/2']['length'] >= 78) {
            $fs_selection = $this->u16($os2 + 62);
            $typo_ascent = $this->i16($os2 + 68);
            $typo_descent = -$this->i16($os2 + 70);
            $typo_gap = $this->i16($os2 + 72);

            if (($fs_selection & 0x0080) || ($ascent === 0 && $descent === 0)) {
                $ascent = $typo_ascent;
                $descent = $typo_descent;
                $line_gap = $typo_gap;
            }
            if ($ascent === 0 && $descent === 0) {
                $ascent = $this->u16($os2 + 74);
                $descent = $this->u16($os2 + 76);
                $line_gap = 0;
            }
        }

        return array('ascent' => $ascent, 'descent' => $descent, 'line_gap' => $line_gap);
    }

    /** Horizontal advance of a glyph in font units. */
    public function advance_width($gid)
    {
        $hhea = $this->table_offset('hhea');
        $hmtx = $this->table_offset('hmtx');
        if ($hhea === null || $hmtx === null) {
            return 0;
        }
        $count = $this->u16($hhea + 34);
        if ($count === 0) {
            return 0;
        }
        $index = $gid < $count ? $gid : $count - 1;
        return $this->u16($hmtx + 4 * $index);
    }

    /* ---------------------------------------------------------------------
     * Outlines (TrueType glyf)
     * ------------------------------------------------------------------- */

    /**
     * Glyph contours in font units (y up): list of contours, each a list of
     * array(x, y, on_curve). Composite glyphs are flattened.
     */
    public function glyph_contours($gid)
    {
        if (!isset($this->outlines[$gid])) {
            $this->outlines[$gid] = $this->read_glyph($gid, 0);
        }
        return $this->outlines[$gid];
    }

    private function glyph_location($gid)
    {
        $head = $this->table_offset('head');
        $loca = $this->table_offset('loca');
        $glyf = $this->table_offset('glyf');
        if ($head === null || $loca === null || $glyf === null || $gid < 0 || $gid >= $this->num_glyphs()) {
            return null;
        }

        if ($this->i16($head + 50) === 0) {
            $start = $this->u16($loca + 2 * $gid) * 2;
            $end = $this->u16($loca + 2 * $gid + 2) * 2;
        } else {
            $start = $this->u32($loca + 4 * $gid);
            $end = $this->u32($loca + 4 * $gid + 4);
        }
        if ($end <= $start) {
            return null; // empty glyph (e.g. space)
        }
        return array('offset' => $glyf + $start, 'length' => $end - $start);
    }

    private function read_glyph($gid, $depth)
    {
        $location = $this->glyph_location($gid);
        if ($location === null || $depth > self::MAX_COMPOSITE_DEPTH) {
            return array();
        }

        $offset = $location['offset'];
        $contour_count = $this->i16($offset);
        if ($contour_count >= 0) {
            return $this->read_simple_glyph($offset, $contour_count);
        }
        return $this->read_composite_glyph($offset, $depth);
    }

    private function read_simple_glyph($offset, $contour_count)
    {
        if ($contour_count === 0) {
            return array();
        }

        $pos = $offset + 10;
        $end_points = array();
        for ($i = 0; $i < $contour_count; $i++) {
            $end_points[] = $this->u16($pos);
            $pos += 2;
        }
        $point_count = end($end_points) + 1;
        $instruction_length = $this->u16($pos);
        $pos += 2 + $instruction_length;

        $flags = array();
        while (count($flags) < $point_count && $pos < $this->size) {
            $flag = ord($this->data[$pos++]);
            $flags[] = $flag;
            if ($flag & 0x08) {
                $repeat = ord($this->data[$pos++]);
                for ($r = 0; $r < $repeat; $r++) {
                    $flags[] = $flag;
                }
            }
        }

        $xs = array();
        $value = 0;
        foreach ($flags as $flag) {
            if ($flag & 0x02) {
                $delta = ord($this->data[$pos++]);
                $value += ($flag & 0x10) ? $delta : -$delta;
            } elseif (!($flag & 0x10)) {
                $value += $this->i16($pos);
                $pos += 2;
            }
            $xs[] = $value;
        }

        $ys = array();
        $value = 0;
        foreach ($flags as $flag) {
            if ($flag & 0x04) {
                $delta = ord($this->data[$pos++]);
                $value += ($flag & 0x20) ? $delta : -$delta;
            } elseif (!($flag & 0x20)) {
                $value += $this->i16($pos);
                $pos += 2;
            }
            $ys[] = $value;
        }

        $contours = array();
        $start = 0;
        foreach ($end_points as $end) {
            $contour = array();
            for ($p = $start; $p <= $end && $p < $point_count; $p++) {
                $contour[] = array($xs[$p], $ys[$p], (bool) ($flags[$p] & 0x01));
            }
            if ($contour) {
                $contours[] = $contour;
            }
            $start = $end + 1;
        }
        return $contours;
    }

    private function read_composite_glyph($offset, $depth)
    {
        $pos = $offset + 10;
        $contours = array();

        do {
            $flags = $this->u16($pos);
            $component = $this->u16($pos + 2);
            $pos += 4;

            if ($flags & 0x0001) { // ARG_1_AND_2_ARE_WORDS
                $arg1 = ($flags & 0x0002) ? $this->i16($pos) : $this->u16($pos);
                $arg2 = ($flags & 0x0002) ? $this->i16($pos + 2) : $this->u16($pos + 2);
                $pos += 4;
            } else {
                $arg1 = ($flags & 0x0002) ? $this->i8($pos) : ord($this->data[$pos]);
                $arg2 = ($flags & 0x0002) ? $this->i8($pos + 1) : ord($this->data[$pos + 1]);
                $pos += 2;
            }

            $a = 1.0;
            $b = 0.0;
            $c = 0.0;
            $d = 1.0;
            if ($flags & 0x0008) { // WE_HAVE_A_SCALE
                $a = $d = $this->f2dot14($pos);
                $pos += 2;
            } elseif ($flags & 0x0040) { // WE_HAVE_AN_X_AND_Y_SCALE
                $a = $this->f2dot14($pos);
                $d = $this->f2dot14($pos + 2);
                $pos += 4;
            } elseif ($flags & 0x0080) { // WE_HAVE_A_TWO_BY_TWO
                $a = $this->f2dot14($pos);
                $b = $this->f2dot14($pos + 2);
                $c = $this->f2dot14($pos + 4);
                $d = $this->f2dot14($pos + 6);
                $pos += 8;
            }

            // ARGS_ARE_XY_VALUES; point-matching offsets are rare and treated as zero.
            $dx = ($flags & 0x0002) ? $arg1 : 0;
            $dy = ($flags & 0x0002) ? $arg2 : 0;

            foreach ($this->read_glyph($component, $depth + 1) as $contour) {
                $transformed = array();
                foreach ($contour as $point) {
                    $transformed[] = array(
                        $a * $point[0] + $c * $point[1] + $dx,
                        $b * $point[0] + $d * $point[1] + $dy,
                        $point[2],
                    );
                }
                $contours[] = $transformed;
            }
        } while (($flags & 0x0020) && $pos < $this->size); // MORE_COMPONENTS

        return $contours;
    }

    /* ---------------------------------------------------------------------
     * PDF font descriptor data
     * ------------------------------------------------------------------- */

    /** head bounding box [xMin, yMin, xMax, yMax] in font units. */
    public function bbox()
    {
        $head = $this->table_offset('head');
        if ($head === null) {
            return array(0, 0, $this->units_per_em(), $this->units_per_em());
        }
        return array($this->i16($head + 36), $this->i16($head + 38), $this->i16($head + 40), $this->i16($head + 42));
    }

    /** post.italicAngle in degrees. */
    public function italic_angle()
    {
        $post = $this->table_offset('post');
        if ($post === null) {
            return 0.0;
        }
        $value = $this->u32($post + 4);
        if ($value >= 0x80000000) {
            $value -= 0x100000000;
        }
        return $value / 65536;
    }

    public function cap_height()
    {
        $os2 = $this->table_offset('OS/2');
        if ($os2 !== null && $this->u16($os2) >= 2 && $this->tables['OS/2']['length'] >= 90) {
            return $this->i16($os2 + 88);
        }
        $metrics = $this->vertical_metrics();
        return $metrics['ascent'];
    }

    /** PostScript name (name ID 6), restricted to characters safe in a PDF name. */
    public function postscript_name()
    {
        $fallback = preg_replace('/[^A-Za-z0-9-]/', '', pathinfo($this->path, PATHINFO_FILENAME));
        $fallback = $fallback !== '' ? $fallback : 'AltegenaFont';
        $table = $this->table_offset('name');
        if ($table === null) {
            return $fallback;
        }

        $count = $this->u16($table + 2);
        $strings = $table + $this->u16($table + 4);
        $found = null;
        for ($i = 0; $i < $count; $i++) {
            $record = $table + 6 + 12 * $i;
            if ($this->u16($record + 6) !== 6) {
                continue;
            }
            $platform = $this->u16($record);
            $raw = substr($this->data, $strings + $this->u16($record + 10), $this->u16($record + 8));
            if ($platform === 3 || $platform === 0) {
                $raw = function_exists('mb_convert_encoding') ? mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE') : str_replace("\x00", '', $raw);
            }
            $clean = preg_replace('/[^A-Za-z0-9-]/', '', (string) $raw);
            if ($clean !== '') {
                $found = $clean;
                if ($platform === 3) {
                    break;
                }
            }
        }
        return $found !== null ? $found : $fallback;
    }

    /* ---------------------------------------------------------------------
     * Legacy kern table (format 0) and GDEF classes
     * ------------------------------------------------------------------- */

    /** Kerning value (font units) for a glyph pair from the legacy 'kern' table. */
    public function legacy_kern($left, $right)
    {
        if ($this->kern_pairs === null) {
            $this->load_kern_pairs();
        }
        $key = ($left << 16) | $right;
        return isset($this->kern_pairs[$key]) ? $this->kern_pairs[$key] : 0;
    }

    public function has_legacy_kern()
    {
        if ($this->kern_pairs === null) {
            $this->load_kern_pairs();
        }
        return !empty($this->kern_pairs);
    }

    private function load_kern_pairs()
    {
        $this->kern_pairs = array();
        $kern = $this->table_offset('kern');
        if ($kern === null || $this->u16($kern) !== 0) {
            return; // only the Microsoft (version 0) table layout
        }

        $count = $this->u16($kern + 2);
        $pos = $kern + 4;
        for ($i = 0; $i < $count; $i++) {
            $length = $this->u16($pos + 2);
            $coverage = $this->u16($pos + 4);
            $format = $coverage >> 8;
            // Horizontal (bit 0), not minimum values (bit 1), not cross-stream (bit 2).
            if ($format === 0 && ($coverage & 0x07) === 0x01) {
                $pairs = $this->u16($pos + 6);
                for ($p = 0; $p < $pairs; $p++) {
                    $record = $pos + 14 + 6 * $p;
                    $key = ($this->u16($record) << 16) | $this->u16($record + 2);
                    $this->kern_pairs[$key] = $this->i16($record + 4);
                }
            }
            if ($length < 6) {
                break;
            }
            $pos += $length;
        }
    }

    /** GDEF glyph class: 1 base, 2 ligature, 3 mark, 4 component, 0 unclassified. */
    public function glyph_class($gid)
    {
        if ($this->glyph_classes === null) {
            $this->load_gdef();
        }
        return isset($this->glyph_classes[$gid]) ? $this->glyph_classes[$gid] : 0;
    }

    public function mark_attach_class($gid)
    {
        if ($this->mark_attach_classes === null) {
            $this->load_gdef();
        }
        return isset($this->mark_attach_classes[$gid]) ? $this->mark_attach_classes[$gid] : 0;
    }

    public function has_glyph_classes()
    {
        if ($this->glyph_classes === null) {
            $this->load_gdef();
        }
        return !empty($this->glyph_classes);
    }

    private function load_gdef()
    {
        $this->glyph_classes = array();
        $this->mark_attach_classes = array();
        $gdef = $this->table_offset('GDEF');
        if ($gdef === null) {
            return;
        }
        $class_def = $this->u16($gdef + 4);
        if ($class_def) {
            $this->glyph_classes = $this->read_class_def($gdef + $class_def);
        }
        $mark_attach = $this->u16($gdef + 10);
        if ($mark_attach) {
            $this->mark_attach_classes = $this->read_class_def($gdef + $mark_attach);
        }
    }

    /** OpenType ClassDef table → gid => class. */
    public function read_class_def($offset)
    {
        $classes = array();
        $format = $this->u16($offset);
        if ($format === 1) {
            $start = $this->u16($offset + 2);
            $count = $this->u16($offset + 4);
            for ($i = 0; $i < $count; $i++) {
                $value = $this->u16($offset + 6 + 2 * $i);
                if ($value) {
                    $classes[$start + $i] = $value;
                }
            }
        } elseif ($format === 2) {
            $count = $this->u16($offset + 2);
            for ($i = 0; $i < $count; $i++) {
                $record = $offset + 4 + 6 * $i;
                $start = $this->u16($record);
                $end = $this->u16($record + 2);
                $value = $this->u16($record + 4);
                if ($value && $end >= $start && $end - $start < 65536) {
                    for ($g = $start; $g <= $end; $g++) {
                        $classes[$g] = $value;
                    }
                }
            }
        }
        return $classes;
    }

    /** OpenType Coverage table → gid => coverage index. */
    public function read_coverage($offset)
    {
        $coverage = array();
        $format = $this->u16($offset);
        if ($format === 1) {
            $count = $this->u16($offset + 2);
            for ($i = 0; $i < $count; $i++) {
                $coverage[$this->u16($offset + 4 + 2 * $i)] = $i;
            }
        } elseif ($format === 2) {
            $count = $this->u16($offset + 2);
            for ($i = 0; $i < $count; $i++) {
                $record = $offset + 4 + 6 * $i;
                $start = $this->u16($record);
                $end = $this->u16($record + 2);
                $index = $this->u16($record + 4);
                if ($end >= $start && $end - $start < 65536) {
                    for ($g = $start; $g <= $end; $g++) {
                        $coverage[$g] = $index + ($g - $start);
                    }
                }
            }
        }
        return $coverage;
    }

    /* ---------------------------------------------------------------------
     * Byte readers (big-endian)
     * ------------------------------------------------------------------- */

    public function u8($offset)
    {
        return $offset < $this->size ? ord($this->data[$offset]) : 0;
    }

    public function i8($offset)
    {
        $value = $this->u8($offset);
        return $value >= 0x80 ? $value - 0x100 : $value;
    }

    public function u16($offset)
    {
        if ($offset + 2 > $this->size || $offset < 0) {
            return 0;
        }
        return (ord($this->data[$offset]) << 8) | ord($this->data[$offset + 1]);
    }

    public function i16($offset)
    {
        $value = $this->u16($offset);
        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    public function u32($offset)
    {
        if ($offset + 4 > $this->size || $offset < 0) {
            return 0;
        }
        return (ord($this->data[$offset]) << 24) | (ord($this->data[$offset + 1]) << 16)
            | (ord($this->data[$offset + 2]) << 8) | ord($this->data[$offset + 3]);
    }

    private function f2dot14($offset)
    {
        return $this->i16($offset) / 16384;
    }
}
