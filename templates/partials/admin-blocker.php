<?php
/**
 * Admin tab "Blokering" (1.11.0, PLAN-1.11.0-v2 §3.5).
 *
 * Included by templates/settings-page.php. Reads trackwp_blocker (KB1),
 * trackwp_blocker_scan (KB3, written by TrackWP_Blocker_Scanner) and
 * trackwp_blocker_status (KB14, written by TrackWP_Blocker). Every value that
 * comes from a scan is escaped on output; blocker.js never uses innerHTML.
 *
 * @package TrackWP
 */

defined('ABSPATH') || exit;

$blk_config    = TrackWP_Settings::get_blocker_config();
$blk_supported = TrackWP_Blocker::html_api_available();
$blk_mode      = $blk_supported ? $blk_config['mode'] : 'off';
$blk_scan      = get_option('trackwp_blocker_scan', array());
$blk_scan      = is_array($blk_scan) ? $blk_scan : array();
$blk_status    = get_option('trackwp_blocker_status', array());
$blk_status    = is_array($blk_status) ? $blk_status : array();
$blk_labels    = TrackWP_Settings::blocker_status_labels();
$blk_catalog   = ( class_exists('TrackWP_Consent_Profile') && method_exists('TrackWP_Consent_Profile', 'vendor_catalog') )
    ? TrackWP_Consent_Profile::vendor_catalog()
    : array();
$blk_cat_names = array(
    'necessary'       => __('Nødvendig', 'trackwp'),
    'statistics'      => __('Statistik', 'trackwp'),
    'marketing'       => __('Marketing', 'trackwp'),
    'personalisation' => __('Præferencer', 'trackwp'),
);
// KC13/F2: the per-finding category dropdown gets "" ("Uafklaret") first.
$blk_row_cat_names = array( '' => __('Uafklaret', 'trackwp') ) + $blk_cat_names;
$blk_type_names = array(
    'handle' => __('Script-handle', 'trackwp'),
    'url'    => __('URL (host + sti)', 'trackwp'),
    'host'   => __('Host (inkl. underdomæner)', 'trackwp'),
    'inline' => __('Inline-markør', 'trackwp'),
    'pixel'  => __('Pixel (host + sti)', 'trackwp'),
    'cookie' => __('Cookienavn (undtag fra server-cookie-gaten)', 'trackwp'),
);
// Statuses where the toggle has no effect (KB4): shown, never toggled. KC5:
// a server_cookie row with a valid rule_id is still toggle-able even though
// its scan status is "cannot_server" — see the per-row override below.
$blk_fixed_statuses = array('cannot_server', 'cannot_serverside', 'cannot_bundled', 'gtm_consent_mode');
$blk_date = function ( $ts ) {
    $ts = (int) $ts;
    return $ts > 0 ? wp_date('Y-m-d H:i', $ts) : '';
};

// One row per rule id: scan items grouped, plus stored rules not seen in the last scan.
$blk_rows = array();
$blk_items = isset($blk_scan['items']) && is_array($blk_scan['items']) ? $blk_scan['items'] : array();
foreach ( $blk_items as $blk_item ) {
    if ( ! is_array($blk_item) || empty($blk_item['rule_id']) || ! is_string($blk_item['rule_id']) ) {
        continue;
    }
    $blk_id = $blk_item['rule_id'];
    if ( ! isset($blk_rows[ $blk_id ]) ) {
        $blk_rows[ $blk_id ] = array('item' => $blk_item, 'pages' => array(), 'dependents' => array(), 'seen' => true);
    }
    foreach ( array('pages', 'dependents') as $blk_list ) {
        if ( ! empty($blk_item[ $blk_list ]) && is_array($blk_item[ $blk_list ]) ) {
            $blk_rows[ $blk_id ][ $blk_list ] = array_values(array_unique(array_merge($blk_rows[ $blk_id ][ $blk_list ], array_map('strval', $blk_item[ $blk_list ]))));
        }
    }
}
foreach ( $blk_config['rules'] as $blk_id => $blk_rule ) {
    if ( ! isset($blk_rows[ $blk_id ]) ) {
        $blk_rows[ $blk_id ] = array('item' => array(), 'pages' => array(), 'dependents' => array(), 'seen' => false);
    }
}
$blk_observed = isset($blk_scan['health']['observed']) && is_array($blk_scan['health']['observed']) ? $blk_scan['health']['observed'] : array();

// "Bed om nyt samtykke" (§3.5): shown when the declared vendors changed since the baseline.
$blk_show_bump = false;
if ( class_exists('TrackWP_Consent_Profile') && method_exists('TrackWP_Consent_Profile', 'material_hash') ) {
    $blk_baseline  = (string) get_option('trackwp_consent_material_hash', '');
    $blk_show_bump = '' !== $blk_baseline && $blk_baseline !== (string) TrackWP_Consent_Profile::material_hash();
}

// Optimisation plugins (§3.3).
$blk_optimizers = array();
if ( defined('WP_ROCKET_VERSION') ) {
    $blk_optimizers[] = array('WP Rocket', true);
}
if ( defined('LSCWP_V') ) {
    $blk_optimizers[] = array('LiteSpeed Cache', true);
}
if ( defined('W3TC') ) {
    $blk_optimizers[] = array('W3 Total Cache', false);
}
if ( defined('AUTOPTIMIZE_PLUGIN_VERSION') ) {
    $blk_optimizers[] = array('Autoptimize', false);
}

// Meta warning (S14, review M1): producer is the scanner; sources are translated labels.
$blk_meta_warning = ( class_exists('TrackWP_Blocker_Scanner') && method_exists('TrackWP_Blocker_Scanner', 'meta_warning') )
    ? (array) TrackWP_Blocker_Scanner::meta_warning($blk_scan)
    : array();
$blk_meta_show    = ! empty($blk_meta_warning['show']);
$blk_meta_sources = $blk_meta_show && isset($blk_meta_warning['sources']) ? (array) $blk_meta_warning['sources'] : array();
?>
<div id="tab-blocker" class="trackwp-tab-content" data-tab="blocker">

    <p class="description">
        <?php echo esc_html__('Blokér trackere, som andre plugins eller temaet indlæser, indtil den besøgende har givet samtykke til kategorien. Kør en scan, vælg hvad der skal blokeres, og test i Test-tilstand før du slår blokeringen til.', 'trackwp'); ?>
    </p>

    <?php if ( ! $blk_supported ) : ?>
    <div class="notice notice-warning inline"><p>
        <?php echo esc_html__('Blokering kræver WordPress 6.5 eller nyere. Tilstanden er låst til Fra.', 'trackwp'); ?>
    </p></div>
    <?php endif; ?>

    <?php
    $blk_error_code  = ! empty($blk_status['last_error']['code']) ? sanitize_key((string) $blk_status['last_error']['code']) : '';
    $blk_gate_texts  = ( class_exists('TrackWP_Settings') && method_exists('TrackWP_Settings', 'cookie_gate_status_texts') ) ? TrackWP_Settings::cookie_gate_status_texts() : array();
    ?>
    <?php if ( '' !== $blk_error_code && isset($blk_gate_texts[ $blk_error_code ]) && 'off' !== $blk_mode ) : ?>
    <div class="notice notice-warning inline"><p>
        <?php
        // KC3: these codes come from TrackWP_Cookie_Gate — never the
        // blocker's own "omskrivningen fejlede" (that is HTML rewriting).
        echo esc_html(sprintf(
            /* translators: 1: message, 2: date/time */
            __('%1$s (%2$s)', 'trackwp'),
            $blk_gate_texts[ $blk_error_code ],
            $blk_date(isset($blk_status['last_error']['ts']) ? $blk_status['last_error']['ts'] : 0)
        ));
        ?>
    </p></div>
    <?php elseif ( '' !== $blk_error_code && 'off' !== $blk_mode ) : ?>
    <div class="notice notice-error inline"><p>
        <?php
        echo esc_html(sprintf(
            /* translators: 1: error code, 2: date/time */
            __('Driftsstatus: omskrivningen fejlede (%1$s, %2$s). Siden blev vist uden blokering.', 'trackwp'),
            $blk_error_code,
            $blk_date(isset($blk_status['last_error']['ts']) ? $blk_status['last_error']['ts'] : 0)
        ));
        ?>
    </p></div>
    <?php elseif ( ! empty($blk_status['last_ok']) && 'off' !== $blk_mode ) : ?>
    <p class="trackwp-blocker-ok">
        <?php
        echo esc_html(sprintf(
            /* translators: %s: date/time */
            __('Driftsstatus: ingen fejl. Senest omskrevet uden fejl %s.', 'trackwp'),
            $blk_date($blk_status['last_ok'])
        ));
        ?>
    </p>
    <?php endif; ?>

    <?php if ( $blk_show_bump ) : ?>
    <div class="notice notice-warning inline trackwp-blocker-bump">
        <p><?php echo esc_html__('Listen over vendors i samtykke-banneret er ændret. Bed de besøgende om nyt samtykke, så de ser den opdaterede erklæring.', 'trackwp'); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="trackwp_bump_consent" />
            <?php wp_nonce_field('trackwp_bump_consent'); ?>
            <p><button type="submit" class="button button-primary"><?php echo esc_html__('Bed om nyt samtykke', 'trackwp'); ?></button></p>
        </form>
    </div>
    <?php endif; ?>

    <!-- Scan -->
    <div class="trackwp-platform-section trackwp-blocker-scan">
        <h2 class="trackwp-section-title"><?php echo esc_html__('Scan', 'trackwp'); ?></h2>
        <p>
            <button type="button" class="button button-secondary" id="trackwp-blocker-scan-button">
                <?php echo esc_html__('Scan sitet', 'trackwp'); ?>
            </button>
            <span class="trackwp-blocker-scan-status" id="trackwp-blocker-scan-status" role="status" aria-live="polite"
                  data-running="<?php echo esc_attr__('Scanner … det kan tage op til et minut.', 'trackwp'); ?>"
                  data-done="<?php echo esc_attr__('Scan færdig. Siden genindlæses.', 'trackwp'); ?>"
                  data-failed="<?php echo esc_attr__('Scan mislykkedes:', 'trackwp'); ?>"
                  data-missing="<?php echo esc_attr__('Scan er ikke tilgængelig (mangler konfiguration).', 'trackwp'); ?>"></span>
        </p>
        <?php if ( ! empty($blk_scan['scanned_at']) ) : ?>
        <p class="description">
            <?php
            $blk_pages = isset($blk_scan['pages']) && is_array($blk_scan['pages']) ? array_map('strval', $blk_scan['pages']) : array();
            echo esc_html(sprintf(
                /* translators: 1: date/time, 2: scanned paths */
                __('Seneste scan: %1$s. Sider: %2$s', 'trackwp'),
                $blk_date($blk_scan['scanned_at']),
                implode(', ', $blk_pages)
            ));
            ?>
        </p>
        <?php else : ?>
        <p class="description"><?php echo esc_html__('Der er ikke kørt en scan endnu. Scanningen henter forsiden og op til fire andre sider som anonym besøgende.', 'trackwp'); ?></p>
        <?php endif; ?>
        <?php if ( ! empty($blk_scan['health']['checked_at']) ) : ?>
        <p class="description">
            <?php
            echo esc_html(sprintf(
                /* translators: %s: date/time */
                __('Helbredstjek %s: kolonnen "Observeret i HTML" viser, om reglen stod omskrevet i forsidens HTML for en almindelig besøgende.', 'trackwp'),
                $blk_date($blk_scan['health']['checked_at'])
            ));
            ?>
        </p>
        <?php endif; ?>

        <?php if ( $blk_meta_show ) : ?>
        <div class="notice notice-warning inline"><p>
            <?php echo esc_html__('Meta indlæses fra mere end én kilde. Det giver dobbelttælling:', 'trackwp'); ?>
        </p><ul class="trackwp-blocker-list">
            <?php foreach ( $blk_meta_sources as $blk_source ) : ?>
            <li><?php echo esc_html(is_scalar($blk_source) ? (string) $blk_source : ''); ?></li>
            <?php endforeach; ?>
        </ul><p class="description">
            <?php echo esc_html__('Server-side Conversions API fra andre kilder kan ikke ses fra browseren og er ikke med i listen.', 'trackwp'); ?>
        </p></div>
        <?php endif; ?>
        <?php if ( ! empty($blk_scan['gtm_active']) ) : ?>
        <p class="description"><?php echo esc_html__('Google Tag Manager er aktiv: scanningen kan ikke se tags i GTM. Fjern Meta-tags i GTM (pause er ikke nok), hvis TrackWP leverer Meta.', 'trackwp'); ?></p>
        <?php endif; ?>

        <?php if ( $blk_optimizers ) : ?>
        <ul class="trackwp-blocker-list">
            <?php foreach ( $blk_optimizers as $blk_opt ) : ?>
            <li>
                <?php
                echo esc_html(sprintf(
                    $blk_opt[1]
                        /* translators: %s: plugin name */
                        ? __('%s: TrackWP skriver selv sine undtagelser.', 'trackwp')
                        /* translators: %s: plugin name */
                        : __('%s: uprøvet sammen med blokering. Test grundigt i Test-tilstand.', 'trackwp'),
                    $blk_opt[0]
                ));
                ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <p class="description"><?php echo esc_html__('Cloudflare Rocket Loader er uprøvet sammen med blokering.', 'trackwp'); ?></p>
    </div>

    <form method="post" action="options.php" id="trackwp-blocker-form">
        <?php settings_fields('trackwp_blocker_group'); ?>

        <!-- Mode -->
        <div class="trackwp-platform-section">
            <h2 class="trackwp-section-title"><?php echo esc_html__('Tilstand', 'trackwp'); ?></h2>
            <?php if ( ! $blk_supported ) : ?>
            <input type="hidden" name="trackwp_blocker[mode]" value="off" />
            <?php endif; ?>
            <fieldset class="trackwp-blocker-mode">
                <?php
                $blk_modes = array(
                    'off'  => __('Fra', 'trackwp'),
                    'test' => __('Test (kun for administratorer)', 'trackwp'),
                    'on'   => __('Til', 'trackwp'),
                );
                foreach ( $blk_modes as $blk_value => $blk_label ) :
                    ?>
                    <label>
                        <input type="radio" name="trackwp_blocker[mode]" value="<?php echo esc_attr($blk_value); ?>"
                               <?php checked($blk_mode, $blk_value); ?> <?php disabled(! $blk_supported); ?> />
                        <?php echo esc_html($blk_label); ?>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <p class="description"><?php echo esc_html__('Test blokerer kun, når du er logget ind som administrator, så du kan kontrollere sitet i et almindeligt vindue før du slår blokeringen til for alle.', 'trackwp'); ?></p>
        </div>

        <!-- Table -->
        <div class="trackwp-platform-section">
            <h2 class="trackwp-section-title"><?php echo esc_html__('Fundne scripts og trackere', 'trackwp'); ?></h2>
            <?php if ( empty($blk_rows) ) : ?>
            <p class="description"><?php echo esc_html__('Ingen fund endnu. Kør en scan.', 'trackwp'); ?></p>
            <?php else : ?>
            <p>
                <button type="button" class="button" id="trackwp-blocker-block-known"><?php echo esc_html__('Bloker alle kendte trackere', 'trackwp'); ?></button>
                <span class="description"><?php echo esc_html__('Slår blokering til for alle fund med en vendor og en kategori, der ikke er nødvendig. Husk at gemme.', 'trackwp'); ?></span>
            </p>
            <table class="widefat striped trackwp-blocker-table"
                   data-protected-warning="<?php echo esc_attr__('Dette er et beskyttet script (fx betaling). Blokeres det, kan checkout holde op med at virke, indtil kunden giver samtykke. Vil du blokere det alligevel?', 'trackwp'); ?>">
                <thead>
                    <tr>
                        <th scope="col"><?php echo esc_html__('Vendor', 'trackwp'); ?></th>
                        <th scope="col"><?php echo esc_html__('Kategori', 'trackwp'); ?></th>
                        <th scope="col"><?php echo esc_html__('Kilde', 'trackwp'); ?></th>
                        <th scope="col"><?php echo esc_html__('Sider', 'trackwp'); ?></th>
                        <th scope="col"><?php echo esc_html__('Status', 'trackwp'); ?></th>
                        <th scope="col"><?php echo esc_html__('Observeret i HTML', 'trackwp'); ?></th>
                        <th scope="col"><?php echo esc_html__('Bloker indtil samtykke', 'trackwp'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                // KC5.2: a server_cookie row whose rule_id is compiled (block=true,
                // mode not off) is "server_gated" and toggle-able, even though its
                // raw scan status is "cannot_server".
                $blk_compiled_cookie_ids = array();
                if ( 'off' !== $blk_mode && class_exists('TrackWP_Blocker_Rules') && method_exists('TrackWP_Blocker_Rules', 'compile') ) {
                    $blk_compiled = TrackWP_Blocker_Rules::compile($blk_config);
                    if ( is_array($blk_compiled) && isset($blk_compiled['cookies']) && is_array($blk_compiled['cookies']) ) {
                        foreach ( $blk_compiled['cookies'] as $blk_cookie_entry ) {
                            if ( is_array($blk_cookie_entry) && isset($blk_cookie_entry[2]) && is_scalar($blk_cookie_entry[2]) ) {
                                $blk_compiled_cookie_ids[ (string) $blk_cookie_entry[2] ] = true;
                            }
                        }
                    }
                }
                foreach ( $blk_rows as $blk_id => $blk_row ) :
                    $blk_id   = (string) $blk_id;
                    $blk_item = $blk_row['item'];
                    $blk_rule = isset($blk_config['rules'][ $blk_id ]) && is_array($blk_config['rules'][ $blk_id ]) ? $blk_config['rules'][ $blk_id ] : array();
                    $blk_vendor = isset($blk_rule['vendor']) ? (string) $blk_rule['vendor'] : ( isset($blk_item['vendor']) && is_string($blk_item['vendor']) ? $blk_item['vendor'] : '' );
                    if ( isset($blk_rule['category']) ) {
                        $blk_cat = (string) $blk_rule['category'];
                    } elseif ( ! empty($blk_item['category_guess']) && is_string($blk_item['category_guess']) ) {
                        $blk_cat = $blk_item['category_guess'];
                    } elseif ( '' !== $blk_vendor && isset($blk_catalog[ $blk_vendor ]['category']) ) {
                        $blk_cat = $blk_catalog[ $blk_vendor ]['category'];
                    } else {
                        $blk_cat = ''; // KC13/F2: "Uafklaret", never a guessed "marketing".
                    }
                    $blk_block  = ! empty($blk_rule['block']);
                    $blk_scan_status = isset($blk_item['status']) && is_string($blk_item['status']) ? $blk_item['status'] : '';
                    $blk_toggleable_cookie = ( 'cannot_server' === $blk_scan_status ) && isset($blk_compiled_cookie_ids[ $blk_id ]);
                    if ( $blk_toggleable_cookie ) {
                        $blk_scan_status = 'server_gated';
                    }
                    $blk_fixed  = in_array($blk_scan_status, $blk_fixed_statuses, true) && ! $blk_toggleable_cookie;
                    if ( 'server_gated' === $blk_scan_status ) {
                        $blk_status_label = $blk_labels['server_gated'];
                    } elseif ( $blk_fixed || in_array($blk_scan_status, array('protected', 'before_trackwp'), true) ) {
                        $blk_status_label = isset($blk_labels[ $blk_scan_status ]) ? $blk_labels[ $blk_scan_status ] : $blk_scan_status;
                    } else {
                        $blk_status_label = $blk_block ? $blk_labels['blocked'] : $blk_labels['allowed'];
                    }
                    // Source: plugin, handle, host+path or "inline: marker".
                    $blk_source = array();
                    if ( ! empty($blk_item['plugin']) ) {
                        $blk_source[] = sprintf(/* translators: %s: plugin slug */ __('plugin: %s', 'trackwp'), (string) $blk_item['plugin']);
                    }
                    if ( ! empty($blk_item['handle']) ) {
                        $blk_source[] = sprintf(/* translators: %s: script handle */ __('handle: %s', 'trackwp'), (string) $blk_item['handle']);
                    }
                    if ( ! empty($blk_item['host']) ) {
                        $blk_source[] = (string) $blk_item['host'] . ( isset($blk_item['path']) ? (string) $blk_item['path'] : '' );
                    }
                    if ( ! empty($blk_item['marker']) ) {
                        $blk_source[] = sprintf(/* translators: %s: inline marker */ __('inline: %s', 'trackwp'), (string) $blk_item['marker']);
                    }
                    if ( ! $blk_source ) {
                        $blk_source[] = $blk_id;
                    }
                    $blk_field = 'trackwp_blocker[rules][' . $blk_id . ']';
                    ?>
                    <tr data-rule-id="<?php echo esc_attr($blk_id); ?>">
                        <td>
                            <select name="<?php echo esc_attr($blk_field . '[vendor]'); ?>" class="trackwp-blocker-vendor" aria-label="<?php echo esc_attr__('Vendor', 'trackwp'); ?>">
                                <option value="" data-category=""><?php echo esc_html__('Uafklaret', 'trackwp'); ?></option>
                                <?php foreach ( $blk_catalog as $blk_key => $blk_entry ) : ?>
                                <option value="<?php echo esc_attr($blk_key); ?>" data-category="<?php echo esc_attr(isset($blk_entry['category']) ? $blk_entry['category'] : ''); ?>" <?php selected($blk_vendor, $blk_key); ?>>
                                    <?php echo esc_html(isset($blk_entry['name']) ? $blk_entry['name'] : $blk_key); ?>
                                </option>
                                <?php endforeach; ?>
                                <?php foreach ( $blk_config['custom_vendors'] as $blk_custom ) : ?>
                                    <?php if ( is_array($blk_custom) && ! empty($blk_custom['key']) ) : ?>
                                <option value="<?php echo esc_attr($blk_custom['key']); ?>" data-category="<?php echo esc_attr(isset($blk_custom['category']) ? $blk_custom['category'] : ''); ?>" <?php selected($blk_vendor, $blk_custom['key']); ?>>
                                    <?php echo esc_html(sprintf(/* translators: %s: custom vendor name */ __('%s (egen)', 'trackwp'), isset($blk_custom['name']) ? $blk_custom['name'] : $blk_custom['key'])); ?>
                                </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select name="<?php echo esc_attr($blk_field . '[category]'); ?>" class="trackwp-blocker-category" aria-label="<?php echo esc_attr__('Kategori', 'trackwp'); ?>">
                                <?php foreach ( $blk_row_cat_names as $blk_key => $blk_label ) : ?>
                                <option value="<?php echo esc_attr($blk_key); ?>" <?php selected($blk_cat, $blk_key); ?>><?php echo esc_html($blk_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <code><?php echo esc_html(implode(' · ', $blk_source)); ?></code>
                            <?php if ( $blk_row['dependents'] ) : ?>
                            <br /><span class="description"><?php echo esc_html(sprintf(/* translators: %s: comma separated script handles */ __('Bruges af: %s', 'trackwp'), implode(', ', $blk_row['dependents']))); ?></span>
                            <?php endif; ?>
                            <?php if ( ! $blk_row['seen'] ) : ?>
                            <br /><span class="description"><?php echo esc_html__('Ikke fundet ved seneste scan.', 'trackwp'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html(implode(', ', $blk_row['pages'])); ?></td>
                        <td class="trackwp-blocker-status"><?php echo esc_html($blk_status_label); ?></td>
                        <td>
                            <?php
                            if ( array_key_exists($blk_id, $blk_observed) ) {
                                echo esc_html($blk_observed[ $blk_id ] ? __('Ja', 'trackwp') : __('Nej', 'trackwp'));
                            } else {
                                echo '&ndash;';
                            }
                            ?>
                        </td>
                        <td>
                            <input type="hidden" name="<?php echo esc_attr($blk_field . '[block]'); ?>" value="0" />
                            <input type="checkbox" name="<?php echo esc_attr($blk_field . '[block]'); ?>" value="1"
                                   class="trackwp-blocker-toggle"
                                   aria-label="<?php echo esc_attr__('Bloker indtil samtykke', 'trackwp'); ?>"
                                   <?php echo 'protected' === $blk_scan_status ? 'data-protected="1"' : ''; ?>
                                   <?php checked($blk_block && ! $blk_fixed); ?> <?php disabled($blk_fixed); ?> />
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            <p class="description"><?php echo esc_html__('Scripts, der indlæses efter samtykke, får ikke sidens DOMContentLoaded-hændelse. Tjek at de virker efter accept.', 'trackwp'); ?></p>
        </div>

        <!-- Exceptions and extra fields -->
        <div class="trackwp-platform-section">
            <h2 class="trackwp-section-title"><?php echo esc_html__('Undtagelser', 'trackwp'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php echo esc_html__('Tillad altid', 'trackwp'); ?></th>
                    <td>
                        <div id="trackwp-blocker-allow-rows">
                        <?php
                        $blk_allow   = (array) $blk_config['exceptions']['allow'];
                        $blk_allow[] = ''; // One empty row for a new entry.
                        foreach ( array_values($blk_allow) as $blk_i => $blk_entry ) :
                            $blk_parts = explode(':', (string) $blk_entry, 2);
                            $blk_type  = count($blk_parts) === 2 ? $blk_parts[0] : 'host';
                            $blk_value = count($blk_parts) === 2 ? $blk_parts[1] : '';
                            ?>
                            <p class="trackwp-blocker-allow-row">
                                <select name="<?php echo esc_attr('trackwp_blocker[exceptions][allow][' . $blk_i . '][type]'); ?>" aria-label="<?php echo esc_attr__('Type', 'trackwp'); ?>">
                                    <?php foreach ( $blk_type_names as $blk_key => $blk_label ) : ?>
                                    <option value="<?php echo esc_attr($blk_key); ?>" <?php selected($blk_type, $blk_key); ?>><?php echo esc_html($blk_label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" class="regular-text" name="<?php echo esc_attr('trackwp_blocker[exceptions][allow][' . $blk_i . '][value]'); ?>"
                                       value="<?php echo esc_attr($blk_value); ?>" placeholder="cdn.example.com" aria-label="<?php echo esc_attr__('Værdi', 'trackwp'); ?>" />
                            </p>
                        <?php endforeach; ?>
                        </div>
                        <p><button type="button" class="button" id="trackwp-blocker-allow-add"><?php echo esc_html__('Tilføj undtagelse', 'trackwp'); ?></button></p>
                        <p class="description"><?php echo esc_html__('Vælg typen og skriv værdien uden præfiks, fx host "cdn.example.com" eller handle "my-script". Tøm feltet for at fjerne en undtagelse.', 'trackwp'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="trackwp_blocker_paths"><?php echo esc_html__('Slå blokering fra på stier', 'trackwp'); ?></label></th>
                    <td>
                        <textarea id="trackwp_blocker_paths" name="trackwp_blocker[exceptions][paths]" rows="3" class="large-text code"><?php echo esc_textarea(implode("\n", (array) $blk_config['exceptions']['paths'])); ?></textarea>
                        <p class="description"><?php echo esc_html__('Én sti pr. linje, der starter med /, fx /kasse/. Blokeringen er slået fra på alle sider, hvis sti starter sådan.', 'trackwp'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="trackwp_blocker_extra_paths"><?php echo esc_html__('Ekstra scan-stier', 'trackwp'); ?></label></th>
                    <td>
                        <textarea id="trackwp_blocker_extra_paths" name="trackwp_blocker[extra_paths]" rows="3" class="large-text code"><?php echo esc_textarea(implode("\n", (array) $blk_config['extra_paths'])); ?></textarea>
                        <p class="description">
                            <?php
                            echo esc_html(sprintf(
                                /* translators: %d: max number of extra paths */
                                __('Højst %d stier, én pr. linje (kun stier, ingen fulde URL\'er). Forsiden scannes altid, og resten udfyldes automatisk op til 5 sider.', 'trackwp'),
                                TrackWP_Settings::BLOCKER_MAX_EXTRA_PATHS
                            ));
                            ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="trackwp_blocker_new_inline"><?php echo esc_html__('Bloker ukendt inline-script', 'trackwp'); ?></label></th>
                    <td>
                        <input type="text" id="trackwp_blocker_new_inline" class="regular-text code" name="trackwp_blocker[new_inline][marker]" value="" pattern="[A-Za-z0-9._\-]{8,64}" placeholder="myTrackerInit" />
                        <select name="trackwp_blocker[new_inline][category]" aria-label="<?php echo esc_attr__('Kategori', 'trackwp'); ?>">
                            <?php foreach ( $blk_cat_names as $blk_key => $blk_label ) : ?>
                                <?php if ( 'necessary' !== $blk_key ) : ?>
                            <option value="<?php echo esc_attr($blk_key); ?>" <?php selected('marketing', $blk_key); ?>><?php echo esc_html($blk_label); ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php echo esc_html__('En unik tekst fra scriptets kode (8-64 tegn: bogstaver, tal, punktum, bindestreg og understreg). Inline-scripts, der indeholder teksten, blokeres indtil samtykke.', 'trackwp'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="trackwp_blocker_new_cookie"><?php echo esc_html__('Tilføj server-cookie', 'trackwp'); ?></label></th>
                    <td>
                        <input type="text" id="trackwp_blocker_new_cookie" class="regular-text code" name="trackwp_blocker[new_cookie][name]" value="" placeholder="min_cookie eller min_praefiks_*" />
                        <select name="trackwp_blocker[new_cookie][category]" aria-label="<?php echo esc_attr__('Kategori', 'trackwp'); ?>">
                            <?php foreach ( $blk_cat_names as $blk_key => $blk_label ) : ?>
                                <?php if ( 'necessary' !== $blk_key ) : ?>
                            <option value="<?php echo esc_attr($blk_key); ?>" <?php selected('marketing', $blk_key); ?>><?php echo esc_html($blk_label); ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <select name="trackwp_blocker[new_cookie][vendor]" aria-label="<?php echo esc_attr__('Vendor', 'trackwp'); ?>">
                            <option value=""><?php echo esc_html__('Uafklaret', 'trackwp'); ?></option>
                            <?php foreach ( $blk_catalog as $blk_key => $blk_entry ) : ?>
                            <option value="<?php echo esc_attr($blk_key); ?>"><?php echo esc_html(isset($blk_entry['name']) ? $blk_entry['name'] : $blk_key); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php echo esc_html__('Navn eller præfiks med * til sidst (fx partner_cookie_*), for cookies som serveren sætter (fx et partner- eller affiliateplugin). Bloker gaten fjerner den fra svaret, hvis den besøgende ikke har givet samtykke til kategorien. Rammer et navn, der aldrig må fjernes (WP-login, WooCommerce-session eller selve samtykket), gemmes reglen ikke.', 'trackwp'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="trackwp_blocker_nocache_params"><?php echo esc_html__('Klik-parametre der ikke må caches', 'trackwp'); ?></label></th>
                    <td>
                        <textarea id="trackwp_blocker_nocache_params" name="trackwp_blocker[nocache_params]" rows="2" class="large-text code"><?php echo esc_textarea(implode("\n", (array) $blk_config['nocache_params'])); ?></textarea>
                        <p class="description">
                            <?php
                            echo esc_html(sprintf(
                                /* translators: %d: max number of nocache parameters */
                                __('Ét parameternavn pr. linje eller kommasepareret, højst %d. Sider besøgt med en af disse GET-parametre (fx annonce-klik-id\'er) caches ikke, så en server-cookie-regel når frem. Standarden dækker de gængse annonce-klik-id\'er — tilføj selv fx et affiliateprograms egen parameter.', 'trackwp'),
                                TrackWP_Settings::BLOCKER_MAX_NOCACHE_PARAMS
                            ));
                            ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Custom vendors (same fields as the GTM custom vendors) -->
        <div class="trackwp-platform-section">
            <h2 class="trackwp-section-title"><?php echo esc_html__('Egne vendors', 'trackwp'); ?></h2>
            <p class="description"><?php echo esc_html__('Til fund, der ikke er i kataloget. De kan vælges i Vendor-kolonnen og kommer med i cookie-deklarationen. Tøm navnet for at fjerne en vendor.', 'trackwp'); ?></p>
            <table class="widefat trackwp-blocker-custom">
                <thead>
                    <tr>
                        <th scope="col"><?php echo esc_html__('Navn', 'trackwp'); ?></th>
                        <th scope="col"><?php echo esc_html__('Udbyder', 'trackwp'); ?></th>
                        <th scope="col"><?php echo esc_html__('Kategori', 'trackwp'); ?></th>
                        <th scope="col"><?php echo esc_html__('Cookies', 'trackwp'); ?></th>
                        <th scope="col"><?php echo esc_html__('Formål', 'trackwp'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $blk_customs   = array_values((array) $blk_config['custom_vendors']);
                $blk_customs[] = array(); // One empty row for a new vendor.
                foreach ( $blk_customs as $blk_i => $blk_custom ) :
                    $blk_custom = is_array($blk_custom) ? $blk_custom : array();
                    $blk_base   = 'trackwp_blocker[custom_vendors][' . $blk_i . ']';
                    $blk_get    = function ( $key ) use ( $blk_custom ) {
                        return isset($blk_custom[ $key ]) && is_scalar($blk_custom[ $key ]) ? (string) $blk_custom[ $key ] : '';
                    };
                    ?>
                    <tr>
                        <td>
                            <input type="hidden" name="<?php echo esc_attr($blk_base . '[key]'); ?>" value="<?php echo esc_attr($blk_get('key')); ?>" />
                            <input type="hidden" name="<?php echo esc_attr($blk_base . '[lifetime]'); ?>" value="<?php echo esc_attr($blk_get('lifetime')); ?>" />
                            <input type="hidden" name="<?php echo esc_attr($blk_base . '[transfer]'); ?>" value="<?php echo esc_attr($blk_get('transfer')); ?>" />
                            <input type="text" name="<?php echo esc_attr($blk_base . '[name]'); ?>" value="<?php echo esc_attr($blk_get('name')); ?>" aria-label="<?php echo esc_attr__('Navn', 'trackwp'); ?>" />
                        </td>
                        <td><input type="text" name="<?php echo esc_attr($blk_base . '[provider]'); ?>" value="<?php echo esc_attr($blk_get('provider')); ?>" aria-label="<?php echo esc_attr__('Udbyder', 'trackwp'); ?>" /></td>
                        <td>
                            <select name="<?php echo esc_attr($blk_base . '[category]'); ?>" aria-label="<?php echo esc_attr__('Kategori', 'trackwp'); ?>">
                                <?php foreach ( $blk_cat_names as $blk_key => $blk_label ) : ?>
                                    <?php if ( 'necessary' !== $blk_key ) : ?>
                                <option value="<?php echo esc_attr($blk_key); ?>" <?php selected('' !== $blk_get('category') ? $blk_get('category') : 'marketing', $blk_key); ?>><?php echo esc_html($blk_label); ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input type="text" name="<?php echo esc_attr($blk_base . '[cookies]'); ?>" value="<?php echo esc_attr($blk_get('cookies')); ?>" aria-label="<?php echo esc_attr__('Cookies', 'trackwp'); ?>" /></td>
                        <td><input type="text" name="<?php echo esc_attr($blk_base . '[purpose]'); ?>" value="<?php echo esc_attr($blk_get('purpose')); ?>" aria-label="<?php echo esc_attr__('Formål', 'trackwp'); ?>" /></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php submit_button( __('Gem blokering', 'trackwp') ); ?>
    </form>
</div>
