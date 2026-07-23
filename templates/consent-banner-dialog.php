<?php
/**
 * Consent banner — "dialog" style (default).
 *
 * Preserves the original two-level dialog markup:
 *   Level 1: heading + description + 3 main buttons (reject/customize/accept).
 *   Level 2: per-category checkbox toggles + vendor accordions + save button.
 *
 * Expects $config, $style_vars, $vendor_list, $trackwp_render_vendors from parent.
 */
defined('ABSPATH') || exit;
?>
<div id="trackwp-consent-overlay" style="display:none;"></div>
<div id="trackwp-consent-banner"
     class="trackwp-consent trackwp-consent--style-dialog trackwp-consent--<?php echo esc_attr($config['banner_style']); ?>"
     data-style="dialog"
     role="dialog"
     aria-modal="true"
     aria-label="<?php esc_attr_e('Cookie consent', 'trackwp'); ?>"
     style="display:none;<?php echo $style_vars; ?>">

    <div class="trackwp-consent__inner">
        <div class="trackwp-consent__content">
            <h3 class="trackwp-consent__heading"><?php echo esc_html($config['heading']); ?></h3>
            <p class="trackwp-consent__description"><?php echo esc_html($config['description']); ?></p>
        </div>

        <!-- Level 1: Main buttons -->
        <div class="trackwp-consent__actions" id="trackwp-consent-actions-main">
            <?php if ($config['show_reject_button']) : ?>
            <button type="button" class="trackwp-consent__btn trackwp-consent__btn--reject" data-action="reject-all">
                <?php echo esc_html($config['reject_text']); ?>
            </button>
            <?php endif; ?>
            <button type="button" class="trackwp-consent__btn trackwp-consent__btn--customize" data-action="customize">
                <?php echo esc_html($config['customize_text']); ?>
            </button>
            <button type="button" class="trackwp-consent__btn trackwp-consent__btn--accept" data-action="accept-all">
                <?php echo esc_html($config['accept_text']); ?>
            </button>
        </div>

        <!-- Level 2: Category toggles (hidden initially) -->
        <div class="trackwp-consent__categories" id="trackwp-consent-categories" style="display:none;">

            <!-- Necessary -- always on -->
            <div class="trackwp-consent__category">
                <div class="trackwp-consent__category-header">
                    <span class="trackwp-consent__category-name"><?php esc_html_e('Nødvendige', 'trackwp'); ?></span>
                    <label class="trackwp-consent__toggle trackwp-consent__toggle--disabled">
                        <input type="checkbox" checked disabled>
                        <span class="trackwp-consent__toggle-slider"></span>
                    </label>
                </div>
                <p class="trackwp-consent__category-desc"><?php esc_html_e('Grundlæggende funktionalitet og cookie-hukommelse. Kan ikke deaktiveres.', 'trackwp'); ?></p>
            </div>

            <!-- Statistics -->
            <div class="trackwp-consent__category">
                <div class="trackwp-consent__category-header">
                    <span class="trackwp-consent__category-name"><?php esc_html_e('Statistik', 'trackwp'); ?></span>
                    <label class="trackwp-consent__toggle">
                        <input type="checkbox" id="trackwp-consent-statistics" value="1">
                        <span class="trackwp-consent__toggle-slider"></span>
                    </label>
                </div>
                <p class="trackwp-consent__category-desc"><?php esc_html_e('Google Analytics og anonymiseret trafikanalyse.', 'trackwp'); ?></p>
                <?php $trackwp_render_vendors(isset($vendor_list['statistics']) ? $vendor_list['statistics'] : array()); ?>
            </div>

            <!-- Marketing -->
            <div class="trackwp-consent__category">
                <div class="trackwp-consent__category-header">
                    <span class="trackwp-consent__category-name"><?php esc_html_e('Marketing', 'trackwp'); ?></span>
                    <label class="trackwp-consent__toggle">
                        <input type="checkbox" id="trackwp-consent-marketing" value="1">
                        <span class="trackwp-consent__toggle-slider"></span>
                    </label>
                </div>
                <p class="trackwp-consent__category-desc"><?php esc_html_e('Google Ads, Meta Pixel, remarketing og konverteringsmåling.', 'trackwp'); ?></p>
                <?php $trackwp_render_vendors(isset($vendor_list['marketing']) ? $vendor_list['marketing'] : array()); ?>
            </div>

            <!-- Personalisation -->
            <div class="trackwp-consent__category">
                <div class="trackwp-consent__category-header">
                    <span class="trackwp-consent__category-name"><?php esc_html_e('Funktionalitet og præferencer', 'trackwp'); ?></span>
                    <label class="trackwp-consent__toggle">
                        <input type="checkbox" id="trackwp-consent-personalisation" name="trackwp_consent[personalisation]" value="1">
                        <span class="trackwp-consent__toggle-slider"></span>
                    </label>
                </div>
                <p class="trackwp-consent__category-desc"><?php esc_html_e('Cookies der husker dine præferencer (sprog, region, præferencer) for en bedre oplevelse.', 'trackwp'); ?></p>
                <?php $trackwp_render_vendors(isset($vendor_list['personalisation']) ? $vendor_list['personalisation'] : array()); ?>
            </div>
        </div>

        <!-- Level 2: Save button (hidden initially) -->
        <div class="trackwp-consent__actions trackwp-consent__actions--detail" id="trackwp-consent-actions-detail" style="display:none;">
            <button type="button" class="trackwp-consent__btn trackwp-consent__btn--save" data-action="save">
                <?php echo esc_html(isset($config['save_text']) ? $config['save_text'] : __('Gem mine valg', 'trackwp')); ?>
            </button>
        </div>

        <?php if (!empty($config['privacy_url'])) : ?>
        <a class="trackwp-consent__link" href="<?php echo esc_url($config['privacy_url']); ?>">
            <?php esc_html_e('Privatlivspolitik', 'trackwp'); ?>
        </a>
        <?php endif; ?>
    </div>
</div>
