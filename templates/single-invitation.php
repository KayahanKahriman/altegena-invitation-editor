<?php
/**
 * Public read-only invitation page (theme-independent).
 *
 * Rendered for single `altegena_invitation` posts via Altegena_Share_Handler::load_single_template().
 * The card itself is drawn client-side by public-invitation.js from the localized
 * `altegena_share.config`; the stored PNG is used as a <noscript> fallback and OG image.
 */

if (!defined('ABSPATH')) {
    exit;
}

$post_id   = get_queried_object_id();
$image_url = get_post_meta($post_id, '_altegena_share_image_url', true);
$page_url  = get_permalink($post_id);
$wa_message = Altegena_Settings::text('share_message');
$wa_href   = 'https://wa.me/?text=' . rawurlencode($wa_message . ' ' . $page_url);
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>

<head>
    <meta charset="<?php bloginfo('charset'); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" />
    <?php wp_head(); ?>
</head>

<body <?php body_class('altegena-public-page'); ?>>

    <div class="altegena-public-stage">
        <div id="altegena-public-app">
            <?php if (!empty($image_url)) : ?>
                <noscript>
                    <img class="altegena-public-fallback-img" src="<?php echo esc_url($image_url); ?>" alt="Davetiye" />
                </noscript>
            <?php endif; ?>
        </div>
    </div>

    <a id="altegena-public-share" class="altegena-public-share" href="<?php echo esc_url($wa_href); ?>" target="_blank" rel="noopener">
        <span class="altegena-public-share-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor">
                <path d="M17.5 14.4c-.3-.15-1.77-.87-2.04-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.17-.17.2-.35.22-.65.07-.3-.15-1.26-.46-2.4-1.48-.89-.79-1.49-1.77-1.66-2.07-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.67-1.62-.92-2.22-.24-.58-.49-.5-.67-.51-.17-.01-.37-.01-.57-.01-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48 0 1.46 1.06 2.87 1.21 3.07.15.2 2.09 3.2 5.07 4.49.71.31 1.26.49 1.69.62.71.23 1.36.2 1.87.12.57-.09 1.77-.72 2.02-1.42.25-.7.25-1.29.17-1.42-.07-.13-.27-.2-.57-.35zM12.05 21.5h-.01a9.4 9.4 0 0 1-4.79-1.31l-.34-.2-3.56.93.95-3.47-.22-.36a9.38 9.38 0 0 1-1.44-5.01c0-5.19 4.23-9.41 9.43-9.41 2.52 0 4.88.98 6.66 2.76a9.35 9.35 0 0 1 2.76 6.66c-.01 5.19-4.24 9.41-9.4 9.41zm5.5-14.9A11.02 11.02 0 0 0 12.04.5C5.95.5 1 5.45 1 11.53c0 1.94.51 3.84 1.47 5.51L.91 22.5l5.58-1.46a11.03 11.03 0 0 0 5.55 1.42h.01c6.09 0 11.04-4.95 11.04-11.03a10.98 10.98 0 0 0-3.24-7.83z" />
            </svg>
        </span>
        <span class="altegena-public-share-label"><?php echo esc_html(Altegena_Settings::text('label_share')); ?></span>
    </a>

    <?php wp_footer(); ?>
</body>

</html>
<?php
// Prevent the theme from appending anything further.
exit;
