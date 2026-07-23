<?php
/**
 * Partial: vendor list (Details tab).
 *
 * Renders per-category vendor accordions using $trackwp_render_vendors closure
 * and $vendor_list array which MUST be defined by the parent template
 * (consent-banner.php master switch).
 */
defined('ABSPATH') || exit;

if (!isset($trackwp_render_vendors) || !is_callable($trackwp_render_vendors)) {
    return;
}
if (!isset($vendor_list) || !is_array($vendor_list)) {
    $vendor_list = array();
}
?>
<div class="trackwp-consent__vendor-section" data-category="necessary">
    <h3 class="trackwp-consent__vendor-heading"><?php esc_html_e('Nødvendige', 'trackwp'); ?></h3>
    <p class="trackwp-consent__category-desc"><?php esc_html_e('Grundlæggende funktionalitet og cookie-hukommelse. Kan ikke deaktiveres.', 'trackwp'); ?></p>
    <?php $trackwp_render_vendors(isset($vendor_list['necessary']) ? $vendor_list['necessary'] : array()); ?>
</div>

<div class="trackwp-consent__vendor-section" data-category="statistics">
    <h3 class="trackwp-consent__vendor-heading"><?php esc_html_e('Statistik', 'trackwp'); ?></h3>
    <?php $trackwp_render_vendors(isset($vendor_list['statistics']) ? $vendor_list['statistics'] : array()); ?>
</div>

<div class="trackwp-consent__vendor-section" data-category="marketing">
    <h3 class="trackwp-consent__vendor-heading"><?php esc_html_e('Marketing', 'trackwp'); ?></h3>
    <?php $trackwp_render_vendors(isset($vendor_list['marketing']) ? $vendor_list['marketing'] : array()); ?>
</div>

<div class="trackwp-consent__vendor-section" data-category="personalisation">
    <h3 class="trackwp-consent__vendor-heading"><?php esc_html_e('Funktionalitet og præferencer', 'trackwp'); ?></h3>
    <?php $trackwp_render_vendors(isset($vendor_list['personalisation']) ? $vendor_list['personalisation'] : array()); ?>
</div>

<?php if (!empty($vendor_list['unclassified'])) : ?>
<div class="trackwp-consent__vendor-section" data-category="unclassified">
    <h3 class="trackwp-consent__vendor-heading"><?php esc_html_e('Uklassificerede', 'trackwp'); ?></h3>
    <p class="trackwp-consent__category-desc"><?php esc_html_e('Cookies fundet på enheden som endnu ikke er klassificeret.', 'trackwp'); ?></p>
    <?php $trackwp_render_vendors($vendor_list['unclassified']); ?>
</div>
<?php endif; ?>
