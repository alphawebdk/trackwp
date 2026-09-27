<?php
/**
 * Consent banner: "bottombar" style.
 *
 * Bottom bar = first layer: heading, the full first-layer text and the three
 * buttons ("Afvis valgfrie" with the same weight as accept). "Tilpas valg"
 * opens a drawer that mirrors the cookiebot modal (tabs + categories +
 * declarations + status). consent.js toggles #trackwp-consent-drawer and
 * #trackwp-consent-overlay.
 *
 * Expects from parent: $config, $style_vars, $texts, $categories,
 * $category_labels, $vendor_list, $banner_hash, $trackwp_render_vendors.
 */
defined('ABSPATH') || exit;
?>
<div id="trackwp-consent-banner"
     class="trackwp-consent trackwp-consent--style-bottombar"
     data-style="bottombar"
     data-mode="consent"
     data-banner-hash="<?php echo esc_attr($banner_hash); ?>"
     role="region"
     aria-labelledby="trackwp-consent-bar-heading"
     style="display:none;<?php echo $style_vars; // phpcs:ignore WordPress.Security.EscapeOutput -- built from esc_attr()/absint() in the master template. ?>">
    <div class="trackwp-consent__inner trackwp-consent__inner--bar">
        <div class="trackwp-consent__bar-text">
            <h2 class="trackwp-consent__heading" id="trackwp-consent-bar-heading"><?php echo esc_html($texts['heading']); ?></h2>
            <?php
            $trackwp_text_id = 'trackwp-consent-bar-text';
            $trackwp_privacy = true;
            include __DIR__ . '/partials/consent-text.php';
            ?>
        </div>
        <div class="trackwp-consent__bar-actions">
            <button type="button" class="trackwp-consent__btn trackwp-consent__btn--reject" data-action="reject-all"><?php echo esc_html($texts['reject']); ?></button>
            <button type="button" class="trackwp-consent__btn trackwp-consent__btn--customize" data-action="customize" aria-controls="trackwp-consent-drawer" aria-expanded="false"><?php echo esc_html($texts['customize']); ?></button>
            <button type="button" class="trackwp-consent__btn trackwp-consent__btn--accept" data-action="accept-all"><?php echo esc_html($texts['accept']); ?></button>
        </div>
    </div>
</div>

<!-- Drawer: revealed by JS when the user clicks "Tilpas valg". Mirrors the cookiebot modal. -->
<div id="trackwp-consent-drawer"
     class="trackwp-consent trackwp-consent--style-cookiebot trackwp-consent--drawer"
     data-style="bottombar-drawer"
     role="dialog"
     aria-modal="true"
     aria-labelledby="trackwp-consent-drawer-heading"
     aria-describedby="trackwp-consent-drawer-text"
     hidden
     style="<?php echo $style_vars; // phpcs:ignore WordPress.Security.EscapeOutput -- built from esc_attr()/absint() in the master template. ?>">
    <div class="trackwp-consent__inner">
        <header class="trackwp-consent__header">
            <span class="trackwp-consent__brand"><?php echo esc_html(get_bloginfo('name')); ?></span>
        </header>

        <?php $trackwp_id_prefix = 'drawer'; include __DIR__ . '/partials/consent-tabs.php'; ?>

        <footer class="trackwp-consent__actions trackwp-consent__actions--cookiebot">
            <button type="button" class="trackwp-consent__btn trackwp-consent__btn--reject" data-action="reject-all"><?php echo esc_html($texts['reject']); ?></button>
            <button type="button" class="trackwp-consent__btn trackwp-consent__btn--save" data-action="save"><?php echo esc_html($texts['save']); ?></button>
            <button type="button" class="trackwp-consent__btn trackwp-consent__btn--accept" data-action="accept-all"><?php echo esc_html($texts['accept']); ?></button>
        </footer>
    </div>
</div>

<div id="trackwp-consent-overlay" class="trackwp-consent-overlay" hidden></div>
