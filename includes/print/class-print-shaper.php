<?php
/**
 * OpenType shaping subset for print, mirroring what Chrome (HarfBuzz) applies
 * to Latin text with the default feature set, so printed glyphs match the
 * customer's screen.
 *
 * Supported:
 * - script latn (then DFLT/dflt); language TRK (then the default LangSys);
 * - required feature + ccmp, locl, rlig, calt, clig, liga;
 * - GSUB lookup types 1, 2, 4, 5, 6 and 7, with lookup flags, mark attachment
 *   classes and mark filtering sets;
 * - GPOS kern via lookup types 1, 2 and 9; the legacy 'kern' table is used only
 *   when GPOS has no kern feature (HarfBuzz behavior).
 *
 * Not supported (rare in the bundled fonts): mark attachment positioning
 * (GPOS 4/5/6), contextual positioning (GPOS 7/8), reverse chaining (GSUB 8).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Altegena_Print_Shaper
{
    const MAX_NESTING = 8;
    const MAX_OPERATIONS = 50000;

    /** Features Blink turns off when letter-spacing is not zero. */
    const LETTER_SPACING_DISABLED = array('liga', 'clig', 'calt', 'dlig', 'hlig');

    private static $instances = array();

    private $font;
    private $data;
    private $coverage_cache = array();
    private $class_def_cache = array();
    private $lookup_cache = array();
    private $lookup_list_cache = array();
    private $mark_sets = null;
    private $operations = 0;

    public static function for_font(Altegena_Print_Font $font)
    {
        $key = $font->path();
        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = new self($font);
        }
        return self::$instances[$key];
    }

    private function __construct(Altegena_Print_Font $font)
    {
        $this->font = $font;
        $this->data = $font->bytes();
    }

    /**
     * Shape one line of text.
     *
     * @param int[] $codepoints
     * @param array $options {language: 4-char OpenType tag, disable: feature tags}
     * @return array list of glyphs {gid, start, end, advance, dx, dy}; start/end are
     *               code point indexes (a ligature spans several), metrics in font units
     */
    public function shape($codepoints, $options = array())
    {
        $language = isset($options['language']) ? $options['language'] : 'TRK ';
        $disabled = isset($options['disable']) ? $options['disable'] : array();
        $this->operations = 0;

        $buffer = array();
        foreach (array_values($codepoints) as $index => $cp) {
            $buffer[] = array('gid' => $this->font->glyph_id($cp), 'start' => $index, 'end' => $index + 1);
        }

        $gsub = $this->lookups('GSUB', array_values(array_diff(array('ccmp', 'locl', 'rlig', 'calt', 'clig', 'liga'), $disabled)), $language);
        foreach ($gsub['lookups'] as $lookup) {
            $this->apply_lookup($buffer, $lookup);
        }

        foreach ($buffer as &$glyph) {
            $glyph['advance'] = $this->font->advance_width($glyph['gid']);
            $glyph['dx'] = 0;
            $glyph['dy'] = 0;
        }
        unset($glyph);

        $gpos = $this->lookups('GPOS', array('kern'), $language);
        if ($gpos['found']) {
            foreach ($gpos['lookups'] as $lookup) {
                $this->apply_lookup($buffer, $lookup);
            }
        } elseif ($this->font->has_legacy_kern()) {
            $this->apply_legacy_kern($buffer);
        }

        return $buffer;
    }

    /* ---------------------------------------------------------------------
     * Script / language / feature → lookups
     * ------------------------------------------------------------------- */

    /**
     * @return array {found: bool (a requested feature exists), lookups: parsed lookups in index order}
     */
    private function lookups($table, $features, $language)
    {
        $key = $table . '|' . implode(',', $features) . '|' . $language;
        if (isset($this->lookup_cache[$key])) {
            return $this->lookup_cache[$key];
        }

        $result = array('found' => false, 'lookups' => array());
        $base = $this->font->table_offset($table);
        if ($base === null) {
            return $this->lookup_cache[$key] = $result;
        }

        $script_list = $base + $this->u16($base + 4);
        $feature_list = $base + $this->u16($base + 6);
        $lookup_list = $base + $this->u16($base + 8);

        $script = null;
        foreach (array('latn', 'DFLT', 'dflt') as $tag) {
            $script = $this->find_tagged_offset($script_list, $tag, 6);
            if ($script !== null) {
                break;
            }
        }
        if ($script === null) {
            return $this->lookup_cache[$key] = $result;
        }

        $lang_sys = null;
        $lang_count = $this->u16($script + 2);
        for ($i = 0; $i < $lang_count; $i++) {
            $record = $script + 4 + 6 * $i;
            if (substr($this->data, $record, 4) === $language) {
                $lang_sys = $script + $this->u16($record + 4);
                break;
            }
        }
        if ($lang_sys === null && $this->u16($script)) {
            $lang_sys = $script + $this->u16($script);
        }
        if ($lang_sys === null) {
            return $this->lookup_cache[$key] = $result;
        }

        $feature_indexes = array();
        $required = $this->u16($lang_sys + 2);
        if ($required !== 0xFFFF) {
            $feature_indexes[] = array($required, true);
        }
        $count = $this->u16($lang_sys + 4);
        for ($i = 0; $i < $count; $i++) {
            $feature_indexes[] = array($this->u16($lang_sys + 6 + 2 * $i), false);
        }

        $feature_count = $this->u16($feature_list);
        $lookup_indexes = array();
        foreach ($feature_indexes as $entry) {
            list($index, $is_required) = $entry;
            if ($index >= $feature_count) {
                continue;
            }
            $record = $feature_list + 2 + 6 * $index;
            if (!$is_required) {
                if (!in_array(substr($this->data, $record, 4), $features, true)) {
                    continue;
                }
                $result['found'] = true;
            }
            $feature = $feature_list + $this->u16($record + 4);
            $n = $this->u16($feature + 2);
            for ($l = 0; $l < $n; $l++) {
                $lookup_indexes[$this->u16($feature + 4 + 2 * $l)] = true;
            }
        }

        $lookup_indexes = array_keys($lookup_indexes);
        sort($lookup_indexes);
        foreach ($lookup_indexes as $index) {
            $lookup = $this->parse_lookup($table, $lookup_list, $index);
            if ($lookup) {
                $result['lookups'][] = $lookup;
            }
        }

        return $this->lookup_cache[$key] = $result;
    }

    private function find_tagged_offset($list, $tag, $record_size)
    {
        $count = $this->u16($list);
        for ($i = 0; $i < $count; $i++) {
            $record = $list + 2 + $record_size * $i;
            if (substr($this->data, $record, 4) === $tag) {
                return $list + $this->u16($record + 4);
            }
        }
        return null;
    }

    private function parse_lookup($table, $lookup_list, $index)
    {
        $key = $table . '|' . $index;
        if (array_key_exists($key, $this->lookup_list_cache)) {
            return $this->lookup_list_cache[$key];
        }

        if ($index >= $this->u16($lookup_list)) {
            return $this->lookup_list_cache[$key] = null;
        }

        $offset = $lookup_list + $this->u16($lookup_list + 2 + 2 * $index);
        $type = $this->u16($offset);
        $flag = $this->u16($offset + 2);
        $count = $this->u16($offset + 4);
        $extension = ($table === 'GSUB') ? 7 : 9;

        $subtables = array();
        for ($s = 0; $s < $count; $s++) {
            $subtable = $offset + $this->u16($offset + 6 + 2 * $s);
            if ($type === $extension) {
                $subtables[] = array($this->u16($subtable + 2), $subtable + $this->u32($subtable + 4));
            } else {
                $subtables[] = array($type, $subtable);
            }
        }

        return $this->lookup_list_cache[$key] = array(
            'table' => $table,
            'list' => $lookup_list,
            'flag' => $flag,
            'mark_set' => ($flag & 0x0010) ? $this->u16($offset + 6 + 2 * $count) : null,
            'subtables' => $subtables,
        );
    }

    /* ---------------------------------------------------------------------
     * Lookup application
     * ------------------------------------------------------------------- */

    private function apply_lookup(&$buffer, $lookup)
    {
        $i = 0;
        while ($i < count($buffer)) {
            if (++$this->operations > self::MAX_OPERATIONS) {
                return;
            }
            if ($this->skip($buffer[$i]['gid'], $lookup)) {
                $i++;
                continue;
            }
            $next = $this->apply_at($buffer, $i, $lookup, 0);
            $i = ($next !== false && $next > $i) ? $next : $i + 1;
        }
    }

    /** Try each subtable at one position; returns the index to continue from, or false. */
    private function apply_at(&$buffer, $i, $lookup, $depth)
    {
        foreach ($lookup['subtables'] as $subtable) {
            list($type, $offset) = $subtable;
            if ($lookup['table'] === 'GSUB') {
                $next = $this->apply_gsub($buffer, $i, $type, $offset, $lookup, $depth);
            } else {
                $next = $this->apply_gpos($buffer, $i, $type, $offset, $lookup);
            }
            if ($next !== false) {
                return $next;
            }
        }
        return false;
    }

    private function apply_gsub(&$buffer, $i, $type, $offset, $lookup, $depth)
    {
        $gid = $buffer[$i]['gid'];
        $format = $this->u16($offset);

        switch ($type) {
            case 1: // Single substitution
                $coverage = $this->coverage($offset + $this->u16($offset + 2));
                if (!isset($coverage[$gid])) {
                    return false;
                }
                if ($format === 1) {
                    $buffer[$i]['gid'] = ($gid + $this->i16($offset + 4)) & 0xFFFF;
                } elseif ($format === 2) {
                    $index = $coverage[$gid];
                    if ($index >= $this->u16($offset + 4)) {
                        return false;
                    }
                    $buffer[$i]['gid'] = $this->u16($offset + 6 + 2 * $index);
                } else {
                    return false;
                }
                return $i + 1;

            case 2: // Multiple substitution
                $coverage = $this->coverage($offset + $this->u16($offset + 2));
                if (!isset($coverage[$gid]) || $coverage[$gid] >= $this->u16($offset + 4)) {
                    return false;
                }
                $sequence = $offset + $this->u16($offset + 6 + 2 * $coverage[$gid]);
                $count = $this->u16($sequence);
                $glyphs = array();
                for ($k = 0; $k < $count; $k++) {
                    $glyphs[] = array('gid' => $this->u16($sequence + 2 + 2 * $k), 'start' => $buffer[$i]['start'], 'end' => $buffer[$i]['end']);
                }
                array_splice($buffer, $i, 1, $glyphs);
                return $i + $count;

            case 4: // Ligature substitution
                $coverage = $this->coverage($offset + $this->u16($offset + 2));
                if (!isset($coverage[$gid]) || $coverage[$gid] >= $this->u16($offset + 4)) {
                    return false;
                }
                $set = $offset + $this->u16($offset + 6 + 2 * $coverage[$gid]);
                $ligatures = $this->u16($set);
                for ($l = 0; $l < $ligatures; $l++) {
                    $ligature = $set + $this->u16($set + 2 + 2 * $l);
                    $components = $this->u16($ligature + 2);
                    $positions = array($i);
                    $position = $i;
                    for ($c = 1; $c < $components; $c++) {
                        $position = $this->next_index($buffer, $position + 1, $lookup);
                        if ($position < 0 || $buffer[$position]['gid'] !== $this->u16($ligature + 4 + 2 * ($c - 1))) {
                            continue 2;
                        }
                        $positions[] = $position;
                    }

                    $start = $buffer[$i]['start'];
                    $end = $buffer[$i]['end'];
                    foreach ($positions as $p) {
                        $start = min($start, $buffer[$p]['start']);
                        $end = max($end, $buffer[$p]['end']);
                    }
                    $buffer[$i] = array('gid' => $this->u16($ligature), 'start' => $start, 'end' => $end);
                    for ($p = count($positions) - 1; $p >= 1; $p--) {
                        array_splice($buffer, $positions[$p], 1);
                    }
                    return $i + 1;
                }
                return false;

            case 5:
                return $this->apply_context($buffer, $i, $offset, $lookup, $depth);

            case 6:
                return $this->apply_chain_context($buffer, $i, $offset, $lookup, $depth);
        }

        return false; // 3 (alternates: no default feature), 8 (reverse chaining: unsupported)
    }

    private function apply_gpos(&$buffer, $i, $type, $offset, $lookup)
    {
        $gid = $buffer[$i]['gid'];
        $format = $this->u16($offset);

        if ($type === 1) { // Single adjustment
            $coverage = $this->coverage($offset + $this->u16($offset + 2));
            if (!isset($coverage[$gid])) {
                return false;
            }
            $value_format = $this->u16($offset + 4);
            if ($format === 1) {
                $value = $this->value_record($offset + 6, $value_format);
            } elseif ($format === 2) {
                $value = $this->value_record($offset + 8 + $coverage[$gid] * $this->value_size($value_format), $value_format);
            } else {
                return false;
            }
            $this->add_value($buffer[$i], $value);
            return $i + 1;
        }

        if ($type === 2) { // Pair adjustment
            $coverage = $this->coverage($offset + $this->u16($offset + 2));
            if (!isset($coverage[$gid])) {
                return false;
            }
            $j = $this->next_index($buffer, $i + 1, $lookup);
            if ($j < 0) {
                return false;
            }
            $second = $buffer[$j]['gid'];
            $format1 = $this->u16($offset + 4);
            $format2 = $this->u16($offset + 6);
            $size1 = $this->value_size($format1);
            $size2 = $this->value_size($format2);

            if ($format === 1) {
                $index = $coverage[$gid];
                if ($index >= $this->u16($offset + 8)) {
                    return false;
                }
                $set = $offset + $this->u16($offset + 10 + 2 * $index);
                $record_size = 2 + $size1 + $size2;
                $low = 0;
                $high = $this->u16($set) - 1;
                while ($low <= $high) {
                    $mid = ($low + $high) >> 1;
                    $record = $set + 2 + $mid * $record_size;
                    $glyph = $this->u16($record);
                    if ($glyph === $second) {
                        $this->add_value($buffer[$i], $this->value_record($record + 2, $format1));
                        $this->add_value($buffer[$j], $this->value_record($record + 2 + $size1, $format2));
                        return $format2 ? $j + 1 : $j;
                    }
                    if ($glyph < $second) {
                        $low = $mid + 1;
                    } else {
                        $high = $mid - 1;
                    }
                }
                return false;
            }

            if ($format === 2) {
                $classes1 = $this->class_def($offset + $this->u16($offset + 8));
                $classes2 = $this->class_def($offset + $this->u16($offset + 10));
                $count1 = $this->u16($offset + 12);
                $count2 = $this->u16($offset + 14);
                $class1 = isset($classes1[$gid]) ? $classes1[$gid] : 0;
                $class2 = isset($classes2[$second]) ? $classes2[$second] : 0;
                if ($class1 >= $count1 || $class2 >= $count2) {
                    return false;
                }
                $record = $offset + 16 + ($class1 * $count2 + $class2) * ($size1 + $size2);
                $this->add_value($buffer[$i], $this->value_record($record, $format1));
                $this->add_value($buffer[$j], $this->value_record($record + $size1, $format2));
                return $format2 ? $j + 1 : $j;
            }
        }

        return false;
    }

    /* ---------------------------------------------------------------------
     * Contextual substitution (GSUB 5 and 6)
     * ------------------------------------------------------------------- */

    private function apply_context(&$buffer, $i, $offset, $lookup, $depth)
    {
        $gid = $buffer[$i]['gid'];
        $format = $this->u16($offset);

        if ($format === 1 || $format === 2) {
            $coverage = $this->coverage($offset + $this->u16($offset + 2));
            if (!isset($coverage[$gid])) {
                return false;
            }
            $classes = ($format === 2) ? $this->class_def($offset + $this->u16($offset + 4)) : null;
            $set_index = ($format === 1) ? $coverage[$gid] : (isset($classes[$gid]) ? $classes[$gid] : 0);
            $set_count_pos = ($format === 1) ? $offset + 4 : $offset + 6;
            if ($set_index >= $this->u16($set_count_pos) || !$this->u16($set_count_pos + 2 + 2 * $set_index)) {
                return false;
            }
            $set = $offset + $this->u16($set_count_pos + 2 + 2 * $set_index);

            $rules = $this->u16($set);
            for ($r = 0; $r < $rules; $r++) {
                $rule = $set + $this->u16($set + 2 + 2 * $r);
                $glyph_count = $this->u16($rule);
                $record_count = $this->u16($rule + 2);
                $predicates = array(null);
                for ($k = 1; $k < $glyph_count; $k++) {
                    $predicates[] = $this->predicate($format === 1 ? 'glyph' : 'class', $this->u16($rule + 4 + 2 * ($k - 1)), $classes);
                }
                $positions = $this->match_input($buffer, $i, $lookup, $predicates);
                if ($positions !== null) {
                    return $this->apply_records($buffer, $i, $positions, $this->records($rule + 4 + 2 * max(0, $glyph_count - 1), $record_count), $lookup, $depth);
                }
            }
            return false;
        }

        if ($format === 3) {
            $glyph_count = $this->u16($offset + 2);
            $record_count = $this->u16($offset + 4);
            $predicates = array();
            for ($k = 0; $k < $glyph_count; $k++) {
                $predicates[] = $this->predicate('coverage', $offset + $this->u16($offset + 6 + 2 * $k), null);
            }
            $positions = $this->match_input($buffer, $i, $lookup, $predicates);
            if ($positions === null) {
                return false;
            }
            return $this->apply_records($buffer, $i, $positions, $this->records($offset + 6 + 2 * $glyph_count, $record_count), $lookup, $depth);
        }

        return false;
    }

    private function apply_chain_context(&$buffer, $i, $offset, $lookup, $depth)
    {
        $gid = $buffer[$i]['gid'];
        $format = $this->u16($offset);

        if ($format === 1 || $format === 2) {
            $coverage = $this->coverage($offset + $this->u16($offset + 2));
            if (!isset($coverage[$gid])) {
                return false;
            }

            $backtrack_classes = $input_classes = $lookahead_classes = null;
            if ($format === 2) {
                $backtrack_classes = $this->class_def($offset + $this->u16($offset + 4));
                $input_classes = $this->class_def($offset + $this->u16($offset + 6));
                $lookahead_classes = $this->class_def($offset + $this->u16($offset + 8));
                $set_index = isset($input_classes[$gid]) ? $input_classes[$gid] : 0;
                $set_count_pos = $offset + 10;
            } else {
                $set_index = $coverage[$gid];
                $set_count_pos = $offset + 4;
            }
            if ($set_index >= $this->u16($set_count_pos) || !$this->u16($set_count_pos + 2 + 2 * $set_index)) {
                return false;
            }
            $set = $offset + $this->u16($set_count_pos + 2 + 2 * $set_index);
            $kind = ($format === 1) ? 'glyph' : 'class';

            $rules = $this->u16($set);
            for ($r = 0; $r < $rules; $r++) {
                $pos = $set + $this->u16($set + 2 + 2 * $r);

                $backtrack = array();
                $count = $this->u16($pos);
                for ($k = 0; $k < $count; $k++) {
                    $backtrack[] = $this->predicate($kind, $this->u16($pos + 2 + 2 * $k), $backtrack_classes);
                }
                $pos += 2 + 2 * $count;

                $input = array(null);
                $count = $this->u16($pos);
                for ($k = 1; $k < $count; $k++) {
                    $input[] = $this->predicate($kind, $this->u16($pos + 2 + 2 * ($k - 1)), $input_classes);
                }
                $pos += 2 + 2 * max(0, $count - 1);

                $lookahead = array();
                $count = $this->u16($pos);
                for ($k = 0; $k < $count; $k++) {
                    $lookahead[] = $this->predicate($kind, $this->u16($pos + 2 + 2 * $k), $lookahead_classes);
                }
                $pos += 2 + 2 * $count;

                $positions = $this->match_chain($buffer, $i, $lookup, $backtrack, $input, $lookahead);
                if ($positions !== null) {
                    return $this->apply_records($buffer, $i, $positions, $this->records($pos + 2, $this->u16($pos)), $lookup, $depth);
                }
            }
            return false;
        }

        if ($format === 3) {
            $pos = $offset + 2;
            $groups = array();
            foreach (array('backtrack', 'input', 'lookahead') as $group) {
                $count = $this->u16($pos);
                $groups[$group] = array();
                for ($k = 0; $k < $count; $k++) {
                    $groups[$group][] = $this->predicate('coverage', $offset + $this->u16($pos + 2 + 2 * $k), null);
                }
                $pos += 2 + 2 * $count;
            }
            if (!$groups['input']) {
                return false;
            }
            $positions = $this->match_chain($buffer, $i, $lookup, $groups['backtrack'], $groups['input'], $groups['lookahead']);
            if ($positions === null) {
                return false;
            }
            return $this->apply_records($buffer, $i, $positions, $this->records($pos + 2, $this->u16($pos)), $lookup, $depth);
        }

        return false;
    }

    private function predicate($kind, $value, $classes)
    {
        if ($kind === 'glyph') {
            return function ($gid) use ($value) {
                return $gid === $value;
            };
        }
        if ($kind === 'class') {
            return function ($gid) use ($value, $classes) {
                return (isset($classes[$gid]) ? $classes[$gid] : 0) === $value;
            };
        }
        $coverage = $this->coverage($value);
        return function ($gid) use ($coverage) {
            return isset($coverage[$gid]);
        };
    }

    /** Match input glyphs starting at $i (a null predicate accepts the first glyph). */
    private function match_input($buffer, $i, $lookup, $predicates)
    {
        $positions = array();
        $position = $i;
        foreach ($predicates as $k => $predicate) {
            if ($k > 0) {
                $position = $this->next_index($buffer, $position + 1, $lookup);
                if ($position < 0) {
                    return null;
                }
            }
            if ($predicate !== null && !$predicate($buffer[$position]['gid'])) {
                return null;
            }
            $positions[] = $position;
        }
        return $positions;
    }

    private function match_chain($buffer, $i, $lookup, $backtrack, $input, $lookahead)
    {
        $positions = $this->match_input($buffer, $i, $lookup, $input);
        if ($positions === null) {
            return null;
        }

        $position = $i;
        foreach ($backtrack as $predicate) {
            $position = $this->prev_index($buffer, $position - 1, $lookup);
            if ($position < 0 || !$predicate($buffer[$position]['gid'])) {
                return null;
            }
        }

        $position = end($positions);
        foreach ($lookahead as $predicate) {
            $position = $this->next_index($buffer, $position + 1, $lookup);
            if ($position < 0 || !$predicate($buffer[$position]['gid'])) {
                return null;
            }
        }

        return $positions;
    }

    private function records($offset, $count)
    {
        $records = array();
        for ($k = 0; $k < $count; $k++) {
            $records[] = array($this->u16($offset + 4 * $k), $this->u16($offset + 4 * $k + 2));
        }
        return $records;
    }

    /** Apply nested lookups at matched positions; returns the index after the match. */
    private function apply_records(&$buffer, $i, $positions, $records, $lookup, $depth)
    {
        foreach ($records as $record) {
            list($sequence_index, $lookup_index) = $record;
            if ($depth >= self::MAX_NESTING || $sequence_index >= count($positions)) {
                continue;
            }
            $position = $positions[$sequence_index];
            if ($position >= count($buffer)) {
                continue;
            }
            $nested = $this->parse_lookup($lookup['table'], $lookup['list'], $lookup_index);
            if (!$nested || $this->skip($buffer[$position]['gid'], $nested)) {
                continue;
            }

            $before = count($buffer);
            $this->apply_at($buffer, $position, $nested, $depth + 1);
            $delta = count($buffer) - $before;
            if ($delta) {
                foreach ($positions as $k => $p) {
                    if ($p > $position) {
                        $positions[$k] = max($position, $p + $delta);
                    }
                }
            }
        }

        return max($i + 1, end($positions) + 1);
    }

    /* ---------------------------------------------------------------------
     * Legacy kern, skipping, value records, table helpers
     * ------------------------------------------------------------------- */

    private function apply_legacy_kern(&$buffer)
    {
        $previous = -1;
        foreach ($buffer as $index => $glyph) {
            if ($this->font->glyph_class($glyph['gid']) === 3) {
                continue;
            }
            if ($previous >= 0) {
                $buffer[$previous]['advance'] += $this->font->legacy_kern($buffer[$previous]['gid'], $glyph['gid']);
            }
            $previous = $index;
        }
    }

    private function skip($gid, $lookup)
    {
        $flag = $lookup['flag'];
        if (!($flag & 0xFF1E)) {
            return false;
        }
        $class = $this->font->glyph_class($gid);
        if (($class === 1 && ($flag & 0x0002)) || ($class === 2 && ($flag & 0x0004))) {
            return true;
        }
        if ($class === 3) {
            if ($flag & 0x0008) {
                return true;
            }
            if (($flag & 0x0010) && $lookup['mark_set'] !== null && !$this->in_mark_set($lookup['mark_set'], $gid)) {
                return true;
            }
            $attach_type = $flag >> 8;
            if ($attach_type && $this->font->mark_attach_class($gid) !== $attach_type) {
                return true;
            }
        }
        return false;
    }

    private function next_index($buffer, $index, $lookup)
    {
        $count = count($buffer);
        for ($k = $index; $k < $count; $k++) {
            if (!$this->skip($buffer[$k]['gid'], $lookup)) {
                return $k;
            }
        }
        return -1;
    }

    private function prev_index($buffer, $index, $lookup)
    {
        for ($k = $index; $k >= 0; $k--) {
            if (!$this->skip($buffer[$k]['gid'], $lookup)) {
                return $k;
            }
        }
        return -1;
    }

    private function in_mark_set($set, $gid)
    {
        if ($this->mark_sets === null) {
            $this->mark_sets = array();
            $gdef = $this->font->table_offset('GDEF');
            if ($gdef !== null && $this->u16($gdef + 2) >= 2 && $this->u16($gdef + 12)) {
                $base = $gdef + $this->u16($gdef + 12);
                $count = $this->u16($base + 2);
                for ($k = 0; $k < $count; $k++) {
                    $this->mark_sets[$k] = $base + $this->u32($base + 4 + 4 * $k);
                }
            }
        }
        if (!isset($this->mark_sets[$set])) {
            return false;
        }
        $coverage = $this->coverage($this->mark_sets[$set]);
        return isset($coverage[$gid]);
    }

    private function value_size($format)
    {
        $bits = 0;
        for ($b = 0; $b < 8; $b++) {
            if ($format & (1 << $b)) {
                $bits++;
            }
        }
        return 2 * $bits;
    }

    /** @return array {x, y, advance} — device tables are ignored (no hinting in print). */
    private function value_record($offset, $format)
    {
        $value = array('x' => 0, 'y' => 0, 'advance' => 0);
        if ($format & 0x0001) {
            $value['x'] = $this->i16($offset);
            $offset += 2;
        }
        if ($format & 0x0002) {
            $value['y'] = $this->i16($offset);
            $offset += 2;
        }
        if ($format & 0x0004) {
            $value['advance'] = $this->i16($offset);
        }
        return $value;
    }

    private function add_value(&$glyph, $value)
    {
        $glyph['dx'] += $value['x'];
        $glyph['dy'] += $value['y'];
        $glyph['advance'] += $value['advance'];
    }

    private function coverage($offset)
    {
        if (!isset($this->coverage_cache[$offset])) {
            $this->coverage_cache[$offset] = $this->font->read_coverage($offset);
        }
        return $this->coverage_cache[$offset];
    }

    private function class_def($offset)
    {
        if (!isset($this->class_def_cache[$offset])) {
            $this->class_def_cache[$offset] = $this->font->read_class_def($offset);
        }
        return $this->class_def_cache[$offset];
    }

    private function u16($offset)
    {
        return $this->font->u16($offset);
    }

    private function i16($offset)
    {
        return $this->font->i16($offset);
    }

    private function u32($offset)
    {
        return $this->font->u32($offset);
    }
}
