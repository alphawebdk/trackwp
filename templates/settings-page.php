<?php
/**
 * TrackWP Admin Settings Page Template
 *
 * Loaded by TrackWP_Settings::render_page().
 * Provides 5 tabs: Platforms, Events, WooCommerce, Consent, Advanced.
 *
 * @package TrackWP
 */

defined('ABSPATH') || exit;

if ( ! current_user_can('manage_options') ) {
    wp_die( esc_html__('Adgang nægtet.', 'trackwp') );
}

$platforms = get_option('trackwp_platforms', array());
$events    = get_option('trackwp_events', array());
$consent   = get_option('trackwp_consent', array());
$advanced  = get_option('trackwp_advanced', array());
$cookie_declarations = get_option('trackwp_cookie_declarations', array());

$trigger_types    = TrackWP_Events::get_trigger_types();
$meta_event_types = TrackWP_Events::get_meta_event_types();
$currencies       = TrackWP_Events::get_currencies();

$has_woocommerce = class_exists('WooCommerce');
?>
<div class="wrap trackwp-settings">
    <h1><?php echo esc_html__('TrackWP', 'trackwp'); ?></h1>

    <?php
    // WordPress only auto-prints these on the built-in options-*.php screens.
    // Without this call the sanitizers' add_settings_error() messages (invalid
    // event name, duplicate name, bad GA4 ID …) — and the "settings saved"
    // confirmation — were never shown on this page.
    settings_errors();
    ?>

    <nav class="nav-tab-wrapper trackwp-tabs">
        <a href="#dashboard" class="nav-tab nav-tab-active" data-tab="dashboard">
            <?php echo esc_html__('Dashboard', 'trackwp'); ?>
        </a>
        <a href="#platforms" class="nav-tab" data-tab="platforms">
            <?php echo esc_html__('Platforme', 'trackwp'); ?>
        </a>
        <a href="#events" class="nav-tab" data-tab="events">
            <?php echo esc_html__('Begivenheder', 'trackwp'); ?>
        </a>
        <?php if ( $has_woocommerce ) : ?>
        <a href="#woocommerce" class="nav-tab" data-tab="woocommerce">
            <?php echo esc_html__('WooCommerce', 'trackwp'); ?>
        </a>
        <?php endif; ?>
        <a href="#consent" class="nav-tab" data-tab="consent">
            <?php echo esc_html__('Samtykke', 'trackwp'); ?>
        </a>
        <a href="#advanced" class="nav-tab" data-tab="advanced">
            <?php echo esc_html__('Avanceret', 'trackwp'); ?>
        </a>
    </nav>

    <!-- ================================================================
         TAB 0: Dashboard
         ================================================================ -->
    <?php
    $stats_window = isset( $_GET['stats_window'] ) ? (int) $_GET['stats_window'] : 7;
    if ( ! in_array( $stats_window, array( 7, 30 ), true ) ) {
        $stats_window = 7;
    }
    $data = TrackWP_Settings::aggregate_stats_with_trend( $stats_window );
    $agg  = $data['current'];
    $trend = $data['trend'];

    $max_per_day = 0;
    foreach ( $agg['per_day'] as $row ) {
        if ( $row['events'] > $max_per_day ) {
            $max_per_day = $row['events'];
        }
    }

    $active_platforms = 0;
    $platform_chips   = array();
    foreach ( array(
        'ga4_enabled'        => 'GA4',
        'google_ads_enabled' => 'Google Ads',
        'meta_enabled'       => 'Meta',
        'gtm_enabled'        => 'GTM',
    ) as $pk => $plabel ) {
        if ( ! empty( $platforms[ $pk ] ) ) {
            $active_platforms++;
            $platform_chips[] = $plabel;
        }
    }

    // Build sparkline SVG path from per_day events.
    $sparkline_w = 240;
    $sparkline_h = 60;
    $n = max( 1, count( $agg['per_day'] ) - 1 );
    $sparkline_path = '';
    $sparkline_area = '';
    if ( $max_per_day > 0 ) {
        $points = array();
        foreach ( $agg['per_day'] as $i => $row ) {
            $x = round( ( $i / $n ) * $sparkline_w, 2 );
            $y = round( $sparkline_h - ( ( $row['events'] / $max_per_day ) * ( $sparkline_h - 4 ) ) - 2, 2 );
            $points[] = "{$x},{$y}";
        }
        $sparkline_path = 'M ' . implode( ' L ', $points );
        $sparkline_area = 'M 0,' . $sparkline_h . ' L ' . implode( ' L ', $points ) . " L {$sparkline_w},{$sparkline_h} Z";
    }

    /**
     * Render a trend pill given the % delta.
     */
    $render_trend = function( $delta ) use ( $stats_window ) {
        if ( $delta === null ) {
            return '<span class="trackwp-trend trackwp-trend--neutral">' . esc_html__( 'Ingen tidligere data', 'trackwp' ) . '</span>';
        }
        $cls = 'trackwp-trend--neutral';
        $arrow = '→';
        if ( $delta > 0 ) {
            $cls = 'trackwp-trend--up';
            $arrow = '↑';
        } elseif ( $delta < 0 ) {
            $cls = 'trackwp-trend--down';
            $arrow = '↓';
        }
        $pct = abs( $delta );
        /* translators: 1: arrow, 2: percent, 3: window days */
        $label = sprintf( __( '%1$s %2$s%% vs. forrige %3$d dage', 'trackwp' ), $arrow, $pct, $stats_window );
        return '<span class="trackwp-trend ' . esc_attr( $cls ) . '">' . esc_html( $label ) . '</span>';
    };
    ?>
    <div id="tab-dashboard" class="trackwp-tab-content active" data-tab="dashboard">

        <?php if ( isset( $_GET['trackwp_stats_reset'] ) ) : ?>
            <div class="notice notice-success inline trackwp-dashboard-notice"><p><?php echo esc_html__( 'Statistik nulstillet.', 'trackwp' ); ?></p></div>
        <?php endif; ?>

        <div class="trackwp-dashboard-header">
            <div class="trackwp-dashboard-header-title">
                <h2><?php echo esc_html__( 'Dashboard', 'trackwp' ); ?></h2>
                <p class="trackwp-dashboard-header-sub">
                    <?php
                    /* translators: %d: number of days */
                    echo esc_html( sprintf( __( 'Aktivitet de sidste %d dage', 'trackwp' ), $stats_window ) );
                    ?>
                </p>
            </div>
            <div class="trackwp-dashboard-header-actions">
                <div class="trackwp-pillbar" role="tablist" aria-label="<?php echo esc_attr__( 'Periode', 'trackwp' ); ?>">
                    <a class="trackwp-pill <?php echo $stats_window === 7 ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=trackwp&stats_window=7#dashboard' ) ); ?>">
                        <?php echo esc_html__( '7 dage', 'trackwp' ); ?>
                    </a>
                    <a class="trackwp-pill <?php echo $stats_window === 30 ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=trackwp&stats_window=30#dashboard' ) ); ?>">
                        <?php echo esc_html__( '30 dage', 'trackwp' ); ?>
                    </a>
                </div>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="trackwp-reset-form" onsubmit="return confirm('<?php echo esc_attr__( 'Nulstil al statistik?', 'trackwp' ); ?>');">
                    <input type="hidden" name="action" value="trackwp_reset_stats" />
                    <?php wp_nonce_field( 'trackwp_reset_stats' ); ?>
                    <button type="submit" class="trackwp-icon-button" title="<?php echo esc_attr__( 'Nulstil statistik', 'trackwp' ); ?>" aria-label="<?php echo esc_attr__( 'Nulstil statistik', 'trackwp' ); ?>">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 9-9"></path><polyline points="3 4 3 10 9 10"></polyline></svg>
                        <span><?php echo esc_html__( 'Nulstil', 'trackwp' ); ?></span>
                    </button>
                </form>
            </div>
        </div>

        <!-- HERO card -->
        <div class="trackwp-hero">
            <div class="trackwp-hero-main">
                <div class="trackwp-hero-icon" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"></path><path d="M7 14l4-4 4 4 5-5"></path></svg>
                </div>
                <div class="trackwp-hero-value"><?php echo esc_html( number_format_i18n( $agg['totals']['events'] ) ); ?></div>
                <div class="trackwp-hero-label"><?php echo esc_html__( 'Events i alt', 'trackwp' ); ?></div>
                <div class="trackwp-hero-trend"><?php echo wp_kses_post( $render_trend( $trend['events'] ) ); ?></div>
            </div>
            <div class="trackwp-hero-sparkline" aria-hidden="true">
                <?php if ( $sparkline_path ) : ?>
                <svg viewBox="0 0 <?php echo esc_attr( $sparkline_w ); ?> <?php echo esc_attr( $sparkline_h ); ?>" preserveAspectRatio="none">
                    <defs>
                        <linearGradient id="sparklineFill" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#30D3C0" stop-opacity="0.35"/>
                            <stop offset="100%" stop-color="#30D3C0" stop-opacity="0"/>
                        </linearGradient>
                    </defs>
                    <path d="<?php echo esc_attr( $sparkline_area ); ?>" fill="url(#sparklineFill)"/>
                    <path d="<?php echo esc_attr( $sparkline_path ); ?>" fill="none" stroke="#30D3C0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <?php else : ?>
                <div class="trackwp-sparkline-empty"><?php echo esc_html__( 'Ingen data', 'trackwp' ); ?></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Secondary stat cards -->
        <div class="trackwp-stat-grid">
            <!-- Samtykke-rate -->
            <div class="trackwp-stat-card">
                <div class="trackwp-stat-icon" aria-hidden="true">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"></path></svg>
                </div>
                <div class="trackwp-stat-value"><?php echo esc_html( $agg['accept_rate'] ); ?><span class="trackwp-stat-unit">%</span></div>
                <div class="trackwp-stat-label"><?php echo esc_html__( 'Samtykke-rate', 'trackwp' ); ?></div>
                <div class="trackwp-stat-sub">
                    <?php
                    /* translators: 1: accept count, 2: reject count */
                    echo esc_html( sprintf( __( '%1$s ja · %2$s nej', 'trackwp' ), number_format_i18n( $agg['totals']['consent_accept'] ), number_format_i18n( $agg['totals']['consent_reject'] ) ) );
                    ?>
                </div>
            </div>

            <!-- Bots -->
            <div class="trackwp-stat-card">
                <div class="trackwp-stat-icon" aria-hidden="true">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"></rect><circle cx="12" cy="5" r="2"></circle><path d="M12 7v4"></path><line x1="8" y1="16" x2="8" y2="16"></line><line x1="16" y1="16" x2="16" y2="16"></line></svg>
                </div>
                <div class="trackwp-stat-value"><?php echo esc_html( number_format_i18n( $agg['totals']['bot_skipped'] ) ); ?></div>
                <div class="trackwp-stat-label"><?php echo esc_html__( 'Bots filtreret', 'trackwp' ); ?></div>
                <div class="trackwp-stat-sub"><?php echo wp_kses_post( $render_trend( $trend['bot_skipped'] ) ); ?></div>
            </div>

            <!-- Platforme -->
            <div class="trackwp-stat-card">
                <div class="trackwp-stat-icon" aria-hidden="true">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect></svg>
                </div>
                <div class="trackwp-stat-value"><?php echo esc_html( $active_platforms ); ?><span class="trackwp-stat-unit">/ 4</span></div>
                <div class="trackwp-stat-label"><?php echo esc_html__( 'Aktive platforme', 'trackwp' ); ?></div>
                <div class="trackwp-stat-sub trackwp-stat-chips">
                    <?php if ( $platform_chips ) : ?>
                        <?php foreach ( $platform_chips as $chip ) : ?>
                            <span class="trackwp-chip"><?php echo esc_html( $chip ); ?></span>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <span class="trackwp-stat-empty"><?php echo esc_html__( 'Ingen aktive', 'trackwp' ); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Events per dag -->
        <div class="trackwp-panel">
            <div class="trackwp-panel-header">
                <h3 class="trackwp-panel-title"><?php echo esc_html__( 'Events per dag', 'trackwp' ); ?></h3>
                <span class="trackwp-panel-meta"><?php
                    /* translators: %s: highest count */
                    echo esc_html( sprintf( __( 'Højeste dag: %s events', 'trackwp' ), number_format_i18n( $max_per_day ) ) );
                ?></span>
            </div>
            <?php if ( $max_per_day > 0 ) : ?>
                <div class="trackwp-chart">
                    <?php foreach ( $agg['per_day'] as $row ) :
                        $pct = round( ( $row['events'] / $max_per_day ) * 100 );
                        $day_label = mysql2date( 'd/m', $row['date'] );
                        $full_label = mysql2date( 'd. M Y', $row['date'] );
                    ?>
                        <div class="trackwp-chart-col" title="<?php echo esc_attr( $full_label . ' — ' . number_format_i18n( $row['events'] ) . ' events' ); ?>">
                            <div class="trackwp-chart-bar" style="height: <?php echo esc_attr( max( 2, $pct ) ); ?>%;"></div>
                            <div class="trackwp-chart-label"><?php echo esc_html( $day_label ); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <div class="trackwp-empty">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3v18h18"></path><path d="M7 14l4-4 4 4 5-5"></path></svg>
                    <p><?php echo esc_html__( 'Ingen events registreret endnu', 'trackwp' ); ?></p>
                    <p class="trackwp-empty-hint"><?php echo esc_html__( 'Statistik vises her efter de første hits.', 'trackwp' ); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Top events -->
        <div class="trackwp-panel">
            <div class="trackwp-panel-header">
                <h3 class="trackwp-panel-title"><?php echo esc_html__( 'Top events', 'trackwp' ); ?></h3>
                <span class="trackwp-panel-meta">
                    <?php
                    $top_total = array_sum( $agg['by_event'] );
                    /* translators: %s: total count */
                    echo esc_html( sprintf( __( 'I alt: %s', 'trackwp' ), number_format_i18n( $top_total ) ) );
                    ?>
                </span>
            </div>
            <?php if ( empty( $agg['by_event'] ) ) : ?>
                <div class="trackwp-empty">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"></rect><line x1="9" y1="9" x2="15" y2="9"></line><line x1="9" y1="13" x2="15" y2="13"></line><line x1="9" y1="17" x2="13" y2="17"></line></svg>
                    <p><?php echo esc_html__( 'Ingen events endnu', 'trackwp' ); ?></p>
                </div>
            <?php else :
                $top = array_slice( $agg['by_event'], 0, 5, true );
                $max_top = max( $top );
                $sum_top = array_sum( $top );
                $rank = 0;
            ?>
                <ul class="trackwp-top-list">
                    <?php foreach ( $top as $name => $count ) :
                        $rank++;
                        $bar_pct = $max_top > 0 ? round( ( $count / $max_top ) * 100 ) : 0;
                        $share = $sum_top > 0 ? round( ( $count / $sum_top ) * 100 ) : 0;
                    ?>
                        <li class="trackwp-top-row">
                            <span class="trackwp-top-rank"><?php echo esc_html( $rank ); ?></span>
                            <span class="trackwp-top-name"><code><?php echo esc_html( $name ); ?></code></span>
                            <span class="trackwp-top-bar">
                                <span class="trackwp-top-bar-fill" style="width: <?php echo esc_attr( $bar_pct ); ?>%;"></span>
                            </span>
                            <span class="trackwp-top-count"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
                            <span class="trackwp-top-share"><?php echo esc_html( $share ); ?>%</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <p class="trackwp-footnote">
            <?php
            /* translators: %d: number of days */
            echo esc_html( sprintf( __( 'Statistik gemmes lokalt i %d dage. Ingen persondata indsamles.', 'trackwp' ), TrackWP_Settings::STATS_RETENTION_DAYS ) );
            ?>
        </p>
    </div>

    <!-- ================================================================
         TAB 1: Platforms
         ================================================================ -->
    <div id="tab-platforms" class="trackwp-tab-content" data-tab="platforms">
        <form method="post" action="options.php">
            <?php settings_fields('trackwp_platforms_group'); ?>

            <!-- GA4 -->
            <div class="trackwp-platform-section">
                <h2 class="trackwp-section-title">
                    <label>
                        <input type="checkbox"
                               name="trackwp_platforms[ga4_enabled]"
                               value="1"
                               <?php checked( ! empty($platforms['ga4_enabled']) ); ?> />
                        <?php echo esc_html__('Google Analytics 4', 'trackwp'); ?>
                    </label>
                    <span class="trackwp-status-badge <?php echo ! empty($platforms['ga4_enabled']) ? 'enabled' : 'disabled'; ?>"></span>
                </h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="ga4_measurement_id"><?php echo esc_html__('Measurement ID', 'trackwp'); ?></label>
                        </th>
                        <td>
                            <input type="text"
                                   id="ga4_measurement_id"
                                   name="trackwp_platforms[ga4_measurement_id]"
                                   value="<?php echo esc_attr( isset($platforms['ga4_measurement_id']) ? $platforms['ga4_measurement_id'] : '' ); ?>"
                                   class="regular-text"
                                   placeholder="G-XXXXXXXXXX" />
                            <p class="description">
                                <?php echo esc_html__('Findes i GA4 > Admin > Data Streams > din stream > Measurement ID.', 'trackwp'); ?>
                            </p>
                            <div id="trackwp-ga4-id-warning" class="trackwp-inline-warning" style="display:none;" role="alert">
                                <strong><?php esc_html_e( 'Bemaerk:', 'trackwp' ); ?></strong>
                                <span class="trackwp-warning-text"></span>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ga4_api_secret"><?php echo esc_html__('API Secret', 'trackwp'); ?></label>
                        </th>
                        <td>
                            <div class="trackwp-password-field">
                                <input type="password"
                                       id="ga4_api_secret"
                                       name="trackwp_platforms[ga4_api_secret]"
                                       value="<?php echo ! empty($platforms['ga4_api_secret']) ? '••••••••' : ''; ?>"
                                       class="regular-text"
                                       autocomplete="new-password" />
                                <?php if ( ! empty($platforms['ga4_api_secret']) ) : ?>
                                <button type="button" class="button trackwp-clear-secret" data-target="ga4_api_secret">
                                    <?php echo esc_html__('Ryd', 'trackwp'); ?>
                                </button>
                                <?php endif; ?>
                            </div>
                            <p class="description">
                                <?php echo esc_html__('GA4 > Admin > Data Streams > din stream > Measurement Protocol API secrets. Opret en hvis ingen findes.', 'trackwp'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php echo esc_html__('Indlæs gtag.js', 'trackwp'); ?>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="trackwp_platforms[ga4_gtag_enabled]"
                                       value="1"
                                       <?php checked( ! empty($platforms['ga4_gtag_enabled']) ); ?> />
                                <?php echo esc_html__('Indlæs Googles gtag.js og send sidevisninger/sessions til GA4.', 'trackwp'); ?>
                            </label>
                            <p class="description">
                                <?php echo esc_html__('Anbefales. Slå kun fra hvis GA4 allerede indlæses via GTM eller dit tema. Uden gtag.js får GA4 ingen sidevisninger — kun server-events.', 'trackwp'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Google Ads -->
            <div class="trackwp-platform-section">
                <h2 class="trackwp-section-title">
                    <label>
                        <input type="checkbox"
                               name="trackwp_platforms[google_ads_enabled]"
                               value="1"
                               <?php checked( ! empty($platforms['google_ads_enabled']) ); ?> />
                        <?php echo esc_html__('Google Ads', 'trackwp'); ?>
                    </label>
                    <span class="trackwp-status-badge <?php echo ! empty($platforms['google_ads_enabled']) ? 'enabled' : 'disabled'; ?>"></span>
                </h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="google_ads_conversion_id"><?php echo esc_html__('Conversion ID', 'trackwp'); ?></label>
                        </th>
                        <td>
                            <input type="text"
                                   id="google_ads_conversion_id"
                                   name="trackwp_platforms[google_ads_conversion_id]"
                                   value="<?php echo esc_attr( isset($platforms['google_ads_conversion_id']) ? $platforms['google_ads_conversion_id'] : '' ); ?>"
                                   class="regular-text"
                                   placeholder="AW-XXXXXXXXXX" />
                            <p class="description">
                                <?php echo esc_html__('Google Ads > Tools > Conversions > din konverteringshandling > Tag setup > Conversion ID.', 'trackwp'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="google_ads_customer_id"><?php echo esc_html__('Customer ID', 'trackwp'); ?></label>
                        </th>
                        <td>
                            <input type="text"
                                   id="google_ads_customer_id"
                                   name="trackwp_platforms[google_ads_customer_id]"
                                   value="<?php echo esc_attr( isset($platforms['google_ads_customer_id']) ? $platforms['google_ads_customer_id'] : '' ); ?>"
                                   class="regular-text"
                                   placeholder="123-456-7890" />
                            <p class="description">
                                <?php echo esc_html__('Format: 123-456-7890 eller 10 cifre. Findes øverst til højre i Google Ads UI.', 'trackwp'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="google_ads_conversion_action_id"><?php echo esc_html__('Conversion Action ID', 'trackwp'); ?></label>
                        </th>
                        <td>
                            <input type="text"
                                   id="google_ads_conversion_action_id"
                                   name="trackwp_platforms[google_ads_conversion_action_id]"
                                   value="<?php echo esc_attr( isset($platforms['google_ads_conversion_action_id']) ? $platforms['google_ads_conversion_action_id'] : '' ); ?>"
                                   class="regular-text"
                                   placeholder="987654321"
                                   pattern="[0-9]+" />
                            <p class="description">
                                <?php echo esc_html__('Numerisk ID for konverteringshandlingen (kun cifre). Bruges til Google Ads API uploads.', 'trackwp'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="google_ads_developer_token"><?php echo esc_html__('Developer Token', 'trackwp'); ?></label>
                        </th>
                        <td>
                            <div class="trackwp-password-field">
                                <input type="password"
                                       id="google_ads_developer_token"
                                       name="trackwp_platforms[google_ads_developer_token]"
                                       value="<?php echo ! empty($platforms['google_ads_developer_token']) ? '••••••••' : ''; ?>"
                                       class="regular-text"
                                       autocomplete="new-password" />
                                <?php if ( ! empty($platforms['google_ads_developer_token']) ) : ?>
                                <button type="button" class="button trackwp-clear-secret" data-target="google_ads_developer_token">
                                    <?php echo esc_html__('Ryd', 'trackwp'); ?>
                                </button>
                                <?php endif; ?>
                            </div>
                            <p class="description">
                                <?php echo esc_html__('Google Ads API Center > API Access > Developer Token. Krævet til server-side API-kald.', 'trackwp'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="google_ads_oauth_client_id"><?php echo esc_html__('OAuth Client ID', 'trackwp'); ?></label>
                        </th>
                        <td>
                            <input type="text"
                                   id="google_ads_oauth_client_id"
                                   name="trackwp_platforms[google_ads_oauth_client_id]"
                                   value="<?php echo esc_attr( isset($platforms['google_ads_oauth_client_id']) ? $platforms['google_ads_oauth_client_id'] : '' ); ?>"
                                   class="regular-text" />
                            <p class="description">
                                <?php echo esc_html__('Fra Google Cloud Console > APIs & Services > Credentials (OAuth 2.0 Client ID).', 'trackwp'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="google_ads_oauth_client_secret"><?php echo esc_html__('OAuth Client Secret', 'trackwp'); ?></label>
                        </th>
                        <td>
                            <div class="trackwp-password-field">
                                <input type="password"
                                       id="google_ads_oauth_client_secret"
                                       name="trackwp_platforms[google_ads_oauth_client_secret]"
                                       value="<?php echo ! empty($platforms['google_ads_oauth_client_secret']) ? '••••••••' : ''; ?>"
                                       class="regular-text"
                                       autocomplete="new-password" />
                                <?php if ( ! empty($platforms['google_ads_oauth_client_secret']) ) : ?>
                                <button type="button" class="button trackwp-clear-secret" data-target="google_ads_oauth_client_secret">
                                    <?php echo esc_html__('Ryd', 'trackwp'); ?>
                                </button>
                                <?php endif; ?>
                            </div>
                            <p class="description">
                                <?php echo esc_html__('Fra Google Cloud Console > APIs & Services > Credentials (OAuth 2.0 Client ID).', 'trackwp'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="google_ads_oauth_refresh_token"><?php echo esc_html__('OAuth Refresh Token', 'trackwp'); ?></label>
                        </th>
                        <td>
                            <div class="trackwp-password-field">
                                <input type="password"
                                       id="google_ads_oauth_refresh_token"
                                       name="trackwp_platforms[google_ads_oauth_refresh_token]"
                                       value="<?php echo ! empty($platforms['google_ads_oauth_refresh_token']) ? '••••••••' : ''; ?>"
                                       class="regular-text"
                                       autocomplete="new-password" />
                                <?php if ( ! empty($platforms['google_ads_oauth_refresh_token']) ) : ?>
                                <button type="button" class="button trackwp-clear-secret" data-target="google_ads_oauth_refresh_token">
                                    <?php echo esc_html__('Ryd', 'trackwp'); ?>
                                </button>
                                <?php endif; ?>
                            </div>
                            <p class="description">
                                <?php echo esc_html__('Genereres een gang med OAuth Playground eller et script — bruges til automatisk token-fornyelse. Kraever scope https://www.googleapis.com/auth/adwords.', 'trackwp'); ?>
                            </p>
                            <p class="description">
                                <?php echo esc_html__('Bemaerk: Google lukker UploadClickConversions for NYE developer tokens fra 15. juni 2026 (Data Manager API er afloeseren). Eksisterende tokens fortsaetter.', 'trackwp'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Meta -->
            <div class="trackwp-platform-section">
                <h2 class="trackwp-section-title">
                    <label>
                        <input type="checkbox"
                               name="trackwp_platforms[meta_enabled]"
                               value="1"
                               <?php checked( ! empty($platforms['meta_enabled']) ); ?> />
                        <?php echo esc_html__('Meta (Facebook)', 'trackwp'); ?>
                    </label>
                    <span class="trackwp-status-badge <?php echo ! empty($platforms['meta_enabled']) ? 'enabled' : 'disabled'; ?>"></span>
                </h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="meta_pixel_id"><?php echo esc_html__('Pixel ID', 'trackwp'); ?></label>
                        </th>
                        <td>
                            <input type="text"
                                   id="meta_pixel_id"
                                   name="trackwp_platforms[meta_pixel_id]"
                                   value="<?php echo esc_attr( isset($platforms['meta_pixel_id']) ? $platforms['meta_pixel_id'] : '' ); ?>"
                                   class="regular-text"
                                   placeholder="123456789012345" />
                            <p class="description">
                                <?php echo esc_html__('Meta Events Manager > Data Sources > dit Pixel > Settings > Pixel ID.', 'trackwp'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php echo esc_html__('Klient-side Pixel', 'trackwp'); ?>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="trackwp_platforms[meta_pixel_client_enabled]"
                                       value="1"
                                       <?php checked( ! empty($platforms['meta_pixel_client_enabled']) ); ?> />
                                <?php echo esc_html__('Indlæs Meta Pixel i browseren (kræver marketing-samtykke).', 'trackwp'); ?>
                            </label>
                            <p class="description">
                                <?php echo esc_html__('Anbefales. Pixel + Conversions API med samme event_id giver bedre match quality — Meta dedupliker selv. Slå fra hvis Pixel allerede indlæses via GTM eller andet plugin.', 'trackwp'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="meta_access_token"><?php echo esc_html__('Access Token', 'trackwp'); ?></label>
                        </th>
                        <td>
                            <div class="trackwp-password-field">
                                <input type="password"
                                       id="meta_access_token"
                                       name="trackwp_platforms[meta_access_token]"
                                       value="<?php echo ! empty($platforms['meta_access_token']) ? '••••••••' : ''; ?>"
                                       class="regular-text"
                                       autocomplete="new-password" />
                                <?php if ( ! empty($platforms['meta_access_token']) ) : ?>
                                <button type="button" class="button trackwp-clear-secret" data-target="meta_access_token">
                                    <?php echo esc_html__('Ryd', 'trackwp'); ?>
                                </button>
                                <?php endif; ?>
                            </div>
                            <p class="description">
                                <?php echo esc_html__('Meta Events Manager > Data Sources > dit Pixel > Settings > Generate access token (Conversions API).', 'trackwp'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="meta_test_event_code"><?php echo esc_html__('Test Event Code', 'trackwp'); ?></label>
                        </th>
                        <td>
                            <input type="text"
                                   id="meta_test_event_code"
                                   name="trackwp_platforms[meta_test_event_code]"
                                   value="<?php echo esc_attr( isset($platforms['meta_test_event_code']) ? $platforms['meta_test_event_code'] : '' ); ?>"
                                   class="regular-text"
                                   placeholder="TEST12345"
                                   pattern="TEST\d+" />
                            <p class="description">
                                <?php echo esc_html__('Valgfri. Format: TEST efterfulgt af cifre (fx TEST12345). Bruges til at validere events i Meta Events Manager > Test Events.', 'trackwp'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="meta_api_version"><?php echo esc_html__('Graph API version', 'trackwp'); ?></label>
                        </th>
                        <td>
                            <?php $meta_version_current = isset($platforms['meta_api_version']) ? $platforms['meta_api_version'] : 'v21.0'; ?>
                            <select id="meta_api_version" name="trackwp_platforms[meta_api_version]">
                                <?php foreach ( array('v18.0', 'v19.0', 'v20.0', 'v21.0', 'v22.0') as $mv ) : ?>
                                    <option value="<?php echo esc_attr($mv); ?>" <?php selected($meta_version_current, $mv); ?>>
                                        <?php echo esc_html($mv); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php echo esc_html__('Meta Graph API-version til Conversions API-kald. Standard: v21.0.', 'trackwp'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Google Tag Manager -->
            <div class="trackwp-platform-section">
                <h2 class="trackwp-section-title">
                    <label>
                        <input type="checkbox"
                               name="trackwp_platforms[gtm_enabled]"
                               value="1"
                               <?php checked( ! empty($platforms['gtm_enabled']) ); ?> />
                        <?php echo esc_html__('Google Tag Manager', 'trackwp'); ?>
                    </label>
                    <span class="trackwp-status-badge <?php echo ! empty($platforms['gtm_enabled']) ? 'enabled' : 'disabled'; ?>"></span>
                </h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="gtm_container_id"><?php echo esc_html__('Container ID', 'trackwp'); ?></label>
                        </th>
                        <td>
                            <input type="text"
                                   id="gtm_container_id"
                                   name="trackwp_platforms[gtm_container_id]"
                                   value="<?php echo esc_attr( isset($platforms['gtm_container_id']) ? $platforms['gtm_container_id'] : '' ); ?>"
                                   class="regular-text"
                                   placeholder="GTM-XXXXXXX" />
                            <p class="description">
                                <?php echo esc_html__("Format: GTM-XXXXXXX. Snippet'et indsættes automatisk i <head> og <body>.", 'trackwp'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <?php submit_button( __('Gem platforme', 'trackwp') ); ?>
        </form>
    </div>

    <!-- ================================================================
         TAB 2: Events
         ================================================================ -->
    <div id="tab-events" class="trackwp-tab-content" data-tab="events">
        <form method="post" action="options.php" id="trackwp-events-form">
            <?php settings_fields('trackwp_events_group'); ?>

            <textarea id="trackwp-events-json"
                      name="trackwp_events"
                      style="display:none;"><?php echo esc_textarea( wp_json_encode($events) ); ?></textarea>

            <table class="wp-list-table widefat striped trackwp-events-table">
                <thead>
                    <tr>
                        <th class="trackwp-col-enabled"><?php echo esc_html__('Aktiveret', 'trackwp'); ?></th>
                        <th class="trackwp-col-name"><?php echo esc_html__('Begivenhedsnavn', 'trackwp'); ?></th>
                        <th class="trackwp-col-display"><?php echo esc_html__('Visningsnavn', 'trackwp'); ?></th>
                        <th class="trackwp-col-trigger"><?php echo esc_html__('Trigger-type', 'trackwp'); ?></th>
                        <th class="trackwp-col-value"><?php echo esc_html__('Værdi', 'trackwp'); ?></th>
                        <th class="trackwp-col-currency"><?php echo esc_html__('Valuta', 'trackwp'); ?></th>
                        <th class="trackwp-col-ads"><?php echo esc_html__('Google Ads Label', 'trackwp'); ?></th>
                        <th class="trackwp-col-meta"><?php echo esc_html__('Meta Event', 'trackwp'); ?></th>
                        <th class="trackwp-col-actions"><?php echo esc_html__('Handlinger', 'trackwp'); ?></th>
                    </tr>
                </thead>
                <tbody id="trackwp-events-tbody">
                    <!-- Rows rendered by admin.js -->
                </tbody>
            </table>

            <p style="margin-top: 12px;">
                <button type="button" id="trackwp-add-event" class="button button-secondary">
                    + <?php echo esc_html__('Tilføj begivenhed', 'trackwp'); ?>
                </button>
            </p>

            <!-- Data for JS -->
            <script type="text/template" id="trackwp-trigger-types"><?php echo wp_json_encode($trigger_types); ?></script>
            <script type="text/template" id="trackwp-meta-event-types"><?php echo wp_json_encode($meta_event_types); ?></script>
            <script type="text/template" id="trackwp-currencies"><?php echo wp_json_encode($currencies); ?></script>
            <script type="text/template" id="trackwp-conditions-schema"><?php echo wp_json_encode(TrackWP_Conditions::admin_schema()); ?></script>

            <?php submit_button( __('Gem begivenheder', 'trackwp') ); ?>
        </form>
    </div>

    <!-- ================================================================
         TAB 3: WooCommerce (only if active)
         ================================================================ -->
    <?php if ( $has_woocommerce ) : ?>
    <div id="tab-woocommerce" class="trackwp-tab-content" data-tab="woocommerce">
        <div class="notice notice-info inline" style="margin-top: 20px;">
            <p>
                <strong><?php echo esc_html__('Kommer snart', 'trackwp'); ?></strong><br>
                <?php echo esc_html__('WooCommerce-tracking er planlagt til TrackWP v1.5.', 'trackwp'); ?>
            </p>
        </div>
    </div>
    <?php endif; ?>

    <!-- ================================================================
         TAB 4: Consent
         ================================================================ -->
    <div id="tab-consent" class="trackwp-tab-content" data-tab="consent">
        <form method="post" action="options.php">
            <?php settings_fields('trackwp_consent_group'); ?>

            <h2 class="trackwp-section-title"><?php echo esc_html__('Banner-stil', 'trackwp'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="consent_banner_style"><?php echo esc_html__('Stil', 'trackwp'); ?></label>
                    </th>
                    <td>
                        <?php $banner_style = isset($consent['banner_style']) ? $consent['banner_style'] : 'dialog'; ?>
                        <select name="trackwp_consent[banner_style]" id="trackwp_banner_style">
                            <option value="cookiebot" <?php selected($banner_style, 'cookiebot'); ?>><?php esc_html_e('Cookiebot-stil (modal med faner og toggles)', 'trackwp'); ?></option>
                            <option value="dialog" <?php selected($banner_style, 'dialog'); ?>><?php esc_html_e('Centreret dialog (standard)', 'trackwp'); ?></option>
                            <option value="bottombar" <?php selected($banner_style, 'bottombar'); ?>><?php esc_html_e('Bjaelke i bunden (minimal)', 'trackwp'); ?></option>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Cookiebot-stil: Stor centreret modal med faner — bedst til ny-besoegende. Dialog: Kompakt centreret popup. Bjaelke: Slim bar nederst — mindre indtraengende.', 'trackwp'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <h2 class="trackwp-section-title"><?php echo esc_html__('Farver', 'trackwp'); ?></h2>
            <div class="trackwp-color-row">
                <div class="trackwp-color-group">
                    <label for="consent_bg_color"><?php echo esc_html__('Baggrund', 'trackwp'); ?></label>
                    <input type="text"
                           id="consent_bg_color"
                           name="trackwp_consent[bg_color]"
                           value="<?php echo esc_attr( isset($consent['bg_color']) ? $consent['bg_color'] : '#274A45' ); ?>"
                           class="trackwp-color-field" />
                </div>
                <div class="trackwp-color-group">
                    <label for="consent_text_color"><?php echo esc_html__('Tekst', 'trackwp'); ?></label>
                    <input type="text"
                           id="consent_text_color"
                           name="trackwp_consent[text_color]"
                           value="<?php echo esc_attr( isset($consent['text_color']) ? $consent['text_color'] : '#ffffff' ); ?>"
                           class="trackwp-color-field" />
                </div>
                <div class="trackwp-color-group">
                    <label for="consent_accent_color"><?php echo esc_html__('Fremhævning / knap', 'trackwp'); ?></label>
                    <input type="text"
                           id="consent_accent_color"
                           name="trackwp_consent[accent_color]"
                           value="<?php echo esc_attr( isset($consent['accent_color']) ? $consent['accent_color'] : '#30D3C0' ); ?>"
                           class="trackwp-color-field" />
                </div>
                <div class="trackwp-color-group">
                    <label for="consent_button_text_color"><?php echo esc_html__('Knaptekst', 'trackwp'); ?></label>
                    <input type="text"
                           id="consent_button_text_color"
                           name="trackwp_consent[button_text_color]"
                           value="<?php echo esc_attr( isset($consent['button_text_color']) ? $consent['button_text_color'] : '#274A45' ); ?>"
                           class="trackwp-color-field" />
                </div>
            </div>

            <h2 class="trackwp-section-title"><?php echo esc_html__('Udseende', 'trackwp'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="consent_border_radius"><?php echo esc_html__('Hjørneradius (px)', 'trackwp'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               id="consent_border_radius"
                               name="trackwp_consent[border_radius]"
                               value="<?php echo esc_attr( isset($consent['border_radius']) ? (int) $consent['border_radius'] : 8 ); ?>"
                               class="small-text"
                               min="0"
                               max="50" />
                    </td>
                </tr>
            </table>

            <h2 class="trackwp-section-title"><?php echo esc_html__('Tekst', 'trackwp'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="consent_heading"><?php echo esc_html__('Overskrift', 'trackwp'); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               id="consent_heading"
                               name="trackwp_consent[heading]"
                               value="<?php echo esc_attr( isset($consent['heading']) ? $consent['heading'] : '' ); ?>"
                               class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="consent_description"><?php echo esc_html__('Beskrivelse', 'trackwp'); ?></label>
                    </th>
                    <td>
                        <textarea id="consent_description"
                                  name="trackwp_consent[description]"
                                  class="large-text"
                                  rows="3"><?php echo esc_textarea( isset($consent['description']) ? $consent['description'] : '' ); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="consent_accept_text"><?php echo esc_html__('Accepter-knap', 'trackwp'); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               id="consent_accept_text"
                               name="trackwp_consent[accept_text]"
                               value="<?php echo esc_attr( isset($consent['accept_text']) ? $consent['accept_text'] : '' ); ?>"
                               class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="consent_reject_text"><?php echo esc_html__('Afvis-knap', 'trackwp'); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               id="consent_reject_text"
                               name="trackwp_consent[reject_text]"
                               value="<?php echo esc_attr( isset($consent['reject_text']) ? $consent['reject_text'] : '' ); ?>"
                               class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="consent_customize_text"><?php echo esc_html__('Tilpas-knap', 'trackwp'); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               id="consent_customize_text"
                               name="trackwp_consent[customize_text]"
                               value="<?php echo esc_attr( isset($consent['customize_text']) ? $consent['customize_text'] : '' ); ?>"
                               class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="consent_save_text"><?php echo esc_html__('Gem-knap', 'trackwp'); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               id="consent_save_text"
                               name="trackwp_consent[save_text]"
                               value="<?php echo esc_attr( isset($consent['save_text']) ? $consent['save_text'] : '' ); ?>"
                               class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="consent_privacy_page_id"><?php echo esc_html__('Side med privatlivspolitik', 'trackwp'); ?></label>
                    </th>
                    <td>
                        <?php
                        wp_dropdown_pages(array(
                            'name'              => 'trackwp_consent[privacy_page_id]',
                            'id'                => 'consent_privacy_page_id',
                            'selected'          => isset($consent['privacy_page_id']) ? (int) $consent['privacy_page_id'] : 0,
                            'show_option_none'  => __('-- Vælg side --', 'trackwp'),
                            'option_none_value' => 0,
                        ));
                        ?>
                    </td>
                </tr>
            </table>

            <h2 class="trackwp-section-title"><?php echo esc_html__('Adfærd', 'trackwp'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php echo esc_html__('Vis afvis-knap', 'trackwp'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="trackwp_consent[show_reject_button]"
                                   value="1"
                                   <?php checked( ! empty($consent['show_reject_button']) ); ?> />
                            <?php echo esc_html__('Vis en "Afvis alle"-knap på samtykke-banneret.', 'trackwp'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Kræv aktivt samtykke', 'trackwp'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="trackwp_consent[require_active_consent]"
                                   value="1"
                                   <?php checked( ! empty($consent['require_active_consent']) ); ?> />
                            <?php echo esc_html__('Bloker al tracking indtil brugeren aktivt accepterer (GDPR-anbefalet).', 'trackwp'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Log samtykke', 'trackwp'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="trackwp_consent[log_consent]"
                                   value="1"
                                   <?php checked( ! empty($consent['log_consent']) ); ?> />
                            <?php echo esc_html__('Gem en server-side log over hver samtykke-handling til audit-formål.', 'trackwp'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Genindhent samtykke ved policy-ændring', 'trackwp'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="trackwp_consent[reconsent_on_policy_change]"
                                   value="1"
                                   <?php checked( ! empty($consent['reconsent_on_policy_change']) ); ?> />
                            <?php echo esc_html__('Bed automatisk brugere om nyt samtykke når samtykke-tekst ændres.', 'trackwp'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="consent_cookie_lifetime"><?php echo esc_html__('Cookie-levetid (måneder)', 'trackwp'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               id="consent_cookie_lifetime"
                               name="trackwp_consent[cookie_lifetime_months]"
                               value="<?php echo esc_attr( isset($consent['cookie_lifetime_months']) ? (int) $consent['cookie_lifetime_months'] : 12 ); ?>"
                               class="small-text"
                               min="1"
                               max="24" />
                    </td>
                </tr>
            </table>

            <h2 class="trackwp-section-title"><?php echo esc_html__('Forhåndsvisning', 'trackwp'); ?></h2>
            <div id="trackwp-consent-preview" class="trackwp-consent-preview trackwp-consent-preview--style-dialog" data-preview-style="dialog">
                <div class="trackwp-preview-stage">
                    <div class="trackwp-preview-banner">
                        <div class="trackwp-preview-tabs" aria-hidden="true">
                            <span class="trackwp-preview-tab is-active"><?php esc_html_e('Samtykke', 'trackwp'); ?></span>
                            <span class="trackwp-preview-tab"><?php esc_html_e('Detaljer', 'trackwp'); ?></span>
                            <span class="trackwp-preview-tab"><?php esc_html_e('Om', 'trackwp'); ?></span>
                        </div>
                        <div class="trackwp-preview-heading"></div>
                        <div class="trackwp-preview-description"></div>
                        <div class="trackwp-preview-categories" aria-hidden="true">
                            <span class="trackwp-preview-cat"><?php esc_html_e('Nødvendige', 'trackwp'); ?></span>
                            <span class="trackwp-preview-cat"><?php esc_html_e('Statistik', 'trackwp'); ?></span>
                            <span class="trackwp-preview-cat"><?php esc_html_e('Marketing', 'trackwp'); ?></span>
                            <span class="trackwp-preview-cat"><?php esc_html_e('Præferencer', 'trackwp'); ?></span>
                        </div>
                        <div class="trackwp-preview-buttons">
                            <button type="button" class="trackwp-preview-btn trackwp-preview-reject"></button>
                            <button type="button" class="trackwp-preview-btn trackwp-preview-customize"></button>
                            <button type="button" class="trackwp-preview-btn trackwp-preview-accept"></button>
                        </div>
                    </div>
                </div>
            </div>

            <?php submit_button( __('Gem samtykke-indstillinger', 'trackwp') ); ?>
        </form>

        <!-- Cookie declaration: auto-scan reference + custom editor -->
        <div class="trackwp-cookie-declaration">
            <h2 class="trackwp-section-title"><?php echo esc_html__('Cookie-deklaration', 'trackwp'); ?></h2>
            <p class="description"><?php echo esc_html__('TrackWP registrerer automatisk kendte cookies (Google, Meta, WordPress, WooCommerce, PHPSESSID, Breakdance m.fl.) og viser dem i banneret under "Detaljer". Tilføj selv cookies fra andre plugins her.', 'trackwp'); ?></p>

            <h3><?php echo esc_html__('Registreres automatisk', 'trackwp'); ?></h3>
            <table class="widefat striped trackwp-known-cookies">
                <thead><tr>
                    <th><?php esc_html_e('Cookie / mønster', 'trackwp'); ?></th>
                    <th><?php esc_html_e('Kategori', 'trackwp'); ?></th>
                    <th><?php esc_html_e('Udbyder', 'trackwp'); ?></th>
                    <th><?php esc_html_e('Levetid', 'trackwp'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( TrackWP_Cookie_Scanner::known_cookies() as $kc ) : ?>
                    <tr>
                        <td><code><?php echo esc_html($kc['match']); ?></code></td>
                        <td><?php echo esc_html($kc['category']); ?></td>
                        <td><?php echo esc_html($kc['provider']); ?></td>
                        <td><?php echo esc_html(isset($kc['lifetime']) ? $kc['lifetime'] : ''); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <form method="post" action="options.php" class="trackwp-cookie-declarations-form">
                <?php settings_fields('trackwp_cookie_declarations_group'); ?>
                <h3><?php echo esc_html__('Egne cookie-deklarationer', 'trackwp'); ?></h3>
                <table class="widefat trackwp-custom-cookies">
                    <thead><tr>
                        <th><?php esc_html_e('Kategori', 'trackwp'); ?></th>
                        <th><?php esc_html_e('Navn', 'trackwp'); ?></th>
                        <th><?php esc_html_e('Udbyder', 'trackwp'); ?></th>
                        <th><?php esc_html_e('Cookies', 'trackwp'); ?></th>
                        <th><?php esc_html_e('Formål', 'trackwp'); ?></th>
                        <th><?php esc_html_e('Levetid', 'trackwp'); ?></th>
                        <th><?php esc_html_e('Dataoverførsel', 'trackwp'); ?></th>
                        <th></th>
                    </tr></thead>
                    <tbody id="trackwp-cookie-rows"></tbody>
                </table>
                <p><button type="button" class="button" id="trackwp-add-cookie-row"><?php esc_html_e('Tilføj række', 'trackwp'); ?></button></p>
                <textarea name="trackwp_cookie_declarations" id="trackwp-cookie-declarations-json" style="display:none;"></textarea>
                <?php submit_button( __('Gem cookie-deklarationer', 'trackwp') ); ?>
            </form>

            <script type="application/json" id="trackwp-cookie-declarations-initial"><?php
                $flat = array();
                if ( is_array($cookie_declarations) ) {
                    foreach ( array('necessary','statistics','marketing','personalisation') as $cat ) {
                        if ( empty($cookie_declarations[$cat]) || ! is_array($cookie_declarations[$cat]) ) { continue; }
                        foreach ( $cookie_declarations[$cat] as $e ) {
                            if ( ! is_array($e) ) { continue; }
                            $flat[] = array(
                                'category' => $cat,
                                'name'     => isset($e['name']) ? $e['name'] : '',
                                'provider' => isset($e['provider']) ? $e['provider'] : '',
                                'cookies'  => isset($e['cookies']) ? $e['cookies'] : '',
                                'purpose'  => isset($e['purpose']) ? $e['purpose'] : '',
                                'lifetime' => isset($e['lifetime']) ? $e['lifetime'] : '',
                                'transfer' => isset($e['transfer']) ? $e['transfer'] : '',
                            );
                        }
                    }
                }
                echo wp_json_encode($flat);
            ?></script>
            <script>
            (function(){
                var tbody = document.getElementById('trackwp-cookie-rows');
                var json = document.getElementById('trackwp-cookie-declarations-json');
                var addBtn = document.getElementById('trackwp-add-cookie-row');
                if (!tbody || !json || !addBtn) return;
                var cats = [['necessary','Nødvendige'],['statistics','Statistik'],['marketing','Marketing'],['personalisation','Funktionalitet']];
                function esc(s){ return (s==null?'':String(s)).replace(/"/g,'&quot;'); }
                function rowHtml(r){
                    r = r || {};
                    var opts = cats.map(function(c){ return '<option value="'+c[0]+'"'+(r.category===c[0]?' selected':'')+'>'+c[1]+'</option>'; }).join('');
                    return '<td><select data-f="category">'+opts+'</select></td>'+
                        '<td><input type="text" data-f="name" value="'+esc(r.name)+'"></td>'+
                        '<td><input type="text" data-f="provider" value="'+esc(r.provider)+'"></td>'+
                        '<td><input type="text" data-f="cookies" value="'+esc(r.cookies)+'"></td>'+
                        '<td><input type="text" data-f="purpose" value="'+esc(r.purpose)+'"></td>'+
                        '<td><input type="text" data-f="lifetime" value="'+esc(r.lifetime)+'"></td>'+
                        '<td><input type="text" data-f="transfer" value="'+esc(r.transfer)+'"></td>'+
                        '<td><button type="button" class="button-link trackwp-remove-cookie-row" aria-label="Fjern">&#10005;</button></td>';
                }
                function addRow(r){ var tr=document.createElement('tr'); tr.innerHTML=rowHtml(r); tbody.appendChild(tr); }
                function serialize(){
                    var rows=[];
                    tbody.querySelectorAll('tr').forEach(function(tr){
                        var o={};
                        tr.querySelectorAll('[data-f]').forEach(function(el){ o[el.getAttribute('data-f')]=el.value; });
                        if (o.name||o.cookies||o.provider){ rows.push(o); }
                    });
                    json.value=JSON.stringify(rows);
                }
                tbody.addEventListener('input', serialize);
                tbody.addEventListener('change', serialize);
                tbody.addEventListener('click', function(e){
                    var btn=e.target.closest('.trackwp-remove-cookie-row');
                    if(btn){ var tr=btn.closest('tr'); if(tr){ tr.parentNode.removeChild(tr); serialize(); } }
                });
                addBtn.addEventListener('click', function(){ addRow({category:'necessary'}); serialize(); });
                var initial=[];
                try{ initial=JSON.parse(document.getElementById('trackwp-cookie-declarations-initial').textContent||'[]'); }catch(e){}
                initial.forEach(addRow);
                serialize();
            })();
            </script>
        </div>
    </div>

    <!-- ================================================================
         TAB 5: Advanced
         ================================================================ -->
    <div id="tab-advanced" class="trackwp-tab-content" data-tab="advanced">
        <form method="post" action="options.php">
            <?php settings_fields('trackwp_advanced_group'); ?>

            <h2 class="trackwp-section-title"><?php echo esc_html__('Endpoint', 'trackwp'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="advanced_endpoint_path"><?php echo esc_html__('Endpoint slug', 'trackwp'); ?></label>
                    </th>
                    <td>
                        <code id="advanced_endpoint_preview"><?php echo esc_html( rest_url('trackwp/v1/') ); ?><span id="advanced_endpoint_slug_preview"><?php echo esc_html( isset($advanced['endpoint_path']) ? $advanced['endpoint_path'] : 'event' ); ?></span></code>
                        <br>
                        <input type="text"
                               id="advanced_endpoint_path"
                               name="trackwp_advanced[endpoint_path]"
                               value="<?php echo esc_attr( isset($advanced['endpoint_path']) ? $advanced['endpoint_path'] : 'event' ); ?>"
                               class="regular-text"
                               placeholder="event"
                               maxlength="32"
                               pattern="[a-z0-9\-]+" />
                        <p class="description">
                            <?php echo esc_html__('Kun slug (a-z, 0-9, bindestreger; maks. 32 tegn). Nyttigt for at omgå adblockers. Må ikke være "consent-log".', 'trackwp'); ?>
                        </p>
                        <script>
                        (function(){
                            var input = document.getElementById('advanced_endpoint_path');
                            var preview = document.getElementById('advanced_endpoint_slug_preview');
                            if (!input || !preview) return;
                            input.addEventListener('input', function(){
                                var v = input.value.toLowerCase().replace(/[^a-z0-9\-]/g, '');
                                preview.textContent = v || 'event';
                            });
                        })();
                        </script>
                    </td>
                </tr>
            </table>

            <h2 class="trackwp-section-title"><?php echo esc_html__('Førsteparts-cookie', 'trackwp'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php echo esc_html__('Aktivér', 'trackwp'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="trackwp_advanced[first_party_cookie_enabled]"
                                   value="1"
                                   <?php checked( ! empty($advanced['first_party_cookie_enabled']) ); ?> />
                            <?php echo esc_html__('Brug en førsteparts-cookie til at identificere returnerende besøgende.', 'trackwp'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="advanced_cookie_name"><?php echo esc_html__('Cookie-navn', 'trackwp'); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               id="advanced_cookie_name"
                               name="trackwp_advanced[cookie_name]"
                               value="<?php echo esc_attr( isset($advanced['cookie_name']) ? $advanced['cookie_name'] : '_twp_cid' ); ?>"
                               class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="advanced_cookie_lifetime"><?php echo esc_html__('Levetid (måneder)', 'trackwp'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               id="advanced_cookie_lifetime"
                               name="trackwp_advanced[cookie_lifetime_months]"
                               value="<?php echo esc_attr( isset($advanced['cookie_lifetime_months']) ? (int) $advanced['cookie_lifetime_months'] : 24 ); ?>"
                               class="small-text"
                               min="1"
                               max="24" />
                    </td>
                </tr>
            </table>

            <h2 class="trackwp-section-title"><?php echo esc_html__('Consent Mode v2', 'trackwp'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php echo esc_html__('Cookieløse pings', 'trackwp'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="trackwp_advanced[consent_mode_cookieless_pings]"
                                   value="1"
                                   <?php checked( ! empty($advanced['consent_mode_cookieless_pings']) ); ?> />
                            <?php echo esc_html__('Indlæs Google-tags før samtykke med denied-defaults, så gtag kan sende cookieløse pings (Consent Mode advanced — hjælper konverteringsmodellering). Slå fra for først at indlæse Google-tags efter samtykke (basic mode).', 'trackwp'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Annoncesignaler', 'trackwp'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="trackwp_advanced[consent_mode_ad_signals]"
                                   value="1"
                                   <?php checked( ! empty($advanced['consent_mode_ad_signals']) ); ?> />
                            <?php echo esc_html__('Send annonce-klik-identifiers (gclid, fbclid) i cookieløse pings.', 'trackwp'); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <h2 class="trackwp-section-title"><?php echo esc_html__('Dedup-strategi', 'trackwp'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php echo esc_html__('Tilstand', 'trackwp'); ?></th>
                    <td>
                        <?php $dedup_mode = isset($advanced['dedup_mode']) ? $advanced['dedup_mode'] : 'client_and_server'; ?>
                        <fieldset>
                            <label>
                                <input type="radio" name="trackwp_advanced[dedup_mode]" value="client_and_server" <?php checked($dedup_mode, 'client_and_server'); ?> />
                                <?php echo esc_html__('Klient + server (standard — begge fyrer, dedup via event_id)', 'trackwp'); ?>
                            </label><br>
                            <label>
                                <input type="radio" name="trackwp_advanced[dedup_mode]" value="server_only" <?php checked($dedup_mode, 'server_only'); ?> />
                                <?php echo esc_html__('Kun server (brug når GTM håndterer klient-side tags)', 'trackwp'); ?>
                            </label><br>
                            <label>
                                <input type="radio" name="trackwp_advanced[dedup_mode]" value="client_only" <?php checked($dedup_mode, 'client_only'); ?> />
                                <?php echo esc_html__('Kun klient (spring server-proxy GA4/Meta dispatch over)', 'trackwp'); ?>
                            </label>
                        </fieldset>
                        <p class="description">
                            <?php echo esc_html__('Førsteparts-cookie og samtykke-log kører altid uanset tilstand.', 'trackwp'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Jeg bruger GTM på dette site', 'trackwp'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" id="trackwp_i_use_gtm" name="trackwp_advanced[uses_gtm]" value="1" <?php checked( ! empty($advanced['uses_gtm']) ); ?> />
                            <?php echo esc_html__('Vælg automatisk "Kun server"-tilstand ovenfor.', 'trackwp'); ?>
                        </label>
                        <script>
                        (function(){
                            var cb = document.getElementById('trackwp_i_use_gtm');
                            if (!cb) return;
                            cb.addEventListener('change', function(){
                                if (!cb.checked) return;
                                var radios = document.getElementsByName('trackwp_advanced[dedup_mode]');
                                for (var i = 0; i < radios.length; i++) {
                                    if (radios[i].value === 'server_only') { radios[i].checked = true; break; }
                                }
                            });
                            var radios = document.getElementsByName('trackwp_advanced[dedup_mode]');
                            for (var i = 0; i < radios.length; i++) {
                                radios[i].addEventListener('change', function(){
                                    // Uncheck "uses GTM" if user manually switches away from server_only
                                    if (this.checked && this.value !== 'server_only') {
                                        cb.checked = false;
                                    }
                                });
                            }
                        })();
                        </script>
                    </td>
                </tr>
            </table>

            <h2 class="trackwp-section-title"><?php echo esc_html__('Debug', 'trackwp'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php echo esc_html__('Log til fil', 'trackwp'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="trackwp_advanced[debug_log]"
                                   value="1"
                                   <?php checked( ! empty($advanced['debug_log']) ); ?> />
                            <?php echo esc_html__('Skriv tracking-events til WordPress debug-loggen.', 'trackwp'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Konsoloutput', 'trackwp'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="trackwp_advanced[debug_console]"
                                   value="1"
                                   <?php checked( ! empty($advanced['debug_console']) ); ?> />
                            <?php echo esc_html__('Send tracking debug-info til browserkonsollen.', 'trackwp'); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <h2 class="trackwp-section-title"><?php echo esc_html__('Ydelse', 'trackwp'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php echo esc_html__('Batching', 'trackwp'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="trackwp_advanced[batching_enabled]"
                                   value="1"
                                   <?php checked( ! empty($advanced['batching_enabled']) ); ?> />
                            <?php echo esc_html__('Saml flere events og send dem i en samlet request for at reducere netværksbelastning.', 'trackwp'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Førsteparts-loader', 'trackwp'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="trackwp_advanced[first_party_loader_enabled]"
                                   value="1"
                                   <?php checked( ! empty($advanced['first_party_loader_enabled']) ); ?> />
                            <?php echo esc_html__('Server-side proxy af tracking-scripts via dit eget domæne (omgår script-blockers).', 'trackwp'); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <h2 class="trackwp-section-title"><?php echo esc_html__('Identifikation', 'trackwp'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php echo esc_html__('GA4 User ID', 'trackwp'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="trackwp_advanced[ga4_user_id_enabled]"
                                   value="1"
                                   <?php checked( ! empty($advanced['ga4_user_id_enabled']) ); ?> />
                            <?php echo esc_html__('Send User ID til GA4 når en bruger er logget ind (forbedrer cross-device tracking).', 'trackwp'); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <h2 class="trackwp-section-title"><?php echo esc_html__('Leveringslog', 'trackwp'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php echo esc_html__('Gem leveringsstatus', 'trackwp'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="trackwp_advanced[delivery_log_enabled]"
                                   value="1"
                                   <?php checked( ! empty($advanced['delivery_log_enabled']) ); ?> />
                            <?php echo esc_html__('Gem hvilke begivenheder der blev sendt til hver platform, og om de kom frem.', 'trackwp'); ?>
                        </label>
                        <p class="description">
                            <?php echo esc_html__('Gemmer KUN teknisk leveringsinformation: begivenhedsnavn, et tilfældigt begivenheds-id, tidspunkt afrundet til minut, modtagerplatform, leveringsstatus og samtykketilstand. Der gemmes hverken IP, browseroplysninger, side-URL, formularindhold, e-mail, telefonnummer, client_id, gclid eller Facebook-cookies — loggen kan derfor ikke knyttes til en bestemt besøgende.', 'trackwp'); ?>
                        </p>
                        <p class="description">
                            <?php echo esc_html__('Brug den til at bevise at events faktisk når frem, og til at opdage dobbelttælling. Den er ikke et analyseværktøj og forbedrer ikke genkendelsen af tilbagevendende besøgende — det gør førsteparts-cookien.', 'trackwp'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="advanced_delivery_log_retention"><?php echo esc_html__('Slet efter (dage)', 'trackwp'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               id="advanced_delivery_log_retention"
                               name="trackwp_advanced[delivery_log_retention_days]"
                               value="<?php echo esc_attr( isset($advanced['delivery_log_retention_days']) ? (int) $advanced['delivery_log_retention_days'] : TrackWP_Delivery_Log::DEFAULT_RETENTION_DAYS ); ?>"
                               class="small-text"
                               min="1"
                               max="<?php echo esc_attr( TrackWP_Delivery_Log::MAX_RETENTION_DAYS ); ?>" />
                        <p class="description">
                            <?php
                            echo esc_html( sprintf(
                                /* translators: %d: maximum retention in days */
                                __( 'Ældre poster slettes automatisk hver dag. Maks. %d dage — loggen er et diagnoseværktøj, ikke et arkiv.', 'trackwp' ),
                                TrackWP_Delivery_Log::MAX_RETENTION_DAYS
                            ) );
                            ?>
                        </p>
                    </td>
                </tr>
            </table>

            <h2 class="trackwp-section-title"><?php echo esc_html__('Conversions API debug', 'trackwp'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php echo esc_html__('CAPI debug-logging', 'trackwp'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="trackwp_advanced[capi_debug_logging_enabled]"
                                   value="1"
                                   <?php checked( ! empty($advanced['capi_debug_logging_enabled']) ); ?> />
                            <?php echo esc_html__('Log fulde request/response-payloads for Meta CAPI og Google Ads API. Kun til fejlsøgning.', 'trackwp'); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <?php submit_button( __('Gem avancerede indstillinger', 'trackwp') ); ?>
        </form>

            <?php if ( ! empty( $advanced['delivery_log_enabled'] ) ) : ?>
            <hr style="margin: 32px 0;">

            <h2 class="trackwp-section-title"><?php echo esc_html__( 'Leveringslog — seneste', 'trackwp' ); ?></h2>

            <?php if ( isset( $_GET['trackwp_log_cleared'] ) ) : ?>
                <div class="notice notice-success inline"><p><?php echo esc_html__( 'Leveringsloggen er tømt.', 'trackwp' ); ?></p></div>
            <?php endif; ?>

            <?php
            $log_rows      = TrackWP_Delivery_Log::get_recent( 50 );
            $log_total     = TrackWP_Delivery_Log::count();
            $log_dupes     = TrackWP_Delivery_Log::find_duplicates( 10 );
            $log_overview  = TrackWP_Delivery_Log::overview();
            $log_per_day   = TrackWP_Delivery_Log::per_day();
            $log_days      = TrackWP_Delivery_Log::retention_days();
            $log_day_max   = 0;
            foreach ( $log_per_day as $d ) {
                if ( $d['events'] > $log_day_max ) { $log_day_max = $d['events']; }
            }
            $dest_labels = array( 'ga4' => 'GA4', 'meta' => 'Meta', 'google_ads' => 'Google Ads' );
            ?>

            <?php if ( $log_dupes ) : ?>
                <div class="notice notice-warning inline">
                    <p><strong><?php echo esc_html__( 'Mulig dobbelttælling opdaget', 'trackwp' ); ?></strong><br>
                    <?php echo esc_html__( 'Samme begivenhed er sendt flere gange til samme platform inden for det samme minut, med forskellige begivenheds-id\'er:', 'trackwp' ); ?></p>
                    <ul style="list-style: disc; margin-left: 20px;">
                        <?php foreach ( $log_dupes as $dupe ) : ?>
                            <li><code><?php echo esc_html( $dupe['event_name'] ); ?></code> → <?php echo esc_html( $dupe['destination'] ); ?> — <?php echo esc_html( sprintf( __( '%1$d gange kl. %2$s', 'trackwp' ), (int) $dupe['hits'], $dupe['logged_at'] ) ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <p class="description">
                <?php
                echo esc_html( sprintf(
                    /* translators: 1: number of days, 2: number of rows */
                    __( 'Viser de seneste %1$d dage (%2$s poster). Tidspunkter er UTC og afrundet til minut.', 'trackwp' ),
                    $log_days,
                    number_format_i18n( $log_total )
                ) );
                ?>
            </p>

            <?php if ( $log_day_max > 0 ) : ?>
            <div class="trackwp-log-chart" style="display:flex; align-items:flex-end; gap:4px; height:70px; margin:16px 0; padding:8px; background:#f6f7f7; border:1px solid #dcdcde;">
                <?php foreach ( $log_per_day as $d ) : ?>
                    <div title="<?php echo esc_attr( $d['date'] . ' — ' . number_format_i18n( $d['events'] ) . ' events' ); ?>"
                         style="flex:1; min-width:6px; background:#2271b1; height:<?php echo esc_attr( max( 2, round( ( $d['events'] / $log_day_max ) * 100 ) ) ); ?>%;"></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ( $log_overview ) : ?>
            <h3><?php echo esc_html__( 'Sendte begivenheder', 'trackwp' ); ?></h3>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e( 'Begivenhed', 'trackwp' ); ?></th>
                    <th><?php esc_html_e( 'Udløst', 'trackwp' ); ?></th>
                    <th><?php esc_html_e( 'Videresendt', 'trackwp' ); ?></th>
                    <th><?php esc_html_e( 'Senest', 'trackwp' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $log_overview as $ov ) : ?>
                    <tr>
                        <td><code><?php echo esc_html( $ov['event_name'] ); ?></code></td>
                        <td><strong><?php echo esc_html( number_format_i18n( $ov['received'] ? $ov['received'] : $ov['delivered'] ) ); ?></strong></td>
                        <td>
                            <?php if ( empty( $ov['destinations'] ) ) : ?>
                                <span class="description"><?php esc_html_e( 'Ingen platform modtog denne begivenhed', 'trackwp' ); ?></span>
                            <?php endif; ?>
                            <?php foreach ( $ov['destinations'] as $dest => $counts ) : ?>
                                <?php
                                $label = isset( $dest_labels[ $dest ] ) ? $dest_labels[ $dest ] : $dest;
                                $bits  = array();
                                if ( ! empty( $counts['ok'] ) )      { $bits[] = sprintf( __( '%s leveret', 'trackwp' ), number_format_i18n( $counts['ok'] ) ); }
                                if ( ! empty( $counts['failed'] ) )  { $bits[] = sprintf( __( '%s fejlet', 'trackwp' ), number_format_i18n( $counts['failed'] ) ); }
                                if ( ! empty( $counts['skipped'] ) ) { $bits[] = sprintf( __( '%s sprunget over', 'trackwp' ), number_format_i18n( $counts['skipped'] ) ); }
                                ?>
                                <div<?php echo ! empty( $counts['failed'] ) ? ' style="color:#b32d2e;"' : ''; ?>>
                                    <?php echo esc_html( $label . ': ' . ( $bits ? implode( ', ', $bits ) : '—' ) ); ?>
                                </div>
                            <?php endforeach; ?>
                        </td>
                        <td><?php echo esc_html( $ov['last_seen'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <?php if ( $log_rows ) : ?>
            <h3><?php echo esc_html__( 'Seneste afsendelser', 'trackwp' ); ?></h3>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e( 'Tidspunkt (UTC)', 'trackwp' ); ?></th>
                    <th><?php esc_html_e( 'Begivenhed', 'trackwp' ); ?></th>
                    <th><?php esc_html_e( 'Platform', 'trackwp' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'trackwp' ); ?></th>
                    <th><?php esc_html_e( 'Samtykke', 'trackwp' ); ?></th>
                    <th><?php esc_html_e( 'Begivenheds-id', 'trackwp' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $log_rows as $row ) : ?>
                    <tr>
                        <td><?php echo esc_html( $row['logged_at'] ); ?></td>
                        <td><code><?php echo esc_html( $row['event_name'] ); ?></code></td>
                        <td><?php echo esc_html( $row['destination'] ); ?></td>
                        <td><?php echo esc_html( $row['status'] ); ?></td>
                        <td><?php
                            $flags = array();
                            if ( ! empty( $row['consent_analytics'] ) ) { $flags[] = 'statistik'; }
                            if ( ! empty( $row['consent_marketing'] ) ) { $flags[] = 'marketing'; }
                            echo esc_html( $flags ? implode( ' + ', $flags ) : '—' );
                        ?></td>
                        <td><code style="font-size:11px;"><?php echo esc_html( $row['event_id'] ); ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else : ?>
                <p><?php echo esc_html__( 'Ingen poster endnu.', 'trackwp' ); ?></p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;" onsubmit="return confirm('<?php echo esc_attr__( 'Tøm leveringsloggen?', 'trackwp' ); ?>');">
                <input type="hidden" name="action" value="trackwp_clear_delivery_log" />
                <?php wp_nonce_field( 'trackwp_clear_delivery_log' ); ?>
                <button type="submit" class="button"><?php echo esc_html__( 'Tøm leveringslog', 'trackwp' ); ?></button>
            </form>
            <?php endif; ?>

            <hr style="margin: 32px 0;">

            <h2 class="trackwp-section-title"><?php echo esc_html__( 'Import / eksport', 'trackwp' ); ?></h2>

            <?php
            // Show import status notice if redirected back.
            if ( isset( $_GET['trackwp_import'] ) ) {
                $status = sanitize_key( $_GET['trackwp_import'] );
                $messages = array(
                    'success'      => array( 'updated',  __( 'Indstillinger importeret.', 'trackwp' ) ),
                    'no_file'      => array( 'error',    __( 'Ingen fil valgt.', 'trackwp' ) ),
                    'invalid_json' => array( 'error',    __( 'Filen er ikke gyldig JSON.', 'trackwp' ) ),
                    'error'        => array( 'error',    __( 'Import fejlede — kontroller filformat.', 'trackwp' ) ),
                );
                if ( isset( $messages[ $status ] ) ) {
                    list( $cls, $msg ) = $messages[ $status ];
                    echo '<div class="notice notice-' . esc_attr( $cls ) . ' inline"><p>' . esc_html( $msg ) . '</p></div>';
                }
            }
            ?>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php echo esc_html__( 'Eksport', 'trackwp' ); ?></th>
                    <td>
                        <form method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                            <input type="hidden" name="action" value="trackwp_export" />
                            <?php wp_nonce_field( 'trackwp_export' ); ?>
                            <label style="display:block; margin-bottom: 8px;">
                                <input type="checkbox" name="include_secrets" value="1" />
                                <?php echo esc_html__( 'Inkludér API secrets (base64) — fjern hvis du deler filen', 'trackwp' ); ?>
                            </label>
                            <button type="submit" class="button"><?php echo esc_html__( 'Download JSON', 'trackwp' ); ?></button>
                        </form>
                        <p class="description">
                            <?php echo esc_html__( 'Eksporterer alle TrackWP-indstillinger som JSON-fil. Nyttigt for backup eller deployment til andre sites.', 'trackwp' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__( 'Import', 'trackwp' ); ?></th>
                    <td>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="trackwp_import" />
                            <?php wp_nonce_field( 'trackwp_import' ); ?>
                            <input type="file" name="trackwp_import_file" accept="application/json,.json" required />
                            <button type="submit" class="button"><?php echo esc_html__( 'Importér indstillinger', 'trackwp' ); ?></button>
                        </form>
                        <p class="description">
                            <?php echo esc_html__( 'Overskriver ALLE eksisterende TrackWP-indstillinger med fil-indholdet. Tag backup først.', 'trackwp' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
    </div>

</div>
