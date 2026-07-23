<?php
/**
 * Partial: consent categories (4 toggles).
 *
 * Renders the 4 standard categories in a uniform layout that works both as
 * toggle-switch (cookiebot/bottombar drawer) and checkbox (dialog) via CSS.
 *
 * - Necessary is always checked + disabled (cannot be opted out).
 * - Other 3 are unchecked by default (compliance: no pre-tick).
 *
 * Expected to be included from within a style template; no input vars required.
 */
defined('ABSPATH') || exit;
?>
<div class="trackwp-consent__category" data-category="necessary">
    <label class="trackwp-consent__toggle-label">
        <input type="checkbox" class="trackwp-consent__input" checked disabled>
        <span class="trackwp-consent__toggle"></span>
        <span class="trackwp-consent__category-name"><?php esc_html_e('Nødvendige', 'trackwp'); ?></span>
    </label>
    <p class="trackwp-consent__category-desc"><?php esc_html_e('Grundlæggende funktionalitet og cookie-hukommelse. Kan ikke deaktiveres.', 'trackwp'); ?></p>
</div>

<div class="trackwp-consent__category" data-category="statistics">
    <label class="trackwp-consent__toggle-label">
        <input type="checkbox" id="trackwp-consent-statistics" class="trackwp-consent__input" value="1">
        <span class="trackwp-consent__toggle"></span>
        <span class="trackwp-consent__category-name"><?php esc_html_e('Statistik', 'trackwp'); ?></span>
    </label>
    <p class="trackwp-consent__category-desc"><?php esc_html_e('Cookies der måler hvordan du bruger sitet (fx Google Analytics).', 'trackwp'); ?></p>
</div>

<div class="trackwp-consent__category" data-category="marketing">
    <label class="trackwp-consent__toggle-label">
        <input type="checkbox" id="trackwp-consent-marketing" class="trackwp-consent__input" value="1">
        <span class="trackwp-consent__toggle"></span>
        <span class="trackwp-consent__category-name"><?php esc_html_e('Marketing', 'trackwp'); ?></span>
    </label>
    <p class="trackwp-consent__category-desc"><?php esc_html_e('Google Ads, Meta Pixel, remarketing og konverteringsmåling.', 'trackwp'); ?></p>
</div>

<div class="trackwp-consent__category" data-category="personalisation">
    <label class="trackwp-consent__toggle-label">
        <input type="checkbox" id="trackwp-consent-personalisation" class="trackwp-consent__input" name="trackwp_consent[personalisation]" value="1">
        <span class="trackwp-consent__toggle"></span>
        <span class="trackwp-consent__category-name"><?php esc_html_e('Funktionalitet og præferencer', 'trackwp'); ?></span>
    </label>
    <p class="trackwp-consent__category-desc"><?php esc_html_e('Cookies der husker dine præferencer (sprog, region, præferencer) for en bedre oplevelse.', 'trackwp'); ?></p>
</div>
