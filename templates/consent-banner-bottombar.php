<?php
/**
 * Consent banner — "bottombar" style.
 *
 * Slim bottom bar with 3 buttons (Afvis / Tilpas / Accepter). The "Tilpas"
 * action reveals a drawer that mirrors the cookiebot modal (tabs + categories +
 * vendor list). Frontend JS toggles visibility of #trackwp-consent-drawer
 * and #trackwp-consent-overlay on customize-click.
 *
 * Expects $config, $style_vars, $vendor_list, $trackwp_render_vendors from parent.
 */
defined('ABSPATH') || exit;
?>
<div id="trackwp-consent-banner"
     class="trackwp-consent trackwp-consent--style-bottombar"
     data-style="bottombar"
     role="region"
     aria-label="<?php esc_attr_e('Cookie-samtykke', 'trackwp'); ?>"
     style="display:none;<?php echo $style_vars; ?>">
    <div class="trackwp-consent__inner trackwp-consent__inner--bar">
        <p class="trackwp-consent__bar-text">
            <?php echo esc_html($config['description']); ?>
            <?php if (!empty($config['privacy_url'])): ?>
                <a class="trackwp-consent__link" href="<?php echo esc_url($config['privacy_url']); ?>"><?php esc_html_e('Læs mere', 'trackwp'); ?></a>
            <?php endif; ?>
        </p>
        <div class="trackwp-consent__bar-actions">
            <?php if ($config['show_reject_button']) : ?>
            <button type="button" class="trackwp-consent__btn trackwp-consent__btn--reject" data-action="reject-all"><?php echo esc_html( !empty($config['reject_text']) ? $config['reject_text'] : __('Afvis', 'trackwp') ); ?></button>
            <?php endif; ?>
            <button type="button" class="trackwp-consent__btn trackwp-consent__btn--customize" data-action="customize"><?php echo esc_html( !empty($config['customize_text']) ? $config['customize_text'] : __('Tilpas', 'trackwp') ); ?></button>
            <button type="button" class="trackwp-consent__btn trackwp-consent__btn--accept" data-action="accept-all"><?php echo esc_html( !empty($config['accept_text']) ? $config['accept_text'] : __('Accepter', 'trackwp') ); ?></button>
        </div>
    </div>
</div>

<!-- Drawer: revealed by JS when user clicks "Tilpas". Mirrors cookiebot modal. -->
<div id="trackwp-consent-drawer"
     class="trackwp-consent trackwp-consent--style-cookiebot trackwp-consent--drawer"
     data-style="bottombar-drawer"
     role="dialog"
     aria-modal="true"
     aria-labelledby="trackwp-consent-drawer-heading"
     hidden
     style="<?php echo $style_vars; ?>">
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
                <h2 id="trackwp-consent-drawer-heading"><?php echo esc_html($config['heading']); ?></h2>
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
            <button type="button" class="trackwp-consent__btn trackwp-consent__btn--reject" data-action="reject-all"><?php echo esc_html( !empty($config['reject_text']) ? $config['reject_text'] : __('Kun nødvendige cookies', 'trackwp') ); ?></button>
            <?php endif; ?>
            <button type="button" class="trackwp-consent__btn trackwp-consent__btn--save" data-action="save"><?php echo esc_html( !empty($config['save_text']) ? $config['save_text'] : __('Tillad valgte', 'trackwp') ); ?></button>
            <button type="button" class="trackwp-consent__btn trackwp-consent__btn--accept" data-action="accept-all"><?php echo esc_html( !empty($config['accept_text']) ? $config['accept_text'] : __('Tillad alle cookies', 'trackwp') ); ?></button>
        </footer>
    </div>
</div>

<div id="trackwp-consent-overlay" class="trackwp-consent-overlay" hidden></div>
