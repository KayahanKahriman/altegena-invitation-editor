(function ($) {
    'use strict';

    const SIE_Editor = {
        config: null,
        container: null,
        sidebar: null,
        preview: null,
        is_admin_mode: false,

        init: function () {
            if (typeof sie_config === 'undefined' || !sie_config.raw_config) return;

            try {
                this.config = JSON.parse(sie_config.raw_config);
            } catch (e) {
                console.error('SIE: Invalid JSON configuration', e);
                return;
            }

            this.is_admin_mode = sie_config.is_admin_mode;
            this.container = $('#sie-editor-app');

            if (this.is_admin_mode) {
                $('body').addClass('sie-admin-mode');
            }

            this.renderLayout();
            this.renderLayers();
            this.loadFromLocalStorage();
            this.bindEvents();
            this.updateHiddenInput();
        },

        renderLayout: function () {
            this.container.html(`
                <div class="sie-sidebar"></div>
                <div class="sie-preview-area">
                    <div class="sie-canvas" style="
                        background-image: url('${this.config.canvas.bg_image}');
                    "></div>
                </div>
            `);

            this.sidebar = this.container.find('.sie-sidebar');
            this.preview = this.container.find('.sie-canvas');
            this.previewArea = this.container.find('.sie-preview-area');

            // Use ResizeObserver to fit canvas when modal becomes visible or resizes
            const self = this;
            this._resizeObserver = new ResizeObserver(function () {
                self.fitCanvas();
            });
            this._resizeObserver.observe(this.previewArea[0]);
        },

        fitCanvas: function () {
            const areaW = this.previewArea[0].clientWidth;
            const areaH = this.previewArea[0].clientHeight;
            if (areaW === 0 || areaH === 0) return; // still hidden

            const pad = window.innerWidth <= 900 ? 20 : 40;
            const availW = areaW - pad * 2;
            const availH = areaH - pad * 2;
            const baseW = this.config.canvas.width;
            const baseH = this.config.canvas.height;
            const scale = Math.min(availW / baseW, availH / baseH);

            // Keep canvas at natural size, use CSS transform to scale visually
            this.preview.css({
                width: baseW + 'px',
                height: baseH + 'px',
                transform: 'scale(' + scale + ')',
                transformOrigin: 'top left',
                marginRight: Math.floor(baseW * (scale - 1)) + 'px',
                marginBottom: Math.floor(baseH * (scale - 1)) + 'px'
            });
            this.scaleFactor = scale;
        },

        renderLayers: function () {
            this.config.layers.forEach(layer => {
                if (layer.type === 'text') {
                    // Convert literal \n to actual newline characters
                    if (layer.default_text) {
                        layer.default_text = layer.default_text.replace(/\\n/g, '\n');
                    }
                    this.addTextLayer(layer);
                }
            });
        },

        addTextLayer: function (layer) {
            // Create Sidebar Input (skip for hidden/fixed layers)
            if (!layer.hidden_on_frontend) {
                const inputHtml = `
                    <div class="sie-input-group" data-layer-id="${layer.id}">
                        <label>${layer.label}</label>
                        <textarea class="sie-layer-input" rows="3">${layer.default_text}</textarea>
                    </div>
                `;
                this.sidebar.append(inputHtml);
            }

            // Create Preview Layer
            const style = Object.assign({}, layer.style);

            // Convert left+width to center-point positioning
            if (style.left && style.width) {
                const left = parseFloat(style.left);
                const width = parseFloat(style.width);
                style.left = (left + width / 2) + '%';
                delete style.width;
            }

            let transform = 'translateX(-50%)';
            if (style.rotate) {
                transform += ` rotate(${style.rotate}deg)`;
                delete style.rotate;
            }
            style.transform = transform;

            const isEditable = !this.is_admin_mode && !layer.hidden_on_frontend;
            const elAttrs = {
                class: 'sie-layer',
                id: `sie-layer-${layer.id}`,
                contenteditable: isEditable,
                text: layer.default_text
            };
            if (this.is_admin_mode) {
                elAttrs.tabindex = '0';
            }
            const $el = $('<div>', elAttrs).css(style);

            if (this.is_admin_mode) {
                $el.data('sie-layer-config', layer);
                this.makeDraggable($el, layer);
            }

            this.preview.append($el);
        },

        bindEvents: function () {
            const self = this;

            // Sidebar Input -> Preview
            this.sidebar.on('input', '.sie-layer-input', function () {
                const $input = $(this);
                const layerId = $input.closest('.sie-input-group').data('layer-id');
                const text = $input.val();
                const $layer = $(`#sie-layer-${layerId}`);
                $layer.text(text);
                self.updateHiddenInput();
            });



            // Preview -> Sidebar Input
            this.preview.on('input', '.sie-layer', function () {
                const $layer = $(this);
                const layerId = $layer.attr('id').replace('sie-layer-', '');
                const text = $layer.text();
                $(`.sie-input-group[data-layer-id="${layerId}"] input`).val(text);
                self.updateHiddenInput();
            });

            // Highlight link
            this.preview.on('focus', '.sie-layer', function () {
                const layerId = $(this).attr('id').replace('sie-layer-', '');
                $('.sie-input-group').removeClass('active');
                $(`.sie-input-group[data-layer-id="${layerId}"]`).addClass('active')[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            });

            this.sidebar.on('focus', '.sie-layer-input', function () {
                const layerId = $(this).closest('.sie-input-group').data('layer-id');
                $('.sie-layer').css('box-shadow', 'none');
                $(`#sie-layer-${layerId}`).css('box-shadow', '0 0 0 2px #d4af37');
            });

            // Admin mode: layer selection and keyboard movement
            if (this.is_admin_mode) {
                // Select layer on click
                this.preview.on('click', '.sie-layer', function (e) {
                    e.stopPropagation();
                    self.selectLayer($(this));
                });

                // Deselect on canvas background click
                this.preview.on('click', function (e) {
                    if ($(e.target).hasClass('sie-canvas') || $(e.target).hasClass('sie-preview-area')) {
                        self.deselectLayer();
                    }
                });
                this.previewArea.on('click', function (e) {
                    if ($(e.target).is(self.previewArea)) {
                        self.deselectLayer();
                    }
                });

                // Arrow key movement on selected layer
                $(document).on('keydown', function (e) {
                    if (!self.selectedLayer) return;

                    const key = e.key;
                    if (!['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'Escape'].includes(key)) return;

                    if (key === 'Escape') {
                        self.deselectLayer();
                        return;
                    }

                    e.preventDefault();
                    const step = e.shiftKey ? 10 : 1;
                    const $el = self.selectedLayer;
                    const layer = $el.data('sie-layer-config');

                    let left = parseFloat($el.css('left'));
                    let top = parseFloat($el.css('top'));

                    if (key === 'ArrowLeft') left -= step;
                    if (key === 'ArrowRight') left += step;
                    if (key === 'ArrowUp') top -= step;
                    if (key === 'ArrowDown') top += step;

                    const newLeft = (left / self.preview.width() * 100).toFixed(2) + '%';
                    const newTop = (top / self.preview.height() * 100).toFixed(2) + '%';

                    $el.css({ left: newLeft, top: newTop });
                    layer.style.left = newLeft;
                    layer.style.top = newTop;
                    self.updateAdminExport();
                });
            }

            // Add to Cart Button (using event delegation since modal is moved to body)
            $(document).on('click', '#sie-add-to-cart-btn', function () {
                var $btn = $(this);
                if ($btn.hasClass('sie-loading')) return;

                // Update hidden input with latest data
                self.updateHiddenInput();

                var $form = $('form.cart');

                // Check if variation is selected for variable products
                var variationId = 0;
                if ($form.find('.variations').length) {
                    variationId = $form.find('input[name="variation_id"]').val();
                    if (!variationId || variationId === '0') {
                        alert('Lütfen önce bir varyasyon seçin.');
                        return;
                    }
                }

                // Get product ID
                var productId = $form.find('input[name="product_id"]').val()
                    || $form.find('button[name="add-to-cart"]').val();

                if (!productId) {
                    console.error('SIE: Product ID not found');
                    return;
                }

                // Loading state
                var originalText = $btn.text();
                $btn.addClass('sie-loading').text('Ekleniyor...').prop('disabled', true);

                // Build form data for submission
                var formData = $form.serialize();
                formData += '&add-to-cart=' + productId;

                // Submit via AJAX using the product page URL (handles both simple & variable)
                $.ajax({
                    url: $form.attr('action') || window.location.href,
                    type: 'POST',
                    data: formData,
                    success: function () {
                        $btn.text('Eklendi ✓');
                        setTimeout(function () {
                            window.location.href = wc_add_to_cart_params.cart_url;
                        }, 600);
                    },
                    error: function () {
                        $btn.removeClass('sie-loading').text(originalText).prop('disabled', false);
                        alert('Sepete eklenirken bir hata oluştu.');
                    }
                });
            });

            // Share Button (event delegation since modal is moved to body)
            $(document).on('click', '#sie-share-btn', function () {
                self.shareHandler($(this));
            });
        },


        selectedLayer: null,

        selectLayer: function ($el) {
            this.deselectLayer();
            $el.addClass('is-selected').focus();
            this.selectedLayer = $el;
        },

        deselectLayer: function () {
            if (this.selectedLayer) {
                this.selectedLayer.removeClass('is-selected');
                this.selectedLayer = null;
            }
        },

        makeDraggable: function ($el, layer) {
            const self = this;
            let isDragging = false;
            let startX, startY, startLeft, startTop;

            $el.on('mousedown', function (e) {
                self.selectLayer($el);
                isDragging = true;
                startX = e.clientX;
                startY = e.clientY;
                startLeft = parseFloat($el.css('left'));
                startTop = parseFloat($el.css('top'));

                $(document).on('mousemove.sie-drag', function (e) {
                    if (!isDragging) return;

                    const dx = e.clientX - startX;
                    const dy = e.clientY - startY;

                    const newLeft = ((startLeft + dx) / self.preview.width() * 100).toFixed(2) + '%';
                    const newTop = ((startTop + dy) / self.preview.height() * 100).toFixed(2) + '%';

                    $el.css({ left: newLeft, top: newTop });

                    // Update the config object for export
                    layer.style.left = newLeft;
                    layer.style.top = newTop;
                    self.updateAdminExport();
                });

                $(document).on('mouseup.sie-drag', function () {
                    isDragging = false;
                    $(document).off('.sie-drag');
                });
            });
        },

        updateHiddenInput: function () {
            const data = {};
            this.config.layers.forEach(layer => {
                const $group = $(`.sie-input-group[data-layer-id="${layer.id}"]`);
                const text = $group.find('.sie-layer-input').val();

                data[layer.id] = {
                    label: layer.label,
                    text: text,
                    fontFamily: layer.style.fontFamily
                };
            });
            const jsonString = JSON.stringify(data);
            $('#sie-custom-data').val(jsonString);

            // Autosave to LocalStorage
            const productId = $('form.cart').find('button[name="add-to-cart"]').val() || window.location.pathname;
            localStorage.setItem('sie_autosave_' + productId, jsonString);
        },

        loadFromLocalStorage: function () {
            const productId = $('form.cart').find('button[name="add-to-cart"]').val() || window.location.pathname;
            const saved = localStorage.getItem('sie_autosave_' + productId);
            if (saved) {
                try {
                    const data = JSON.parse(saved);
                    Object.keys(data).forEach(layerId => {
                        const layerData = data[layerId];
                        const $group = $(`.sie-input-group[data-layer-id="${layerId}"]`);
                        const $layer = $(`#sie-layer-${layerId}`);

                        if (layerData.text !== undefined) {
                            $group.find('.sie-layer-input').val(layerData.text);
                            $layer.text(layerData.text);
                        }
                    });
                } catch (e) {
                    console.warn('SIE: Failed to load autosave', e);
                }
            }
        },

        updateAdminExport: function () {
            if (!this.is_admin_mode) return;

            // Console log the updated JSON for easy copying by admin
            console.log('UPDATED CONFIG:', JSON.stringify(this.config, null, 2));

            // Optionally, we could add a "Copy JSON" button in the UI
            if ($('#sie-admin-copy-json').length === 0) {
                this.sidebar.prepend('<button id="sie-admin-copy-json" style="margin-bottom: 10px;">Copy Updated JSON</button>');
                $('#sie-admin-copy-json').on('click', (e) => {
                    e.preventDefault();
                    const json = JSON.stringify(this.config, null, 2);
                    navigator.clipboard.writeText(json).then(() => {
                        alert('JSON copied to clipboard!');
                    });
                });
            }
        },

        /* ------------------------------------------------------------------
         * WhatsApp Share
         * ---------------------------------------------------------------- */

        // Text-only map, same shape as #sie-custom-data ({id:{label,text,fontFamily}}).
        // The server merges this into the trusted product template.
        buildTextMap: function () {
            const data = {};
            this.config.layers.forEach(layer => {
                const $group = $(`.sie-input-group[data-layer-id="${layer.id}"]`);
                const text = $group.find('.sie-layer-input').val();
                data[layer.id] = {
                    label: layer.label,
                    text: text !== undefined ? text : (layer.default_text || ''),
                    fontFamily: layer.style.fontFamily
                };
            });
            return data;
        },

        // Wait for the specific custom fonts used by the layers so the PNG does
        // not capture the swap fallback (fonts.css uses font-display: swap).
        waitForFonts: function () {
            if (!document.fonts || !document.fonts.ready) return Promise.resolve();

            const families = [];
            this.config.layers.forEach(layer => {
                const fam = layer.style && layer.style.fontFamily;
                if (fam && families.indexOf(fam) === -1) families.push(fam);
            });

            return document.fonts.ready.then(() => {
                const missing = families.filter(f => {
                    try { return !document.fonts.check("1em '" + f + "'"); }
                    catch (e) { return false; }
                });
                if (!missing.length) return;
                return Promise.race([
                    Promise.all(missing.map(f => document.fonts.load("1em '" + f + "'").catch(() => { }))),
                    new Promise(resolve => setTimeout(resolve, 1500))
                ]);
            });
        },

        // Capture the card at native resolution. The live .sie-canvas carries a
        // transform: scale() from fitCanvas(), which html2canvas does not honor
        // reliably, so we capture an un-scaled off-screen clone instead.
        captureCard: function () {
            const self = this;
            const baseW = this.config.canvas.width;
            const baseH = this.config.canvas.height;

            return this.waitForFonts().then(() => {
                const clone = self.preview[0].cloneNode(true);
                clone.style.transform = 'none';
                clone.style.margin = '0';
                clone.style.width = baseW + 'px';
                clone.style.height = baseH + 'px';
                clone.style.boxShadow = 'none';

                const host = document.createElement('div');
                host.style.cssText = 'position:fixed;left:-100000px;top:0;width:' + baseW + 'px;height:' + baseH + 'px;overflow:hidden;';
                host.appendChild(clone);
                document.body.appendChild(host);

                return html2canvas(clone, {
                    backgroundColor: null,
                    scale: 1,
                    width: baseW,
                    height: baseH,
                    windowWidth: baseW,
                    windowHeight: baseH,
                    useCORS: true,
                    logging: false
                }).then(canvas => {
                    document.body.removeChild(host);
                    return canvas.toDataURL('image/png');
                }).catch(err => {
                    if (host.parentNode) document.body.removeChild(host);
                    throw err;
                });
            });
        },

        shareHandler: function ($btn) {
            const self = this;
            if ($btn.hasClass('sie-loading')) return;

            if (typeof html2canvas === 'undefined') {
                alert('Paylaşım aracı yüklenemedi. Lütfen sayfayı yenileyin.');
                return;
            }

            const originalText = $btn.text();
            $btn.addClass('sie-loading').text('Hazırlanıyor...').prop('disabled', true);

            self.updateHiddenInput();
            const textMap = self.buildTextMap();

            self.captureCard().then(imageDataUrl => {
                return $.ajax({
                    url: sie_config.ajax_url,
                    type: 'POST',
                    data: {
                        action: sie_config.share_action,
                        nonce: sie_config.share_nonce,
                        product_id: sie_config.product_id,
                        custom_data: JSON.stringify(textMap),
                        image: imageDataUrl
                    }
                }).then(res => {
                    if (!res || !res.success || !res.data || !res.data.url) {
                        throw new Error('bad response');
                    }
                    self.showShareDialog({
                        url: res.data.url,
                        imageUrl: res.data.image_url,
                        imageDataUrl: imageDataUrl
                    });
                });
            }).catch(err => {
                console.error('SIE share error', err);
                alert('Paylaşım oluşturulurken bir hata oluştu. Lütfen tekrar deneyin.');
            }).then(() => {
                $btn.removeClass('sie-loading').text(originalText).prop('disabled', false);
            });
        },

        showShareDialog: function (opts) {
            const message = 'Davetiyemize göz atın:';
            const waHref = 'https://wa.me/?text=' + encodeURIComponent(message + ' ' + opts.url);

            $('.sie-share-overlay').remove();

            const $overlay = $(`
                <div class="sie-share-overlay">
                    <div class="sie-share-dialog" role="dialog" aria-label="Paylaş">
                        <button type="button" class="sie-share-close" aria-label="Kapat">&times;</button>
                        <h3>Davetiyeni Paylaş</h3>
                        <img class="sie-share-preview" alt="Davetiye">
                        <div class="sie-share-actions">
                            <a class="sie-share-action sie-wa-link" target="_blank" rel="noopener">WhatsApp'ta bağlantı gönder</a>
                            <button type="button" class="sie-share-action sie-wa-image">Görseli paylaş</button>
                            <button type="button" class="sie-share-action sie-copy-link">Bağlantıyı kopyala</button>
                            <a class="sie-share-action sie-download-img" download="davetiye.png">Görseli indir</a>
                        </div>
                    </div>
                </div>
            `);

            $overlay.find('.sie-share-preview').attr('src', opts.imageUrl || opts.imageDataUrl);
            $overlay.find('.sie-wa-link').attr('href', waHref);
            $overlay.find('.sie-download-img').attr('href', opts.imageDataUrl);

            // Image share via Web Share API (mobile). Hide when unsupported.
            const $imgBtn = $overlay.find('.sie-wa-image');
            let shareFile = null;
            try {
                const bstr = atob(opts.imageDataUrl.split(',')[1]);
                let n = bstr.length;
                const u8 = new Uint8Array(n);
                while (n--) u8[n] = bstr.charCodeAt(n);
                shareFile = new File([u8], 'davetiye.png', { type: 'image/png' });
            } catch (e) { shareFile = null; }

            if (shareFile && navigator.canShare && navigator.canShare({ files: [shareFile] })) {
                $imgBtn.on('click', function () {
                    navigator.share({
                        files: [shareFile],
                        title: 'Davetiye',
                        text: message + ' ' + opts.url
                    }).catch(function () { /* cancelled/unsupported */ });
                });
            } else {
                $imgBtn.remove();
            }

            // Copy link
            $overlay.find('.sie-copy-link').on('click', function () {
                const $b = $(this);
                const restore = $b.text();
                const done = function () { $b.text('Kopyalandı ✓'); setTimeout(() => $b.text(restore), 1500); };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(opts.url).then(done).catch(done);
                } else {
                    const $tmp = $('<input>').val(opts.url).appendTo('body').select();
                    try { document.execCommand('copy'); } catch (e) { }
                    $tmp.remove();
                    done();
                }
            });

            // Close interactions
            const close = function () {
                $(document).off('keydown.sie-share');
                $overlay.remove();
            };
            $overlay.find('.sie-share-close').on('click', close);
            $overlay.on('click', function (e) {
                if (e.target === this) close();
            });
            $(document).on('keydown.sie-share', function (e) {
                if (e.key === 'Escape') close();
            });

            $('#card-designer').append($overlay);
        }
    };

    $(document).ready(function () {
        SIE_Editor.init();
    });

})(jQuery);
