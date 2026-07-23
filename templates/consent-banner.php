<?php
/**
 * Consent banner master template.
 *
 * Loads the correct style sub-template based on $config['banner_style'].
 * All shared variables are defined here so they are in scope for the included file.
 *
 * Available styles: 'cookiebot', 'dialog', 'bottombar' (default 'dialog').
 *
 * Expected variables from caller (class-trackwp-consent.php::render_banner):
 *   $config       array  Banner config from get_option('trackwp_consent').
 *   $privacy_url  string Optional pre-resolved privacy policy URL.
 */
defined('ABSPATH') || exit;

$defaults = array(
    'banner_style'       => 'dialog',
    'bg_color'           => '#274A45',
    'text_color'         => '#ffffff',
    'accent_color'       => '#30D3C0',
    'button_text_color'  => '#274A45',
    'border_radius'      => 8,
    'heading'            => __('Vi bruger cookies', 'trackwp'),
    'description'        => __('Vi bruger cookies til at analysere trafik og forbedre din oplevelse. Du kan vælge hvilke du accepterer.', 'trackwp'),
    'accept_text'        => __('Accepter alle', 'trackwp'),
    'reject_text'        => __('Kun nødvendige', 'trackwp'),
    'customize_text'     => __('Tilpas valg', 'trackwp'),
    'save_text'          => __('Gem mine valg', 'trackwp'),
    'show_reject_button' => true,
);
$config = wp_parse_args(isset($config) && is_array($config) ? $config : array(), $defaults);

// Expose privacy_url on $config for sub-templates (keeps a single source of truth).
if (empty($config['privacy_url']) && !empty($privacy_url)) {
    $config['privacy_url'] = $privacy_url;
}

// CSS custom properties applied to the root banner element.
$style_vars = sprintf(
    '--twp-bg:%s;--twp-text:%s;--twp-accent:%s;--twp-btn-text:%s;--twp-radius:%dpx;',
    esc_attr($config['bg_color']),
    esc_attr($config['text_color']),
    esc_attr($config['accent_color']),
    esc_attr($config['button_text_color']),
    absint($config['border_radius'])
);

// Vendor list (shared across styles that show details).
$vendor_list = apply_filters('trackwp_consent_vendor_list', array(
    'statistics' => array(
        array(
            'name'     => 'Google Analytics 4',
            'provider' => 'Google LLC, USA',
            'cookies'  => '_ga, _ga_*',
            'purpose'  => __('Analyse af besøgsadfærd', 'trackwp'),
            'lifetime' => __('2 år', 'trackwp'),
            'transfer' => __('USA (tilstrækkelighedsafgørelse — EU-US Data Privacy Framework)', 'trackwp'),
        ),
    ),
    'marketing' => array(
        array(
            'name'     => 'Google Ads',
            'provider' => 'Google LLC, USA',
            'cookies'  => '_gcl_au, _gcl_aw',
            'purpose'  => __('Conversion tracking og remarketing', 'trackwp'),
            'lifetime' => __('90 dage', 'trackwp'),
            'transfer' => __('USA (tilstrækkelighedsafgørelse — EU-US Data Privacy Framework)', 'trackwp'),
        ),
        array(
            'name'     => 'Meta Pixel',
            'provider' => 'Meta Platforms Ireland Ltd / Meta Inc, USA',
            'cookies'  => '_fbp, _fbc',
            'purpose'  => __('Conversion tracking og remarketing', 'trackwp'),
            'lifetime' => __('90 dage', 'trackwp'),
            'transfer' => __('USA (tilstrækkelighedsafgørelse — EU-US Data Privacy Framework)', 'trackwp'),
        ),
    ),
    'personalisation' => array(),
));

// Shared closure used by dialog + vendor-list partial to render a <details> block per category.
$trackwp_render_vendors = function ($vendors) {
    ?>
    <details class="trackwp-consent-vendors">
        <summary><?php esc_html_e('Se cookies og leverandører', 'trackwp'); ?></summary>
        <?php if (empty($vendors)) : ?>
            <p><?php esc_html_e('Ingen aktive cookies i denne kategori.', 'trackwp'); ?></p>
        <?php else : ?>
            <ul>
                <?php foreach ($vendors as $vendor) : ?>
                    <li>
                        <strong><?php echo esc_html($vendor['name']); ?></strong>
                        <?php if (!empty($vendor['provider'])) : ?>
                            (<?php echo esc_html($vendor['provider']); ?>)
                        <?php endif; ?>
                        <br>
                        <em><?php esc_html_e('Cookies:', 'trackwp'); ?></em> <?php echo esc_html($vendor['cookies']); ?><br>
                        <em><?php esc_html_e('Formål:', 'trackwp'); ?></em> <?php echo esc_html($vendor['purpose']); ?><br>
                        <em><?php esc_html_e('Levetid:', 'trackwp'); ?></em> <?php echo esc_html($vendor['lifetime']); ?><br>
                        <em><?php esc_html_e('Dataoverførsel:', 'trackwp'); ?></em> <?php echo esc_html($vendor['transfer']); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </details>
    <?php
};

// Whitelist style; fallback to 'dialog'.
$style = isset($config['banner_style']) ? (string) $config['banner_style'] : 'dialog';
if (!in_array($style, array('cookiebot', 'dialog', 'bottombar'), true)) {
    $style = 'dialog';
}

$style_template = __DIR__ . '/consent-banner-' . $style . '.php';
if (!file_exists($style_template)) {
    $style_template = __DIR__ . '/consent-banner-dialog.php';
}

include $style_template;
