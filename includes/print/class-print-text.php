<?php
/**
 * Text helpers for print data.
 *
 * Print text must match what the customer saw in the editor, where layers use
 * `white-space: pre`: leading/trailing spaces and blank lines are visible. So
 * this sanitizer never trims and never strips markup-like sequences — output is
 * escaped wherever the text is rendered instead.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Altegena_Print_Text
{
    const MAX_LENGTH = 2000;

    public static function sanitize($text)
    {
        $text = wp_check_invalid_utf8((string) $text, true);
        $text = str_replace(array("\r\n", "\r", "\u{2028}", "\u{2029}"), "\n", $text);
        $text = str_replace("\t", ' ', $text);
        // Control characters (LF is kept) and bidi overrides/isolates.
        $text = preg_replace('/[\x{0000}-\x{0009}\x{000B}-\x{001F}\x{007F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text);
        if (!is_string($text)) {
            return '';
        }

        if (class_exists('Normalizer')) {
            $normalized = Normalizer::normalize($text, Normalizer::FORM_C);
            if (is_string($normalized)) {
                $text = $normalized;
            }
        }

        return function_exists('mb_substr') ? mb_substr($text, 0, self::MAX_LENGTH) : substr($text, 0, self::MAX_LENGTH);
    }

    /** Unicode code points of a UTF-8 string. */
    public static function codepoints($text)
    {
        $text = (string) $text;
        if ($text === '') {
            return array();
        }

        if (function_exists('mb_convert_encoding')) {
            $values = unpack('N*', mb_convert_encoding($text, 'UTF-32BE', 'UTF-8'));
            return $values ? array_values($values) : array();
        }

        preg_match_all('/./us', $text, $matches);
        $out = array();
        foreach ($matches[0] as $char) {
            $bytes = array_values(unpack('C*', $char));
            switch (count($bytes)) {
                case 1:
                    $out[] = $bytes[0];
                    break;
                case 2:
                    $out[] = (($bytes[0] & 0x1F) << 6) | ($bytes[1] & 0x3F);
                    break;
                case 3:
                    $out[] = (($bytes[0] & 0x0F) << 12) | (($bytes[1] & 0x3F) << 6) | ($bytes[2] & 0x3F);
                    break;
                default:
                    $out[] = (($bytes[0] & 0x07) << 18) | (($bytes[1] & 0x3F) << 12) | (($bytes[2] & 0x3F) << 6) | ($bytes[3] & 0x3F);
            }
        }
        return $out;
    }

    /** UTF-8 encoding of a single code point. */
    public static function chr_utf8($cp)
    {
        if ($cp < 0x80) {
            return chr($cp);
        }
        if ($cp < 0x800) {
            return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
        }
        if ($cp < 0x10000) {
            return chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
        }
        return chr(0xF0 | ($cp >> 18)) . chr(0x80 | (($cp >> 12) & 0x3F)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
    }
}
