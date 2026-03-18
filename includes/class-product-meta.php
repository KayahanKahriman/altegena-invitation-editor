<?php
/**
 * Handles Product Meta for Simple Invitation Editor
 */

if (!defined('ABSPATH')) {
    exit;
}

class SIE_Product_Meta
{

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
        add_action('add_meta_boxes', array($this, 'add_meta_box'));
        add_action('woocommerce_process_product_meta', array($this, 'save_product_data_tab'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    public function add_meta_box()
    {
        add_meta_box(
            'sie_invitation_editor',
            __('Invitation Editor', 'simple-invitation-editor'),
            array($this, 'render_meta_box'),
            'product',
            'normal',
            'high'
        );
    }

    public function enqueue_admin_assets($hook)
    {
        if (!in_array($hook, array('post.php', 'post-new.php'), true)) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'product') {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');

        wp_enqueue_style(
            'sie-fonts-css',
            SIE_PLUGIN_URL . 'assets/css/fonts.css',
            array(),
            filemtime(SIE_PLUGIN_DIR . 'assets/css/fonts.css')
        );

        wp_enqueue_style(
            'sie-admin-editor-css',
            SIE_PLUGIN_URL . 'assets/css/admin-editor.css',
            array('wp-color-picker'),
            filemtime(SIE_PLUGIN_DIR . 'assets/css/admin-editor.css')
        );

        wp_enqueue_script(
            'sie-admin-editor-js',
            SIE_PLUGIN_URL . 'assets/js/admin-editor.js',
            array('jquery', 'wp-color-picker', 'jquery-ui-sortable'),
            filemtime(SIE_PLUGIN_DIR . 'assets/js/admin-editor.js'),
            true
        );

        wp_localize_script('sie-admin-editor-js', 'sie_admin_config', array(
            'fonts' => $this->get_available_fonts(),
            'plugin_url' => SIE_PLUGIN_URL,
        ));
    }

    public function get_available_fonts()
    {
        return array(
            'Aire Light Pro',
            'Amostely Signature',
            'Andellia Davilton',
            'Angers Script',
            'Anthem Of The Angels',
            'Ayalena',
            'Avant Garde',
            'Blacksword',
            'Belgedes',
            'Breakfast And Chill',
            'CAC Champagne',
            'Champignon',
            'Candlescript',
            'Christmas Wish Calligraphy',
            'Darleston',
            'Fenice',
            'Flemish Script',
            'Fleur De Leah',
            'Fragrance',
            'Futura Book',
            'Kastangel',
            'Lovely Home',
            'Madina',
            'Mokka',
            'Marquette',
            'Mussica Swash',
            'Neutraface Condensed',
            'Nexa Light',
            'Opel Sans',
            'Optimus Princeps',
            'Perpetua',
            'Playball',
            'Queen Xylophia',
            'Riesling',
            'Rosellinda Alyamore',
            'Silk Script',
            'Silmastin',
            'Sweety Lovers',
            'Trajan Pro',
            'University Roman',
            'Vollkorn SC',
            'Vollkorn SC Bold',
            'Vollkorn SC Bold Italic',
            'Yaquote Script',
            'Zalitta',
        );
    }

    public function render_meta_box($post)
    {
        $config = get_post_meta($post->ID, '_invitation_json_config', true);
        ?>

        <!-- Hidden input for form submission -->
        <input type="hidden" id="sie_invitation_json_config" name="sie_invitation_json_config"
            value="<?php echo esc_attr($config); ?>">

        <!-- Tab Navigation -->
        <div class="sie-admin-tabs">
            <button type="button" class="sie-admin-tab-btn active" data-tab="visual">Görsel Düzenleyici
            </button>
            <button type="button" class="sie-admin-tab-btn" data-tab="json">JSON</button>
        </div>

        <!-- Visual Editor Tab -->
        <div id="sie-tab-visual" class="sie-admin-tab-content active">
            <div id="sie-admin-visual-editor" class="sie-admin-editor">

                <!-- Left Panel: Canvas Settings + Layer List -->
                <div class="sie-admin-left-panel">
                    <div class="sie-admin-panel-section">
                        <h4>Tuval Ayarları</h4>
                        <div class="sie-admin-field-row">
                            <div class="sie-admin-field">
                                <label for="sie-canvas-width">Genişlik (px)</label>
                                <input type="number" id="sie-canvas-width" min="100" max="5000" value="1200">
                            </div>
                            <div class="sie-admin-field">
                                <label for="sie-canvas-height">Yükseklik (px)</label>
                                <input type="number" id="sie-canvas-height" min="100" max="5000" value="1800">
                            </div>
                        </div>
                        <div class="sie-admin-field">
                            <label>Arka Plan Görseli</label>
                            <div class="sie-admin-bg-field">
                                <input type="text" id="sie-bg-url" readonly placeholder="Görsel seçilmedi">
                                <button type="button" class="button" id="sie-bg-select-btn">Seç</button>
                            </div>
                            <div class="sie-admin-bg-preview"></div>
                        </div>
                    </div>

                    <div class="sie-admin-panel-section" style="flex: 1;">
                        <h4>Katmanlar</h4>
                        <ul class="sie-admin-layer-list"></ul>
                        <button type="button" class="button sie-admin-add-layer-btn">+ Katman Ekle</button>
                    </div>
                </div>

                <!-- Center Panel: Canvas Preview -->
                <div class="sie-admin-canvas-area">
                    <button type="button" class="sie-admin-fullscreen-btn" title="Tam Ekran Yap / Çık"><span
                            class="dashicons dashicons-editor-expand"></span></button>
                    <div class="sie-admin-canvas"></div>
                </div>

                <!-- Right Panel: Layer Properties -->
                <div class="sie-admin-right-panel hidden">
                    <div class="sie-admin-canvas-align-section" style="display:none">
                        <div class="sie-admin-panel-section">
                            <h4>Canvas'a Hizala</h4>
                            <div class="sie-admin-align-buttons">
                                <button type="button" class="sie-canvas-align-btn" data-canvas-align="canvas-left"
                                    title="Sola Hizala"><span class="dashicons dashicons-align-left"></span></button>
                                <button type="button" class="sie-canvas-align-btn" data-canvas-align="canvas-center-h"
                                    title="Yatay Ortala"><span class="dashicons dashicons-align-center"></span></button>
                                <button type="button" class="sie-canvas-align-btn" data-canvas-align="canvas-right"
                                    title="Sağa Hizala"><span class="dashicons dashicons-align-right"></span></button>
                                <button type="button" class="sie-canvas-align-btn" data-canvas-align="canvas-top"
                                    title="Üste Hizala"><span
                                        class="dashicons dashicons-align-left sie-rotate-90"></span></button>
                                <button type="button" class="sie-canvas-align-btn" data-canvas-align="canvas-center-v"
                                    title="Dikey Ortala"><span
                                        class="dashicons dashicons-align-center sie-rotate-90"></span></button>
                                <button type="button" class="sie-canvas-align-btn" data-canvas-align="canvas-bottom"
                                    title="Alta Hizala"><span
                                        class="dashicons dashicons-align-right sie-rotate-90"></span></button>
                            </div>
                        </div>
                    </div>
                    <div class="sie-admin-align-section" style="display:none">
                        <div class="sie-admin-panel-section">
                            <h4>Hizalama</h4>
                            <div class="sie-admin-align-buttons">
                                <button type="button" class="sie-align-btn" data-align="align-left" title="Sola Hizala"><span
                                        class="dashicons dashicons-align-left"></span></button>
                                <button type="button" class="sie-align-btn" data-align="align-center-h"
                                    title="Yatay Ortala"><span class="dashicons dashicons-align-center"></span></button>
                                <button type="button" class="sie-align-btn" data-align="align-right" title="Sağa Hizala"><span
                                        class="dashicons dashicons-align-right"></span></button>
                                <button type="button" class="sie-align-btn" data-align="align-top" title="Üste Hizala"><span
                                        class="dashicons dashicons-align-left sie-rotate-90"></span></button>
                                <button type="button" class="sie-align-btn" data-align="align-center-v"
                                    title="Dikey Ortala"><span
                                        class="dashicons dashicons-align-center sie-rotate-90"></span></button>
                                <button type="button" class="sie-align-btn" data-align="align-bottom" title="Aşağı Hizala"><span
                                        class="dashicons dashicons-align-right sie-rotate-90"></span></button>
                                <button type="button" class="sie-align-btn" data-align="distribute-h" title="Yatay Dağıt"><span
                                        class="dashicons dashicons-ellipsis"></span></button>
                                <button type="button" class="sie-align-btn" data-align="distribute-v" title="Dikey Dağıt"><span
                                        class="dashicons dashicons-ellipsis sie-rotate-90"></span></button>
                            </div>
                        </div>
                    </div>
                    <div class="sie-admin-panel-section">
                        <h4>Katman Özellikleri</h4>

                        <div class="sie-admin-field">
                            <label for="sie-prop-id">ID</label>
                            <input type="text" id="sie-prop-id">
                        </div>

                        <div class="sie-admin-field">
                            <label for="sie-prop-label">Etiket</label>
                            <input type="text" id="sie-prop-label">
                        </div>

                        <div class="sie-admin-field">
                            <label for="sie-prop-group">Grup</label>
                            <input type="text" id="sie-prop-group" placeholder="Örn: Ön Yüz">
                        </div>

                        <div class="sie-admin-field">
                            <label for="sie-prop-default-text">Varsayılan Metin</label>
                            <textarea id="sie-prop-default-text" rows="2"></textarea>
                        </div>

                        <div class="sie-admin-field sie-admin-field-checkbox">
                            <label>
                                <input type="checkbox" id="sie-prop-hidden-frontend">
                                Önyüzde Gizle (Sabit Alan)
                            </label>
                        </div>
                    </div>

                    <div class="sie-admin-panel-section">
                        <h4>Stil</h4>

                        <div class="sie-admin-field">
                            <label for="sie-prop-font">Yazı Tipi</label>
                            <select id="sie-prop-font"></select>
                        </div>

                        <div class="sie-admin-field">
                            <label for="sie-prop-fontsize">Boyut (px)</label>
                            <input type="number" id="sie-prop-fontsize" min="8" max="200">
                        </div>

                        <div class="sie-admin-field">
                            <label for="sie-prop-color">Renk</label>
                            <input type="text" id="sie-prop-color" value="#333333">
                        </div>

                        <div class="sie-admin-field-row">
                            <div class="sie-admin-field">
                                <label for="sie-prop-fontweight">Kalınlık</label>
                                <select id="sie-prop-fontweight">
                                    <option value="normal">Normal</option>
                                    <option value="300">Light</option>
                                    <option value="bold">Bold</option>
                                    <option value="600">Semi-Bold</option>
                                </select>
                            </div>
                            <div class="sie-admin-field">
                                <label for="sie-prop-fontstyle">Stil</label>
                                <select id="sie-prop-fontstyle">
                                    <option value="normal">Normal</option>
                                    <option value="italic">İtalik</option>
                                </select>
                            </div>
                        </div>

                        <div class="sie-admin-field">
                            <label>Metin Hizalama</label>
                            <div class="sie-admin-text-align-buttons">
                                <button type="button" class="sie-text-align-btn" data-align="left" title="Sola Dayalı"><span class="dashicons dashicons-align-left"></span></button>
                                <button type="button" class="sie-text-align-btn" data-align="center" title="Ortalı"><span class="dashicons dashicons-align-center"></span></button>
                                <button type="button" class="sie-text-align-btn" data-align="right" title="Sağa Dayalı"><span class="dashicons dashicons-align-right"></span></button>
                                <button type="button" class="sie-text-align-btn" data-align="justify" title="İki Yana Yaslı"><span class="dashicons dashicons-align-justify"></span></button>
                            </div>
                            <input type="hidden" id="sie-prop-textalign" value="center">
                        </div>

                        <div class="sie-admin-field-row">
                            <div class="sie-admin-field">
                                <label for="sie-prop-letterspacing">Harf Aralığı</label>
                                <input type="number" id="sie-prop-letterspacing" step="0.5">
                            </div>
                            <div class="sie-admin-field">
                                <label for="sie-prop-lineheight">Satır Yüksekliği</label>
                                <input type="text" id="sie-prop-lineheight" placeholder="ör: 1.5">
                            </div>
                        </div>
                    </div>

                    <div class="sie-admin-panel-section">
                        <h4>Konum</h4>

                        <div class="sie-admin-field-row">
                            <div class="sie-admin-field">
                                <label for="sie-prop-left">Sol (%)</label>
                                <input type="number" id="sie-prop-left" step="any" min="-50" max="100">
                            </div>
                            <div class="sie-admin-field">
                                <label for="sie-prop-top">Üst (%)</label>
                                <input type="number" id="sie-prop-top" step="any" min="-50" max="100">
                            </div>
                        </div>

                        <div class="sie-admin-field">
                            <label for="sie-prop-width">Genişlik (%)</label>
                            <input type="number" id="sie-prop-width" step="any" min="5" max="100">
                        </div>

                        <div class="sie-admin-field">
                            <label for="sie-prop-rotate">Döndürme (Derece)</label>
                            <input type="number" id="sie-prop-rotate" step="1" min="-360" max="360" value="0">
                        </div>
                    </div>

                    <div class="sie-admin-panel-section">
                        <div class="sie-admin-prop-actions">
                            <button type="button" class="button" id="sie-prop-duplicate">Çoğalt</button>
                            <button type="button" class="button button-link-delete" id="sie-prop-delete">Sil</button>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- JSON Tab -->
        <div id="sie-tab-json" class="sie-admin-tab-content sie-json-tab">
            <textarea id="sie-json-textarea"
                placeholder='{"canvas": {"width": 1200, "height": 1800, "bg_image": ""}, "layers": []}'><?php echo esc_textarea($config); ?></textarea>
            <div class="sie-json-actions">
                <button type="button" class="button" id="sie-validate-json">Doğrula</button>
                <span class="sie-json-status"></span>
            </div>
        </div>
        <?php
    }

    public function save_product_data_tab($post_id)
    {
        if (isset($_POST['sie_invitation_json_config'])) {
            $raw = wp_unslash($_POST['sie_invitation_json_config']);

            // Validate JSON before saving
            if (!empty($raw)) {
                $decoded = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($decoded['canvas']) && isset($decoded['layers'])) {
                    // wp_slash to compensate for update_post_meta's internal wp_unslash
                    update_post_meta($post_id, '_invitation_json_config', wp_slash($raw));
                }
            } else {
                delete_post_meta($post_id, '_invitation_json_config');
            }
        }
    }
}
