(function ($) {
    'use strict';

    /**
     * Read-only public renderer for a shared invitation.
     *
     * Standalone (does NOT reuse editor.js, which is coupled to the modal/sidebar)
     * but mirrors editor.js fitCanvas() + addTextLayer() math EXACTLY so the public
     * page matches the captured PNG pixel-for-pixel. Key difference: other people's
     * text is rendered, so it is HTML-escaped (with \n -> <br>) instead of using .text().
     */
    const Altegena_Public = {
        config: null,
        stage: null,
        canvas: null,
        scaleFactor: 1,

        init: function () {
            if (typeof altegena_share === 'undefined' || !altegena_share.config) return;

            try {
                this.config = (typeof altegena_share.config === 'string')
                    ? JSON.parse(altegena_share.config)
                    : altegena_share.config;
            } catch (e) {
                console.error('Altegena Public: invalid config', e);
                return;
            }
            if (!this.config || !this.config.canvas || !Array.isArray(this.config.layers)) return;

            this.stage = $('.altegena-public-stage');
            if (!this.stage.length) return;

            // The #altegena-public-app wrapper only holds the <noscript> fallback; replace it.
            $('#altegena-public-app').remove();

            this.buildCanvas();
            this.renderLayers();
            this.fitCanvas();
            this.bindResize();
            this.wireShareButton();

            const self = this;
            if (document.fonts && document.fonts.ready) {
                document.fonts.ready.then(function () { self.fitCanvas(); });
            }
        },

        buildCanvas: function () {
            const bg = this.config.canvas.bg_image || '';
            this.canvas = $('<div>', { class: 'altegena-canvas' }).css({
                'background-image': bg ? "url('" + bg + "')" : 'none'
            });
            this.stage.append(this.canvas);
        },

        renderLayers: function () {
            const self = this;
            this.config.layers.forEach(function (layer) {
                if (layer.type !== 'text') return;
                self.addTextLayer(layer);
            });
        },

        addTextLayer: function (layer) {
            // Normalize literal \n to real newlines (mirrors editor.js:86).
            let text = layer.default_text || '';
            text = text.replace(/\\n/g, '\n');

            const style = Object.assign({}, layer.style);

            // Convert left+width to center-point positioning (mirrors editor.js:109-114).
            if (style.left && style.width) {
                const left = parseFloat(style.left);
                const width = parseFloat(style.width);
                style.left = (left + width / 2) + '%';
                delete style.width;
            }

            // Transform (mirrors editor.js:116-121); no transformOrigin, for parity.
            let transform = 'translateX(-50%)';
            if (style.rotate) {
                transform += ' rotate(' + style.rotate + 'deg)';
                delete style.rotate;
            }
            style.transform = transform;

            const $el = $('<div>', {
                class: 'altegena-layer altegena-layer--readonly',
                id: 'altegena-layer-' + layer.id
            }).css(style);

            // Escape (rendering another user's text) then honor line breaks.
            $el.html(this.escapeHtml(text).replace(/\n/g, '<br>'));

            this.canvas.append($el);
        },

        // Same scaling algorithm as editor.js fitCanvas() (57-79).
        fitCanvas: function () {
            const areaW = this.stage[0].clientWidth;
            const areaH = this.stage[0].clientHeight;
            if (areaW === 0 || areaH === 0) return;

            const pad = window.innerWidth <= 900 ? 20 : 40;
            const availW = areaW - pad * 2;
            const availH = areaH - pad * 2;
            const baseW = this.config.canvas.width;
            const baseH = this.config.canvas.height;
            const scale = Math.min(availW / baseW, availH / baseH);

            this.canvas.css({
                width: baseW + 'px',
                height: baseH + 'px',
                transform: 'scale(' + scale + ')',
                transformOrigin: 'top left',
                marginRight: Math.floor(baseW * (scale - 1)) + 'px',
                marginBottom: Math.floor(baseH * (scale - 1)) + 'px'
            });
            this.scaleFactor = scale;
        },

        bindResize: function () {
            const self = this;
            if (window.ResizeObserver) {
                this._ro = new ResizeObserver(function () { self.fitCanvas(); });
                this._ro.observe(this.stage[0]);
            }
            $(window).on('resize orientationchange', function () { self.fitCanvas(); });
        },

        wireShareButton: function () {
            const $btn = $('#altegena-public-share');
            if (!$btn.length) return;

            const pageUrl = (altegena_share.page_url) || window.location.href;
            const waText = (altegena_share.wa_text) || 'Davetiyemize göz atın:';

            // Progressive enhancement: use the native share sheet when available,
            // otherwise fall back to the static wa.me href already on the anchor.
            if (navigator.share) {
                $btn.on('click', function (e) {
                    e.preventDefault();
                    navigator.share({
                        title: 'Davetiye',
                        text: waText,
                        url: pageUrl
                    }).catch(function () { /* user cancelled or unsupported */ });
                });
            }
        },

        escapeHtml: function (str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
    };

    $(document).ready(function () {
        Altegena_Public.init();
    });

})(jQuery);
