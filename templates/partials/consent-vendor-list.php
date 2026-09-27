<?php
/**
 * Partial: declaration list (Details tab).
 *
 * Renders one section per category: necessary, the active optional
 * categories, and any other category with cookies found on the device
 * (including unclassified). Nothing on the device is hidden.
 *
 * Expects from the parent template: $vendor_list, $trackwp_render_vendors,
 * $categories, $category_labels.
 */
defined('ABSPATH') || exit;

if (!isset($trackwp_render_vendors) || !is_callable($trackwp_render_vendors)) {
    return;
}
if (!isset($vendor_list) || !is_array($vendor_list)) {
    $vendor_list = array();
}
$trackwp_active = isset($categories) && is_array($categories) ? $categories : array();

foreach (array('necessary', 'statistics', 'marketing', 'personalisation', 'unclassified') as $trackwp_cat) :
    $trackwp_entries = isset($vendor_list[$trackwp_cat]) && is_array($vendor_list[$trackwp_cat]) ? $vendor_list[$trackwp_cat] : array();
    if ($trackwp_cat !== 'necessary' && !in_array($trackwp_cat, $trackwp_active, true) && empty($trackwp_entries)) {
        continue;
    }
    $trackwp_heading_id = 'trackwp-consent-vendors-' . $trackwp_cat . (isset($trackwp_id_prefix) ? '-' . $trackwp_id_prefix : '');
    ?>
    <section class="trackwp-consent__vendor-section" data-category="<?php echo esc_attr($trackwp_cat); ?>" aria-labelledby="<?php echo esc_attr($trackwp_heading_id); ?>">
        <h3 class="trackwp-consent__vendor-heading" id="<?php echo esc_attr($trackwp_heading_id); ?>"><?php echo esc_html($category_labels[$trackwp_cat]['label']); ?></h3>
        <p class="trackwp-consent__category-desc"><?php echo esc_html($category_labels[$trackwp_cat]['description']); ?></p>
        <?php $trackwp_render_vendors($trackwp_entries); ?>
    </section>
<?php
endforeach;
unset($trackwp_active, $trackwp_cat, $trackwp_entries, $trackwp_heading_id);
