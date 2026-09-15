<?php
/**
 * Dependency-free PDF 1.7 writer for print output.
 *
 * Supports exactly what the print engine needs: pages with content streams,
 * Flate compression, JPEG pass-through images (other formats via GD) and
 * TrueType/CID font objects added by the text emitter.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Altegena_Print_Pdf_Writer
{
    private $objects = array();
    private $page_ids = array();
    private $next_id = 3; // 1 = Catalog, 2 = Pages

    public function reserve_id()
    {
        return $this->next_id++;
    }

    public function set_object($id, $body)
    {
        $this->objects[$id] = $body;
    }

    public function add_object($body)
    {
        $id = $this->reserve_id();
        $this->objects[$id] = $body;
        return $id;
    }

    /**
     * @param string $dictionary extra dictionary entries (without << >>)
     */
    public function add_stream($dictionary, $data, $compress = true)
    {
        if ($compress && function_exists('gzcompress')) {
            $data = gzcompress($data, 6);
            $dictionary = '/Filter /FlateDecode ' . $dictionary;
        }
        return $this->add_object('<< ' . trim($dictionary) . ' /Length ' . strlen($data) . " >>\nstream\n" . $data . "\nendstream");
    }

    /**
     * Add an image file as an XObject.
     *
     * @return array|WP_Error {id, width, height}
     */
    public function add_image($path)
    {
        $bytes = is_readable($path) ? file_get_contents($path) : false;
        if ($bytes === false || $bytes === '') {
            return new WP_Error('background_unreadable', sprintf('Arka plan görseli okunamadı: %s', basename($path)));
        }

        if (substr($bytes, 0, 3) === "\xFF\xD8\xFF") {
            $jpeg = self::jpeg_info($bytes);
            if ($jpeg && !$jpeg['progressive_unsupported']) {
                return $this->add_jpeg_bytes($bytes, $jpeg);
            }
        }

        return $this->add_image_via_gd($bytes, basename($path));
    }

    private function add_jpeg_bytes($bytes, $info)
    {
        $color_space = '/DeviceRGB';
        $decode = '';
        if ($info['components'] === 1) {
            $color_space = '/DeviceGray';
        } elseif ($info['components'] === 4) {
            $color_space = '/DeviceCMYK';
            // Adobe-marked CMYK JPEGs are stored inverted.
            if ($info['adobe']) {
                $decode = ' /Decode [1 0 1 0 1 0 1 0]';
            }
        }

        $id = $this->add_object(sprintf(
            "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace %s /BitsPerComponent 8%s /Filter /DCTDecode /Length %d >>\nstream\n%s\nendstream",
            $info['width'],
            $info['height'],
            $color_space,
            $decode,
            strlen($bytes),
            $bytes
        ));

        return array('id' => $id, 'width' => $info['width'], 'height' => $info['height']);
    }

    private function add_image_via_gd($bytes, $name)
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            return new WP_Error('background_unsupported', sprintf('Arka plan görsel biçimi desteklenmiyor (GD yok): %s', $name));
        }

        $size = @getimagesizefromstring($bytes);
        if (!$size) {
            return new WP_Error('background_unsupported', sprintf('Arka plan görseli çözülemedi: %s', $name));
        }

        // Decoded GD images need roughly width x height x 5 bytes; refuse before running out of memory.
        $needed = $size[0] * $size[1] * 5 + memory_get_usage();
        $limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        if ($limit > 0 && $needed > $limit) {
            return new WP_Error('background_too_large', sprintf('Arka plan görseli bellek sınırı için çok büyük (%d×%d). JPEG olarak yükleyin.', $size[0], $size[1]));
        }

        $image = @imagecreatefromstring($bytes);
        if (!$image) {
            return new WP_Error('background_unsupported', sprintf('Arka plan görseli çözülemedi: %s', $name));
        }

        // Flatten transparency on white, as the browser shows the card over a white page.
        $width = imagesx($image);
        $height = imagesy($image);
        $flat = imagecreatetruecolor($width, $height);
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $image, 0, 0, 0, 0, $width, $height);
        imagedestroy($image);

        ob_start();
        imagejpeg($flat, null, 95);
        $jpeg = ob_get_clean();
        imagedestroy($flat);

        $info = self::jpeg_info($jpeg);
        if (!$info) {
            return new WP_Error('background_unsupported', sprintf('Arka plan görseli dönüştürülemedi: %s', $name));
        }
        return $this->add_jpeg_bytes($jpeg, $info);
    }

    /** Parse JPEG SOF marker: dimensions, components, Adobe marker. */
    private static function jpeg_info($bytes)
    {
        $length = strlen($bytes);
        $pos = 2;
        $adobe = false;
        while ($pos + 4 <= $length) {
            if (ord($bytes[$pos]) !== 0xFF) {
                $pos++;
                continue;
            }
            $marker = ord($bytes[$pos + 1]);
            if ($marker === 0xD8 || ($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01 || $marker === 0xFF) {
                $pos += ($marker === 0xFF) ? 1 : 2;
                continue;
            }
            $segment = (ord($bytes[$pos + 2]) << 8) | ord($bytes[$pos + 3]);
            if ($marker === 0xEE && substr($bytes, $pos + 4, 5) === 'Adobe') {
                $adobe = true;
            }
            // SOF0..SOF15 except DHT (C4), JPG (C8), DAC (CC).
            if ($marker >= 0xC0 && $marker <= 0xCF && !in_array($marker, array(0xC4, 0xC8, 0xCC), true)) {
                return array(
                    'height' => (ord($bytes[$pos + 5]) << 8) | ord($bytes[$pos + 6]),
                    'width' => (ord($bytes[$pos + 7]) << 8) | ord($bytes[$pos + 8]),
                    'components' => ord($bytes[$pos + 9]),
                    'adobe' => $adobe,
                    // Lossless/hierarchical/arithmetic JPEGs aren't reliably supported by PDF viewers.
                    'progressive_unsupported' => !in_array($marker, array(0xC0, 0xC1, 0xC2), true),
                );
            }
            $pos += 2 + $segment;
        }
        return null;
    }

    /**
     * @param string $content    page content stream (uncompressed)
     * @param string $resources  resource dictionary body (without << >>)
     */
    public function add_page($width_pt, $height_pt, $content, $resources)
    {
        $content_id = $this->add_stream('', $content);
        $box = sprintf('[0 0 %s %s]', self::num($width_pt), self::num($height_pt));
        $page_id = $this->add_object(sprintf(
            '<< /Type /Page /Parent 2 0 R /MediaBox %s /TrimBox %s /Resources << %s >> /Contents %d 0 R >>',
            $box,
            $box,
            $resources,
            $content_id
        ));
        $this->page_ids[] = $page_id;
        return $page_id;
    }

    public function output($title = '')
    {
        $this->objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = array();
        foreach ($this->page_ids as $id) {
            $kids[] = $id . ' 0 R';
        }
        $this->objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($this->page_ids) . ' >>';

        $info_id = $this->add_object(sprintf(
            '<< /Producer %s /Creator %s /Title %s /CreationDate %s >>',
            self::text_string('Altegena Invitation Editor ' . (defined('ALTEGENA_VERSION') ? ALTEGENA_VERSION : '')),
            self::text_string('Altegena Print'),
            self::text_string($title),
            self::text_string('D:' . gmdate('YmdHis') . 'Z')
        ));

        ksort($this->objects);
        $pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $offsets = array();
        foreach ($this->objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }

        $max_id = max(array_keys($this->objects));
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . ($max_id + 1) . "\n0000000000 65535 f \n";
        for ($id = 1; $id <= $max_id; $id++) {
            $pdf .= isset($offsets[$id]) ? sprintf("%010d 00000 n \n", $offsets[$id]) : "0000000000 65535 f \n";
        }

        $file_id = md5($pdf);
        $pdf .= sprintf(
            "trailer\n<< /Size %d /Root 1 0 R /Info %d 0 R /ID [<%s> <%s>] >>\nstartxref\n%d\n%%%%EOF\n",
            $max_id + 1,
            $info_id,
            $file_id,
            $file_id,
            $xref
        );

        return $pdf;
    }

    /** PDF number: fixed notation, up to 4 decimals, no trailing zeros, locale independent. */
    public static function num($value)
    {
        $formatted = rtrim(rtrim(sprintf('%.4F', (float) $value), '0'), '.');
        return ($formatted === '-0' || $formatted === '') ? '0' : $formatted;
    }

    /** PDF text string encoded as UTF-16BE with BOM (hex). */
    public static function text_string($text)
    {
        $utf16 = function_exists('mb_convert_encoding') ? mb_convert_encoding((string) $text, 'UTF-16BE', 'UTF-8') : (string) $text;
        return '<FEFF' . strtoupper(bin2hex($utf16)) . '>';
    }
}
