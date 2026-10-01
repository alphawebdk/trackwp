<?php
defined('ABSPATH') || exit;

/**
 * Consent profile: a single, deterministic description of what this site
 * actually does with tracking technologies, derived from the saved options.
 *
 * It is the only source for:
 *  - which vendors (third parties) are active, incl. manually declared GTM vendors,
 *  - which optional consent categories are active,
 *  - the storage declaration (cookies, localStorage, sessionStorage) with lifetimes,
 *  - the dynamic default banner texts (Danish),
 *  - whether a consent dialog is required at all,
 *  - the material hash used for the admin "platforms changed" notice and banner_hash.
 *
 * 1.10.1: NO automatic version bump. material_hash() is informational only.
 *
 * Option keys read (W7 owns the sanitizers):
 *  - trackwp_platforms: ga4_*, google_ads_*, meta_*, gtm_enabled, gtm_container_id
 *  - trackwp_advanced: first_party_cookie_enabled, cookie_name, consent_mode_cookieless_pings,
 *    uses_gtm (customer_data_sharing via TrackWP_Hash::customer_data_sharing_enabled())
 *  - trackwp_consent: controller_name, gtm_vendors, description_mode, heading, description,
 *    accept_text, reject_text, customize_text, save_text
 *  - trackwp_woocommerce: enabled
 *  - trackwp_cookie_declarations: admin-defined declarations grouped by category
 */
class TrackWP_Consent_Profile {

    /** Optional categories in display order. */
    const OPTIONAL_CATEGORIES = array('statistics', 'marketing', 'personalisation');

    /** Placeholder vendor key used when GTM is active without declared vendors. */
    const GTM_PLACEHOLDER_KEY = 'gtm_undeclared';

    /**
     * The sentence required on the first layer while Advanced Consent Mode
     * (cookieless pings) is on and a Google tag is loaded (BESLUTNINGER §4).
     *
     * @return string
     */
    public static function acm_sentence() {
        return __('Uden dit samtykke sender Google-tagget begrænsede målesignaler uden cookies (fx IP-adresse og side-URL) til Google.', 'trackwp');
    }

    /* ------------------------------------------------------------------
     * Option access
     * ------------------------------------------------------------------ */

    /** @return array */
    protected static function opt($name) {
        $v = get_option($name, array());
        return is_array($v) ? $v : array();
    }

    /**
     * Read a boolean flag with a default for a missing key.
     *
     * @param array  $arr
     * @param string $key
     * @param bool   $default
     * @return bool
     */
    protected static function flag($arr, $key, $default) {
        if (!array_key_exists($key, $arr)) {
            return (bool) $default;
        }
        return !empty($arr[$key]);
    }

    /** @return string */
    protected static function str($arr, $key) {
        return isset($arr[$key]) && is_scalar($arr[$key]) ? trim((string) $arr[$key]) : '';
    }

    /**
     * Which platforms and client-side tags are effectively active.
     *
     * @return array<string,bool>
     */
    public static function platform_state() {
        $p = self::opt('trackwp_platforms');
        $a = self::opt('trackwp_advanced');

        $gtm      = !empty($p['gtm_enabled']) && self::str($p, 'gtm_container_id') !== '';
        $uses_gtm = $gtm || !empty($a['uses_gtm']);

        $ga4 = !empty($p['ga4_enabled']) && self::str($p, 'ga4_measurement_id') !== '';

        $ads_conv_id = self::str($p, 'google_ads_conversion_id');
        $ads = !empty($p['google_ads_enabled'])
            && ($ads_conv_id !== '' || self::str($p, 'google_ads_customer_id') !== '');

        $meta = !empty($p['meta_enabled']) && self::str($p, 'meta_pixel_id') !== '';

        // Mirrors TrackWP::render_gtag_head(): gtag is skipped when GTM is used.
        $ga4_client  = $ga4 && self::flag($p, 'ga4_gtag_enabled', true) && !$uses_gtm;
        $ads_client  = $ads && (bool) preg_match('/^AW-\d{6,12}$/', $ads_conv_id) && !$uses_gtm;
        $meta_pixel  = $meta && self::flag($p, 'meta_pixel_client_enabled', true);
        $google_tag  = $ga4_client || $ads_client || $gtm;

        // Mirrors render_consent_mode_defaults(): a missing key means "on".
        $cookieless = self::flag($a, 'consent_mode_cookieless_pings', true);

        $woo = false;
        if (class_exists('WooCommerce')) {
            $woo = !empty(self::opt('trackwp_woocommerce')['enabled']);
        }

        return array(
            'ga4'                   => $ga4,
            'ga4_client'            => $ga4_client,
            'google_ads'            => $ads,
            'google_ads_client'     => $ads_client,
            'meta'                  => $meta,
            'meta_pixel'            => $meta_pixel,
            'gtm'                   => $gtm,
            'google_tag'            => $google_tag,
            'acm'                   => $google_tag && $cookieless,
            'woocommerce'           => $woo,
            'first_party_cookie'    => self::flag($a, 'first_party_cookie_enabled', true),
            'customer_data_sharing' => TrackWP_Hash::customer_data_sharing_enabled(),
        );
    }

    /** @return bool True when any platform that loads TrackWP tracking is active. */
    protected static function any_tracking($s) {
        return $s['ga4'] || $s['google_ads'] || $s['meta'] || $s['gtm'];
    }

    /* ------------------------------------------------------------------
     * Lifetimes
     * ------------------------------------------------------------------ */

    /**
     * Lifetime in days for a TrackWP-set cookie. TrackWP_Cookies::lifetime_days()
     * (W1, K7) is the single source. The fallback mirrors K7 and is only used
     * while that class is not loaded (e.g. an isolated unit run).
     *
     * @param string $name Canonical cookie name: trackwp_consent, _twp_cid, _twp_click, _fbc.
     * @return int
     */
    public static function cookie_days($name) {
        if (class_exists('TrackWP_Cookies') && method_exists('TrackWP_Cookies', 'lifetime_days')) {
            $days = TrackWP_Cookies::lifetime_days($name);
            if (is_numeric($days) && (int) $days > 0) {
                return (int) $days;
            }
        }
        switch ($name) {
            case 'trackwp_consent':
                $c = self::opt('trackwp_consent');
                $m = isset($c['cookie_lifetime_months']) ? (int) $c['cookie_lifetime_months'] : 12;
                return max(1, min(12, $m)) * 30;
            case '_twp_cid':
                $a = self::opt('trackwp_advanced');
                $m = isset($a['cookie_lifetime_months']) ? (int) $a['cookie_lifetime_months'] : 24;
                return min(400, max(1, min(24, $m)) * 30);
            default:
                return 90;
        }
    }

    /**
     * Human-readable lifetime for a TrackWP-configured cookie.
     *
     * @param string $name
     * @return string
     */
    public static function configured_lifetime_text($name) {
        $days = self::cookie_days($name);
        /* translators: %d: number of days */
        $text = sprintf(_n('%d dag', '%d dage', $days, 'trackwp'), $days);
        return sprintf(
            /* translators: %s: lifetime, e.g. "360 dage" */
            __('%s (TrackWP-konfigureret)', 'trackwp'),
            $text
        );
    }

    /**
     * Provider-controlled maximum lifetimes for third-party cookies. ONLY
     * lifetimes the provider documents officially are listed; anything else
     * is rendered as "Styret af udbyderen" without a number. TrackWP does not
     * control these. Sources: see vendor_catalog().
     *
     * _gid is deliberately absent: Google's GA4 cookie page
     * (support.google.com/analytics/answer/11397207) does not document it.
     *
     * @return array<string,string> cookie token => max lifetime text
     */
    public static function provider_lifetimes() {
        return array(
            '_ga'     => __('2 år', 'trackwp'),
            '_ga_*'   => __('2 år', 'trackwp'),
            '_gcl_au' => __('90 dage', 'trackwp'),
            '_gcl_aw' => __('90 dage', 'trackwp'),
            '_gcl_*'  => __('90 dage', 'trackwp'),
            '_fbp'    => __('90 dage', 'trackwp'),
        );
    }

    /**
     * "Styret af udbyderen (op til …)" for a provider cookie token.
     *
     * @param string $token
     * @return string
     */
    public static function provider_lifetime_text($token) {
        $map = self::provider_lifetimes();
        if (isset($map[$token])) {
            return self::up_to($map[$token]);
        }
        return __('Styret af udbyderen', 'trackwp');
    }

    /** @return string "Styret af udbyderen (op til %s)" */
    protected static function up_to($max) {
        /* translators: %s: maximum lifetime, e.g. "2 år" */
        return sprintf(__('Styret af udbyderen (op til %s)', 'trackwp'), $max);
    }

    /* ------------------------------------------------------------------
     * Vendors
     * ------------------------------------------------------------------ */

    /**
     * The ONLY vendor catalog. Used for native platforms and by W7 for the
     * manual GTM vendor selection (keys are stable).
     *
     * Legal EU entity, cookies with documented lifetimes and transfer basis
     * follow C:\tools\trackwp\VENDOR-DEKLARATIONER.md (research 2026-09-27),
     * which cites the providers' own documentation:
     *  - GA4: support.google.com/analytics/answer/11397207,
     *    marketingplatform.google.com/about/analytics/terms/gb/, policies.google.com/privacy/frameworks
     *  - Google Ads: business.safety.google/adscookies/, support.google.com/tagmanager/answer/7549390
     *  - Meta: meta.com/help/quest/681948573016913/, facebook.com/legal/terms/Privacy/Europe/,
     *    dataprivacyframework.gov/participant/4452
     *  - LinkedIn: linkedin.com/legal/l/cookie-table, linkedin.com/help/lms/answer/a423304,
     *    dataprivacyframework.gov/participant/5448
     *  - TikTok: ads.tiktok.com/help/article/using-cookies-with-tiktok-pixel,
     *    tiktok.com/legal/page/eea/privacy-policy/en (SCC; not on the DPF list)
     *  - Microsoft UET: learn.microsoft.com/en-us/advertising/msa-help/hlp_ba_conc_uet_consentfaq,
     *    microsoft.com/en-us/privacy/privacystatement, dataprivacyframework.gov/participant/6474
     *  - Contentsquare (formerly Hotjar, merged 1 July 2025):
     *    help.hotjar.com/hc/en-us/articles/36819973371409, help.hotjar.com/hc/en-us/articles/36819964438545,
     *    help.hotjar.com/hc/en-us/articles/36820023511953 (SCC)
     * Only officially documented cookies are listed (e.g. _gid, _tt_enable_cookie
     * and _uetmsclkid are not). Undocumented lifetimes read "Styret af udbyderen".
     * The research recommends re-validation every 6-12 months.
     *
     * 'transfer_basis': 'dpf' (EU-US Data Privacy Framework), 'scc' (standard
     * contractual clauses), 'none' (no transfer outside the EU/EEA) or 'other'
     * (unresolved or not DPF; the banner then never claims DPF).
     *
     * 1.11.0 (PLAN-1.11.0-v2 KB5): every entry also carries
     *  - 'signatures' => array(handles, urls, hosts, inline, pixels), see catalog_signatures(),
     *  - 'server_side_note' => '' or a Danish note about server-to-server traffic that
     *    cannot be blocked in the browser (scan status cannot_serverside).
     * New vendors (leadinfo, klaviyo, jetpack, wc_order_attribution, sleeknote) follow
     * VENDOR-DEKLARATIONER.md "Tillæg 1.11.0" (research 2026-09-29), verified items only:
     *  - Leadinfo: leadinfo.com/en/legal/privacy/, help.leadinfo.com/en/what-cookies-are-created-by-leadinfo,
     *    help.leadinfo.com/en/cookieless-tracking. Transfer mechanism not named: "uafklaret".
     *  - Klaviyo: help.klaviyo.com/hc/en-us/articles/360034666712,
     *    klaviyo.com/legal/privacy/privacy-notice-12-31-2024, dataprivacyframework.gov/participant/6149.
     *  - Jetpack/WooCommerce Analytics: automattic.com/privacy/, automattic.com/cookies/,
     *    jetpack.com/support/cookies/, wpvip.com/privacyframework/ (Automattic's DPF,
     *    participant/4709, excludes Jetpack and WooCommerce: SCC only).
     *  - Sleeknote: sleeknote.com/gdpr (Sleeknote ApS, CVR 35840699). Cookie names and
     *    transfer are unverified (help.sleeknote.com HTTP 403): "uafklaret".
     *  - Order attribution: woocommerce.com/document/order-attribution-tracking/ (sbjs_*,
     *    first-party, 30 minutes/session, nothing sent to another domain).
     *
     * @return array<string,array>
     */
    public static function vendor_catalog() {
        $dpf     = __('USA (EU-US Data Privacy Framework)', 'trackwp');
        $ads     = __('Måling af annoncer samt tilpasning og målretning af annoncer', 'trackwp');
        $managed = __('Styret af udbyderen', 'trackwp');
        $unknown = self::unresolved();
        $catalog = array(
            'ga4' => array(
                'key'            => 'ga4',
                'name'           => 'Google Analytics 4',
                'provider'       => 'Google Ireland Limited',
                'recipient'      => 'Google',
                'category'       => 'statistics',
                'cookies'        => '_ga, _ga_*',
                'purpose'        => __('Statistik over, hvordan websitet bruges', 'trackwp'),
                'lifetime'       => self::provider_lifetime_text('_ga'),
                'transfer'       => $dpf,
                'transfer_basis' => 'dpf',
            ),
            'google_ads' => array(
                'key'            => 'google_ads',
                'name'           => 'Google Ads',
                'provider'       => 'Google Ireland Limited',
                'recipient'      => 'Google',
                'category'       => 'marketing',
                'cookies'        => '_gcl_au, _gcl_aw, _gcl_gs, _gcl_gb, _gcl_ag, _gcl_dc, _gcl_ls',
                'purpose'        => $ads,
                /* translators: %s: "Styret af udbyderen (op til 90 dage)" */
                'lifetime'       => sprintf(__('%s. _gcl_ls (lokal lagring): styret af udbyderen', 'trackwp'), self::provider_lifetime_text('_gcl_*')),
                'transfer'       => $dpf,
                'transfer_basis' => 'dpf',
            ),
            'meta' => array(
                'key'            => 'meta',
                'name'           => 'Meta (Facebook og Instagram)',
                'provider'       => 'Meta Platforms Ireland Limited',
                'recipient'      => 'Meta',
                'category'       => 'marketing',
                'cookies'        => '_fbp, _fbc',
                'purpose'        => $ads,
                /* translators: %s: "Styret af udbyderen (op til 90 dage)" */
                'lifetime'       => sprintf(__('_fbp: %s. _fbc: styret af udbyderen', 'trackwp'), self::provider_lifetime_text('_fbp')),
                'transfer'       => $dpf,
                'transfer_basis' => 'dpf',
            ),
            'linkedin' => array(
                'key'            => 'linkedin',
                'name'           => 'LinkedIn Insight Tag',
                'provider'       => 'LinkedIn Ireland Unlimited Company',
                'recipient'      => 'LinkedIn',
                'category'       => 'marketing',
                'cookies'        => 'li_fat_id, lidc, bcookie, li_sugr, UserMatchHistory, AnalyticsSyncHistory, ln_or',
                'purpose'        => $ads,
                'lifetime'       => self::up_to(__('1 år', 'trackwp')),
                'transfer'       => $dpf,
                'transfer_basis' => 'dpf',
            ),
            'tiktok' => array(
                'key'            => 'tiktok',
                'name'           => 'TikTok Pixel',
                'provider'       => 'TikTok Technology Limited (Irland) og TikTok Information Technologies UK Limited',
                'recipient'      => 'TikTok',
                'category'       => 'marketing',
                'cookies'        => '_ttp, ttcsid, ttcsid_*, ttclid, _pangle',
                'purpose'        => $ads,
                'lifetime'       => self::up_to(__('13 måneder', 'trackwp')),
                'transfer'       => __('Bl.a. USA, Malaysia og Singapore (EU\'s standardkontraktbestemmelser, SCC)', 'trackwp'),
                'transfer_basis' => 'scc',
            ),
            'microsoft_ads' => array(
                'key'            => 'microsoft_ads',
                'name'           => 'Microsoft Advertising (UET)',
                'provider'       => 'Microsoft Ireland Operations Limited',
                'recipient'      => 'Microsoft',
                'category'       => 'marketing',
                'cookies'        => 'MUID, _uetsid, _uetvid, _uetsid_exp, _uetvid_exp, MSPTC',
                'purpose'        => $ads,
                'lifetime'       => $managed,
                'transfer'       => $dpf,
                'transfer_basis' => 'dpf',
            ),
            'hotjar' => array(
                'key'            => 'hotjar',
                'name'           => __('Contentsquare (tidligere Hotjar)', 'trackwp'),
                'provider'       => __('Content Square SAS, Frankrig (Contentsquare-koncernen, som Hotjar Ltd. er fusioneret ind i)', 'trackwp'),
                'recipient'      => 'Contentsquare',
                'category'       => 'statistics',
                'cookies'        => '_hjSessionUser_*, _hjSession_*, _hjTLDTest, _hjid, _hjFirstSeen, _hjUserAttributesHash, _hjCachedUserAttributes, _hjViewportId, _hjSessionTooLarge, _hjSessionRejected, _hjSessionResumed, _hjIncludedInPageviewSample, _hjIncludedInSessionSample, _hjAbsoluteSessionInProgress, _hjRecordingEnabled, _hjClosedSurveyInvites, _hjDonePolls, _hjMinimizedPolls, _hjShownFeedbackMessage, _hjLocalStorageTest, _hjRecordingLastActivity',
                'purpose'        => __('Analyse af, hvordan besøgende bruger siderne', 'trackwp'),
                'lifetime'       => self::up_to(__('365 dage', 'trackwp')),
                'transfer'       => __('Primært EU. Enkelte oplysninger kan overføres til USA (EU\'s standardkontraktbestemmelser, SCC)', 'trackwp'),
                'transfer_basis' => 'scc',
            ),
            'leadinfo' => array(
                'key'            => 'leadinfo',
                'name'           => 'Leadinfo',
                'provider'       => __('Leadinfo B.V., Nederlandene', 'trackwp'),
                'recipient'      => 'Leadinfo',
                'category'       => 'marketing',
                // Leadinfo may also run cookieless, depending on the site's own Leadinfo setting.
                'cookies'        => '_li_id.*, _li_ses.*',
                'purpose'        => __('Identifikation af virksomheder, der besøger websitet (B2B-leadgenerering)', 'trackwp'),
                'lifetime'       => __('_li_id.*: 2 år. _li_ses.*: session. Kan køre uden cookies, afhængigt af opsætningen hos Leadinfo', 'trackwp'),
                // Leadinfo only states "appropriate safeguards"; the mechanism is not named.
                'transfer'       => $unknown,
                'transfer_basis' => 'other',
            ),
            'klaviyo' => array(
                'key'              => 'klaviyo',
                'name'             => 'Klaviyo',
                'provider'         => __('Klaviyo, Inc. (USA), fælles ansvarlig med Klaviyo Ltd og Klaviyo Ireland Limited', 'trackwp'),
                'recipient'        => 'Klaviyo',
                'category'         => 'marketing',
                'cookies'          => '__kla_id',
                'purpose'          => __('E-mailmarkedsføring, tilmeldingsformularer og målretning', 'trackwp'),
                'lifetime'         => self::up_to(__('2 år, begrænset af browseren', 'trackwp')),
                'transfer'         => $dpf,
                'transfer_basis'   => 'dpf',
                'server_side_note' => __('Klaviyo-pluginet kan sende oplysninger direkte fra serveren til Klaviyo. Det kan ikke blokeres i browseren.', 'trackwp'),
            ),
            'jetpack' => array(
                'key'              => 'jetpack',
                'name'             => __('Jetpack Stats og WooCommerce Analytics', 'trackwp'),
                'provider'         => __('Aut O\'Mattic A8C Ireland Ltd. (Jetpack) og WooCommerce Ireland Ltd. (WooCommerce Analytics), Irland', 'trackwp'),
                'recipient'        => 'Automattic',
                'category'         => 'statistics',
                'cookies'          => 'tk_ai, tk_qs, tk_ni, tk_*',
                'purpose'          => __('Statistik over besøg og butikshændelser', 'trackwp'),
                'lifetime'         => __('tk_ai og tk_qs: session. Øvrige: styret af udbyderen', 'trackwp'),
                // Automattic's DPF certification excludes Jetpack and WooCommerce: SCC only.
                'transfer'         => __('USA (EU\'s standardkontraktbestemmelser, SCC)', 'trackwp'),
                'transfer_basis'   => 'scc',
                'server_side_note' => __('WooCommerce Analytics kan sende hændelser fra serveren. Det kan ikke blokeres i browseren.', 'trackwp'),
            ),
            'wc_order_attribution' => array(
                'key'            => 'wc_order_attribution',
                'name'           => __('WooCommerce ordreattribution', 'trackwp'),
                'provider'       => __('Dette website (førsteparts)', 'trackwp'),
                'recipient'      => '',
                // Default marketing (WooCommerce #59611); the admin may change it per rule.
                'category'       => 'marketing',
                'cookies'        => 'sbjs_*',
                'purpose'        => __('Registrerer, hvor besøget kom fra (fx en kampagne eller en henvisning), så en ordre kan knyttes til kilden', 'trackwp'),
                'lifetime'       => __('30 minutter (session)', 'trackwp'),
                'transfer'       => '',
                'transfer_basis' => 'none',
            ),
            'sleeknote' => array(
                'key'            => 'sleeknote',
                'name'           => 'Sleeknote',
                'provider'       => __('Sleeknote ApS, Danmark', 'trackwp'),
                'recipient'      => 'Sleeknote',
                'category'       => 'marketing',
                // Cookie names could not be verified in an official source.
                'cookies'        => $unknown,
                'purpose'        => __('Pop-ups og tilmeldingsformularer til markedsføring', 'trackwp'),
                'lifetime'       => $managed,
                'transfer'       => $unknown,
                'transfer_basis' => 'other',
            ),
        );

        $catalog['meta']['server_side_note'] = __('Meta for WooCommerce kan sende hændelser direkte fra serveren (Conversions API). Det kan ikke blokeres i browseren.', 'trackwp');

        $signatures = self::catalog_signatures();
        foreach ($catalog as $key => $entry) {
            $catalog[$key]['signatures'] = isset($signatures[$key]) ? $signatures[$key] : self::empty_signatures();
            $catalog[$key] += array('server_side_note' => '');
        }
        return $catalog;
    }

    /** @return string The Danish marker for data without a verified source. */
    public static function unresolved() {
        return __('uafklaret', 'trackwp');
    }

    /** @return array Signature structure with every list present and empty. */
    protected static function empty_signatures() {
        return array('handles' => array(), 'urls' => array(), 'hosts' => array(), 'inline' => array(), 'pixels' => array());
    }

    /**
     * Blocker signatures per catalog vendor (KB5). Only read through vendor_catalog().
     *  - handles: WordPress script handles (matched via script_loader_tag and id="<handle>-js*").
     *  - urls / pixels: host + path prefix, no scheme and no query.
     *  - hosts: host, matched on domain boundaries.
     *  - inline: markers ([A-Za-z0-9._-]{8,64}) searched in inline scripts without src.
     * ga4 and google_ads have none (status gtm_consent_mode).
     *
     * Sources:
     *  - meta, leadinfo, klaviyo, jetpack, wc_order_attribution, sleeknote: live HTML
     *    live-test/adashofmagic/fe2-source-home.html (fb4woo 3.7.6: lines 667-673, 949-959,
     *    4455, 4505, 4531; noscript facebook.com/tr; Leadinfo 4124; Klaviyo 4416 and 4516;
     *    Jetpack 381 and 4518-4523; Sleeknote 458-465; sourcebuster-js / wc-order-attribution).
     *  - Meta param builder on jsdelivr: fb4woo latest, facebook-commerce-events-tracker.php:37
     *    (cdn.jsdelivr.net/npm/meta-capi-param-builder-clientjs@1.3.2, unpkg as fallback :40).
     *  - Hosts per VENDOR-DEKLARATIONER.md "Tillæg 1.11.0" del B: LinkedIn
     *    linkedin.com/help/lms/answer/a425696; Microsoft UET hlp_BA_CONC_UETv2CSP.md
     *    (bat.bing.com); Hotjar help.hotjar.com/hc/en-us/articles/36820026388881 and
     *    docs.contentsquare.com/en/web/content-security-policy/ (t.contentsquare.net);
     *    Leadinfo help.leadinfo.com/en/why-is-the-leadinfo-tracker-blocked-on-my-website;
     *    Klaviyo help.klaviyo.com/hc/en-us/articles/115005076767 and /360034666712.
     *  - UNVERIFIED, kept: TikTok analytics.tiktok.com (TikTok does not publish the pixel
     *    code; consistent in independent reviews and not in official help text) and Sleeknote
     *    sleeknotecustomerscripts.sleeknote.com (official CSP page only seen as a search
     *    snippet, but also present in the live HTML).
     *
     * @return array<string,array>
     */
    protected static function catalog_signatures() {
        $s = array(
            'meta' => array(
                'handles' => array('wc-facebook-pixel-events', 'facebook-capi-param-builder', 'facebook-for-woocommerce-inline', 'wc-facebook-signals'),
                'urls'    => array('unpkg.com/meta-capi-param-builder-clientjs', 'cdn.jsdelivr.net/npm/meta-capi-param-builder-clientjs'),
                'hosts'   => array('connect.facebook.net'),
                'inline'  => array('connect.facebook.net', 'FacebookSignals'),
                'pixels'  => array('www.facebook.com/tr'),
            ),
            'tiktok' => array(
                'hosts'  => array('analytics.tiktok.com'),
                'inline' => array('analytics.tiktok.com'),
            ),
            'linkedin' => array(
                'hosts'  => array('snap.licdn.com'),
                'inline' => array('_linkedin_partner_id', 'snap.licdn.com'),
                'pixels' => array('px.ads.linkedin.com/collect'),
            ),
            'microsoft_ads' => array(
                'hosts'  => array('bat.bing.com'),
                'inline' => array('bat.bing.com'),
                'pixels' => array('bat.bing.com/action'),
            ),
            'hotjar' => array(
                'hosts'  => array('static.hotjar.com', 'script.hotjar.com', 't.contentsquare.net'),
                'inline' => array('static.hotjar.com'),
            ),
            'leadinfo' => array(
                'hosts'  => array('cdn.leadinfo.net', 'collector.leadinfo.net'),
                'inline' => array('cdn.leadinfo.net'),
            ),
            'klaviyo' => array(
                'handles' => array('kl-identify-browser'),
                'hosts'   => array('static.klaviyo.com', 'static-tracking.klaviyo.com'),
                'inline'  => array('static.klaviyo.com'),
            ),
            'jetpack' => array(
                'handles' => array('jetpack-stats', 'woocommerce-analytics', 'woocommerce-analytics-client'),
                'hosts'   => array('stats.wp.com'),
                'pixels'  => array('pixel.wp.com/g.gif'),
            ),
            'wc_order_attribution' => array(
                'handles' => array('sourcebuster-js', 'wc-order-attribution'),
            ),
            'sleeknote' => array(
                'hosts'  => array('sleeknotecustomerscripts.sleeknote.com'),
                'inline' => array('sleeknotecustomerscripts.sleeknote.com'),
            ),
        );
        foreach ($s as $key => $sig) {
            $s[$key] = array_merge(self::empty_signatures(), $sig);
        }
        return $s;
    }

    /**
     * Normalise one admin-entered custom vendor (GTM vendors use 'gtm_', the
     * blocker's custom_vendors use 'blk_'). Shared so both declare identically.
     *
     * @param mixed  $entry  {name, provider, category, cookies, purpose, lifetime, transfer}
     * @param string $prefix Key prefix.
     * @return array|null Vendor entry, or null when the name or category is invalid.
     */
    public static function normalize_custom_vendor($entry, $prefix) {
        if (!is_array($entry) || self::str($entry, 'name') === '') {
            return null;
        }
        $cat = self::str($entry, 'category');
        if (!in_array($cat, self::OPTIONAL_CATEGORIES, true)) {
            return null;
        }
        $name     = self::str($entry, 'name');
        $provider = self::str($entry, 'provider');
        $key      = $prefix . sanitize_key($name);

        // 1.11.1 F3 (KC14): an empty transfer field must not silently read as
        // "no transfer" (transfer_basis 'none' asserts something the admin
        // never confirmed). Only an explicit "Ingen"/"none" does that.
        $transfer_raw = self::str($entry, 'transfer');
        $lower        = strtolower($transfer_raw);
        if ($transfer_raw === '') {
            $transfer       = self::unresolved();
            $transfer_basis = 'other';
        } elseif (in_array($lower, array('ingen', 'ingen overførsel', 'none'), true)) {
            $transfer       = $transfer_raw;
            $transfer_basis = 'none';
        } elseif (stripos($transfer_raw, 'Data Privacy Framework') !== false || preg_match('/\bDPF\b/', $transfer_raw)) {
            $transfer       = $transfer_raw;
            $transfer_basis = 'dpf';
        } else {
            $transfer       = $transfer_raw;
            $transfer_basis = 'other';
        }

        return array(
            'key'            => $key,
            'name'           => $name,
            'provider'       => $provider,
            'recipient'      => $provider !== '' ? $provider : $name,
            'category'       => $cat,
            'cookies'        => self::str($entry, 'cookies'),
            'purpose'        => self::str($entry, 'purpose'),
            'lifetime'       => self::str($entry, 'lifetime') !== '' ? self::str($entry, 'lifetime') : __('Styret af udbyderen', 'trackwp'),
            'transfer'       => $transfer,
            'transfer_basis' => $transfer_basis,
        );
    }

    /**
     * Normalise the manual GTM vendor option (structure owned by W7):
     *   trackwp_consent['gtm_vendors'] = array(
     *     'known'  => string[] keys of vendor_catalog(),
     *     'custom' => array[] {name, provider, category, cookies, purpose, lifetime, transfer},
     *   )
     *
     * @return array[]
     */
    public static function gtm_vendors() {
        $c   = self::opt('trackwp_consent');
        $opt = isset($c['gtm_vendors']) && is_array($c['gtm_vendors']) ? $c['gtm_vendors'] : array();
        $known  = isset($opt['known']) && is_array($opt['known']) ? $opt['known'] : array();
        $custom = isset($opt['custom']) && is_array($opt['custom']) ? $opt['custom'] : array();
        $catalog = self::vendor_catalog();
        $out = array();
        foreach ($known as $key) {
            if (is_string($key) && isset($catalog[$key])) {
                $out[$key] = $catalog[$key];
            }
        }
        foreach ($custom as $entry) {
            $v = self::normalize_custom_vendor($entry, 'gtm_');
            if ($v !== null) {
                $out[$v['key']] = $v;
            }
        }
        return array_values($out);
    }

    /**
     * Vendors found by the blocker scan (option trackwp_blocker_scan, KB3) plus
     * the blocker's custom_vendors (option trackwp_blocker, KB1), REGARDLESS of
     * the block toggle and of the blocker mode: they run on the site either way.
     * The category comes from the rule (KB1 rules[rule_id].category), else the
     * catalog; the vendor from the rule, else the scan item. Missing fields
     * read unresolved().
     *
     * Each entry gets via_blocker=true and blocked=bool (true only when every
     * rule of that vendor has block=true).
     *
     * 1.11.1 F1 (KC12): scan rows of kind 'server_side', or 'server_cookie'
     * without a rule_id, declare the vendor's presence but never toggle
     * $blocked (there is no browser rule to block them by). A vendor that is
     * found ONLY through such rows gets server_only=true.
     *
     * @return array<string,array> keyed by vendor key, in scan order.
     */
    public static function blocker_vendors() {
        $blocker = self::opt('trackwp_blocker');
        $scan    = self::opt('trackwp_blocker_scan');
        $rules   = isset($blocker['rules']) && is_array($blocker['rules']) ? $blocker['rules'] : array();
        $items   = isset($scan['items']) && is_array($scan['items']) ? $scan['items'] : array();
        $custom  = isset($blocker['custom_vendors']) && is_array($blocker['custom_vendors']) ? $blocker['custom_vendors'] : array();
        $catalog = self::vendor_catalog();
        $cats    = array_merge(array('necessary'), self::OPTIONAL_CATEGORIES);
        $unknown = self::unresolved();

        $customs = array();
        foreach ($custom as $entry) {
            $v = self::normalize_custom_vendor($entry, 'blk_');
            if ($v !== null) {
                $customs[$v['key']] = $v;
            }
        }

        $out         = array();
        $blocked     = array();
        $server_only = array();
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $rule_id = self::str($item, 'rule_id');
            $rule    = ($rule_id !== '' && isset($rules[$rule_id]) && is_array($rules[$rule_id])) ? $rules[$rule_id] : array();
            $key     = self::str($rule, 'vendor') !== '' ? self::str($rule, 'vendor') : self::str($item, 'vendor');
            $key     = sanitize_key($key);
            if ($key === '' || $key === 'uafklaret' || $key === 'unresolved') {
                continue;
            }
            $kind             = self::str($item, 'kind');
            $server_decl      = ($kind === 'server_side') || ($kind === 'server_cookie' && $rule_id === '');
            $known_vendor_key = isset($catalog[$key]) || isset($customs[$key]);

            // M3: a server_cookie row without an explicit cookie: rule (KC2)
            // may carry the scanner's display-only text in 'vendor' (a cookie
            // provider name such as "Webserver (PHP)", not a catalog key,
            // TrackWP_Blocker_Scanner::server_cookie_items()). Inventing an
            // unknown-vendor entry from that text would sanitize_key() it
            // into a phantom duplicate. Only a row that already resolves to a
            // real catalog key or a declared custom vendor is accepted here;
            // every other row kind keeps the existing "declare it honestly as
            // unresolved" fallback below, which IS intentional for genuine
            // signature findings (script/inline/server_side) with an unknown key.
            if ($kind === 'server_cookie' && $rule_id === '' && !$known_vendor_key) {
                continue;
            }

            if (!isset($server_only[$key])) {
                $server_only[$key] = true;
            }
            if (!$server_decl) {
                $server_only[$key] = false;
                $is_blocked    = !empty($rule['block']);
                $blocked[$key] = isset($blocked[$key]) ? ($blocked[$key] && $is_blocked) : $is_blocked;
            }
            if (isset($out[$key])) {
                continue;
            }
            if (isset($catalog[$key])) {
                $v = $catalog[$key];
            } elseif (isset($customs[$key])) {
                $v = $customs[$key];
            } else {
                // A vendor key the catalog does not know: declare it honestly as unresolved.
                $v = array(
                    'key'            => $key,
                    'name'           => $key,
                    'provider'       => $unknown,
                    'recipient'      => $key,
                    'category'       => 'marketing',
                    'cookies'        => $unknown,
                    'purpose'        => $unknown,
                    'lifetime'       => __('Styret af udbyderen', 'trackwp'),
                    'transfer'       => $unknown,
                    'transfer_basis' => 'other',
                );
            }
            $rule_cat = self::str($rule, 'category');
            if (in_array($rule_cat, $cats, true)) {
                $v['category'] = $rule_cat;
            }
            $out[$key] = $v;
        }
        foreach ($customs as $key => $v) {
            if (!isset($out[$key])) {
                $out[$key] = $v;
            }
        }
        foreach ($out as $key => $v) {
            foreach (array('provider', 'cookies', 'purpose', 'lifetime') as $f) {
                if (!isset($v[$f]) || $v[$f] === '') {
                    $v[$f] = $f === 'lifetime' ? __('Styret af udbyderen', 'trackwp') : $unknown;
                }
            }
            $v['via_blocker'] = true;
            $v['blocked']     = !empty($blocked[$key]);
            $v['server_only'] = !empty($server_only[$key]);
            $out[$key]        = $v;
        }
        return $out;
    }

    /**
     * Keys of vendors found by the blocker in an optional category that are
     * not blocked (warning 'unblocked_vendors'). Excludes server_only vendors
     * (KC12 F1): there is no browser rule that could block them, so listing
     * them here would be misleading.
     *
     * @return string[]
     */
    public static function unblocked_vendors() {
        $keys = array();
        foreach (self::active_vendors() as $key => $v) {
            if (!empty($v['via_blocker']) && empty($v['blocked']) && empty($v['server_only']) && $v['category'] !== 'necessary') {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    /**
     * Display-name warning lists for the admin notice (KC12 F1). 'not_selected'
     * mirrors unblocked_vendors() (browser-blockable, found, but not chosen to
     * block). 'server_side' lists vendors that can never be blocked in the
     * browser (server_only=true), so blocking has to happen elsewhere (a
     * server-side cookie rule, a plugin setting) or is not possible at all.
     *
     * 'meta' is dropped from 'server_side' when TrackWP itself is delivering
     * Meta and has suppressed Meta for WooCommerce's own server delivery
     * (TrackWP_Meta_Takeover::status()['fb4woo_suppressed']): in that case the
     * "cannot be blocked server-side" warning would be about traffic TrackWP
     * already stopped. W5 owns that class and key; both are read defensively
     * because the two workstreams change in parallel.
     *
     * @return array{not_selected: string[], server_side: string[]}
     */
    public static function blocker_warning_vendors() {
        $suppressed = false;
        if (class_exists('TrackWP_Meta_Takeover') && method_exists('TrackWP_Meta_Takeover', 'status')) {
            $status     = TrackWP_Meta_Takeover::status();
            $suppressed = is_array($status) && isset($status['fb4woo_suppressed']) && $status['fb4woo_suppressed'];
        }

        $not_selected = array();
        $server_side  = array();
        foreach (self::active_vendors() as $key => $v) {
            if (empty($v['via_blocker']) || $v['category'] === 'necessary') {
                continue;
            }
            if (!empty($v['server_only'])) {
                if ($key === 'meta' && $suppressed) {
                    continue;
                }
                $server_side[] = $v['name'];
                continue;
            }
            if (empty($v['blocked'])) {
                $not_selected[] = $v['name'];
            }
        }
        return array(
            'not_selected' => $not_selected,
            'server_side'  => $server_side,
        );
    }

    /**
     * Active third-party vendors, keyed by vendor key, in a stable order.
     * Each: key, name, provider, recipient, category, cookies, purpose,
     * lifetime, transfer, placeholder (bool), via_gtm (bool), via_blocker (bool),
     * blocked (bool). Order: native, GTM, blocker findings (blocker_vendors()).
     *
     * @return array<string,array>
     */
    public static function active_vendors() {
        $s       = self::platform_state();
        $catalog = self::vendor_catalog();
        $out     = array();

        if ($s['ga4']) {
            $v = $catalog['ga4'];
            if (!$s['ga4_client']) {
                // Server-side only: GA4 sets no cookies; the TrackWP id is declared separately.
                $v['cookies']  = '';
                $v['lifetime'] = '';
            }
            $out['ga4'] = $v;
        }
        if ($s['google_ads']) {
            $v = $catalog['google_ads'];
            if (!$s['google_ads_client']) {
                $v['cookies']  = '';
                $v['lifetime'] = '';
            }
            $out['google_ads'] = $v;
        }
        if ($s['meta']) {
            $v = $catalog['meta'];
            // _fbc is declared as TrackWP storage (K7: set by TrackWP, 90 days).
            $v['cookies']  = '_fbp';
            $v['lifetime'] = self::provider_lifetime_text('_fbp');
            if (!$s['meta_pixel']) {
                // Conversions API only: Meta sets no cookies in the browser.
                $v['cookies']  = '';
                $v['lifetime'] = '';
            }
            $out['meta'] = $v;
        }

        if ($s['gtm']) {
            $gtm = self::gtm_vendors();
            if (empty($gtm)) {
                $out[self::GTM_PLACEHOLDER_KEY] = array(
                    'key'         => self::GTM_PLACEHOLDER_KEY,
                    'name'        => __('Tjenester indlæst via Google Tag Manager', 'trackwp'),
                    'provider'    => __('Ikke angivet af webstedets ejer', 'trackwp'),
                    'recipient'   => '',
                    'category'    => 'marketing',
                    'cookies'     => '',
                    'purpose'     => __('Webstedets ejer har endnu ikke angivet, hvilke tjenester der indlæses via Google Tag Manager.', 'trackwp'),
                    'lifetime'    => __('Styret af udbyderen', 'trackwp'),
                    'transfer'    => '',
                    'placeholder' => true,
                );
            }
            foreach ($gtm as $v) {
                // A native platform wins; GTM only adds what is not already declared.
                if (!isset($out[$v['key']]) || $out[$v['key']]['cookies'] === '') {
                    $v['via_gtm'] = true;
                    $out[$v['key']] = $v;
                }
            }
        }

        // Blocker scan findings and custom vendors (1.11.0, §3.6): native and GTM win.
        foreach (self::blocker_vendors() as $key => $v) {
            if (!isset($out[$key]) || $out[$key]['cookies'] === '') {
                $out[$key] = $v;
            }
        }

        foreach ($out as $k => $v) {
            $out[$k] += array('placeholder' => false, 'via_gtm' => false, 'via_blocker' => false, 'blocked' => false, 'server_only' => false);
        }
        return apply_filters('trackwp_consent_profile_vendors', $out, $s);
    }

    /**
     * Admin-facing warnings (W7 renders them as notices).
     *
     * @return string[] Warning codes: 'gtm_vendors_missing', 'controller_missing',
     *                  'unblocked_vendors' (see unblocked_vendors()).
     */
    public static function warnings() {
        $w = array();
        if (isset(self::active_vendors()[self::GTM_PLACEHOLDER_KEY])) {
            $w[] = 'gtm_vendors_missing';
        }
        if (self::unblocked_vendors()) {
            $w[] = 'unblocked_vendors';
        }
        if (self::str(self::opt('trackwp_consent'), 'controller_name') === '') {
            $w[] = 'controller_missing';
        }
        return $w;
    }

    /* ------------------------------------------------------------------
     * Storage declarations
     * ------------------------------------------------------------------ */

    /**
     * First-party storage used by TrackWP (and _gid when present on the device).
     *
     * @param string[]|null $present_cookies Cookie names on the device. null = $_COOKIE.
     *                                       Pass array() for a device-independent result.
     * @return array[] Each: name, cookies, type, category, provider, purpose, lifetime,
     *                 lifetime_source ('configured'|'provider'|'browser'), transfer.
     */
    public static function storage_declarations($present_cookies = null) {
        if ($present_cookies === null) {
            $present_cookies = is_array($_COOKIE) ? array_keys($_COOKIE) : array();
        }
        $s        = self::platform_state();
        $vendors  = self::active_vendors();
        $tracking = self::any_tracking($s);
        $stats    = self::vendor_categories_have($vendors, 'statistics') || ($tracking && $s['first_party_cookie']);
        // Storage that is written with analytics OR marketing consent is declared
        // under statistics when that category exists, otherwise under marketing.
        $either   = $stats ? 'statistics' : 'marketing';
        $a        = self::opt('trackwp_advanced');
        $cid_name = self::str($a, 'cookie_name') !== '' ? self::str($a, 'cookie_name') : '_twp_cid';
        $site     = __('Dette website (førsteparts)', 'trackwp');
        $out      = array();

        $out[] = array(
            'name'            => __('Cookie-samtykke', 'trackwp'),
            'cookies'         => 'trackwp_consent',
            'type'            => 'cookie',
            'category'        => 'necessary',
            'provider'        => $site,
            'purpose'         => __('Husker dit cookievalg, tidspunktet og dit samtykke-ID', 'trackwp'),
            'lifetime'        => self::configured_lifetime_text('trackwp_consent'),
            'lifetime_source' => 'configured',
            'transfer'        => '',
        );

        if ($tracking && $s['first_party_cookie']) {
            $out[] = array(
                'name'            => __('Førsteparts-id', 'trackwp'),
                'cookies'         => $cid_name,
                'type'            => 'cookie',
                'category'        => 'statistics',
                'provider'        => $site,
                'purpose'         => __('Genkender din browser ved gentagne besøg, så besøg kan tælles korrekt', 'trackwp'),
                'lifetime'        => self::configured_lifetime_text('_twp_cid'),
                'lifetime_source' => 'configured',
                'transfer'        => '',
            );
        }

        if ($tracking && $stats) {
            $out[] = array(
                'name'            => __('Session-id', 'trackwp'),
                'cookies'         => 'trackwp_sid',
                'type'            => 'sessionStorage',
                'category'        => 'statistics',
                'provider'        => $site,
                'purpose'         => __('Samler sidevisninger fra samme besøg i én session', 'trackwp'),
                'lifetime'        => __('Indtil fanebladet lukkes (TrackWP-konfigureret)', 'trackwp'),
                'lifetime_source' => 'configured',
                'transfer'        => '',
            );
        }

        if ($tracking) {
            $out[] = array(
                'name'            => __('Tidspunkt for fornyelse', 'trackwp'),
                'cookies'         => 'trackwp_ka_ts',
                'type'            => 'localStorage',
                'category'        => $either,
                'provider'        => $site,
                'purpose'         => __('Husker, hvornår målecookies sidst blev fornyet, så det højst sker én gang i timen', 'trackwp'),
                'lifetime'        => __('Indtil du sletter browserdata (TrackWP-konfigureret)', 'trackwp'),
                'lifetime_source' => 'browser',
                'transfer'        => '',
            );
        }

        if ($tracking && $s['woocommerce']) {
            $out[] = array(
                'name'            => __('Registrerede køb', 'trackwp'),
                'cookies'         => 'trackwp_woo_purchases',
                'type'            => 'localStorage',
                'category'        => $either,
                'provider'        => $site,
                'purpose'         => __('Husker op til 20 ordrenumre, så det samme køb ikke tælles to gange', 'trackwp'),
                'lifetime'        => __('Indtil du sletter browserdata (TrackWP-konfigureret)', 'trackwp'),
                'lifetime_source' => 'browser',
                'transfer'        => '',
            );
        }

        if ($s['google_ads']) {
            $out[] = array(
                'name'            => __('Annonceklik', 'trackwp'),
                'cookies'         => '_twp_click',
                'type'            => 'cookie',
                'category'        => 'marketing',
                'provider'        => $site,
                'purpose'         => __('Gemmer klik-id fra Google-annoncer (gclid, gbraid, wbraid), så et køb kan knyttes til annoncen', 'trackwp'),
                'lifetime'        => self::configured_lifetime_text('_twp_click'),
                'lifetime_source' => 'configured',
                'transfer'        => '',
            );
        }

        if ($s['meta']) {
            $out[] = array(
                'name'            => __('Meta-annonceklik', 'trackwp'),
                'cookies'         => '_fbc',
                'type'            => 'cookie',
                'category'        => 'marketing',
                'provider'        => $site,
                'purpose'         => __('Gemmer klik-id fra Meta-annoncer (fbclid), så et køb kan knyttes til annoncen', 'trackwp'),
                'lifetime'        => self::configured_lifetime_text('_fbc'),
                'lifetime_source' => 'configured',
                'transfer'        => '',
            );
        }

        if (in_array('_gid', (array) $present_cookies, true)) {
            $out[] = array(
                'name'            => 'Google Analytics',
                'cookies'         => '_gid',
                'type'            => 'cookie',
                'category'        => 'statistics',
                'provider'        => 'Google Ireland Limited',
                'purpose'         => __('Google Analytics-cookie fundet på enheden (ikke dokumenteret af Google som GA4-standardcookie)', 'trackwp'),
                'lifetime'        => self::provider_lifetime_text('_gid'),
                'lifetime_source' => 'provider',
                'transfer'        => __('USA (EU-US Data Privacy Framework)', 'trackwp'),
            );
        }

        return apply_filters('trackwp_consent_profile_storage', $out, $s);
    }

    /** @return bool */
    protected static function vendor_categories_have($vendors, $cat) {
        foreach ($vendors as $v) {
            if (isset($v['category']) && $v['category'] === $cat) {
                return true;
            }
        }
        return false;
    }

    /**
     * Admin-defined declarations (option trackwp_cookie_declarations).
     *
     * @return array<string,array[]>
     */
    public static function custom_declarations() {
        $opt = self::opt('trackwp_cookie_declarations');
        $out = array();
        foreach ($opt as $cat => $entries) {
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $e) {
                if (is_array($e)) {
                    $out[$cat][] = $e;
                }
            }
        }
        return $out;
    }

    /* ------------------------------------------------------------------
     * Categories and consent requirement
     * ------------------------------------------------------------------ */

    /**
     * Optional categories that are actually in use, in display order.
     * Device-independent (scanned cookies do not activate a category).
     *
     * @return string[]
     */
    public static function active_categories() {
        $used = array();
        foreach (self::active_vendors() as $v) {
            $used[$v['category']] = true;
        }
        foreach (self::storage_declarations(array()) as $d) {
            $used[$d['category']] = true;
        }
        foreach (self::custom_declarations() as $cat => $entries) {
            if (!empty($entries)) {
                $used[$cat] = true;
            }
        }
        if (isset(self::active_vendors()[self::GTM_PLACEHOLDER_KEY])) {
            // Unknown GTM payload: both statistics and marketing must be choosable.
            $used['statistics'] = true;
            $used['marketing']  = true;
        }
        $out = array();
        foreach (self::OPTIONAL_CATEGORIES as $c) {
            if (!empty($used[$c])) {
                $out[] = $c;
            }
        }
        return $out;
    }

    /**
     * False when the site only uses strictly necessary technologies. The
     * banner then renders an information notice instead of a consent dialog.
     *
     * @return bool
     */
    public static function requires_consent() {
        return (bool) apply_filters('trackwp_consent_requires_consent', !empty(self::active_categories()));
    }

    /* ------------------------------------------------------------------
     * Texts
     * ------------------------------------------------------------------ */

    /** @return string The data controller: controller_name, else the site name. Never TrackWP. */
    public static function controller_name() {
        $name = self::str(self::opt('trackwp_consent'), 'controller_name');
        if ($name === '') {
            $name = wp_strip_all_tags((string) get_bloginfo('name'));
        }
        return $name;
    }

    /**
     * Join names Danish style: "A", "A og B", "A, B og C".
     *
     * @param string[] $items
     * @return string
     */
    public static function join_list($items) {
        $items = array_values(array_filter(array_unique($items), 'strlen'));
        $n = count($items);
        if ($n === 0) {
            return '';
        }
        if ($n === 1) {
            return $items[0];
        }
        $last = array_pop($items);
        /* translators: 1: comma separated list, 2: last item */
        return sprintf(__('%1$s og %2$s', 'trackwp'), implode(', ', $items), $last);
    }

    /**
     * Unique third-party recipient names in vendor order.
     *
     * @param array $vendors
     * @return string[]
     */
    protected static function recipients($vendors) {
        $r = array();
        foreach ($vendors as $v) {
            if (!empty($v['recipient'])) {
                $r[] = $v['recipient'];
            }
        }
        return array_values(array_unique($r));
    }

    /**
     * Human-readable labels and descriptions for all categories.
     *
     * @return array<string,array{label:string,description:string}>
     */
    public static function category_labels() {
        $s   = self::platform_state();
        $ads = $s['google_ads'] || $s['meta'];
        return array(
            'necessary' => array(
                'label'       => __('Nødvendige', 'trackwp'),
                'description' => __('Nødvendige for, at websitet fungerer, og for at huske dit cookievalg. Kan ikke fravælges.', 'trackwp'),
            ),
            'statistics' => array(
                'label'       => __('Statistik', 'trackwp'),
                'description' => __('Måler, hvordan websitet bruges, så vi kan forbedre det.', 'trackwp'),
            ),
            'marketing' => array(
                'label'       => __('Marketing', 'trackwp'),
                'description' => $ads
                    ? __('Måler, hvordan vores annoncer virker, og bruges til tilpasning og målretning af annoncer.', 'trackwp')
                    : __('Bruges til markedsføring.', 'trackwp'),
            ),
            'personalisation' => array(
                'label'       => __('Funktionalitet og præferencer', 'trackwp'),
                'description' => __('Husker dine præferencer, fx sprog og region.', 'trackwp'),
            ),
            'unclassified' => array(
                'label'       => __('Uklassificerede', 'trackwp'),
                'description' => __('Cookies fundet på din enhed, som endnu ikke er klassificeret.', 'trackwp'),
            ),
        );
    }

    /**
     * Dynamic Danish default texts. Only mentions what is actually active.
     *
     * Keys: heading, controller, purposes, data_types, sharing, acm, withdraw,
     * blocks (ordered non-empty paragraphs), description (blocks joined),
     * accept, reject, customize, save, withdraw_button, close,
     * info_heading, info_description.
     *
     * @return array
     */
    public static function default_texts() {
        $s       = self::platform_state();
        $vendors = self::active_vendors();
        $cats    = self::active_categories();
        $has     = array_fill_keys($cats, true);
        $ads     = $s['google_ads'] || $s['meta'];
        $site    = self::controller_name();
        $c       = self::opt('trackwp_consent');
        // Heading and button labels: admin setting in both description modes, else default.
        $label   = function ($key, $default) use ($c) {
            $v = self::str($c, $key);
            return $v !== '' ? $v : $default;
        };

        $t = array(
            'heading'         => $label('heading', __('Vi bruger cookies', 'trackwp')),
            'controller'      => '',
            'purposes'        => '',
            'data_types'      => '',
            'sharing'         => '',
            'acm'             => '',
            'withdraw'        => '',
            'accept'          => $label('accept_text', __('Accepter alle', 'trackwp')),
            'reject'          => $label('reject_text', __('Afvis valgfrie', 'trackwp')),
            'customize'       => $label('customize_text', __('Tilpas valg', 'trackwp')),
            'save'            => $label('save_text', __('Gem mine valg', 'trackwp')),
            'withdraw_button' => __('Træk samtykke tilbage', 'trackwp'),
            'close'           => __('Luk', 'trackwp'),
            'info_heading'    => __('Cookies på dette website', 'trackwp'),
            'info_description' => sprintf(
                /* translators: %s: data controller name */
                __('%s bruger kun cookies og lignende teknologier, som er nødvendige for, at websitet fungerer. Derfor beder vi ikke om dit samtykke. Du kan se dem herunder.', 'trackwp'),
                $site
            ),
        );

        if ($site !== '') {
            /* translators: %s: data controller name */
            $t['controller'] = sprintf(__('%s er dataansvarlig for behandlingen af dine oplysninger på dette website.', 'trackwp'), $site);
        }

        // Purposes.
        $purposes = array();
        if (!empty($has['statistics'])) {
            $purposes[] = __('statistik over, hvordan websitet bruges', 'trackwp');
        }
        if (!empty($has['marketing'])) {
            $purposes[] = $ads
                ? __('måling af, hvordan vores annoncer virker, samt tilpasning og målretning af annoncer', 'trackwp')
                : __('markedsføring', 'trackwp');
        }
        if (!empty($has['personalisation'])) {
            $purposes[] = __('at huske dine præferencer', 'trackwp');
        }
        if ($purposes) {
            /* translators: %s: list of purposes */
            $t['purposes'] = sprintf(__('Med dit samtykke bruger vi cookies og lignende teknologier til %s.', 'trackwp'), self::join_list($purposes));
        }

        // Data types.
        if (!empty($has['statistics']) || !empty($has['marketing'])) {
            $types = array(
                __('online-id\'er fra cookies', 'trackwp'),
                __('IP-adresse', 'trackwp'),
                __('hvilke sider du besøger', 'trackwp'),
                __('oplysninger om din browser og enhed', 'trackwp'),
                $s['woocommerce']
                    ? __('handlinger som klik, formularindsendelser og køb', 'trackwp')
                    : __('handlinger som klik og formularindsendelser', 'trackwp'),
            );
            if (!empty($has['marketing']) && $ads) {
                $types[] = __('hvilken annonce du kom fra', 'trackwp');
            }
            /* translators: %s: list of data types */
            $t['data_types'] = sprintf(__('Det drejer sig om oplysninger som %s.', 'trackwp'), self::join_list($types));

            $hash_recipients = array();
            if ($s['google_ads']) {
                $hash_recipients[] = 'Google';
            }
            if ($s['meta']) {
                $hash_recipients[] = 'Meta';
            }
            if ($s['customer_data_sharing'] && $hash_recipients) {
                $t['data_types'] .= ' ' . sprintf(
                    /* translators: %s: recipients, e.g. "Google og Meta" */
                    __('Indtaster du e-mail eller telefonnummer i en formular eller ved et køb, kan de sendes til %s i kodet form, som stadig gør det muligt for modtageren at genkende dig.', 'trackwp'),
                    self::join_list($hash_recipients)
                );
            }
        }

        // Sharing: only when there are real third-party recipients.
        $recipients = self::recipients($vendors);
        if ($recipients) {
            $n    = count($recipients);
            $list = self::join_list($recipients);
            // Transfer basis per recipient: 'dpf' only when ALL its vendors are DPF;
            // any non-DPF transfer makes it 'other'; no transfer at all is 'none'.
            $basis = array();
            foreach ($vendors as $v) {
                if (empty($v['recipient'])) {
                    continue;
                }
                $b = isset($v['transfer_basis']) ? $v['transfer_basis'] : (empty($v['transfer']) ? 'none' : 'other');
                $r = $v['recipient'];
                if (!isset($basis[$r]) || $basis[$r] === 'none' || ($basis[$r] === 'dpf' && $b !== 'none' && $b !== 'dpf')) {
                    $basis[$r] = $b === 'dpf' || $b === 'none' ? $b : 'other';
                }
            }
            $kinds = array_values(array_unique($basis));
            $see_all = sprintf(
                /* translators: %s: label of the customize button */
                __('Du kan se alle samarbejdspartnere og cookies under »%s«.', 'trackwp'),
                $t['customize']
            );
            if ($kinds === array('none')) {
                $t['sharing'] = sprintf(
                    /* translators: %s: recipient name or list of recipients */
                    _n('Oplysningerne deles med vores samarbejdspartner %s.', 'Oplysningerne deles med vores samarbejdspartnere %s.', $n, 'trackwp'),
                    $list
                ) . ' ' . $see_all;
            } elseif ($kinds === array('dpf')) {
                $t['sharing'] = sprintf(
                    /* translators: %s: recipient name or list of recipients */
                    _n('Oplysningerne deles med vores samarbejdspartner %s, som kan behandle dem i USA under EU-US Data Privacy Framework.', 'Oplysningerne deles med vores samarbejdspartnere %s, som kan behandle dem i USA under EU-US Data Privacy Framework.', $n, 'trackwp'),
                    $list
                ) . ' ' . $see_all;
            } elseif ($n === 1) {
                // A single recipient without DPF (e.g. TikTok): never claim DPF.
                $t['sharing'] = sprintf(
                    /* translators: 1: recipient name, 2: label of the customize button */
                    __('Oplysningerne deles med vores samarbejdspartner %1$s, som kan behandle dem uden for EU/EØS. Se grundlaget for overførslen under »%2$s«.', 'trackwp'),
                    $list,
                    $t['customize']
                );
            } else {
                // Different (or non-DPF) bases: no blanket DPF claim.
                $t['sharing'] = sprintf(
                    /* translators: 1: list of recipients, 2: label of the customize button */
                    __('Oplysningerne deles med vores samarbejdspartnere %1$s. Nogle af dem kan behandle oplysningerne uden for EU/EØS. Se grundlaget for hver samarbejdspartner under »%2$s«.', 'trackwp'),
                    $list,
                    $t['customize']
                );
            }
        } elseif (isset($vendors[self::GTM_PLACEHOLDER_KEY])) {
            $t['sharing'] = __('Oplysningerne kan deles med tjenester, som indlæses via Google Tag Manager.', 'trackwp');
        }

        if ($s['acm']) {
            $t['acm'] = self::acm_sentence();
        }

        if ($cats) {
            $t['withdraw'] = __('Du kan til enhver tid ændre dit valg eller trække dit samtykke tilbage under Cookie-indstillinger. Det påvirker ikke lovligheden af den behandling, der er sket, før du trak samtykket tilbage.', 'trackwp');
        }

        $t['blocks'] = array_values(array_filter(array(
            $t['controller'],
            $t['purposes'],
            $t['data_types'],
            $t['sharing'],
            $t['acm'],
            $t['withdraw'],
        ), 'strlen'));
        $t['description'] = implode(' ', $t['blocks']);

        return apply_filters('trackwp_consent_default_texts', $t, $s);
    }

    /**
     * Texts as rendered in the banner. Heading and button labels always come
     * from the admin settings (defaults in default_texts()). description_mode
     * only governs the description: 'custom' uses the admin description, 'auto'
     * the dynamic blocks. The ACM sentence is always kept while ACM is active.
     *
     * @return array Same keys as default_texts(), plus 'mode'.
     */
    public static function banner_texts() {
        $t = self::default_texts();
        $c = self::opt('trackwp_consent');
        $mode = self::str($c, 'description_mode') === 'custom' ? 'custom' : 'auto';
        $t['mode'] = $mode;
        if ($mode === 'custom' && self::str($c, 'description') !== '') {
            $blocks = array(self::str($c, 'description'));
            if ($t['acm'] !== '' && strpos($blocks[0], $t['acm']) === false) {
                $blocks[] = $t['acm'];
            }
            $t['blocks']      = $blocks;
            $t['description'] = implode(' ', $blocks);
        }
        return $t;
    }

    /* ------------------------------------------------------------------
     * Declaration list for templates
     * ------------------------------------------------------------------ */

    /**
     * Vendor list grouped by category, as consumed by the banner templates and
     * the trackwp_consent_vendor_list filter (scanner merges device cookies).
     * Device-independent; the scanner adds what is found on the device.
     *
     * @return array<string,array[]>
     */
    public static function vendor_list() {
        $list = array(
            'necessary'       => array(),
            'statistics'      => array(),
            'marketing'       => array(),
            'personalisation' => array(),
            'unclassified'    => array(),
        );
        foreach (self::active_vendors() as $v) {
            $list[$v['category']][] = $v;
        }
        foreach (self::storage_declarations(array()) as $d) {
            $list[$d['category']][] = $d;
        }
        return $list;
    }

    /* ------------------------------------------------------------------
     * Material hash
     * ------------------------------------------------------------------ */

    /**
     * Everything that is material for the user's decision. Device-independent.
     *
     * @return array
     */
    public static function material() {
        $vendors = array();
        foreach (self::active_vendors() as $v) {
            $vendors[] = array($v['key'], $v['category'], $v['name'], $v['provider'], $v['cookies'], $v['purpose'], $v['transfer']);
        }
        $storage = array();
        foreach (self::storage_declarations(array()) as $d) {
            $storage[] = array($d['cookies'], $d['type'], $d['category'], $d['purpose'], $d['lifetime']);
        }
        // Wording is deliberately excluded: text edits are covered by banner_hash().
        return array(
            'controller' => self::controller_name(),
            'categories' => self::active_categories(),
            'requires'   => self::requires_consent(),
            'vendors'    => $vendors,
            'storage'    => $storage,
            'custom'     => self::custom_declarations(),
        );
    }

    /**
     * sha256 over material(). Used ONLY for the admin "platforms changed"
     * notice (baseline in option trackwp_consent_material_hash).
     * It never bumps consent_version (no auto-bump in 1.10.1).
     *
     * @return string 64 hex chars
     */
    public static function material_hash() {
        return hash('sha256', (string) wp_json_encode(self::material()));
    }

    /**
     * Hash of what the user actually saw: material_hash() plus the final
     * rendered texts (heading, description blocks incl. a custom description,
     * button labels). Rendered as data-banner-hash and logged as banner_hash.
     *
     * @return string 64 hex chars
     */
    public static function banner_hash() {
        $t = self::banner_texts();
        $shown = array(
            'mode'             => $t['mode'],
            'heading'          => $t['heading'],
            'blocks'           => $t['blocks'],
            'accept'           => $t['accept'],
            'reject'           => $t['reject'],
            'customize'        => $t['customize'],
            'save'             => $t['save'],
            'withdraw_button'  => $t['withdraw_button'],
            'close'            => $t['close'],
            'info_heading'     => $t['info_heading'],
            'info_description' => $t['info_description'],
        );
        return hash('sha256', self::material_hash() . (string) wp_json_encode($shown));
    }
}
