<?php
/**
 * Consent banner: "dialog" style (default).
 *
 *   Level 1: heading + first-layer text + "Afvis valgfrie" / "Tilpas valg" / "Accepter alle".
 *            Reject and accept always have the same visual weight.
 *   Level 2: necessary + active categories with declarations, unclassified
 *            cookies, status block with withdrawal, save button.
 *
 * Expects from parent: $config, $style_vars, $texts, $categories,
 * $category_labels, $vendor_list, $banner_hash, $trackwp_render_vendors.
 */
defined('ABSPATH') || exit;
?>
<div id="trackwp-consent-overlay" style="display:none;"></div>
<div id="trackwp-consent-banner"
     class="trackwp-consent trackwp-consent--style-dialog trackwp-consent--dialog"
     data-style="dialog"
     data-mode="consent"
     data-banner-hash="<?php echo esc_attr($banner_hash); ?>"
     role="dialog"
     aria-modal="true"
     aria-labelledby="trackwp-consent-heading"
     aria-describedby="trackwp-consent-text"
     style="display:none;<?php echo $style_vars; // phpcs:ignore WordPress.Security.EscapeOutput -- built from esc_attr()/absint() in the master template. ?>">

    <div class="trackwp-consent__inner">
        <div class="trackwp-consent__content">
            <h2 class="trackwp-consent__heading" id="trackwp-consent-heading"><?php echo esc_html($texts['heading']); ?></h2>
            <?php
            $trackwp_text_id = 'trackwp-consent-text';
            $trackwp_privacy = true;
            include __DIR__ . '/partials/consent-text.php';
            ?>
        </div>

        <!-- Level 1: main buttons. Reject is always present. -->
        <div class="trackwp-consent__actions" id="trackwp-consent-actions-main">
            <button type="button" class="trackwp-consent__btn trackwp-consent__btn--reject" data-action="reject-all">
                <?php echo esc_html($texts['reject']); ?>
            </button>
            <button type="button" class="trackwp-consent__btn trackwp-consent__btn--customize" data-action="customize" aria-controls="trackwp-consent-categories" aria-expanded="false">
                <?php echo esc_html($texts['customize']); ?>
            </button>
            <button type="button" class="trackwp-consent__btn trackwp-consent__btn--accept" data-action="accept-all">
                <?php echo esc_html($texts['accept']); ?>
            </button>
        </div>

        <!-- Level 2: categories with declarations (hidden initially). -->
        <div class="trackwp-consent__categories" id="trackwp-consent-categories" role="group" aria-label="<?php esc_attr_e('Cookiekategorier', 'trackwp'); ?>" style="display:none;">
            <?php
            $trackwp_cat_layout  = 'dialog';
            $trackwp_cat_vendors = true;
            include __DIR__ . '/partials/consent-categories.php';
            include __DIR__ . '/partials/consent-status.php';
            ?>
        </div>

        <!-- Level 2: save + reject/accept stay available (hidden initially). -->
        <div class="trackwp-consent__actions trackwp-consent__actions--detail" id="trackwp-consent-actions-detail" style="display:none;">
            <button type="button" class="trackwp-consent__btn trackwp-consent__btn--reject" data-action="reject-all">
                <?php echo esc_html($texts['reject']); ?>
            </button>
            <button type="button" class="trackwp-consent__btn trackwp-consent__btn--save" data-action="save">
                <?php echo esc_html($texts['save']); ?>
            </button>
            <button type="button" class="trackwp-consent__btn trackwp-consent__btn--accept" data-action="accept-all">
                <?php echo esc_html($texts['accept']); ?>
            </button>
        </div>
    </div>
</div>
