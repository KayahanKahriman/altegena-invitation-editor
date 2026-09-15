<?php
/**
 * Invitation design config helpers shared by sharing and print.
 *
 * The product meta `_invitation_json_config` is the trusted template: styles,
 * positions, fonts and canvas always come from here, never from the client.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Altegena_Design_Config
{
    const META_KEY = '_invitation_json_config';
    const MAX_SHARE_TEXT_LENGTH = 2000;

    /**
     * @return array|WP_Error {raw, config}
     */
    public static function get_template($product_id)
    {
        $raw = get_post_meta($product_id, self::META_KEY, true);
        if (empty($raw) || !is_string($raw)) {
            return new WP_Error('bad_config', 'Şablon bulunamadı.');
        }

        $config = json_decode($raw, true);
        if (!is_array($config) || !isset($config['canvas']) || !isset($config['layers']) || !is_array($config['layers'])) {
            return new WP_Error('bad_config', 'Şablon geçersiz.');
        }

        return array('raw' => $raw, 'config' => $config);
    }

    /**
     * Merge the trusted template with client text: each layer whose id is in
     * $text_map gets its default_text replaced by the sanitized text.
     *
     * @return array|WP_Error merged config
     */
    public static function merge($product_id, $text_map, $sanitizer)
    {
        $template = self::get_template($product_id);
        if (is_wp_error($template)) {
            return $template;
        }

        $config = $template['config'];
        foreach ($config['layers'] as &$layer) {
            if (!isset($layer['id'])) {
                continue;
            }
            $id = $layer['id'];
            if (isset($text_map[$id]) && is_array($text_map[$id]) && isset($text_map[$id]['text'])) {
                $layer['default_text'] = call_user_func($sanitizer, (string) $text_map[$id]['text']);
            }
        }
        unset($layer);

        return $config;
    }

    /** Text sanitizer used by the public share page. */
    public static function sanitize_share_text($text)
    {
        $text = sanitize_textarea_field($text);
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, self::MAX_SHARE_TEXT_LENGTH);
        }
        return substr($text, 0, self::MAX_SHARE_TEXT_LENGTH);
    }

    /** A layer's template text with literal "\n" sequences as real newlines (as editor.js renders it). */
    public static function template_text($layer)
    {
        $text = isset($layer['default_text']) && is_scalar($layer['default_text']) ? (string) $layer['default_text'] : '';
        return str_replace('\\n', "\n", $text);
    }

    /**
     * Immutable per-order-item snapshot for print: the trusted template layout
     * plus the text each layer showed to the customer.
     *
     * @param array $text_map {layer_id: {text, ...}} as stored in the cart item
     * @return array|WP_Error
     */
    public static function build_snapshot($product_id, $variation_id, $text_map, $backfilled = false)
    {
        $template = self::get_template($product_id);
        if (is_wp_error($template)) {
            return $template;
        }

        $registry = Altegena_Print_Font_Registry::get_instance();
        $config = $template['config'];
        $text_map = is_array($text_map) ? $text_map : array();
        $layers = array();
        $fonts = array();

        foreach ($config['layers'] as $layer) {
            if (!is_array($layer) || !isset($layer['id']) || !is_scalar($layer['id'])) {
                continue;
            }
            if (isset($layer['type']) && $layer['type'] !== 'text') {
                continue;
            }

            $id = (string) $layer['id'];
            $style = isset($layer['style']) && is_array($layer['style']) ? $layer['style'] : array();
            $hidden = !empty($layer['hidden_on_frontend']);

            // Fixed (hidden) layers can't be edited by the customer, so client text is ignored for them.
            $text = self::template_text($layer);
            if (!$hidden && isset($text_map[$id]) && is_array($text_map[$id]) && isset($text_map[$id]['text']) && is_scalar($text_map[$id]['text'])) {
                $text = Altegena_Print_Text::sanitize((string) $text_map[$id]['text']);
            }

            $layers[] = array(
                'id' => $id,
                'label' => isset($layer['label']) && is_scalar($layer['label']) ? (string) $layer['label'] : '',
                'hidden_on_frontend' => $hidden,
                'style' => $style,
                'text' => $text,
            );

            $family = Altegena_Print_Font_Registry::clean_family(isset($style['fontFamily']) ? $style['fontFamily'] : '');
            $weight = Altegena_Print_Font_Registry::normalize_weight(isset($style['fontWeight']) ? $style['fontWeight'] : '');
            $font_style = Altegena_Print_Font_Registry::normalize_style(isset($style['fontStyle']) ? $style['fontStyle'] : '');
            $key = $family . '|' . $weight . '|' . $font_style;
            if (isset($fonts[$key])) {
                continue;
            }

            $match = $registry->match($family, $weight, $font_style);
            if (is_wp_error($match)) {
                $fonts[$key] = array('error' => $match->get_error_code());
            } else {
                $fonts[$key] = array(
                    'file' => $match['face']['basename'],
                    'sha1' => Altegena_Print_Font_Registry::file_sha1($match['face']['file']),
                    'synthetic_bold' => $match['synthetic_bold'],
                    'synthetic_italic' => $match['synthetic_italic'],
                );
            }
        }

        return array(
            'schema' => 1,
            'captured_at' => gmdate('c'),
            'product_id' => (int) $product_id,
            'variation_id' => (int) $variation_id,
            'template_sha1' => sha1($template['raw']),
            'backfilled' => (bool) $backfilled,
            'canvas' => is_array($config['canvas']) ? $config['canvas'] : array(),
            'layers' => $layers,
            'fonts' => $fonts,
        );
    }
}
