<?php
/**
 * Consent banner master template.
 *
 * Builds all shared variables from TrackWP_Consent_Profile and loads the style
 * sub-template. When the site only uses strictly necessary technologies
 * (requires_consent() === false) an information notice is rendered instead
 * of a consent dialog, regardless of style.
 *
 * Available styles: 'cookiebot', 'dialog', 'bottombar' (default 'dialog').
 *
 * Expected variables from caller (class-trackwp-consent.php::render_banner):
 *   $config       array  Banner config from get_option('trackwp_consent').
 *   $privacy_url  string Optional pre-resolved privacy policy URL.
 *
 * Variables provided to sub-templates and partials:
 *   $config, $style_vars, $texts, $categories (active optional categories),
 *   $category_labels, $vendor_list, $banner_hash, $trackwp_render_vendors.
 *
 * JS hooks (consent.js, W5):
 *   - root #trackwp-consent-banner: data-style, data-mode ("consent"|"info"),
 *     data-banner-hash (TrackWP_Consent_Profile::banner_hash(): what the user saw, sent as banner_hash)
 *   - buttons [data-action]: accept-all, reject-all, customize, save, withdraw, close
 *   - status block [data-role="consent-status"] (hidden) with
 *     [data-field="status"], [data-field="date"], [data-field="id"]
 */
defined('ABSPATH') || exit;

if (!class_exists('TrackWP_Consent_Profile')) {
    require_once dirname(__DIR__) . '/includes/class-trackwp-consent-profile.php';
}

$defaults = array(
    'banner_style'      => 'dialog',
    'bg_color'          => '#274A45',
    'text_color'        => '#ffffff',
    'accent_color'      => '#30D3C0',
    'button_text_color' => '#274A45',
    'border_radius'     => 8,
);
$config = wp_parse_args(isset($config) && is_array($config) ? $config : array(), $defaults);

// Expose privacy_url on $config for sub-templates (keeps a single source of truth).
if (empty($config['privacy_url']) && !empty($privacy_url)) {
    $config['privacy_url'] = $privacy_url;
}

$texts            = TrackWP_Consent_Profile::banner_texts();
$categories       = TrackWP_Consent_Profile::active_categories();
$category_labels  = TrackWP_Consent_Profile::category_labels();
$requires_consent = TrackWP_Consent_Profile::requires_consent();
$banner_hash      = TrackWP_Consent_Profile::banner_hash();

// CSS custom properties applied to the root banner element.
$style_vars = sprintf(
    '--twp-bg:%s;--twp-text:%s;--twp-accent:%s;--twp-btn-text:%s;--twp-radius:%dpx;',
    esc_attr($config['bg_color']),
    esc_attr($config['text_color']),
    esc_attr($config['accent_color']),
    esc_attr($config['button_text_color']),
    absint($config['border_radius'])
);

// Declaration: profile (vendors + TrackWP storage), then device cookies via the scanner filter.
$vendor_list = apply_filters('trackwp_consent_vendor_list', TrackWP_Consent_Profile::vendor_list());
foreach (array('necessary', 'statistics', 'marketing', 'personalisation', 'unclassified') as $trackwp_cat) {
    if (!isset($vendor_list[$trackwp_cat]) || !is_array($vendor_list[$trackwp_cat])) {
        $vendor_list[$trackwp_cat] = array();
    }
}
unset($trackwp_cat);

// Shared closure: renders a <details> block with the declarations of one category.
$trackwp_render_vendors = function ($vendors) {
    $types = array(
        'localStorage'   => __('Lokal lagring (localStorage)', 'trackwp'),
        'sessionStorage' => __('Sessionslagring (sessionStorage)', 'trackwp'),
    );
    ?>
    <details class="trackwp-consent-vendors">
        <summary><?php esc_html_e('Se cookies og samarbejdspartnere', 'trackwp'); ?></summary>
        <?php if (empty($vendors)) : ?>
            <p><?php esc_html_e('Ingen cookies i denne kategori.', 'trackwp'); ?></p>
        <?php else : ?>
            <ul>
                <?php foreach ($vendors as $vendor) : ?>
                    <?php if (!is_array($vendor)) { continue; } ?>
                    <li<?php echo !empty($vendor['placeholder']) ? ' class="trackwp-consent-vendors__placeholder"' : ''; ?>>
                        <strong><?php echo esc_html(isset($vendor['name']) ? $vendor['name'] : ''); ?></strong>
                        <?php if (!empty($vendor['provider'])) : ?>
                            (<?php echo esc_html($vendor['provider']); ?>)
                        <?php endif; ?>
                        <?php if (!empty($vendor['cookies'])) : ?>
                            <br><em><?php echo esc_html(isset($vendor['type'], $types[$vendor['type']]) ? $types[$vendor['type']] . ':' : __('Cookies:', 'trackwp')); ?></em> <?php echo esc_html($vendor['cookies']); ?>
                        <?php endif; ?>
                        <?php if (!empty($vendor['purpose'])) : ?>
                            <br><em><?php esc_html_e('Formål:', 'trackwp'); ?></em> <?php echo esc_html($vendor['purpose']); ?>
                        <?php endif; ?>
                        <?php if (!empty($vendor['lifetime'])) : ?>
                            <br><em><?php esc_html_e('Levetid:', 'trackwp'); ?></em> <?php echo esc_html($vendor['lifetime']); ?>
                        <?php endif; ?>
                        <?php if (!empty($vendor['transfer'])) : ?>
                            <br><em><?php esc_html_e('Overførsel:', 'trackwp'); ?></em> <?php echo esc_html($vendor['transfer']); ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </details>
    <?php
};

if (!$requires_consent) {
    include __DIR__ . '/consent-banner-info.php';
    return;
}

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
