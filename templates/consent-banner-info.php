<?php
/**
 * Consent banner: information mode.
 *
 * Used when the site only uses strictly necessary technologies
 * (TrackWP_Consent_Profile::requires_consent() === false). No consent is
 * asked for: there are no category toggles and no accept/reject buttons.
 * consent.js (W5) must NOT open this automatically and must not write a
 * consent cookie; it is opened via the "Cookie-indstillinger" trigger and
 * closed with data-action="close".
 *
 * Expects from parent: $config, $style_vars, $texts, $category_labels,
 * $vendor_list, $banner_hash, $trackwp_render_vendors.
 */
defined('ABSPATH') || exit;
?>
<div id="trackwp-consent-overlay" class="trackwp-consent-overlay" style="display:none;"></div>
<div id="trackwp-consent-banner"
     class="trackwp-consent trackwp-consent--style-dialog trackwp-consent--info"
     data-style="info"
     data-mode="info"
     data-banner-hash="<?php echo esc_attr($banner_hash); ?>"
     role="dialog"
     aria-modal="false"
     aria-labelledby="trackwp-consent-heading"
     aria-describedby="trackwp-consent-text"
     style="display:none;<?php echo $style_vars; // phpcs:ignore WordPress.Security.EscapeOutput -- built from esc_attr()/absint() in the master template. ?>">
    <div class="trackwp-consent__inner">
        <div class="trackwp-consent__content">
            <h2 class="trackwp-consent__heading" id="trackwp-consent-heading"><?php echo esc_html($texts['info_heading']); ?></h2>
            <div class="trackwp-consent__description" id="trackwp-consent-text">
                <p><?php echo esc_html($texts['info_description']); ?></p>
                <?php if (!empty($config['privacy_url'])) : ?>
                    <p><a class="trackwp-consent__link" href="<?php echo esc_url($config['privacy_url']); ?>"><?php esc_html_e('Læs vores privatlivspolitik', 'trackwp'); ?></a></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="trackwp-consent__categories">
            <?php
            $categories          = array();
            $trackwp_cat_layout  = 'dialog';
            $trackwp_cat_vendors = true;
            include __DIR__ . '/partials/consent-categories.php';
            ?>
        </div>

        <div class="trackwp-consent__actions">
            <button type="button" class="trackwp-consent__btn trackwp-consent__btn--accept" data-action="close"><?php echo esc_html($texts['close']); ?></button>
        </div>
    </div>
</div>
