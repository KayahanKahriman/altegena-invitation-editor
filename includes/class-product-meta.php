<?php
/**
 * Handles Product Meta for Altegena Invitation Editor
 */

if (!defined('ABSPATH')) {
    exit;
}

class Altegena_Product_Meta
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
            'altegena_invitation_editor',
            __('Invitation Editor', 'altegena-invitation-editor'),
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
            'altegena-fonts-css',
            ALTEGENA_PLUGIN_URL . 'assets/css/fonts.css',
            array(),
            filemtime(ALTEGENA_PLUGIN_DIR . 'assets/css/fonts.css')
        );

        wp_enqueue_style(
            'altegena-admin-editor-css',
            ALTEGENA_PLUGIN_URL . 'assets/css/admin-editor.css',
            array('wp-color-picker'),
            filemtime(ALTEGENA_PLUGIN_DIR . 'assets/css/admin-editor.css')
        );

        wp_enqueue_script(
            'altegena-admin-editor-js',
            ALTEGENA_PLUGIN_URL . 'assets/js/admin-editor.js',
            array('jquery', 'wp-color-picker', 'jquery-ui-sortable'),
            filemtime(ALTEGENA_PLUGIN_DIR . 'assets/js/admin-editor.js'),
            true
        );

        wp_localize_script('altegena-admin-editor-js', 'altegena_admin_config', array(
            'fonts' => $this->get_available_fonts(),
            'plugin_url' => ALTEGENA_PLUGIN_URL,
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
        <input type="hidden" id="altegena_invitation_json_config" name="altegena_invitation_json_config"
            value="<?php echo esc_attr($config); ?>">

        <!-- Tab Navigation -->
        <div class="altegena-admin-tabs">
            <button type="button" class="altegena-admin-tab-btn active" data-tab="visual">Görsel Düzenleyici
            </button>
            <button type="button" class="altegena-admin-tab-btn" data-tab="json">JSON</button>
        </div>

        <!-- Visual Editor Tab -->
        <div id="altegena-tab-visual" class="altegena-admin-tab-content active">
            <div id="altegena-admin-visual-editor" class="altegena-admin-editor">

                <!-- Left Panel: Canvas Settings + Layer List -->
                <div class="altegena-admin-left-panel">
                    <div class="altegena-admin-panel-section">
                        <h4>Tuval Ayarları</h4>
                        <div class="altegena-admin-field-row">
                            <div class="altegena-admin-field">
                                <label for="altegena-canvas-width">Genişlik (px)</label>
                                <input type="number" id="altegena-canvas-width" min="100" max="5000" value="1200">
                            </div>
                            <div class="altegena-admin-field">
                                <label for="altegena-canvas-height">Yükseklik (px)</label>
                                <input type="number" id="altegena-canvas-height" min="100" max="5000" value="1800">
                            </div>
                        </div>
                        <div class="altegena-admin-field">
                            <label>Arka Plan Görseli</label>
                            <div class="altegena-admin-bg-field">
                                <input type="text" id="altegena-bg-url" readonly placeholder="Görsel seçilmedi">
                                <button type="button" class="button" id="altegena-bg-select-btn">Seç</button>
                            </div>
                            <div class="altegena-admin-bg-preview"></div>
                        </div>
                    </div>

                    <div class="altegena-admin-panel-section" style="flex: 1;">
                        <h4>Katmanlar</h4>
                        <ul class="altegena-admin-layer-list"></ul>
                        <button type="button" class="button altegena-admin-add-layer-btn">+ Katman Ekle</button>
                    </div>
                </div>

                <!-- Center Panel: Canvas Preview -->
                <div class="altegena-admin-canvas-area">
                    <button type="button" class="altegena-admin-fullscreen-btn" title="Tam Ekran Yap / Çık"><span
                            class="dashicons dashicons-editor-expand"></span></button>
                    <div class="altegena-admin-canvas"></div>
                </div>

                <!-- Right Panel: Layer Properties -->
                <div class="altegena-admin-right-panel hidden">
                    <div class="altegena-admin-canvas-align-section" style="display:none">
                        <div class="altegena-admin-panel-section">
                            <h4>Canvas'a Hizala</h4>
                            <div class="altegena-admin-align-buttons">
                                <button type="button" class="altegena-canvas-align-btn" data-canvas-align="canvas-left"
                                    title="Sola Hizala"><span class="dashicons dashicons-align-left"></span></button>
                                <button type="button" class="altegena-canvas-align-btn" data-canvas-align="canvas-center-h"
                                    title="Yatay Ortala"><span class="dashicons dashicons-align-center"></span></button>
                                <button type="button" class="altegena-canvas-align-btn" data-canvas-align="canvas-right"
                                    title="Sağa Hizala"><span class="dashicons dashicons-align-right"></span></button>
                                <button type="button" class="altegena-canvas-align-btn" data-canvas-align="canvas-top"
                                    title="Üste Hizala"><span
                                        class="dashicons dashicons-align-left altegena-rotate-90"></span></button>
                                <button type="button" class="altegena-canvas-align-btn" data-canvas-align="canvas-center-v"
                                    title="Dikey Ortala"><span
                                        class="dashicons dashicons-align-center altegena-rotate-90"></span></button>
                                <button type="button" class="altegena-canvas-align-btn" data-canvas-align="canvas-bottom"
                                    title="Alta Hizala"><span
                                        class="dashicons dashicons-align-right altegena-rotate-90"></span></button>
                            </div>
                        </div>
                    </div>
                    <div class="altegena-admin-align-section" style="display:none">
                        <div class="altegena-admin-panel-section">
                            <h4>Hizalama</h4>
                            <div class="altegena-admin-align-buttons">
                                <button type="button" class="altegena-align-btn" data-align="align-left" title="Sola Hizala"><span
                                        class="dashicons dashicons-align-left"></span></button>
                                <button type="button" class="altegena-align-btn" data-align="align-center-h"
                                    title="Yatay Ortala"><span class="dashicons dashicons-align-center"></span></button>
                                <button type="button" class="altegena-align-btn" data-align="align-right" title="Sağa Hizala"><span
                                        class="dashicons dashicons-align-right"></span></button>
                                <button type="button" class="altegena-align-btn" data-align="align-top" title="Üste Hizala"><span
                                        class="dashicons dashicons-align-left altegena-rotate-90"></span></button>
                                <button type="button" class="altegena-align-btn" data-align="align-center-v"
                                    title="Dikey Ortala"><span
                                        class="dashicons dashicons-align-center altegena-rotate-90"></span></button>
                                <button type="button" class="altegena-align-btn" data-align="align-bottom" title="Aşağı Hizala"><span
                                        class="dashicons dashicons-align-right altegena-rotate-90"></span></button>
                                <button type="button" class="altegena-align-btn" data-align="distribute-h" title="Yatay Dağıt"><span
                                        class="dashicons dashicons-ellipsis"></span></button>
                                <button type="button" class="altegena-align-btn" data-align="distribute-v" title="Dikey Dağıt"><span
                                        class="dashicons dashicons-ellipsis altegena-rotate-90"></span></button>
                            </div>
                        </div>
                    </div>
                    <div class="altegena-admin-panel-section">
                        <h4>Katman Özellikleri</h4>

                        <div class="altegena-admin-field">
                            <label for="altegena-prop-id">ID</label>
                            <input type="text" id="altegena-prop-id">
                        </div>

                        <div class="altegena-admin-field">
                            <label for="altegena-prop-label">Etiket</label>
                            <input type="text" id="altegena-prop-label">
                        </div>

                        <div class="altegena-admin-field">
                            <label for="altegena-prop-group">Grup</label>
                            <input type="text" id="altegena-prop-group" placeholder="Örn: Ön Yüz">
                        </div>

                        <div class="altegena-admin-field">
                            <label for="altegena-prop-default-text">Varsayılan Metin</label>
                            <textarea id="altegena-prop-default-text" rows="2"></textarea>
                        </div>

                        <div class="altegena-admin-field altegena-admin-field-checkbox">
                            <label>
                                <input type="checkbox" id="altegena-prop-hidden-frontend">
                                Önyüzde Gizle (Sabit Alan)
                            </label>
                        </div>
                    </div>

                    <div class="altegena-admin-panel-section">
                        <h4>Stil</h4>

                        <div class="altegena-admin-field">
                            <label for="altegena-prop-font">Yazı Tipi</label>
                            <select id="altegena-prop-font"></select>
                        </div>

                        <div class="altegena-admin-field">
                            <label for="altegena-prop-fontsize">Boyut (px)</label>
                            <input type="number" id="altegena-prop-fontsize" min="8" max="200">
                        </div>

                        <div class="altegena-admin-field">
                            <label for="altegena-prop-color">Renk</label>
                            <input type="text" id="altegena-prop-color" value="#333333">
                        </div>

                        <div class="altegena-admin-field-row">
                            <div class="altegena-admin-field">
                                <label for="altegena-prop-fontweight">Kalınlık</label>
                                <select id="altegena-prop-fontweight">
                                    <option value="normal">Normal</option>
                                    <option value="300">Light</option>
                                    <option value="bold">Bold</option>
                                    <option value="600">Semi-Bold</option>
                                </select>
                            </div>
                            <div class="altegena-admin-field">
                                <label for="altegena-prop-fontstyle">Stil</label>
                                <select id="altegena-prop-fontstyle">
                                    <option value="normal">Normal</option>
                                    <option value="italic">İtalik</option>
                                </select>
                            </div>
                        </div>

                        <div class="altegena-admin-field">
                            <label>Metin Hizalama</label>
                            <div class="altegena-admin-text-align-buttons">
                                <button type="button" class="altegena-text-align-btn" data-align="left" title="Sola Dayalı"><span class="dashicons dashicons-align-left"></span></button>
                                <button type="button" class="altegena-text-align-btn" data-align="center" title="Ortalı"><span class="dashicons dashicons-align-center"></span></button>
                                <button type="button" class="altegena-text-align-btn" data-align="right" title="Sağa Dayalı"><span class="dashicons dashicons-align-right"></span></button>
                                <button type="button" class="altegena-text-align-btn" data-align="justify" title="İki Yana Yaslı"><span class="dashicons dashicons-align-justify"></span></button>
                            </div>
                            <input type="hidden" id="altegena-prop-textalign" value="center">
                        </div>

                        <div class="altegena-admin-field-row">
                            <div class="altegena-admin-field">
                                <label for="altegena-prop-letterspacing">Harf Aralığı</label>
                                <input type="number" id="altegena-prop-letterspacing" step="0.5">
                            </div>
                            <div class="altegena-admin-field">
                                <label for="altegena-prop-lineheight">Satır Yüksekliği</label>
                                <input type="text" id="altegena-prop-lineheight" placeholder="ör: 1.5">
                            </div>
                        </div>
                    </div>

                    <div class="altegena-admin-panel-section">
                        <h4>Konum</h4>

                        <div class="altegena-admin-field-row">
                            <div class="altegena-admin-field">
                                <label for="altegena-prop-left">Sol (%)</label>
                                <input type="number" id="altegena-prop-left" step="any" min="-50" max="100">
                            </div>
                            <div class="altegena-admin-field">
                                <label for="altegena-prop-top">Üst (%)</label>
                                <input type="number" id="altegena-prop-top" step="any" min="-50" max="100">
                            </div>
                        </div>

                        <div class="altegena-admin-field">
                            <label for="altegena-prop-width">Genişlik (%)</label>
                            <input type="number" id="altegena-prop-width" step="any" min="5" max="100">
                        </div>

                        <div class="altegena-admin-field">
                            <label for="altegena-prop-rotate">Döndürme (Derece)</label>
                            <input type="number" id="altegena-prop-rotate" step="1" min="-360" max="360" value="0">
                        </div>
                    </div>

                    <div class="altegena-admin-panel-section">
                        <div class="altegena-admin-prop-actions">
                            <button type="button" class="button" id="altegena-prop-duplicate">Çoğalt</button>
                            <button type="button" class="button button-link-delete" id="altegena-prop-delete">Sil</button>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- JSON Tab -->
        <div id="altegena-tab-json" class="altegena-admin-tab-content altegena-json-tab">
            <textarea id="altegena-json-textarea"
                placeholder='{"canvas": {"width": 1200, "height": 1800, "bg_image": ""}, "layers": []}'><?php echo esc_textarea($config); ?></textarea>
            <div class="altegena-json-actions">
                <button type="button" class="button" id="altegena-validate-json">Doğrula</button>
                <span class="altegena-json-status"></span>
            </div>
        </div>
        <?php
    }

    public function save_product_data_tab($post_id)
    {
        if (isset($_POST['altegena_invitation_json_config'])) {
            $raw = wp_unslash($_POST['altegena_invitation_json_config']);

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
