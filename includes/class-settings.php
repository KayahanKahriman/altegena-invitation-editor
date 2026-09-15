<?php
/**
 * Settings + extension API for Altegena Invitation Editor.
 *
 * Provides an admin settings page (Settings > Altegena Davetiye) and a small
 * static API used across the plugin so it stays theme-agnostic:
 *   - Altegena_Settings::text($key)   labels/messages (setting -> built-in default -> `altegena_$key` filter)
 *   - Altegena_Settings::visibility() personalization visibility (admin_only|customer_and_admin|hidden)
 *   - Altegena_Settings::css_vars()   :root CSS custom properties fed to the front stylesheets
 *   - Altegena_Settings::get($key)    raw option value
 *
 * Themes/sites customise either from this page or via the documented filters.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Altegena_Settings
{

    private static $instance = null;
    private static $cache = null;

    const OPTION = 'altegena_settings';
    const MENU_SLUG = 'altegena-invitation-editor';
    const GROUP = 'altegena_settings_group';

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('admin_menu', array($this, 'add_page'));
        add_action('admin_init', array($this, 'register'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    /* ---------------------------------------------------------------------
     * Defaults
     * ------------------------------------------------------------------- */

    public static function defaults()
    {
        return array(
            'personalization_visibility' => 'admin_only',
            'accent_color'      => '#d4af37',
            'share_color'       => '#25D366',
            'label_customize'   => '',
            'label_add_to_cart' => '',
            'label_share'       => '',
            'modal_title'       => '',
            'share_message'     => '',
            'og_title'          => '',
            'og_description'    => '',
            'print_default_line_height' => 1.55,
        );
    }

    /** Built-in text used when a label/message field is left blank. */
    public static function text_defaults()
    {
        return array(
            'label_customize'   => 'Davetiyeni Özelleştir',
            'label_add_to_cart' => 'Sepete Ekle',
            'label_share'       => "WhatsApp'ta Paylaş",
            'modal_title'       => 'Tasarımcı',
            'share_message'     => 'Davetiyemize göz atın:',
            'og_title'          => 'Davetiyemize Davetlisiniz',
            'og_description'    => 'Özel günümüz için hazırladığımız davetiyeyi görüntüleyin.',
        );
    }

    /* ---------------------------------------------------------------------
     * Read API
     * ------------------------------------------------------------------- */

    public static function all()
    {
        if (self::$cache === null) {
            self::$cache = wp_parse_args((array) get_option(self::OPTION, array()), self::defaults());
        }
        return self::$cache;
    }

    /** Raw value with a caller-supplied default when empty. */
    public static function get($key, $default = null)
    {
        $all = self::all();
        return (isset($all[$key]) && $all[$key] !== '') ? $all[$key] : $default;
    }

    /**
     * A label/message: configured value, else built-in default, then passed
     * through the `altegena_$key` filter (e.g. `altegena_label_share`).
     */
    public static function text($key)
    {
        $all = self::all();
        $td  = self::text_defaults();
        $val = (isset($all[$key]) && $all[$key] !== '') ? $all[$key] : (isset($td[$key]) ? $td[$key] : '');
        return apply_filters('altegena_' . $key, $val);
    }

    /** admin_only | customer_and_admin | hidden (filterable). */
    public static function visibility()
    {
        $v = self::get('personalization_visibility', 'admin_only');
        if (!in_array($v, array('admin_only', 'customer_and_admin', 'hidden'), true)) {
            $v = 'admin_only';
        }
        return apply_filters('altegena_personalization_visibility', $v);
    }

    /** `:root{...}` custom properties fed to the front stylesheets. */
    public static function css_vars()
    {
        $accent = self::get('accent_color', '#d4af37');
        $share  = self::get('share_color', '#25D366');

        $vars = apply_filters('altegena_colors', array(
            '--altegena-accent'      => $accent,
            '--altegena-accent-dark' => self::darken($accent, 12),
            '--altegena-share'       => $share,
            '--altegena-share-dark'  => self::darken($share, 10),
        ));

        // Not a color, so it is added after the altegena_colors filter.
        $vars['--altegena-line-height'] = self::css_number(self::line_height());

        $out = ':root{';
        foreach ($vars as $k => $v) {
            $out .= $k . ':' . $v . ';';
        }
        $out .= '}';
        return $out;
    }

    /** Unitless line-height for layers without their own (editor, share page and print PDF). */
    public static function line_height()
    {
        $value = (float) self::get('print_default_line_height', 1.55);
        if ($value <= 0 || $value > 5) {
            $value = 1.55;
        }
        return (float) apply_filters('altegena_default_line_height', $value);
    }

    /** Locale-independent number formatting for CSS output. */
    private static function css_number($value)
    {
        return rtrim(rtrim(sprintf('%.4F', (float) $value), '0'), '.');
    }

    /** Darken a hex color by an absolute amount (percent of 255). */
    private static function darken($hex, $percent)
    {
        $hex = ltrim((string) $hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return '#' . $hex;
        }
        $step = round(255 * $percent / 100);
        $r = max(0, hexdec(substr($hex, 0, 2)) - $step);
        $g = max(0, hexdec(substr($hex, 2, 2)) - $step);
        $b = max(0, hexdec(substr($hex, 4, 2)) - $step);
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    /* ---------------------------------------------------------------------
     * Admin settings page
     * ------------------------------------------------------------------- */

    public function add_page()
    {
        add_options_page(
            'Altegena Davetiye Ayarları',
            'Altegena Davetiye',
            'manage_options',
            self::MENU_SLUG,
            array($this, 'render_page')
        );
    }

    public function register()
    {
        register_setting(self::GROUP, self::OPTION, array(
            'type'              => 'array',
            'sanitize_callback' => array($this, 'sanitize'),
            'default'           => self::defaults(),
        ));
    }

    public function sanitize($input)
    {
        $d = self::defaults();
        $input = is_array($input) ? $input : array();
        $out = array();

        $vis = isset($input['personalization_visibility']) ? $input['personalization_visibility'] : $d['personalization_visibility'];
        $out['personalization_visibility'] = in_array($vis, array('admin_only', 'customer_and_admin', 'hidden'), true) ? $vis : 'admin_only';

        foreach (array('accent_color', 'share_color') as $ck) {
            $val = isset($input[$ck]) ? sanitize_hex_color($input[$ck]) : '';
            $out[$ck] = $val ? $val : $d[$ck];
        }

        foreach (array('label_customize', 'label_add_to_cart', 'label_share', 'modal_title', 'share_message', 'og_title', 'og_description') as $tk) {
            $out[$tk] = isset($input[$tk]) ? sanitize_text_field($input[$tk]) : '';
        }

        $line_height = isset($input['print_default_line_height']) ? (float) str_replace(',', '.', (string) $input['print_default_line_height']) : 0;
        $out['print_default_line_height'] = ($line_height > 0 && $line_height <= 5) ? round($line_height, 3) : $d['print_default_line_height'];

        return $out;
    }

    public function enqueue_assets($hook)
    {
        if ($hook !== 'settings_page_' . self::MENU_SLUG) {
            return;
        }
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        wp_add_inline_script('wp-color-picker', 'jQuery(function($){$(".altegena-color-field").wpColorPicker();});');
    }

    public function render_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $o  = self::all();
        $td = self::text_defaults();

        $text_field = function ($key, $label, $desc) use ($o, $td) {
            $ph = isset($td[$key]) ? $td[$key] : '';
            printf(
                '<tr><th scope="row"><label for="altegena-%1$s">%2$s</label></th><td>' .
                '<input type="text" id="altegena-%1$s" name="%3$s[%1$s]" value="%4$s" class="regular-text" placeholder="%5$s" />' .
                '<p class="description">%6$s</p></td></tr>',
                esc_attr($key),
                esc_html($label),
                esc_attr(self::OPTION),
                esc_attr($o[$key]),
                esc_attr($ph),
                esc_html($desc)
            );
        };
        $tab = (isset($_GET['tab']) && $_GET['tab'] === 'fonts') ? 'fonts' : 'general';
        $page_url = admin_url('options-general.php?page=' . self::MENU_SLUG);
        ?>
        <div class="wrap">
            <h1>Altegena Davetiye Ayarları</h1>
            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url($page_url); ?>" class="nav-tab<?php echo $tab === 'general' ? ' nav-tab-active' : ''; ?>">Genel</a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'fonts', $page_url)); ?>" class="nav-tab<?php echo $tab === 'fonts' ? ' nav-tab-active' : ''; ?>">Font denetimi</a>
            </nav>
            <?php
            if ($tab === 'fonts') {
                Altegena_Print_Font_Audit::get_instance()->render();
                echo '</div>';
                return;
            }
            ?>
            <form method="post" action="options.php">
                <?php settings_fields(self::GROUP); ?>
                <table class="form-table" role="presentation">

                    <tr>
                        <th scope="row"><label for="altegena-visibility">Kişiselleştirme görünürlüğü</label></th>
                        <td>
                            <select id="altegena-visibility" name="<?php echo esc_attr(self::OPTION); ?>[personalization_visibility]">
                                <option value="admin_only" <?php selected($o['personalization_visibility'], 'admin_only'); ?>>Müşteriden gizle, admin'de göster</option>
                                <option value="customer_and_admin" <?php selected($o['personalization_visibility'], 'customer_and_admin'); ?>>Müşteriye de göster</option>
                                <option value="hidden" <?php selected($o['personalization_visibility'], 'hidden'); ?>>Her yerde gizle</option>
                            </select>
                            <p class="description">Davetiye metinlerinin sepet/checkout/sipariş görünümünde nerede gösterileceği. Admin sipariş ekranı baskı için referans alır.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="altegena-accent">Vurgu rengi</label></th>
                        <td><input type="text" id="altegena-accent" class="altegena-color-field" name="<?php echo esc_attr(self::OPTION); ?>[accent_color]" value="<?php echo esc_attr($o['accent_color']); ?>" data-default-color="#d4af37" />
                            <p class="description">Sepete Ekle butonu ve editör vurguları (tema kendi stilini uyguluyorsa geçersiz olabilir).</p></td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="altegena-share">WhatsApp buton rengi</label></th>
                        <td><input type="text" id="altegena-share" class="altegena-color-field" name="<?php echo esc_attr(self::OPTION); ?>[share_color]" value="<?php echo esc_attr($o['share_color']); ?>" data-default-color="#25D366" />
                            <p class="description">Paylaş butonlarının rengi.</p></td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="altegena-line-height">Varsayılan satır yüksekliği</label></th>
                        <td><input type="number" step="0.01" min="0.1" max="5" id="altegena-line-height" class="small-text" name="<?php echo esc_attr(self::OPTION); ?>[print_default_line_height]" value="<?php echo esc_attr(self::css_number($o['print_default_line_height'])); ?>" />
                            <p class="description">Kendi satır yüksekliği olmayan katmanlarda kullanılır. Editör, paylaşım sayfası ve baskı PDF'i aynı değeri kullanır (varsayılan: 1.55).</p></td>
                    </tr>

                    <?php
                    $text_field('label_customize', 'Özelleştir buton metni', 'Boş bırakılırsa: ' . $td['label_customize']);
                    $text_field('label_add_to_cart', 'Sepete Ekle metni', 'Boş bırakılırsa: ' . $td['label_add_to_cart']);
                    $text_field('label_share', 'Paylaş buton metni', 'Boş bırakılırsa: ' . $td['label_share']);
                    $text_field('modal_title', 'Modal başlığı', 'Boş bırakılırsa: ' . $td['modal_title']);
                    $text_field('share_message', 'WhatsApp paylaşım mesajı', 'Boş bırakılırsa: ' . $td['share_message']);
                    $text_field('og_title', 'Paylaşım sayfası OG başlık', 'Boş bırakılırsa: ' . $td['og_title']);
                    $text_field('og_description', 'Paylaşım sayfası OG açıklama', 'Boş bırakılırsa: ' . $td['og_description']);
                    ?>

                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
