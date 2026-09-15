(function ($) {
    'use strict';

    const Altegena_Editor = {
        config: null,
        container: null,
        sidebar: null,
        preview: null,
        is_admin_mode: false,

        init: function () {
            if (typeof altegena_config === 'undefined' || !altegena_config.raw_config) return;

            try {
                this.config = JSON.parse(altegena_config.raw_config);
            } catch (e) {
                console.error('Altegena: Invalid JSON configuration', e);
                return;
            }

            this.is_admin_mode = altegena_config.is_admin_mode;
            this.container = $('#altegena-editor-app');

            if (this.is_admin_mode) {
                $('body').addClass('altegena-admin-mode');
            }

            this.renderLayout();
            this.renderLayers();
            this.loadFromLocalStorage();
            this.bindEvents();
            this.updateHiddenInput();
        },

        renderLayout: function () {
            this.container.html(`
                <div class="altegena-sidebar"></div>
                <div class="altegena-preview-area">
                    <div class="altegena-canvas" style="
                        background-image: url('${this.config.canvas.bg_image}');
                    "></div>
                </div>
            `);

            this.sidebar = this.container.find('.altegena-sidebar');
            this.preview = this.container.find('.altegena-canvas');
            this.previewArea = this.container.find('.altegena-preview-area');

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
                    <div class="altegena-input-group" data-layer-id="${layer.id}">
                        <label>${layer.label}</label>
                        <textarea class="altegena-layer-input" rows="3">${layer.default_text}</textarea>
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
                class: 'altegena-layer',
                id: `altegena-layer-${layer.id}`,
                contenteditable: isEditable,
                text: layer.default_text
            };
            if (this.is_admin_mode) {
                elAttrs.tabindex = '0';
            }
            const $el = $('<div>', elAttrs).css(style);

            if (this.is_admin_mode) {
                $el.data('altegena-layer-config', layer);
                this.makeDraggable($el, layer);
            }

            this.preview.append($el);
        },

        bindEvents: function () {
            const self = this;

            // Sidebar Input -> Preview
            this.sidebar.on('input', '.altegena-layer-input', function () {
                const $input = $(this);
                const layerId = $input.closest('.altegena-input-group').data('layer-id');
                const text = $input.val();
                const $layer = $(`#altegena-layer-${layerId}`);
                $layer.text(text);
                self.updateHiddenInput();
            });



            // Preview -> Sidebar Input
            this.preview.on('input', '.altegena-layer', function () {
                const $layer = $(this);
                const layerId = $layer.attr('id').replace('altegena-layer-', '');
                // innerText keeps the line breaks the browser inserts on Enter (<br>/<div>); .text() drops them
                const text = this.innerText;
                $(`.altegena-input-group[data-layer-id="${layerId}"] .altegena-layer-input`).val(text);
                self.updateHiddenInput();
            });

            // Highlight link
            this.preview.on('focus', '.altegena-layer', function () {
                const layerId = $(this).attr('id').replace('altegena-layer-', '');
                $('.altegena-input-group').removeClass('active');
                $(`.altegena-input-group[data-layer-id="${layerId}"]`).addClass('active')[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            });

            this.sidebar.on('focus', '.altegena-layer-input', function () {
                const layerId = $(this).closest('.altegena-input-group').data('layer-id');
                $('.altegena-layer').css('box-shadow', 'none');
                $(`#altegena-layer-${layerId}`).css('box-shadow', '0 0 0 2px #d4af37');
            });

            // Admin mode: layer selection and keyboard movement
            if (this.is_admin_mode) {
                // Select layer on click
                this.preview.on('click', '.altegena-layer', function (e) {
                    e.stopPropagation();
                    self.selectLayer($(this));
                });

                // Deselect on canvas background click
                this.preview.on('click', function (e) {
                    if ($(e.target).hasClass('altegena-canvas') || $(e.target).hasClass('altegena-preview-area')) {
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
                    const layer = $el.data('altegena-layer-config');

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
            $(document).on('click', '#altegena-add-to-cart-btn', function () {
                var $btn = $(this);
                if ($btn.hasClass('altegena-loading')) return;

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
                    console.error('Altegena: Product ID not found');
                    return;
                }

                // Loading state
                var originalText = $btn.text();
                $btn.addClass('altegena-loading').text('Ekleniyor...').prop('disabled', true);

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
                        $btn.removeClass('altegena-loading').text(originalText).prop('disabled', false);
                        alert('Sepete eklenirken bir hata oluştu.');
                    }
                });
            });

            // Share Button (event delegation since modal is moved to body)
            $(document).on('click', '#altegena-share-btn', function () {
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

                $(document).on('mousemove.altegena-drag', function (e) {
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

                $(document).on('mouseup.altegena-drag', function () {
                    isDragging = false;
                    $(document).off('.altegena-drag');
                });
            });
        },

        updateHiddenInput: function () {
            const data = {};
            this.config.layers.forEach(layer => {
                const $group = $(`.altegena-input-group[data-layer-id="${layer.id}"]`);
                const text = $group.find('.altegena-layer-input').val();

                data[layer.id] = {
                    label: layer.label,
                    text: text,
                    fontFamily: layer.style.fontFamily
                };
            });
            const jsonString = JSON.stringify(data);
            $('#altegena-custom-data').val(jsonString);

            // Autosave to LocalStorage
            const productId = $('form.cart').find('button[name="add-to-cart"]').val() || window.location.pathname;
            localStorage.setItem('altegena_autosave_' + productId, jsonString);
        },

        loadFromLocalStorage: function () {
            const productId = $('form.cart').find('button[name="add-to-cart"]').val() || window.location.pathname;
            const saved = localStorage.getItem('altegena_autosave_' + productId);
            if (saved) {
                try {
                    const data = JSON.parse(saved);
                    Object.keys(data).forEach(layerId => {
                        const layerData = data[layerId];
                        const $group = $(`.altegena-input-group[data-layer-id="${layerId}"]`);
                        const $layer = $(`#altegena-layer-${layerId}`);

                        if (layerData.text !== undefined) {
                            $group.find('.altegena-layer-input').val(layerData.text);
                            $layer.text(layerData.text);
                        }
                    });
                } catch (e) {
                    console.warn('Altegena: Failed to load autosave', e);
                }
            }
        },

        updateAdminExport: function () {
            if (!this.is_admin_mode) return;

            // Console log the updated JSON for easy copying by admin
            console.log('UPDATED CONFIG:', JSON.stringify(this.config, null, 2));

            // Optionally, we could add a "Copy JSON" button in the UI
            if ($('#altegena-admin-copy-json').length === 0) {
                this.sidebar.prepend('<button id="altegena-admin-copy-json" style="margin-bottom: 10px;">Copy Updated JSON</button>');
                $('#altegena-admin-copy-json').on('click', (e) => {
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

        // Text-only map, same shape as #altegena-custom-data ({id:{label,text,fontFamily}}).
        // The server merges this into the trusted product template.
        buildTextMap: function () {
            const data = {};
            this.config.layers.forEach(layer => {
                const $group = $(`.altegena-input-group[data-layer-id="${layer.id}"]`);
                const text = $group.find('.altegena-layer-input').val();
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

        // Capture the card at native resolution. The live .altegena-canvas carries a
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
            if ($btn.hasClass('altegena-loading')) return;

            if (typeof html2canvas === 'undefined') {
                alert('Paylaşım aracı yüklenemedi. Lütfen sayfayı yenileyin.');
                return;
            }

            const originalText = $btn.text();
            $btn.addClass('altegena-loading').text('Hazırlanıyor...').prop('disabled', true);

            self.updateHiddenInput();
            const textMap = self.buildTextMap();

            self.captureCard().then(imageDataUrl => {
                return $.ajax({
                    url: altegena_config.ajax_url,
                    type: 'POST',
                    data: {
                        action: altegena_config.share_action,
                        nonce: altegena_config.share_nonce,
                        product_id: altegena_config.product_id,
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
                console.error('Altegena share error', err);
                alert('Paylaşım oluşturulurken bir hata oluştu. Lütfen tekrar deneyin.');
            }).then(() => {
                $btn.removeClass('altegena-loading').text(originalText).prop('disabled', false);
            });
        },

        showShareDialog: function (opts) {
            const message = altegena_config.share_message || 'Davetiyemize göz atın:';
            const waHref = 'https://wa.me/?text=' + encodeURIComponent(message + ' ' + opts.url);

            $('.altegena-share-overlay').remove();

            const $overlay = $(`
                <div class="altegena-share-overlay">
                    <div class="altegena-share-dialog" role="dialog" aria-label="Paylaş">
                        <button type="button" class="altegena-share-close" aria-label="Kapat">&times;</button>
                        <h3>Davetiyeni Paylaş</h3>
                        <img class="altegena-share-preview" alt="Davetiye">
                        <div class="altegena-share-actions">
                            <a class="altegena-share-action altegena-wa-link" target="_blank" rel="noopener">WhatsApp'ta bağlantı gönder</a>
                            <button type="button" class="altegena-share-action altegena-wa-image">Görseli paylaş</button>
                            <button type="button" class="altegena-share-action altegena-copy-link">Bağlantıyı kopyala</button>
                            <a class="altegena-share-action altegena-download-img" download="davetiye.png">Görseli indir</a>
                        </div>
                    </div>
                </div>
            `);

            $overlay.find('.altegena-share-preview').attr('src', opts.imageUrl || opts.imageDataUrl);
            $overlay.find('.altegena-wa-link').attr('href', waHref);
            $overlay.find('.altegena-download-img').attr('href', opts.imageDataUrl);

            // Image share via Web Share API (mobile). Hide when unsupported.
            const $imgBtn = $overlay.find('.altegena-wa-image');
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
            $overlay.find('.altegena-copy-link').on('click', function () {
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
                $(document).off('keydown.altegena-share');
                $overlay.remove();
            };
            $overlay.find('.altegena-share-close').on('click', close);
            $overlay.on('click', function (e) {
                if (e.target === this) close();
            });
            $(document).on('keydown.altegena-share', function (e) {
                if (e.key === 'Escape') close();
            });

            $('#card-designer').append($overlay);
        }
    };

    $(document).ready(function () {
        Altegena_Editor.init();
    });

})(jQuery);
