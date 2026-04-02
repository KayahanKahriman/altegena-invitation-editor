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
            console.log('SIE: Adding text layer', layer.id, 'Font Family:', layer.style.fontFamily);
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
        }
    };

    $(document).ready(function () {
        SIE_Editor.init();
    });

})(jQuery);
