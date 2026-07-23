<?php
/**
 * Consent banner — "cookiebot" style.
 *
 * Cookiebot-inspired modal with tabs (Samtykke / Detaljer / Om) and overlay.
 * Expects $config, $style_vars, $vendor_list, $trackwp_render_vendors from parent.
 */
defined('ABSPATH') || exit;
?>
<div id="trackwp-consent-overlay" class="trackwp-consent-overlay" style="display:none;"></div>
<div id="trackwp-consent-banner"
     class="trackwp-consent trackwp-consent--style-cookiebot"
     data-style="cookiebot"
     role="dialog"
     aria-modal="true"
     aria-labelledby="trackwp-consent-heading"
     style="display:none;<?php echo $style_vars; ?>">
    <div class="trackwp-consent__inner">
        <header class="trackwp-consent__header">
            <span class="trackwp-consent__brand"><?php echo esc_html(get_bloginfo('name')); ?></span>
        </header>

        <div class="trackwp-consent__tabs" role="tablist">
            <button type="button" role="tab" data-tab="consent" aria-selected="true" class="trackwp-consent__tab is-active"><?php esc_html_e('Samtykke', 'trackwp'); ?></button>
            <button type="button" role="tab" data-tab="details" aria-selected="false" class="trackwp-consent__tab"><?php esc_html_e('Detaljer', 'trackwp'); ?></button>
            <button type="button" role="tab" data-tab="about" aria-selected="false" class="trackwp-consent__tab"><?php esc_html_e('Om', 'trackwp'); ?></button>
        </div>

        <div class="trackwp-consent__tabpanels">
            <div class="trackwp-consent__tabpanel is-active" data-panel="consent" role="tabpanel">
                <h2 id="trackwp-consent-heading"><?php echo esc_html($config['heading']); ?></h2>
                <p><?php echo esc_html($config['description']); ?></p>
                <div class="trackwp-consent__categories trackwp-consent__categories--horizontal">
                    <?php include __DIR__ . '/partials/consent-categories.php'; ?>
                </div>
            </div>

            <div class="trackwp-consent__tabpanel" data-panel="details" role="tabpanel" hidden>
                <?php include __DIR__ . '/partials/consent-vendor-list.php'; ?>
            </div>

            <div class="trackwp-consent__tabpanel" data-panel="about" role="tabpanel" hidden>
                <p><?php esc_html_e('Cookies er små tekstfiler som hjemmesider gemmer på din enhed for at huske dine valg, måle brug og levere personaliseret indhold. Du kan altid ændre dit samtykke ved at klikke på cookie-ikonet nederst.', 'trackwp'); ?></p>
                <?php if (!empty($config['privacy_url'])): ?>
                    <p><a class="trackwp-consent__link" href="<?php echo esc_url($config['privacy_url']); ?>"><?php esc_html_e('Læs privatlivspolitik', 'trackwp'); ?></a></p>
                <?php endif; ?>
            </div>
        </div>

        <footer class="trackwp-consent__actions trackwp-consent__actions--cookiebot">
            <?php if ($config['show_reject_button']) : ?>
            <button type="button" class="trackwp-consent__btn trackwp-consent__btn--reject" data-action="reject-all"><?php echo esc_html( !empty($config['reject_text']) ? $config['reject_text'] : __('Afvis alle', 'trackwp') ); ?></button>
            <?php endif; ?>
            <button type="button" class="trackwp-consent__btn trackwp-consent__btn--save" data-action="save"><?php echo esc_html( !empty($config['save_text']) ? $config['save_text'] : __('Gem mine valg', 'trackwp') ); ?></button>
            <button type="button" class="trackwp-consent__btn trackwp-consent__btn--accept" data-action="accept-all"><?php echo esc_html( !empty($config['accept_text']) ? $config['accept_text'] : __('Accepter alle', 'trackwp') ); ?></button>
        </footer>
    </div>
</div>
