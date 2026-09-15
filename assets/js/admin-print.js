(function ($) {
    'use strict';

    // Order screen: regenerate print PDFs and save text corrections for one item panel.
    function request($item, action, extra) {
        var detailsOpen = $item.find('.altegena-print-texts').prop('open');
        var $spinners = $item.find('.spinner');

        $item.addClass('is-busy').find('button').prop('disabled', true);
        $spinners.addClass('is-active');

        return $.post(altegena_print.ajax_url, $.extend({
            action: action,
            order_id: $item.attr('data-order'),
            item_id: $item.attr('data-item'),
            nonce: $item.attr('data-nonce')
        }, extra || {})).done(function (response) {
            if (response && response.success && response.data && response.data.html) {
                var $fresh = $(response.data.html);
                if (detailsOpen) {
                    $fresh.find('.altegena-print-texts').prop('open', true);
                }
                $item.replaceWith($fresh);
                return;
            }
            window.alert((response && response.data && response.data.message) || altegena_print.i18n.error);
        }).fail(function (xhr) {
            var data = xhr.responseJSON && xhr.responseJSON.data;
            window.alert((data && data.message) || altegena_print.i18n.error);
        }).always(function () {
            $item.removeClass('is-busy').find('button').prop('disabled', false);
            $spinners.removeClass('is-active');
        });
    }

    $(document).on('click', '.altegena-print-regenerate', function (e) {
        e.preventDefault();
        request($(this).closest('.altegena-print-item'), 'altegena_print_regenerate');
    });

    $(document).on('click', '.altegena-print-save', function (e) {
        e.preventDefault();
        var $item = $(this).closest('.altegena-print-item');
        var texts = {};
        $item.find('.altegena-print-texts textarea[data-layer]').each(function () {
            texts[$(this).attr('data-layer')] = $(this).val();
        });
        request($item, 'altegena_print_save_texts', { texts: JSON.stringify(texts) });
    });

    $(document).on('click', '.altegena-print-revert', function (e) {
        e.preventDefault();
        var $textarea = $(this).closest('td').find('textarea');
        $textarea.val($textarea.attr('data-original'));
    });
})(jQuery);
