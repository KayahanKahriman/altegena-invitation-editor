<?php
/**
 * Font audit (Settings > Altegena Davetiye > Font denetimi).
 *
 * Print never falls back to another font, so templates must reference fonts
 * that exist and fonts must contain every character customers type. This page
 * lists template layers whose font file is missing (with safe, spelling-only
 * fixes) and a per-font character coverage report.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Altegena_Print_Font_Audit
{
    const TURKISH_CHARS = 'ÇçĞğİıÖöŞşÜü';
    const BACKUP_META_KEY = '_invitation_json_config_font_fix_backup';

    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('admin_post_altegena_font_safe_fix', array($this, 'handle_safe_fix'));
    }

    private function template_product_ids()
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' ORDER BY post_id",
            Altegena_Design_Config::META_KEY
        ));
        return array_map('intval', $ids);
    }

    /**
     * A spelling-only fix for an unknown family: an existing family with the
     * same name once spaces/hyphens are ignored (e.g. "TrajanPro-Bold" ->
     * "Trajan Pro" + bold), and only if that exact weight/style file exists.
     *
     * @return array|null style properties to set (null value = remove the property)
     */
    public function safe_fix_for($family, $weight, $style)
    {
        $registry = Altegena_Print_Font_Registry::get_instance();
        $suffixes = array(
            'thin' => array(100, null), 'extralight' => array(200, null), 'light' => array(300, null),
            'regular' => array(400, null), 'book' => array(400, null), 'medium' => array(500, null),
            'semibold' => array(600, null), 'demibold' => array(600, null), 'bold' => array(700, null),
            'extrabold' => array(800, null), 'black' => array(900, null),
            'italic' => array(null, 'italic'), 'bolditalic' => array(700, 'italic'),
        );

        $candidates = array(array(self::normalize_name($family), $weight, $style));
        if (preg_match('/^(.+?)[\s_-]*(' . implode('|', array_keys($suffixes)) . ')$/i', $family, $match)) {
            list($suffix_weight, $suffix_style) = $suffixes[strtolower($match[2])];
            $candidates[] = array(
                self::normalize_name($match[1]),
                $suffix_weight !== null ? $suffix_weight : $weight,
                $suffix_style !== null ? $suffix_style : $style,
            );
        }

        foreach ($candidates as $candidate) {
            list($name, $target_weight, $target_style) = $candidate;
            foreach ($registry->families() as $known) {
                if (self::normalize_name($known) !== $name) {
                    continue;
                }
                foreach ($registry->faces($known) as $face) {
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

    private static function normalize_name($name)
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower((string) $name));
    }

    public function analyze()
    {
        $registry = Altegena_Print_Font_Registry::get_instance();
        $issues = array();
        $synthetic = array();
        $usage = array();

        foreach ($this->template_product_ids() as $product_id) {
            $template = Altegena_Design_Config::get_template($product_id);
            if (is_wp_error($template)) {
                $issues[] = array('product_id' => $product_id, 'layer' => '', 'family' => '', 'weight' => 400, 'style' => 'normal', 'message' => $template->get_error_message(), 'fix' => null);
                continue;
            }

            foreach ($template['config']['layers'] as $layer) {
                if (!is_array($layer)) {
                    continue;
                }
                $style = isset($layer['style']) && is_array($layer['style']) ? $layer['style'] : array();
                $family = Altegena_Print_Font_Registry::clean_family(isset($style['fontFamily']) ? $style['fontFamily'] : '');
                $weight = Altegena_Print_Font_Registry::normalize_weight(isset($style['fontWeight']) ? $style['fontWeight'] : '');
                $font_style = Altegena_Print_Font_Registry::normalize_style(isset($style['fontStyle']) ? $style['fontStyle'] : '');
                $label = isset($layer['label']) && is_scalar($layer['label']) ? (string) $layer['label'] : (isset($layer['id']) ? (string) $layer['id'] : '');

                $match = $registry->match($family, $weight, $font_style);
                if (is_wp_error($match)) {
                    $issues[] = array(
                        'product_id' => $product_id,
                        'layer' => $label,
                        'family' => $family,
                        'weight' => $weight,
                        'style' => $font_style,
                        'message' => $match->get_error_message(),
                        'fix' => $this->safe_fix_for($family, $weight, $font_style),
                    );
                    continue;
                }

                if ($match['synthetic_bold'] || $match['synthetic_italic']) {
                    $synthetic[] = array('product_id' => $product_id, 'layer' => $label, 'family' => $family, 'bold' => $match['synthetic_bold'], 'italic' => $match['synthetic_italic']);
                }

                $key = $match['face']['file'];
                if (!isset($usage[$key])) {
                    $usage[$key] = array('layers' => 0, 'text' => '');
                }
                $usage[$key]['layers']++;
                $usage[$key]['text'] .= Altegena_Design_Config::template_text($layer);
            }
        }

        $coverage = array();
        foreach ($registry->all_faces() as $face) {
            $row = array('face' => $face, 'layers' => 0, 'turkish' => array(), 'text' => array(), 'error' => '', 'embeddable' => true, 'cff' => false);
            if (isset($usage[$face['file']])) {
                $row['layers'] = $usage[$face['file']]['layers'];
            }

            $font = Altegena_Print_Font::load($face['file']);
            if (is_wp_error($font)) {
                $row['error'] = $font->get_error_message();
            } else {
                $row['turkish'] = $font->missing_codepoints(self::TURKISH_CHARS);
                if ($row['layers']) {
                    $row['text'] = array_values(array_diff($font->missing_codepoints($usage[$face['file']]['text']), $row['turkish']));
                }
                $row['embeddable'] = $font->embedding_allowed();
                $row['cff'] = $font->is_cff();
            }
            $coverage[] = $row;
        }

        usort($coverage, function ($a, $b) {
            return $b['layers'] - $a['layers'];
        });

        return array('issues' => $issues, 'synthetic' => $synthetic, 'coverage' => $coverage);
    }

    public function handle_safe_fix()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Yetkiniz yok.', 403);
        }
        check_admin_referer('altegena_font_safe_fix');

        $registry = Altegena_Print_Font_Registry::get_instance();
        $fixed = 0;

        foreach ($this->template_product_ids() as $product_id) {
            $raw = get_post_meta($product_id, Altegena_Design_Config::META_KEY, true);
            // Decode as objects so empty "{}" values survive re-encoding unchanged.
            $config = is_string($raw) ? json_decode($raw) : null;
            if (!is_object($config) || !isset($config->layers) || !is_array($config->layers)) {
                continue;
            }

            $changed = 0;
            foreach ($config->layers as $layer) {
                if (!is_object($layer) || !isset($layer->style) || !is_object($layer->style)) {
                    continue;
                }
                $style = $layer->style;
                $family = Altegena_Print_Font_Registry::clean_family(isset($style->fontFamily) ? $style->fontFamily : '');
                $weight = Altegena_Print_Font_Registry::normalize_weight(isset($style->fontWeight) ? $style->fontWeight : '');
                $font_style = Altegena_Print_Font_Registry::normalize_style(isset($style->fontStyle) ? $style->fontStyle : '');
                if (!is_wp_error($registry->match($family, $weight, $font_style))) {
                    continue;
                }

                $fix = $this->safe_fix_for($family, $weight, $font_style);
                if (!$fix) {
                    continue;
                }
                foreach ($fix as $property => $value) {
                    if ($value === null) {
                        unset($style->$property);
                    } else {
                        $style->$property = $value;
                    }
                }
                $changed++;
            }

            if ($changed) {
                // Keep the first pre-fix version so the change can be reverted by hand.
                if (!metadata_exists('post', $product_id, self::BACKUP_META_KEY)) {
                    add_post_meta($product_id, self::BACKUP_META_KEY, wp_slash($raw), true);
                }
                update_post_meta($product_id, Altegena_Design_Config::META_KEY, wp_slash(wp_json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
                $fixed += $changed;
            }
        }

        wp_safe_redirect(add_query_arg(array(
            'page' => Altegena_Settings::MENU_SLUG,
            'tab' => 'fonts',
            'fixed' => $fixed,
        ), admin_url('options-general.php')));
        exit;
    }

    private static function chars_label($codepoints)
    {
        $chars = array();
        foreach ($codepoints as $cp) {
            $chars[] = $cp === 0x20 ? '(boşluk)' : Altegena_Print_Text::chr_utf8($cp);
        }
        return implode(' ', $chars);
    }

    private static function product_link($product_id)
    {
        $title = get_the_title($product_id);
        $link = get_edit_post_link($product_id);
        $text = ($title !== '' ? $title : '#' . $product_id);
        return $link ? '<a href="' . esc_url($link) . '">' . esc_html($text) . '</a>' : esc_html($text);
    }

    public function render()
    {
        $report = $this->analyze();
        $fixable = 0;
        foreach ($report['issues'] as $issue) {
            if ($issue['fix']) {
                $fixable++;
            }
        }
        ?>
        <?php if (isset($_GET['fixed'])) : ?>
            <div class="notice notice-success"><p><?php echo esc_html(sprintf('%d katmanın fontu düzeltildi. Önceki şablon ürüne yedeklendi.', absint($_GET['fixed']))); ?></p></div>
        <?php endif; ?>

        <p>Baskı PDF'i hiçbir zaman yedek font kullanmaz: her katman şablondaki fontun kendi dosyasıyla basılır. Bu sayfa, dosyası olmayan fontları ve fontlarda eksik karakterleri gösterir.</p>

        <h2>Şablonlarda dosyası olmayan fontlar (<?php echo (int) count($report['issues']); ?>)</h2>
        <?php if (!$report['issues']) : ?>
            <p>Sorun yok.</p>
        <?php else : ?>
            <?php if ($fixable) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="altegena_font_safe_fix">
                    <?php wp_nonce_field('altegena_font_safe_fix'); ?>
                    <p><?php submit_button(sprintf('Güvenli düzeltmeleri uygula (%d katman)', $fixable), 'primary', 'submit', false); ?>
                        <span class="description">Sadece yazım farkı olan ve dosyası birebir mevcut fontlar eşlenir.</span></p>
                </form>
            <?php endif; ?>
            <table class="widefat striped">
                <thead><tr><th>Ürün</th><th>Katman</th><th>Font</th><th>Sorun</th><th>Önerilen düzeltme</th></tr></thead>
                <tbody>
                <?php foreach ($report['issues'] as $issue) : ?>
                    <tr>
                        <td><?php echo self::product_link($issue['product_id']); // escaped inside ?></td>
                        <td><?php echo esc_html($issue['layer']); ?></td>
                        <td><?php echo esc_html($issue['family'] . ' ' . $issue['weight'] . ($issue['style'] === 'italic' ? ' italik' : '')); ?></td>
                        <td><?php echo esc_html($issue['message']); ?></td>
                        <td>
                            <?php
                            if ($issue['fix']) {
                                $parts = array();
                                foreach ($issue['fix'] as $property => $value) {
                                    if ($value !== null) {
                                        $parts[] = $property . ': ' . $value;
                                    }
                                }
                                echo esc_html(implode(', ', $parts));
                            } else {
                                echo 'Font dosyasını ekleyin veya şablonda fontu değiştirin.';
                            }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h2>Karakter kapsama</h2>
        <p class="description">Eksik harfler baskıda başka fontla doldurulmaz; bu karakterleri içeren kalemlerin PDF'i, font güncellenene kadar üretilmez.</p>
        <table class="widefat striped">
            <thead><tr><th>Font</th><th>Dosya</th><th>Kullanan katman</th><th>Eksik Türkçe harfler</th><th>Şablon metinlerinde eksik</th><th>Not</th></tr></thead>
            <tbody>
            <?php foreach ($report['coverage'] as $row) : ?>
                <?php
                $notes = array();
                if ($row['error']) {
                    $notes[] = $row['error'];
                }
                if (!$row['embeddable']) {
                    $notes[] = 'Lisansı gömmeye izin vermiyor (metinli PDF\'te kontur olarak basılır)';
                }
                if ($row['cff']) {
                    $notes[] = 'CFF (OTF) — baskı için TTF dönüşümü gerekir';
                }
                ?>
                <tr>
                    <td><?php echo esc_html($row['face']['family'] . ($row['face']['weight'] !== 400 ? ' ' . $row['face']['weight'] : '') . ($row['face']['style'] === 'italic' ? ' italik' : '')); ?></td>
                    <td><code><?php echo esc_html($row['face']['basename']); ?></code></td>
                    <td><?php echo (int) $row['layers']; ?></td>
                    <td><?php echo $row['turkish'] ? '<strong>' . esc_html(self::chars_label($row['turkish'])) . '</strong>' : '—'; ?></td>
                    <td><?php echo $row['text'] ? '<strong>' . esc_html(self::chars_label($row['text'])) . '</strong>' : '—'; ?></td>
                    <td><?php echo esc_html(implode('; ', $notes)); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h2>Sahte kalın / italik (<?php echo (int) count($report['synthetic']); ?>)</h2>
        <p class="description">Fontun bu ağırlıkta dosyası yok; tarayıcıdaki gibi aynı font kalınlaştırılarak/eğilerek basılır.</p>
        <?php if ($report['synthetic']) : ?>
            <table class="widefat striped">
                <thead><tr><th>Ürün</th><th>Katman</th><th>Font</th><th>Uygulanan</th></tr></thead>
                <tbody>
                <?php foreach ($report['synthetic'] as $row) : ?>
                    <tr>
                        <td><?php echo self::product_link($row['product_id']); // escaped inside ?></td>
                        <td><?php echo esc_html($row['layer']); ?></td>
                        <td><?php echo esc_html($row['family']); ?></td>
                        <td><?php echo esc_html(trim(($row['bold'] ? 'kalın ' : '') . ($row['italic'] ? 'italik' : ''))); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }
}
