<?php
/**
 * Draws a laid-out scene with text converted to vector outlines (no fonts in
 * the PDF). Glyph contours are TrueType quadratics converted to cubic curves.
 *
 * Synthetic styles follow Chrome/Skia: bold grows the outline by font-size/24
 * (fill + same-color stroke, round joins), italic skews by -1/4.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Altegena_Print_Outline_Emitter
{
    const ITALIC_SKEW = 0.25;

    private $paths = array();

    /**
     * @param array  $scene      Altegena_Print_Layout::layout() result
     * @param string $background XObject resource name (e.g. '/Bg') or '' for none
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
            $out .= $this->layer($layer, $page);
        }

        return $out;
    }

    /** Outline drawing operators for one layer (also used by the text PDF for fonts that can't be embedded). */
    public function layer($layer, $page)
    {
        $num = array('Altegena_Print_Pdf_Writer', 'num');
        $scale = $layer['size'] / $layer['upm'];
        $color = implode(' ', array_map($num, $layer['color']));
        $paint = ' f';

        $out = 'q ' . $color . ' rg';
        if ($layer['synthetic_bold']) {
            $out .= ' ' . $color . ' RG 1 j';
            $paint = ' ' . call_user_func($num, $layer['upm'] / 24) . ' w B';
        }
        $out .= "\n";

        $layer_to_page = Altegena_Print_Layout::multiply($layer['matrix'], $page);
        foreach ($layer['lines'] as $line) {
            foreach ($line['glyphs'] as $glyph) {
                $path = $this->glyph_path($layer['font'], $glyph['gid']);
                if ($path === '') {
                    continue;
                }
                // Font units (y up) → box px (y down), optional skew, then place on the baseline.
                $glyph_matrix = array($scale, 0, 0, -$scale, 0, 0);
                if ($layer['synthetic_italic']) {
                    $glyph_matrix = Altegena_Print_Layout::multiply($glyph_matrix, array(1, 0, -self::ITALIC_SKEW, 1, 0, 0));
                }
                $glyph_matrix = Altegena_Print_Layout::multiply($glyph_matrix, array(1, 0, 0, 1, $line['x'] + $glyph['x'], $line['baseline'] - $glyph['y']));
                $matrix = Altegena_Print_Layout::multiply($glyph_matrix, $layer_to_page);

                $out .= 'q ' . implode(' ', array_map($num, $matrix)) . " cm\n" . $path . $paint . "\nQ\n";
            }
        }

        return $out . "Q\n";
    }

    /** PDF path operators for a glyph in font units (cached). */
    public function glyph_path($font, $gid)
    {
        $key = $font->path() . '#' . $gid;
        if (isset($this->paths[$key])) {
            return $this->paths[$key];
        }

        $num = array('Altegena_Print_Pdf_Writer', 'num');
        $ops = array();
        foreach ($font->glyph_contours($gid) as $contour) {
            $points = self::with_implied_points($contour);
            $count = count($points);
            if ($count < 2) {
                continue;
            }

            $current = $points[0];
            $ops[] = call_user_func($num, $current[0]) . ' ' . call_user_func($num, $current[1]) . ' m';
            $k = 1;
            while ($k <= $count) {
                $point = $points[$k % $count];
                if ($point[2]) {
                    $ops[] = call_user_func($num, $point[0]) . ' ' . call_user_func($num, $point[1]) . ' l';
                    $current = $point;
                    $k++;
                    continue;
                }
                // Off-curve control followed by an on-curve end point (guaranteed by with_implied_points).
                $end = $points[($k + 1) % $count];
                $c1 = array($current[0] + 2 / 3 * ($point[0] - $current[0]), $current[1] + 2 / 3 * ($point[1] - $current[1]));
                $c2 = array($end[0] + 2 / 3 * ($point[0] - $end[0]), $end[1] + 2 / 3 * ($point[1] - $end[1]));
                $ops[] = implode(' ', array_map($num, array($c1[0], $c1[1], $c2[0], $c2[1], $end[0], $end[1]))) . ' c';
                $current = $end;
                $k += 2;
            }
            $ops[] = 'h';
        }

        return $this->paths[$key] = implode("\n", $ops);
    }

    /**
     * Insert the implied on-curve midpoints between consecutive off-curve points
     * and rotate the contour so it starts on an on-curve point.
     */
    private static function with_implied_points($contour)
    {
        $count = count($contour);
        $expanded = array();
        for ($i = 0; $i < $count; $i++) {
            $point = $contour[$i];
            $next = $contour[($i + 1) % $count];
            $expanded[] = $point;
            if (!$point[2] && !$next[2]) {
                $expanded[] = array(($point[0] + $next[0]) / 2, ($point[1] + $next[1]) / 2, true);
            }
        }

        foreach ($expanded as $index => $point) {
            if ($point[2]) {
                return array_merge(array_slice($expanded, $index), array_slice($expanded, 0, $index));
            }
        }
        return array();
    }
}
