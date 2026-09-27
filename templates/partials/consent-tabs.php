<?php
/**
 * Partial: tab list + panels (Samtykke / Detaljer / Om) shared by the
 * cookiebot modal and the bottombar drawer.
 *
 * Expects: $texts, $categories, $category_labels, $vendor_list,
 * $trackwp_render_vendors, $config.
 * $trackwp_id_prefix  'banner' (cookiebot) or 'drawer'; keeps ids unique.
 * consent.js (W5) switches tabs via [data-tab] / [data-panel].
 */
defined('ABSPATH') || exit;

$trackwp_p       = (isset($trackwp_id_prefix) && $trackwp_id_prefix === 'drawer') ? 'drawer' : 'banner';
$trackwp_head_id = $trackwp_p === 'drawer' ? 'trackwp-consent-drawer-heading' : 'trackwp-consent-heading';
$trackwp_text_id = $trackwp_p === 'drawer' ? 'trackwp-consent-drawer-text' : 'trackwp-consent-text';
$trackwp_tabs    = array(
    'consent' => __('Samtykke', 'trackwp'),
    'details' => __('Detaljer', 'trackwp'),
    'about'   => __('Om cookies', 'trackwp'),
);
?>
<div class="trackwp-consent__tabs" role="tablist" aria-label="<?php esc_attr_e('Cookie-indstillinger', 'trackwp'); ?>">
    <?php $trackwp_first = true; foreach ($trackwp_tabs as $trackwp_key => $trackwp_tab_label) : ?>
        <button type="button"
                role="tab"
                id="trackwp-consent-tab-<?php echo esc_attr($trackwp_key . '-' . $trackwp_p); ?>"
                aria-controls="trackwp-consent-panel-<?php echo esc_attr($trackwp_key . '-' . $trackwp_p); ?>"
                aria-selected="<?php echo $trackwp_first ? 'true' : 'false'; ?>"
                tabindex="<?php echo $trackwp_first ? '0' : '-1'; ?>"
                data-tab="<?php echo esc_attr($trackwp_key); ?>"
                class="trackwp-consent__tab<?php echo $trackwp_first ? ' is-active' : ''; ?>"><?php echo esc_html($trackwp_tab_label); ?></button>
    <?php $trackwp_first = false; endforeach; ?>
</div>

<div class="trackwp-consent__tabpanels">
    <div class="trackwp-consent__tabpanel is-active" data-panel="consent" role="tabpanel"
         id="trackwp-consent-panel-consent-<?php echo esc_attr($trackwp_p); ?>"
         aria-labelledby="trackwp-consent-tab-consent-<?php echo esc_attr($trackwp_p); ?>">
        <h2 id="<?php echo esc_attr($trackwp_head_id); ?>"><?php echo esc_html($texts['heading']); ?></h2>
        <?php
        $trackwp_privacy = true;
        include __DIR__ . '/consent-text.php';
        ?>
        <div class="trackwp-consent__categories trackwp-consent__categories--horizontal" role="group" aria-label="<?php esc_attr_e('Cookiekategorier', 'trackwp'); ?>">
            <?php
            $trackwp_cat_layout  = 'toggle';
            $trackwp_cat_vendors = false;
            include __DIR__ . '/consent-categories.php';
            ?>
        </div>
        <?php include __DIR__ . '/consent-status.php'; ?>
    </div>

    <div class="trackwp-consent__tabpanel" data-panel="details" role="tabpanel" hidden
         id="trackwp-consent-panel-details-<?php echo esc_attr($trackwp_p); ?>"
         aria-labelledby="trackwp-consent-tab-details-<?php echo esc_attr($trackwp_p); ?>">
        <?php include __DIR__ . '/consent-vendor-list.php'; ?>
    </div>

    <div class="trackwp-consent__tabpanel" data-panel="about" role="tabpanel" hidden
         id="trackwp-consent-panel-about-<?php echo esc_attr($trackwp_p); ?>"
         aria-labelledby="trackwp-consent-tab-about-<?php echo esc_attr($trackwp_p); ?>">
        <p><?php esc_html_e('Cookies er små tekstfiler, som websites gemmer på din enhed. Lignende teknologier som lokal lagring i browseren fungerer på samme måde. Nødvendige cookies kræver ikke samtykke. Alle andre bruges kun, hvis du siger ja.', 'trackwp'); ?></p>
        <?php if ($texts['withdraw'] !== '') : ?>
            <p><?php echo esc_html($texts['withdraw']); ?></p>
        <?php endif; ?>
        <?php if (!empty($config['privacy_url'])) : ?>
            <p><a class="trackwp-consent__link" href="<?php echo esc_url($config['privacy_url']); ?>"><?php esc_html_e('Læs vores privatlivspolitik', 'trackwp'); ?></a></p>
        <?php endif; ?>
    </div>
</div>
<?php
unset($trackwp_p, $trackwp_head_id, $trackwp_text_id, $trackwp_tabs, $trackwp_first, $trackwp_key, $trackwp_tab_label, $trackwp_privacy);
