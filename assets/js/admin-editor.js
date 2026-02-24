(function ($) {
    'use strict';

    var SIE_AdminEditor = {
        config: null,
        selectedLayerId: null,
        selectedLayerIds: [],
        scaleFactor: 1,
        _resizeObserver: null,

        // Undo/Redo
        history: [],
        historyIndex: -1,
        maxHistory: 50,
        _isRestoring: false,

        // DOM references
        $container: null,
        $canvasArea: null,
        $canvas: null,
        $leftPanel: null,
        $rightPanel: null,
        $hiddenInput: null,
        $jsonTextarea: null,

        init: function () {
            this.$container = $('#sie-admin-visual-editor');
            if (!this.$container.length) return;

            this.$hiddenInput = $('#sie_invitation_json_config');
            this.$jsonTextarea = $('#sie-json-textarea');
            this.$canvasArea = this.$container.find('.sie-admin-canvas-area');
            this.$canvas = this.$container.find('.sie-admin-canvas');
            this.$leftPanel = this.$container.find('.sie-admin-left-panel');
            this.$rightPanel = this.$container.find('.sie-admin-right-panel');

            this.initializeConfig();
            this.bindTabSwitching();
            this.bindCanvasSettings();
            this.bindLayerManagement();
            this.bindPropertyPanel();
            this.bindFormSubmission();
            this.bindKeyboardMovement();
            this.bindAlignmentButtons();
            this.bindFullscreen();
            this.renderVisualEditor();
            this.setupCanvasScaling();
            this.pushHistory(); // Save initial state
        },

        // ─── Config ──────────────────────────────────────────────

        initializeConfig: function () {
            var raw = this.$hiddenInput.val();
            if (raw) {
                try {
                    this.config = JSON.parse(raw);
                    if (!this.config.canvas) this.config.canvas = this.getDefaultConfig().canvas;
                    if (!this.config.layers) this.config.layers = [];
                } catch (e) {
                    this.config = this.getDefaultConfig();
                }
            } else {
                this.config = this.getDefaultConfig();
            }
        },

        getDefaultConfig: function () {
            return {
                canvas: { width: 1200, height: 1800, bg_image: '' },
                layers: []
            };
        },

        // ─── Tab Switching ───────────────────────────────────────

        bindTabSwitching: function () {
            var self = this;
            $(document).on('click', '.sie-admin-tab-btn', function (e) {
                e.preventDefault();
                var target = $(this).data('tab');

                $('.sie-admin-tab-btn').removeClass('active');
                $(this).addClass('active');
                $('.sie-admin-tab-content').removeClass('active');
                $('#sie-tab-' + target).addClass('active');

                if (target === 'json') {
                    self.syncVisualToJson();
                } else if (target === 'visual') {
                    self.syncJsonToVisual();
                }
            });
        },

        syncVisualToJson: function () {
            var json = JSON.stringify(this.config, null, 2);
            this.$jsonTextarea.val(json);
        },

        syncJsonToVisual: function () {
            var raw = this.$jsonTextarea.val();
            if (!raw.trim()) return;

            try {
                var parsed = JSON.parse(raw);
                if (!parsed.canvas || !Array.isArray(parsed.layers)) {
                    this.showJsonStatus('Geçersiz yapı: canvas ve layers gerekli', false);
                    return;
                }
                this.config = parsed;
                this.deselectLayer();
                this.renderVisualEditor();
                this.fitCanvas();
                this.syncConfigToHiddenField();
                this.showJsonStatus('JSON başarıyla uygulandı', true);
            } catch (e) {
                this.showJsonStatus('JSON söz dizimi hatası: ' + e.message, false);
            }
        },

        validateJson: function () {
            var raw = this.$jsonTextarea.val();
            try {
                var parsed = JSON.parse(raw);
                if (!parsed.canvas || !Array.isArray(parsed.layers)) {
                    this.showJsonStatus('Geçersiz yapı: canvas ve layers gerekli', false);
                    return;
                }
                
                // If valid, apply it to the visual editor
                this.config = parsed;
                this.deselectLayer();
                this.renderVisualEditor();
                this.fitCanvas();
                this.syncConfigToHiddenField();
                
                this.showJsonStatus('JSON geçerli ve uygulandı', true);
            } catch (e) {
                this.showJsonStatus('Hata: ' + e.message, false);
            }
        },

        showJsonStatus: function (msg, isValid) {
            var $status = $('.sie-json-status');
            $status.text(msg)
                .removeClass('valid invalid')
                .addClass(isValid ? 'valid' : 'invalid');
            clearTimeout(this._statusTimeout);
            this._statusTimeout = setTimeout(function () {
                $status.text('');
            }, 5000);
        },

        // ─── Canvas Settings ─────────────────────────────────────

        bindCanvasSettings: function () {
            var self = this;

            this.$leftPanel.on('change', '#sie-canvas-width', function () {
                var val = parseInt($(this).val(), 10);
                if (val > 0) {
                    self.config.canvas.width = val;
                    self.updateCanvas();
                }
            });

            this.$leftPanel.on('change', '#sie-canvas-height', function () {
                var val = parseInt($(this).val(), 10);
                if (val > 0) {
                    self.config.canvas.height = val;
                    self.updateCanvas();
                }
            });

            this.$leftPanel.on('click', '#sie-bg-select-btn', function (e) {
                e.preventDefault();
                self.openMediaPicker();
            });

            this.$leftPanel.on('click', '.sie-admin-bg-remove', function (e) {
                e.preventDefault();
                self.removeBackgroundImage();
            });
        },

        openMediaPicker: function () {
            var self = this;
            var frame = wp.media({
                title: 'Arka Plan Görseli Seç',
                button: { text: 'Seç' },
                multiple: false,
                library: { type: 'image' }
            });

            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                self.setBackgroundImage(attachment.url);
            });

            frame.open();
        },

        setBackgroundImage: function (url) {
            this.config.canvas.bg_image = url;
            $('#sie-bg-url').val(url);
            this.renderBgPreview();
            this.updateCanvas();
        },

        removeBackgroundImage: function () {
            this.config.canvas.bg_image = '';
            $('#sie-bg-url').val('');
            this.renderBgPreview();
            this.updateCanvas();
        },

        renderBgPreview: function () {
            var $preview = this.$leftPanel.find('.sie-admin-bg-preview');
            if (this.config.canvas.bg_image) {
                $preview.html(
                    '<img src="' + this.config.canvas.bg_image + '" alt="">' +
                    '<button type="button" class="sie-admin-bg-remove" title="Kaldır">&times;</button>'
                );
            } else {
                $preview.html('');
            }
        },

        updateCanvas: function () {
            this.$canvas.css({
                width: this.config.canvas.width + 'px',
                height: this.config.canvas.height + 'px',
                backgroundImage: this.config.canvas.bg_image
                    ? 'url(' + this.config.canvas.bg_image + ')'
                    : 'none'
            });
            this.fitCanvas();
            this.syncConfigToHiddenField();
        },

        fitCanvas: function () {
            var areaW = this.$canvasArea[0].clientWidth;
            var areaH = this.$canvasArea[0].clientHeight;
            if (areaW === 0 || areaH === 0) return;

            var pad = 24;
            var availW = areaW - pad * 2;
            var availH = areaH - pad * 2;
            var baseW = this.config.canvas.width;
            var baseH = this.config.canvas.height;
            var scale = Math.min(availW / baseW, availH / baseH); // Removed the 1 limit to allow scaling up

            this.$canvas.css({
                width: baseW + 'px',
                height: baseH + 'px',
                transform: 'scale(' + scale + ')',
                transformOrigin: 'top left',
                marginRight: Math.floor(baseW * (scale - 1)) + 'px',
                marginBottom: Math.floor(baseH * (scale - 1)) + 'px'
            });
            this.scaleFactor = scale;
        },

        setupCanvasScaling: function () {
            var self = this;
            if (typeof ResizeObserver !== 'undefined') {
                this._resizeObserver = new ResizeObserver(function () {
                    self.fitCanvas();
                });
                this._resizeObserver.observe(this.$canvasArea[0]);
            }
        },

        // ─── Layer Management ────────────────────────────────────

        bindLayerManagement: function () {
            var self = this;

            this.$leftPanel.on('click', '.sie-admin-add-layer-btn', function (e) {
                e.preventDefault();
                self.addNewLayer();
            });

            this.$leftPanel.on('click', '.sie-admin-layer-item', function (e) {
                var id = $(this).data('layer-id');
                if (e.shiftKey && self.selectedLayerId) {
                    self.toggleLayerInSelection(id);
                } else {
                    self.selectLayerById(id);
                }
            });

            this.$canvas.on('mousedown', '.sie-admin-layer', function (e) {
                var id = $(this).data('layer-id');
                if (e.shiftKey && self.selectedLayerId) {
                    self.toggleLayerInSelection(id);
                } else if (self.selectedLayerIds.indexOf(id) === -1) {
                    self.selectLayerById(id);
                } else {
                    // Already selected — ensure properties panel is shown
                    self.selectedLayerId = id;
                    var layer = self.getLayerById(id);
                    if (layer) self.showPropertiesPanel(layer);
                }
            });

            // Toggle frontend visibility from layer list eye icon
            this.$leftPanel.on('click', '.sie-admin-layer-visibility', function (e) {
                e.stopPropagation();
                var $item = $(this).closest('.sie-admin-layer-item');
                var id = $item.data('layer-id');
                var layer = self.getLayerById(id);
                if (!layer) return;

                layer.hidden_on_frontend = !layer.hidden_on_frontend;
                $(this).toggleClass('dashicons-visibility dashicons-hidden');
                $item.toggleClass('sie-layer-hidden');
                self.syncConfigToHiddenField();

                // Update checkbox if this layer is currently shown in properties
                if (self.selectedLayerId === id) {
                    $('#sie-prop-hidden-frontend').prop('checked', layer.hidden_on_frontend);
                }
            });

            // Deselect on canvas background mousedown (not click, to avoid conflicts with layer mousedown)
            this.$canvasArea.on('mousedown', function (e) {
                if ($(e.target).hasClass('sie-admin-canvas-area') || $(e.target).hasClass('sie-admin-canvas')) {
                    self.deselectLayer();
                }
            });
        },

        addNewLayer: function () {
            var id = 'layer_' + Date.now();
            var layer = {
                id: id,
                type: 'text',
                label: 'Yeni Katman',
                default_text: 'Metin',
                style: {
                    left: '10%',
                    top: '10%',
                    width: '80%',
                    fontFamily: 'Darleston',
                    fontSize: '48px',
                    color: '#333333',
                    textAlign: 'center'
                }
            };
            this.config.layers.push(layer);
            this.renderVisualEditor();
            this.selectLayerById(id);
            this.syncConfigToHiddenField();
        },

        renderVisualEditor: function () {
            this.renderCanvasSettings();
            this.renderLayerList(); // also calls initLayerSortable
            this.renderLayers();
            this.updateCanvas();
        },

        renderCanvasSettings: function () {
            $('#sie-canvas-width').val(this.config.canvas.width);
            $('#sie-canvas-height').val(this.config.canvas.height);
            $('#sie-bg-url').val(this.config.canvas.bg_image || '');
            this.renderBgPreview();
        },

        collapsedGroups: {},

        buildLayerItem: function (layer) {
            var self = this;
            var selected = self.selectedLayerIds.indexOf(layer.id) !== -1 ? ' selected' : '';
            var hiddenClass = layer.hidden_on_frontend ? ' sie-layer-hidden' : '';
            var eyeIcon = layer.hidden_on_frontend ? 'dashicons-hidden' : 'dashicons-visibility';
            return $(
                '<li class="sie-admin-layer-item' + selected + hiddenClass + '" data-layer-id="' + layer.id + '">' +
                '<span class="sie-admin-layer-drag-handle dashicons dashicons-menu"></span>' +
                '<span class="dashicons dashicons-text"></span>' +
                '<span>' + self.escapeHtml(layer.label) + '</span>' +
                '<span class="sie-admin-layer-visibility dashicons ' + eyeIcon + '" title="Önyüzde Görünürlük"></span>' +
                '</li>'
            );
        },

        renderLayerList: function () {
            var self = this;
            var $list = this.$leftPanel.find('.sie-admin-layer-list');
            $list.empty();

            // Track which group headers have been rendered
            var renderedGroups = {};

            this.config.layers.forEach(function (layer) {
                if (layer.group) {
                    // Render group header once when first encountered
                    if (!renderedGroups[layer.group]) {
                        renderedGroups[layer.group] = true;
                        var isCollapsed = !!self.collapsedGroups[layer.group];
                        var toggleIcon = isCollapsed ? 'dashicons-arrow-right-alt2' : 'dashicons-arrow-down-alt2';
                        $list.append(
                            '<li class="sie-admin-group-header-row" data-group-header="' + self.escapeHtml(layer.group) + '">' +
                            '<span class="sie-admin-group-toggle dashicons ' + toggleIcon + '"></span>' +
                            '<span class="sie-admin-group-name">' + self.escapeHtml(layer.group) + '</span>' +
                            '<button type="button" class="sie-admin-group-duplicate" title="Grubu Çoğalt"><span class="dashicons dashicons-admin-page"></span></button>' +
                            '</li>'
                        );
                    }
                    // Render the layer item, hidden if group is collapsed
                    var $item = self.buildLayerItem(layer);
                    $item.attr('data-group', layer.group);
                    if (self.collapsedGroups[layer.group]) {
                        $item.hide();
                    }
                    $list.append($item);
                } else {
                    $list.append(self.buildLayerItem(layer));
                }
            });

            this.initLayerSortable();
        },

        initLayerSortable: function () {
            var self = this;
            var $list = this.$leftPanel.find('.sie-admin-layer-list');

            if ($list.data('ui-sortable')) { $list.sortable('destroy'); }

            // Single flat sortable — only layer items are draggable, group headers are fixed dividers
            $list.sortable({
                handle: '.sie-admin-layer-drag-handle',
                items: '.sie-admin-layer-item',
                tolerance: 'pointer',
                placeholder: 'sie-admin-sortable-placeholder',
                update: function () {
                    var newOrder = [];
                    var currentGroup = null;

                    $list.children().each(function () {
                        var $item = $(this);

                        if ($item.hasClass('sie-admin-group-header-row')) {
                            // Track which group we're currently under
                            currentGroup = $item.attr('data-group-header');
                        } else if ($item.hasClass('sie-admin-layer-item')) {
                            var id = $item.attr('data-layer-id');
                            var layer = self.getLayerById(id);
                            if (layer) {
                                if (currentGroup) {
                                    layer.group = currentGroup;
                                } else {
                                    delete layer.group;
                                }
                                $item.attr('data-group', currentGroup || '');
                                newOrder.push(layer);
                            }
                        }
                    });

                    self.config.layers = newOrder;
                    self.renderLayers();
                    self.refreshSelectionUI();
                    self.syncConfigToHiddenField();
                }
            });

            // Duplicate group button
            $list.off('click.sie-dup-group').on('click.sie-dup-group', '.sie-admin-group-duplicate', function (e) {
                e.stopPropagation();
                var groupName = $(this).closest('.sie-admin-group-header-row').attr('data-group-header');
                self.duplicateGroup(groupName);
            });

            // Collapse/expand on group header toggle
            $list.off('click.sie-group').on('click.sie-group', '.sie-admin-group-toggle', function (e) {
                e.stopPropagation();
                var $headerRow = $(this).closest('.sie-admin-group-header-row');
                var groupName = $headerRow.attr('data-group-header');
                var isCollapsed = !!self.collapsedGroups[groupName];

                if (isCollapsed) {
                    // Expand: show all items belonging to this group
                    $list.find('.sie-admin-layer-item[data-group="' + groupName + '"]').show();
                    $(this).removeClass('dashicons-arrow-right-alt2').addClass('dashicons-arrow-down-alt2');
                    delete self.collapsedGroups[groupName];
                } else {
                    // Collapse: hide all items belonging to this group
                    $list.find('.sie-admin-layer-item[data-group="' + groupName + '"]').hide();
                    $(this).removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-right-alt2');
                    self.collapsedGroups[groupName] = true;
                }
            });
        },

        renderLayers: function () {
            var self = this;
            this.$canvas.find('.sie-admin-layer').remove();

            this.config.layers.forEach(function (layer) {
                self.renderLayer(layer);
            });
        },

        renderLayer: function (layer) {
            var style = $.extend({}, layer.style);

            // Convert left+width to center-point positioning (same as frontend)
            if (style.left && style.width) {
                var left = parseFloat(style.left);
                var width = parseFloat(style.width);
                style.left = (left + width / 2) + '%';
                delete style.width;
            }
            style.transform = 'translateX(-50%)';
            style.position = 'absolute';

            var text = (layer.default_text || '').replace(/\\n/g, '\n');
            var htmlText = this.escapeHtml(text).replace(/\n/g, '<br>');

            var $el = $('<div>')
                .addClass('sie-admin-layer')
                .attr('data-layer-id', layer.id)
                .css(style)
                .html(htmlText);

            if (this.selectedLayerIds.indexOf(layer.id) !== -1) {
                $el.addClass('selected');
            }

            this.$canvas.append($el);
            this.makeLayerDraggable($el, layer);
        },

        // ─── Selection ───────────────────────────────────────────

        selectLayerById: function (id) {
            this.selectedLayerId = id;
            this.selectedLayerIds = [id];
            this.refreshSelectionUI();

            // Show properties panel
            var layer = this.getLayerById(id);
            if (layer) {
                this.showPropertiesPanel(layer);
            }
        },

        toggleLayerInSelection: function (id) {
            var idx = this.selectedLayerIds.indexOf(id);
            if (idx !== -1) {
                // Remove from selection
                this.selectedLayerIds.splice(idx, 1);
                if (this.selectedLayerId === id) {
                    this.selectedLayerId = this.selectedLayerIds.length ? this.selectedLayerIds[this.selectedLayerIds.length - 1] : null;
                }
            } else {
                // Add to selection
                this.selectedLayerIds.push(id);
                this.selectedLayerId = id;
            }

            this.refreshSelectionUI();

            if (this.selectedLayerId) {
                var layer = this.getLayerById(this.selectedLayerId);
                if (layer) this.showPropertiesPanel(layer);
            } else {
                this.$rightPanel.addClass('hidden');
            }
        },

        refreshSelectionUI: function () {
            var self = this;
            this.$leftPanel.find('.sie-admin-layer-item').removeClass('selected');
            this.$canvas.find('.sie-admin-layer').removeClass('selected');

            this.selectedLayerIds.forEach(function (id) {
                self.$leftPanel.find('.sie-admin-layer-item[data-layer-id="' + id + '"]').addClass('selected');
                self.$canvas.find('.sie-admin-layer[data-layer-id="' + id + '"]').addClass('selected');
            });

            // Show/hide canvas-align section (1+ selected)
            var $canvasAlignSection = this.$rightPanel.find('.sie-admin-canvas-align-section');
            if (this.selectedLayerIds.length >= 1) {
                $canvasAlignSection.show();
            } else {
                $canvasAlignSection.hide();
            }

            // Show/hide alignment section based on multi-selection
            var $alignSection = this.$rightPanel.find('.sie-admin-align-section');
            if (this.selectedLayerIds.length >= 2) {
                $alignSection.show();
            } else {
                $alignSection.hide();
            }
        },

        deselectLayer: function () {
            this.selectedLayerId = null;
            this.selectedLayerIds = [];
            this.$leftPanel.find('.sie-admin-layer-item').removeClass('selected');
            this.$canvas.find('.sie-admin-layer').removeClass('selected');
            this.$rightPanel.addClass('hidden');
            this.$rightPanel.find('.sie-admin-canvas-align-section').hide();
            this.$rightPanel.find('.sie-admin-align-section').hide();
        },

        getLayerById: function (id) {
            for (var i = 0; i < this.config.layers.length; i++) {
                if (this.config.layers[i].id === id) return this.config.layers[i];
            }
            return null;
        },

        getLayerIndex: function (id) {
            for (var i = 0; i < this.config.layers.length; i++) {
                if (this.config.layers[i].id === id) return i;
            }
            return -1;
        },

        // ─── Properties Panel ────────────────────────────────────

        bindPropertyPanel: function () {
            var self = this;

            // Populate font dropdown
            this.populateFontDropdown();

            // Text fields
            this.$rightPanel.on('input', '#sie-prop-label', function () {
                self.updateSelectedLayerProperty('label', $(this).val());
            });

            this.$rightPanel.on('input', '#sie-prop-group', function () {
                if (!self.selectedLayerId) return;
                var layer = self.getLayerById(self.selectedLayerId);
                if (!layer) return;
                var val = $(this).val().trim();
                if (val) {
                    layer.group = val;
                } else {
                    delete layer.group;
                }
                self.renderLayerList();
                self.refreshSelectionUI();
                self.syncConfigToHiddenField();
            });

            this.$rightPanel.on('input', '#sie-prop-default-text', function () {
                self.updateSelectedLayerProperty('default_text', $(this).val());
            });

            this.$rightPanel.on('input', '#sie-prop-id', function () {
                var newId = $(this).val().replace(/[^a-zA-Z0-9_-]/g, '');
                $(this).val(newId);
                if (newId && self.selectedLayerId) {
                    var layer = self.getLayerById(self.selectedLayerId);
                    if (layer) {
                        var oldId = layer.id;
                        layer.id = newId;
                        self.selectedLayerId = newId;
                        // Update DOM
                        self.$canvas.find('.sie-admin-layer[data-layer-id="' + oldId + '"]').attr('data-layer-id', newId);
                        self.$leftPanel.find('.sie-admin-layer-item[data-layer-id="' + oldId + '"]').attr('data-layer-id', newId);
                        self.syncConfigToHiddenField();
                    }
                }
            });

            // Style fields
            this.$rightPanel.on('change', '#sie-prop-font', function () {
                self.updateSelectedLayerStyle('fontFamily', $(this).val());
            });

            this.$rightPanel.on('input', '#sie-prop-fontsize', function () {
                self.updateSelectedLayerStyle('fontSize', $(this).val() + 'px');
            });

            this.$rightPanel.on('input', '#sie-prop-left', function () {
                self.updateSelectedLayerStylePosition('left', $(this).val());
            });

            this.$rightPanel.on('input', '#sie-prop-top', function () {
                self.updateSelectedLayerStylePosition('top', $(this).val());
            });

            this.$rightPanel.on('input', '#sie-prop-width', function () {
                self.updateSelectedLayerStylePosition('width', $(this).val());
            });

            this.$rightPanel.on('input', '#sie-prop-letterspacing', function () {
                var val = $(this).val();
                self.updateSelectedLayerStyle('letterSpacing', val ? val + 'px' : '');
            });

            this.$rightPanel.on('input', '#sie-prop-lineheight', function () {
                var val = $(this).val();
                self.updateSelectedLayerStyle('lineHeight', val || '');
            });

            this.$rightPanel.on('change', '#sie-prop-fontweight', function () {
                self.updateSelectedLayerStyle('fontWeight', $(this).val());
            });

            this.$rightPanel.on('change', '#sie-prop-fontstyle', function () {
                self.updateSelectedLayerStyle('fontStyle', $(this).val());
            });

            // Hidden on frontend checkbox
            this.$rightPanel.on('change', '#sie-prop-hidden-frontend', function () {
                if (!self.selectedLayerId) return;
                var layer = self.getLayerById(self.selectedLayerId);
                if (!layer) return;
                layer.hidden_on_frontend = $(this).is(':checked');
                self.renderLayerList();
                self.refreshSelectionUI();
                self.syncConfigToHiddenField();
            });

            // Duplicate & Delete
            this.$rightPanel.on('click', '#sie-prop-duplicate', function (e) {
                e.preventDefault();
                self.duplicateSelectedLayer();
            });

            this.$rightPanel.on('click', '#sie-prop-delete', function (e) {
                e.preventDefault();
                if (confirm('Bu katmanı silmek istediğinize emin misiniz?')) {
                    self.deleteSelectedLayer();
                }
            });
        },

        populateFontDropdown: function () {
            var $select = this.$rightPanel.find('#sie-prop-font');
            if (!$select.length) return;

            $select.empty();

            // Web-safe fonts
            var webFonts = ['Arial', 'Georgia', 'Times New Roman', 'Verdana', 'Courier New'];
            var $webGroup = $('<optgroup label="Sistem Fontları">');
            webFonts.forEach(function (f) {
                $webGroup.append('<option value="' + f + '">' + f + '</option>');
            });
            $select.append($webGroup);

            // Custom fonts from localized data
            if (typeof sie_admin_config !== 'undefined' && sie_admin_config.fonts) {
                var $customGroup = $('<optgroup label="Özel Fontlar">');
                sie_admin_config.fonts.forEach(function (f) {
                    $customGroup.append('<option value="' + f + '">' + f + '</option>');
                });
                $select.append($customGroup);
            }
        },

        showPropertiesPanel: function (layer) {
            this.$rightPanel.removeClass('hidden');

            // Fill fields
            $('#sie-prop-id').val(layer.id);
            $('#sie-prop-label').val(layer.label || '');
            $('#sie-prop-group').val(layer.group || '');
            $('#sie-prop-default-text').val(layer.default_text || '');

            var s = layer.style || {};
            $('#sie-prop-font').val(s.fontFamily || 'Darleston');
            $('#sie-prop-fontsize').val(parseInt(s.fontSize, 10) || 48);
            $('#sie-prop-left').val(parseFloat(s.left) || 0);
            $('#sie-prop-top').val(parseFloat(s.top) || 0);
            $('#sie-prop-width').val(parseFloat(s.width) || 80);
            $('#sie-prop-letterspacing').val(parseFloat(s.letterSpacing) || '');
            $('#sie-prop-lineheight').val(s.lineHeight || '');
            $('#sie-prop-fontweight').val(s.fontWeight || 'normal');
            $('#sie-prop-fontstyle').val(s.fontStyle || 'normal');
            $('#sie-prop-hidden-frontend').prop('checked', !!layer.hidden_on_frontend);

            // Init/update color picker
            this.initColorPicker(s.color || '#333333');
        },

        initColorPicker: function (color) {
            var self = this;
            var $input = $('#sie-prop-color');

            // Destroy previous if exists
            if ($input.data('wpWpColorPicker')) {
                $input.wpColorPicker('close');
                // Re-create fresh input
                var $parent = $input.closest('.sie-admin-field');
                $parent.find('.wp-picker-container').remove();
                $parent.append('<input type="text" id="sie-prop-color" value="">');
                $input = $('#sie-prop-color');
            }

            $input.val(color);
            $input.wpColorPicker({
                defaultColor: color,
                change: function (event, ui) {
                    self.updateSelectedLayerStyle('color', ui.color.toString());
                },
                clear: function () {
                    self.updateSelectedLayerStyle('color', '#333333');
                }
            });
        },

        updateSelectedLayerProperty: function (prop, value) {
            if (!this.selectedLayerId) return;
            var layer = this.getLayerById(this.selectedLayerId);
            if (!layer) return;

            layer[prop] = value;

            if (prop === 'default_text') {
                var text = value.replace(/\\n/g, '\n');
                var htmlText = this.escapeHtml(text).replace(/\n/g, '<br>');
                this.$canvas.find('.sie-admin-layer[data-layer-id="' + this.selectedLayerId + '"]').html(htmlText);
            }

            if (prop === 'label') {
                this.renderLayerList();
            }

            this.syncConfigToHiddenField();
        },

        updateSelectedLayerStyle: function (prop, value) {
            if (!this.selectedLayerId) return;
            var layer = this.getLayerById(this.selectedLayerId);
            if (!layer) return;

            if (!layer.style) layer.style = {};

            if (value === '' || value === undefined) {
                delete layer.style[prop];
            } else {
                layer.style[prop] = value;
            }

            // Apply to canvas layer (need to handle center-point conversion for positioning)
            var $el = this.$canvas.find('.sie-admin-layer[data-layer-id="' + this.selectedLayerId + '"]');
            if (['left', 'top', 'width'].indexOf(prop) === -1) {
                $el.css(prop, value || '');
            } else {
                // Re-render this single layer to apply position conversion
                this.renderLayers();
                this.selectLayerById(this.selectedLayerId);
            }

            this.syncConfigToHiddenField();
        },

        updateSelectedLayerStylePosition: function (prop, value) {
            if (!this.selectedLayerId) return;
            var layer = this.getLayerById(this.selectedLayerId);
            if (!layer || !layer.style) return;

            layer.style[prop] = value + '%';
            this.renderLayers();
            this.selectLayerById(this.selectedLayerId);
            this.syncConfigToHiddenField();
        },

        duplicateGroup: function (groupName) {
            var self = this;
            var newGroupName = groupName + ' (kopya)';
            // Ensure unique group name
            var suffix = 1;
            var existingNames = {};
            this.config.layers.forEach(function (l) { if (l.group) existingNames[l.group] = true; });
            while (existingNames[newGroupName]) {
                newGroupName = groupName + ' (kopya ' + (++suffix) + ')';
            }

            // Clone all layers in the group
            var clones = [];
            this.config.layers.forEach(function (layer) {
                if (layer.group === groupName) {
                    var clone = JSON.parse(JSON.stringify(layer));
                    clone.id = layer.id + '_copy_' + Date.now() + '_' + Math.floor(Math.random() * 1000);
                    clone.label = layer.label + ' (kopya)';
                    clone.group = newGroupName;
                    if (clone.style && clone.style.top) {
                        clone.style.top = (parseFloat(clone.style.top) + 3) + '%';
                    }
                    clones.push(clone);
                }
            });

            if (!clones.length) return;

            // Insert clones after the last layer of the original group
            var lastIdx = -1;
            for (var i = 0; i < this.config.layers.length; i++) {
                if (this.config.layers[i].group === groupName) lastIdx = i;
            }
            var args = [lastIdx + 1, 0].concat(clones);
            Array.prototype.splice.apply(this.config.layers, args);

            this.renderVisualEditor();
            this.syncConfigToHiddenField();
        },

        duplicateSelectedLayer: function () {
            if (!this.selectedLayerId) return;
            var layer = this.getLayerById(this.selectedLayerId);
            if (!layer) return;

            var clone = JSON.parse(JSON.stringify(layer));
            clone.id = layer.id + '_copy_' + Date.now();
            clone.label = layer.label + ' (kopya)';

            // Offset position slightly
            if (clone.style && clone.style.top) {
                clone.style.top = (parseFloat(clone.style.top) + 3) + '%';
            }

            var idx = this.getLayerIndex(this.selectedLayerId);
            this.config.layers.splice(idx + 1, 0, clone);

            this.renderVisualEditor();
            this.selectLayerById(clone.id);
            this.syncConfigToHiddenField();
        },

        deleteSelectedLayer: function () {
            if (!this.selectedLayerId) return;
            var idx = this.getLayerIndex(this.selectedLayerId);
            if (idx === -1) return;

            this.config.layers.splice(idx, 1);
            this.deselectLayer();
            this.renderVisualEditor();
            this.syncConfigToHiddenField();
        },

        // ─── Drag ────────────────────────────────────────────────

        makeLayerDraggable: function ($el, layer) {
            var self = this;
            var isDragging = false;
            var startX, startY;
            var dragTargets = [];

            $el.on('mousedown', function (e) {
                if (e.which !== 1) return; // left click only
                isDragging = true;
                startX = e.clientX;
                startY = e.clientY;

                // Collect all selected layers (or just this one if not in selection)
                var ids = self.selectedLayerIds.indexOf(layer.id) !== -1
                    ? self.selectedLayerIds
                    : [layer.id];

                dragTargets = [];
                ids.forEach(function (id) {
                    var $layerEl = self.$canvas.find('.sie-admin-layer[data-layer-id="' + id + '"]');
                    var layerData = self.getLayerById(id);
                    if ($layerEl.length && layerData) {
                        dragTargets.push({
                            $el: $layerEl,
                            layer: layerData,
                            startLeft: parseFloat($layerEl.css('left')),
                            startTop: parseFloat($layerEl.css('top'))
                        });
                    }
                });

                e.preventDefault();

                $(document).on('mousemove.sie-admin-drag', function (e) {
                    if (!isDragging) return;

                    var dx = (e.clientX - startX) / self.scaleFactor;
                    var dy = (e.clientY - startY) / self.scaleFactor;

                    var canvasW = self.config.canvas.width;
                    var canvasH = self.config.canvas.height;

                    dragTargets.forEach(function (t) {
                        var newLeftPx = t.startLeft + dx;
                        var newTopPx = t.startTop + dy;

                        var newLeftPct = (newLeftPx / canvasW * 100).toFixed(2);
                        var newTopPct = (newTopPx / canvasH * 100).toFixed(2);

                        t.$el.css({ left: newLeftPct + '%', top: newTopPct + '%' });

                        // Reverse the center-point conversion to store original left+width
                        var origWidth = parseFloat(t.layer.style.width) || 80;
                        t.layer.style.left = (parseFloat(newLeftPct) - origWidth / 2).toFixed(2) + '%';
                        t.layer.style.top = newTopPct + '%';
                    });

                    // Sync property fields for the primary selected layer
                    if (self.selectedLayerId === layer.id) {
                        $('#sie-prop-left').val(parseFloat(layer.style.left).toFixed(2));
                        $('#sie-prop-top').val(parseFloat(layer.style.top).toFixed(2));
                    }
                });

                $(document).on('mouseup.sie-admin-drag', function () {
                    if (isDragging) {
                        isDragging = false;
                        self.syncConfigToHiddenField();
                    }
                    $(document).off('.sie-admin-drag');
                });
            });
        },

        // ─── Keyboard ────────────────────────────────────────────

        bindKeyboardMovement: function () {
            var self = this;

            $(document).on('keydown.sie-admin', function (e) {
                // Don't intercept when typing in inputs
                var tag = e.target.tagName.toLowerCase();
                if (tag === 'input' || tag === 'textarea' || tag === 'select') return;

                // Undo/Redo (works even without layer selection)
                if ((e.ctrlKey || e.metaKey) && !e.altKey) {
                    if (e.key === 'z' && !e.shiftKey) {
                        e.preventDefault();
                        self.undo();
                        return;
                    }
                    if (e.key === 'y' || (e.key === 'z' && e.shiftKey) || (e.key === 'Z' && e.shiftKey)) {
                        e.preventDefault();
                        self.redo();
                        return;
                    }
                }

                if (!self.selectedLayerId) return;

                var key = e.key;
                if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'Escape', 'Delete'].indexOf(key) === -1) return;

                if (key === 'Escape') {
                    self.deselectLayer();
                    return;
                }

                if (key === 'Delete') {
                    if (confirm('Bu katmanı silmek istediğinize emin misiniz?')) {
                        self.deleteSelectedLayer();
                    }
                    return;
                }

                e.preventDefault();
                var step = e.shiftKey ? 10 : 1;
                var canvasW = self.config.canvas.width;
                var canvasH = self.config.canvas.height;

                self.selectedLayerIds.forEach(function (id) {
                    var $el = self.$canvas.find('.sie-admin-layer[data-layer-id="' + id + '"]');
                    var layer = self.getLayerById(id);
                    if (!$el.length || !layer) return;

                    var left = parseFloat($el.css('left'));
                    var top = parseFloat($el.css('top'));

                    if (key === 'ArrowLeft') left -= step;
                    if (key === 'ArrowRight') left += step;
                    if (key === 'ArrowUp') top -= step;
                    if (key === 'ArrowDown') top += step;

                    var newLeftPct = (left / canvasW * 100).toFixed(2);
                    var newTopPct = (top / canvasH * 100).toFixed(2);

                    $el.css({ left: newLeftPct + '%', top: newTopPct + '%' });

                    // Reverse center-point conversion
                    var origWidth = parseFloat(layer.style.width) || 80;
                    layer.style.left = (parseFloat(newLeftPct) - origWidth / 2).toFixed(2) + '%';
                    layer.style.top = newTopPct + '%';

                    // Sync property fields for the primary selected layer
                    if (self.selectedLayerId === id) {
                        $('#sie-prop-left').val(parseFloat(layer.style.left).toFixed(2));
                        $('#sie-prop-top').val(parseFloat(layer.style.top).toFixed(2));
                    }
                });

                self.syncConfigToHiddenField();
            });
        },

        // ─── Alignment & Distribution ─────────────────────────────

        bindFullscreen: function () {
            var self = this;
            this.$container.on('click', '.sie-admin-fullscreen-btn', function (e) {
                e.preventDefault();
                self.$container.toggleClass('sie-admin-fullscreen');
                
                // Allow CSS transition to finish before recalculating scale
                setTimeout(function() {
                    self.fitCanvas();
                }, 350);
            });
        },

        bindAlignmentButtons: function () {
            var self = this;
            this.$rightPanel.on('click', '.sie-align-btn', function (e) {
                e.preventDefault();
                var type = $(this).data('align');
                if (type) self.alignLayers(type);
            });

            this.$rightPanel.on('click', '.sie-canvas-align-btn', function (e) {
                e.preventDefault();
                var type = $(this).attr('data-canvas-align');
                if (type) self.alignLayersToCanvas(type);
            });
        },

        getSelectedLayersBounds: function () {
            var self = this;
            var bounds = [];
            this.selectedLayerIds.forEach(function (id) {
                var layer = self.getLayerById(id);
                if (!layer || !layer.style) return;
                var left = parseFloat(layer.style.left) || 0;
                var top = parseFloat(layer.style.top) || 0;
                var width = parseFloat(layer.style.width) || 80;
                bounds.push({
                    layer: layer,
                    left: left,
                    top: top,
                    width: width,
                    right: left + width,
                    centerX: left + width / 2
                });
            });
            return bounds;
        },

        alignLayers: function (type) {
            var bounds = this.getSelectedLayersBounds();
            if (bounds.length < 2) return;

            var i;
            switch (type) {
                case 'align-left':
                    var minLeft = Infinity;
                    for (i = 0; i < bounds.length; i++) {
                        if (bounds[i].left < minLeft) minLeft = bounds[i].left;
                    }
                    for (i = 0; i < bounds.length; i++) {
                        bounds[i].layer.style.left = minLeft + '%';
                    }
                    break;

                case 'align-center-h':
                    var minL = Infinity, maxR = -Infinity;
                    for (i = 0; i < bounds.length; i++) {
                        if (bounds[i].left < minL) minL = bounds[i].left;
                        if (bounds[i].right > maxR) maxR = bounds[i].right;
                    }
                    var midX = (minL + maxR) / 2;
                    for (i = 0; i < bounds.length; i++) {
                        bounds[i].layer.style.left = (midX - bounds[i].width / 2) + '%';
                    }
                    break;

                case 'align-right':
                    var maxRight = -Infinity;
                    for (i = 0; i < bounds.length; i++) {
                        if (bounds[i].right > maxRight) maxRight = bounds[i].right;
                    }
                    for (i = 0; i < bounds.length; i++) {
                        bounds[i].layer.style.left = (maxRight - bounds[i].width) + '%';
                    }
                    break;

                case 'align-top':
                    var minTop = Infinity;
                    for (i = 0; i < bounds.length; i++) {
                        if (bounds[i].top < minTop) minTop = bounds[i].top;
                    }
                    for (i = 0; i < bounds.length; i++) {
                        bounds[i].layer.style.top = minTop + '%';
                    }
                    break;

                case 'align-center-v':
                    var minT = Infinity, maxT = -Infinity;
                    for (i = 0; i < bounds.length; i++) {
                        if (bounds[i].top < minT) minT = bounds[i].top;
                        if (bounds[i].top > maxT) maxT = bounds[i].top;
                    }
                    var midY = (minT + maxT) / 2;
                    for (i = 0; i < bounds.length; i++) {
                        bounds[i].layer.style.top = midY + '%';
                    }
                    break;

                case 'align-bottom':
                    var maxBot = -Infinity;
                    for (i = 0; i < bounds.length; i++) {
                        if (bounds[i].top > maxBot) maxBot = bounds[i].top;
                    }
                    for (i = 0; i < bounds.length; i++) {
                        bounds[i].layer.style.top = maxBot + '%';
                    }
                    break;

                case 'distribute-h':
                    if (bounds.length < 3) return;
                    bounds.sort(function (a, b) { return a.centerX - b.centerX; });
                    var firstCX = bounds[0].centerX;
                    var lastCX = bounds[bounds.length - 1].centerX;
                    var stepH = (lastCX - firstCX) / (bounds.length - 1);
                    for (i = 1; i < bounds.length - 1; i++) {
                        var newCX = firstCX + stepH * i;
                        bounds[i].layer.style.left = (newCX - bounds[i].width / 2) + '%';
                    }
                    break;

                case 'distribute-v':
                    if (bounds.length < 3) return;
                    bounds.sort(function (a, b) { return a.top - b.top; });
                    var firstTop = bounds[0].top;
                    var lastTop = bounds[bounds.length - 1].top;
                    var stepV = (lastTop - firstTop) / (bounds.length - 1);
                    for (i = 1; i < bounds.length - 1; i++) {
                        bounds[i].layer.style.top = (firstTop + stepV * i) + '%';
                    }
                    break;
            }

            this.renderLayers();
            this.refreshSelectionUI();
            this.syncConfigToHiddenField();

            // Re-show properties for current primary layer
            if (this.selectedLayerId) {
                var layer = this.getLayerById(this.selectedLayerId);
                if (layer) this.showPropertiesPanel(layer);
            }
        },

        alignLayersToCanvas: function (type) {
            var self = this;
            if (!this.selectedLayerIds.length) return;

            this.selectedLayerIds.forEach(function (id) {
                var layer = self.getLayerById(id);
                if (!layer || !layer.style) return;

                var width = parseFloat(layer.style.width) || 80;

                switch (type) {
                    case 'canvas-left':
                        layer.style.left = '0%';
                        break;
                    case 'canvas-center-h':
                        layer.style.left = (50 - width / 2) + '%';
                        break;
                    case 'canvas-right':
                        layer.style.left = (100 - width) + '%';
                        break;
                    case 'canvas-top':
                        layer.style.top = '0%';
                        break;
                    case 'canvas-center-v':
                        layer.style.top = '50%';
                        break;
                    case 'canvas-bottom':
                        layer.style.top = '100%';
                        break;
                }
            });

            this.renderLayers();
            this.refreshSelectionUI();
            this.syncConfigToHiddenField();

            if (this.selectedLayerId) {
                var layer = this.getLayerById(this.selectedLayerId);
                if (layer) this.showPropertiesPanel(layer);
            }
        },

        // ─── Save ────────────────────────────────────────────────

        bindFormSubmission: function () {
            var self = this;
            $('#post').on('submit', function () {
                // If the JSON tab is currently active, try to parse and apply the JSON first
                if ($('#sie-tab-json').hasClass('active')) {
                    var raw = self.$jsonTextarea.val();
                    if (raw.trim()) {
                        try {
                            var parsed = JSON.parse(raw);
                            if (parsed.canvas && Array.isArray(parsed.layers)) {
                                self.config = parsed;
                            }
                        } catch (e) {
                            // If it's invalid JSON, we just fall back to the last valid config
                            console.error('SIE JSON parse error before save:', e);
                        }
                    }
                }
                
                self.syncConfigToHiddenField();
            });
        },

        syncConfigToHiddenField: function () {
            this.$hiddenInput.val(JSON.stringify(this.config));
            this.pushHistory();
        },

        // ─── Undo/Redo ─────────────────────────────────────────

        pushHistory: function () {
            if (this._isRestoring) return;

            var snapshot = JSON.stringify(this.config);

            // Skip if identical to current state
            if (this.historyIndex >= 0 && this.history[this.historyIndex] === snapshot) return;

            // Truncate any redo entries
            this.history = this.history.slice(0, this.historyIndex + 1);
            this.history.push(snapshot);

            // Cap at maxHistory
            if (this.history.length > this.maxHistory) {
                this.history.shift();
            }

            this.historyIndex = this.history.length - 1;
        },

        undo: function () {
            if (this.historyIndex <= 0) return;
            this.historyIndex--;
            this.restoreFromHistory();
        },

        redo: function () {
            if (this.historyIndex >= this.history.length - 1) return;
            this.historyIndex++;
            this.restoreFromHistory();
        },

        restoreFromHistory: function () {
            this._isRestoring = true;
            this.config = JSON.parse(this.history[this.historyIndex]);
            this.deselectLayer();
            this.renderVisualEditor();
            this.fitCanvas();
            this.$hiddenInput.val(JSON.stringify(this.config));
            this._isRestoring = false;
        },

        // ─── Utilities ───────────────────────────────────────────

        escapeHtml: function (str) {
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }
    };

    $(document).ready(function () {
        SIE_AdminEditor.init();

        // Validate JSON button
        $(document).on('click', '#sie-validate-json', function (e) {
            e.preventDefault();
            SIE_AdminEditor.validateJson();
        });
    });

})(jQuery);
