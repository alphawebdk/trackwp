<?php
/**
 * Consent banner: "cookiebot" style.
 *
 * Modal with tabs (Samtykke / Detaljer / Om) and overlay. The first tab is
 * the first layer: controller, purposes, data types, sharing, ACM sentence,
 * withdrawal, active categories and the three buttons. The partner list is
 * one click away in "Detaljer".
 *
 * Expects from parent: $config, $style_vars, $texts, $categories,
 * $category_labels, $vendor_list, $banner_hash, $trackwp_render_vendors.
 */
defined('ABSPATH') || exit;
?>
<div id="trackwp-consent-overlay" class="trackwp-consent-overlay" style="display:none;"></div>
<div id="trackwp-consent-banner"
     class="trackwp-consent trackwp-consent--style-cookiebot"
     data-style="cookiebot"
     data-mode="consent"
     data-banner-hash="<?php echo esc_attr($banner_hash); ?>"
     role="dialog"
     aria-modal="true"
     aria-labelledby="trackwp-consent-heading"
     aria-describedby="trackwp-consent-text"
     style="display:none;<?php echo $style_vars; // phpcs:ignore WordPress.Security.EscapeOutput -- built from esc_attr()/absint() in the master template. ?>">
    <div class="trackwp-consent__inner">
        <header class="trackwp-consent__header">
            <span class="trackwp-consent__brand"><?php echo esc_html(get_bloginfo('name')); ?></span>
        </header>

        <?php $trackwp_id_prefix = 'banner'; include __DIR__ . '/partials/consent-tabs.php'; ?>

        <footer class="trackwp-consent__actions trackwp-consent__actions--cookiebot">
            <button type="button" class="trackwp-consent__btn trackwp-consent__btn--reject" data-action="reject-all"><?php echo esc_html($texts['reject']); ?></button>
            <button type="button" class="trackwp-consent__btn trackwp-consent__btn--save" data-action="save"><?php echo esc_html($texts['save']); ?></button>
            <button type="button" class="trackwp-consent__btn trackwp-consent__btn--accept" data-action="accept-all"><?php echo esc_html($texts['accept']); ?></button>
        </footer>
    </div>
</div>
