<?php
/**
 * Partial: consent categories.
 *
 * Renders "Nødvendige" (always on, disabled) plus ONLY the optional categories
 * that are active in the consent profile. Nothing is pre-ticked.
 *
 * Expects from the parent template: $categories, $category_labels.
 * Optional:
 *   $trackwp_cat_layout   'toggle' (cookiebot/drawer, default) or 'dialog'.
 *   $trackwp_cat_vendors  bool; when true the declarations are rendered under each
 *                         category (needs $vendor_list and $trackwp_render_vendors),
 *                         including necessary and unclassified cookies.
 *
 * Input ids #trackwp-consent-{statistics|marketing|personalisation} are read by consent.js.
 */
defined('ABSPATH') || exit;

$trackwp_layout   = (isset($trackwp_cat_layout) && $trackwp_cat_layout === 'dialog') ? 'dialog' : 'toggle';
$trackwp_with_vnd = !empty($trackwp_cat_vendors) && isset($trackwp_render_vendors) && is_callable($trackwp_render_vendors);
$trackwp_cats     = array_merge(array('necessary'), isset($categories) && is_array($categories) ? $categories : array());

foreach ($trackwp_cats as $trackwp_cat) :
    if (!isset($category_labels[$trackwp_cat])) {
        continue;
    }
    $trackwp_locked  = ($trackwp_cat === 'necessary');
    $trackwp_input   = $trackwp_locked ? '' : 'trackwp-consent-' . $trackwp_cat;
    $trackwp_desc_id = 'trackwp-consent-desc-' . $trackwp_cat;
    $trackwp_label   = $category_labels[$trackwp_cat]['label'];
    ?>
    <div class="trackwp-consent__category" data-category="<?php echo esc_attr($trackwp_cat); ?>">
        <?php if ($trackwp_layout === 'dialog') : ?>
            <div class="trackwp-consent__category-header">
                <label class="trackwp-consent__category-name"<?php echo $trackwp_input ? ' for="' . esc_attr($trackwp_input) . '"' : ''; ?>><?php echo esc_html($trackwp_label); ?></label>
                <span class="trackwp-consent__toggle<?php echo $trackwp_locked ? ' trackwp-consent__toggle--disabled' : ''; ?>">
                    <?php if ($trackwp_locked) : ?>
                        <input type="checkbox" checked disabled aria-label="<?php echo esc_attr($trackwp_label); ?>" aria-describedby="<?php echo esc_attr($trackwp_desc_id); ?>">
                    <?php else : ?>
                        <input type="checkbox" id="<?php echo esc_attr($trackwp_input); ?>" value="1" aria-describedby="<?php echo esc_attr($trackwp_desc_id); ?>">
                    <?php endif; ?>
                    <span class="trackwp-consent__toggle-slider" aria-hidden="true"></span>
                </span>
            </div>
        <?php else : ?>
            <label class="trackwp-consent__toggle-label">
                <?php if ($trackwp_locked) : ?>
                    <input type="checkbox" class="trackwp-consent__input" checked disabled aria-describedby="<?php echo esc_attr($trackwp_desc_id); ?>">
                <?php else : ?>
                    <input type="checkbox" id="<?php echo esc_attr($trackwp_input); ?>" class="trackwp-consent__input" value="1" aria-describedby="<?php echo esc_attr($trackwp_desc_id); ?>">
                <?php endif; ?>
                <span class="trackwp-consent__toggle" aria-hidden="true"></span>
                <span class="trackwp-consent__category-name"><?php echo esc_html($trackwp_label); ?></span>
            </label>
        <?php endif; ?>
        <p class="trackwp-consent__category-desc" id="<?php echo esc_attr($trackwp_desc_id); ?>"><?php echo esc_html($category_labels[$trackwp_cat]['description']); ?></p>
        <?php
        if ($trackwp_with_vnd) {
            $trackwp_render_vendors(isset($vendor_list[$trackwp_cat]) ? $vendor_list[$trackwp_cat] : array());
        }
        ?>
    </div>
<?php endforeach; ?>

<?php
// Cookies found on the device in a category without a toggle (not used by
// this site's own setup) and unclassified cookies: declared, never hidden.
if ($trackwp_with_vnd) :
    foreach (array('statistics', 'marketing', 'personalisation', 'unclassified') as $trackwp_cat) :
        if (in_array($trackwp_cat, $trackwp_cats, true) || empty($vendor_list[$trackwp_cat])) {
            continue;
        }
        ?>
        <div class="trackwp-consent__category trackwp-consent__category--info" data-category="<?php echo esc_attr($trackwp_cat); ?>">
            <div class="trackwp-consent__category-header">
                <span class="trackwp-consent__category-name"><?php echo esc_html($category_labels[$trackwp_cat]['label']); ?></span>
            </div>
            <p class="trackwp-consent__category-desc"><?php echo esc_html($category_labels[$trackwp_cat]['description']); ?></p>
            <?php $trackwp_render_vendors($vendor_list[$trackwp_cat]); ?>
        </div>
        <?php
    endforeach;
endif;

unset($trackwp_layout, $trackwp_with_vnd, $trackwp_cats, $trackwp_cat, $trackwp_locked, $trackwp_input, $trackwp_desc_id, $trackwp_label);
