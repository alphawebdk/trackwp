<?php
/**
 * Blocker catalog signatures and blocker-driven declaration (T1, PLAN-1.11.0-v2 KB5, §3.6, §5).
 *
 * Producers used as fixtures:
 *  - TrackWP_Consent_Profile::vendor_catalog() (signatures, the single source),
 *  - tests/fixtures/blocker/adashofmagic-home.html, a saved real response (T0 copy of
 *    live-test/adashofmagic/fe2-source-home.html). TRACKWP_FIXTURE_HOME may point to
 *    another copy of the same file (e.g. a docker bind mount) while T0's copy is missing.
 * The scan items stored in trackwp_blocker_scan are derived from that real HTML
 * with the catalog's own signatures, not hand-written.
 */

require_once dirname(__DIR__) . '/includes/class-trackwp-consent-profile.php';
require_once dirname(__DIR__) . '/includes/class-trackwp-cookie-scanner.php';

class TrackWP_Blocker_Catalog_Test extends WP_UnitTestCase {

    const MARKER_RE  = '/^[A-Za-z0-9._-]{8,64}$/';
    const RULE_ID_RE = '/^(handle|url|host|inline|pixel):[A-Za-z0-9._\/\-]{3,200}$/';

    public function set_up() {
        parent::set_up();
        update_option('trackwp_platforms', array());
        update_option('trackwp_advanced', array('first_party_cookie_enabled' => true));
        update_option('trackwp_consent', array('controller_name' => 'Eksempel ApS'));
        update_option('trackwp_cookie_declarations', array());
        delete_option('trackwp_blocker');
        delete_option('trackwp_blocker_scan');
    }

    /* ---------------- helpers ---------------- */

    protected function fixture_html() {
        $path = getenv('TRACKWP_FIXTURE_HOME');
        if (!$path) {
            $path = __DIR__ . '/fixtures/blocker/adashofmagic-home.html';
        }
        if (!is_readable($path)) {
            $this->markTestSkipped('Fixture adashofmagic-home.html missing (T0).');
        }
        return (string) file_get_contents($path);
    }

    /** S4: strip the longest suffix first, then -js. */
    protected static function handle_from_id($id) {
        foreach (array('-js-extra', '-js-before', '-js-after', '-js') as $suffix) {
            if ($id !== '' && substr($id, -strlen($suffix)) === $suffix) {
                return substr($id, 0, -strlen($suffix));
            }
        }
        return '';
    }

    /** host + path of a URL (S3 for //host and absolute URLs), '' when not absolute. */
    protected static function host_path($url) {
        $url = trim(html_entity_decode((string) $url));
        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        }
        $p = wp_parse_url($url);
        if (empty($p['host'])) {
            return array('', '');
        }
        return array(strtolower($p['host']), isset($p['path']) ? $p['path'] : '/');
    }

    /**
     * Observed tags in the real HTML: kind, handle, host, path, inline text.
     *
     * @return array[]
     */
    protected function observe($html) {
        $out = array();
        $p   = new WP_HTML_Tag_Processor($html);
        while ($p->next_tag()) {
            $tag = $p->get_tag();
            if ($tag === 'SCRIPT') {
                $id  = (string) $p->get_attribute('id');
                $src = $p->get_attribute('src');
                list($host, $path) = is_string($src) ? self::host_path($src) : array('', '');
                $out[] = array(
                    'kind'   => is_string($src) ? 'script' : 'inline',
                    'id'     => $id,
                    'handle' => self::handle_from_id($id),
                    'host'   => $host,
                    'path'   => $path,
                    'text'   => is_string($src) ? '' : (string) $p->get_modifiable_text(),
                );
            } elseif ($tag === 'IMG' || $tag === 'IFRAME') {
                list($host, $path) = self::host_path((string) $p->get_attribute('src'));
                if ($host !== '') {
                    $out[] = array('kind' => $tag === 'IMG' ? 'pixel' : 'iframe', 'id' => '', 'handle' => '', 'host' => $host, 'path' => $path, 'text' => '');
                }
            }
        }
        // noscript content may be raw text for the tag processor: read pixel <img> directly.
        if (preg_match_all('#<noscript[^>]*>(.*?)</noscript>#is', $html, $m)) {
            foreach ($m[1] as $inner) {
                if (preg_match_all('#<img[^>]+src=["\']([^"\']+)#i', $inner, $im)) {
                    foreach ($im[1] as $src) {
                        list($host, $path) = self::host_path($src);
                        $out[] = array('kind' => 'pixel', 'id' => '', 'handle' => '', 'host' => $host, 'path' => $path, 'text' => '');
                    }
                }
            }
        }
        return $out;
    }

    protected static function host_hits($host, $rule) {
        return $host !== '' && ($host === $rule || substr($host, -strlen('.' . $rule)) === '.' . $rule);
    }

    /**
     * Which catalog signature (vendor, rule_id) matches an observed tag, in KB6 order:
     * handle, url/pixel (longest prefix), host, inline.
     *
     * @return array|null array(vendor, rule_id)
     */
    protected static function match_catalog($o, $catalog) {
        foreach ($catalog as $key => $v) {
            if ($o['handle'] !== '' && in_array($o['handle'], $v['signatures']['handles'], true)) {
                return array($key, 'handle:' . $o['handle']);
            }
        }
        $best = null;
        foreach ($catalog as $key => $v) {
            $list = $o['kind'] === 'pixel' ? $v['signatures']['pixels'] : $v['signatures']['urls'];
            foreach ($list as $prefix) {
                if ($o['host'] !== '' && strpos($o['host'] . $o['path'], $prefix) === 0 && ($best === null || strlen($prefix) > strlen($best[2]))) {
                    $best = array($key, ($o['kind'] === 'pixel' ? 'pixel:' : 'url:') . $prefix, $prefix);
                }
            }
        }
        if ($best) {
            return array($best[0], $best[1]);
        }
        if ($o['kind'] !== 'pixel') {
            foreach ($catalog as $key => $v) {
                foreach ($v['signatures']['hosts'] as $h) {
                    if (self::host_hits($o['host'], $h)) {
                        return array($key, 'host:' . $h);
                    }
                }
            }
        }
        if ($o['kind'] === 'inline') {
            foreach ($catalog as $key => $v) {
                foreach ($v['signatures']['inline'] as $marker) {
                    if (strpos($o['text'], $marker) !== false) {
                        return array($key, 'inline:' . $marker);
                    }
                }
            }
        }
        return null;
    }

    /** KB3-shaped scan items derived from the real HTML with the catalog's signatures. */
    protected function scan_items_from_fixture() {
        $catalog = TrackWP_Consent_Profile::vendor_catalog();
        $items   = array();
        foreach ($this->observe($this->fixture_html()) as $o) {
            $hit = self::match_catalog($o, $catalog);
            if ($hit === null || isset($items[$hit[1]])) {
                continue;
            }
            $items[$hit[1]] = array(
                'obs_id' => substr(sha1($o['kind'] . '|' . $o['host'] . '|' . $o['path'] . '|' . $o['handle']), 0, 12),
                'rule_id' => $hit[1], 'kind' => $o['kind'] === 'inline' ? 'inline' : $o['kind'],
                'host' => $o['host'], 'path' => $o['path'], 'handle' => $o['handle'], 'plugin' => '',
                'deps' => array(), 'dependents' => array(), 'marker' => '', 'pages' => array('/'),
                'vendor' => $hit[0], 'category_guess' => $catalog[$hit[0]]['category'],
                'status' => 'allowed', 'seen_blocked' => null,
            );
        }
        return array_values($items);
    }

    /* ---------------- catalog shape ---------------- */

    public function test_every_catalog_entry_has_signatures_and_server_side_note() {
        foreach (TrackWP_Consent_Profile::vendor_catalog() as $key => $v) {
            $this->assertSame($key, $v['key']);
            $this->assertIsString($v['server_side_note'], $key);
            $this->assertSame(array('handles', 'urls', 'hosts', 'inline', 'pixels'), array_keys($v['signatures']), $key);
            $s = $v['signatures'];
            foreach ($s['handles'] as $h) {
                $this->assertMatchesRegularExpression(self::RULE_ID_RE, 'handle:' . $h, $key);
            }
            foreach (array('urls' => 'url:', 'pixels' => 'pixel:') as $f => $pre) {
                foreach ($s[$f] as $u) {
                    $this->assertMatchesRegularExpression(self::RULE_ID_RE, $pre . $u, $key);
                    $this->assertStringNotContainsString('?', $u, $key);
                    $this->assertStringContainsString('/', $u, "$key $f must be host + path");
                }
            }
            foreach ($s['hosts'] as $h) {
                $this->assertSame(strtolower($h), $h, $key);
                $this->assertMatchesRegularExpression(self::RULE_ID_RE, 'host:' . $h, $key);
            }
            foreach ($s['inline'] as $m) {
                $this->assertMatchesRegularExpression(self::MARKER_RE, $m, $key);
            }
        }
    }

    public function test_new_vendors_and_signature_owners() {
        $c = TrackWP_Consent_Profile::vendor_catalog();
        foreach (array('leadinfo', 'klaviyo', 'jetpack', 'wc_order_attribution', 'sleeknote') as $k) {
            $this->assertArrayHasKey($k, $c);
        }
        $this->assertSame('statistics', $c['jetpack']['category']);
        $this->assertSame('marketing', $c['wc_order_attribution']['category']);
        $this->assertSame(array(), array_merge(...array_values($c['ga4']['signatures'])));
        $this->assertSame(array(), array_merge(...array_values($c['google_ads']['signatures'])));
        foreach (array('meta', 'tiktok', 'linkedin', 'microsoft_ads', 'hotjar') as $k) {
            $this->assertNotEmpty($c[$k]['signatures']['hosts'], $k);
        }
        $this->assertNotSame('', $c['meta']['server_side_note']);
        $this->assertNotSame('', $c['klaviyo']['server_side_note']);
        $this->assertNotSame('', $c['jetpack']['server_side_note']);
    }

    public function test_unsourced_fields_read_uafklaret() {
        $c = TrackWP_Consent_Profile::vendor_catalog();
        $u = TrackWP_Consent_Profile::unresolved();
        $this->assertSame('uafklaret', $u);
        // Verified (VENDOR-DEKLARATIONER.md, Tillæg 1.11.0).
        $this->assertSame('dpf', $c['klaviyo']['transfer_basis']);
        $this->assertSame('scc', $c['jetpack']['transfer_basis'], 'Automattic DPF excludes Jetpack/WooCommerce');
        $this->assertSame('none', $c['wc_order_attribution']['transfer_basis']);
        $this->assertSame('_li_id.*, _li_ses.*', $c['leadinfo']['cookies']);
        $this->assertStringContainsString('Leadinfo B.V.', $c['leadinfo']['provider']);
        $this->assertStringContainsString('Sleeknote ApS', $c['sleeknote']['provider']);
        // Unverified: never guessed.
        foreach (array('leadinfo', 'sleeknote') as $k) {
            $this->assertSame($u, $c[$k]['transfer'], $k);
            $this->assertSame('other', $c[$k]['transfer_basis'], "$k must never claim DPF");
        }
        $this->assertSame($u, $c['sleeknote']['cookies']);
    }

    /* ---------------- signatures against the real HTML ---------------- */

    public function test_signatures_match_the_live_fixture() {
        $catalog = TrackWP_Consent_Profile::vendor_catalog();
        $hits    = array();
        foreach ($this->observe($this->fixture_html()) as $o) {
            $hit = self::match_catalog($o, $catalog);
            if ($hit) {
                $hits[$hit[1]] = $hit[0];
            }
        }
        $expected = array(
            'handle:sourcebuster-js'                         => 'wc_order_attribution',
            'handle:wc-order-attribution'                    => 'wc_order_attribution',
            'handle:jetpack-stats'                           => 'jetpack',
            'handle:woocommerce-analytics'                   => 'jetpack',
            'handle:woocommerce-analytics-client'            => 'jetpack',
            'handle:kl-identify-browser'                     => 'klaviyo',
            'handle:facebook-capi-param-builder'             => 'meta',
            'handle:wc-facebook-signals'                     => 'meta',
            'handle:wc-facebook-pixel-events'                => 'meta',
            'handle:facebook-for-woocommerce-inline'         => 'meta',
            'host:static.klaviyo.com'                        => 'klaviyo',
            'inline:connect.facebook.net'                    => 'meta',
            'inline:cdn.leadinfo.net'                        => 'leadinfo',
            'inline:sleeknotecustomerscripts.sleeknote.com'  => 'sleeknote',
            'pixel:www.facebook.com/tr'                      => 'meta',
        );
        foreach ($expected as $rule_id => $vendor) {
            $this->assertArrayHasKey($rule_id, $hits, "$rule_id not found in fixture");
            $this->assertSame($vendor, $hits[$rule_id], $rule_id);
        }
    }

    public function test_param_builder_url_rules_cover_unpkg_and_jsdelivr() {
        $catalog = TrackWP_Consent_Profile::vendor_catalog();
        $urls = array(
            // live HTML line 4505 (fb4woo 3.7.6)
            'https://unpkg.com/meta-capi-param-builder-clientjs/dist/clientParamBuilder.bundle.js?ver=3.7.6',
            // fb4woo latest facebook-commerce-events-tracker.php:37 and :40
            'https://cdn.jsdelivr.net/npm/meta-capi-param-builder-clientjs@1.3.2/dist/clientParamBuilder.bundle.js',
            '//unpkg.com/meta-capi-param-builder-clientjs@1.3.2/dist/clientParamBuilder.bundle.js',
        );
        foreach ($urls as $u) {
            list($host, $path) = self::host_path($u);
            $hit = self::match_catalog(array('kind' => 'script', 'handle' => '', 'host' => $host, 'path' => $path, 'text' => ''), $catalog);
            $this->assertNotNull($hit, $u);
            $this->assertSame('meta', $hit[0], $u);
            $this->assertStringStartsWith('url:', $hit[1], $u);
        }
    }

    public function test_signatures_never_hit_trackwp_core_or_gtm_in_fixture() {
        $catalog = TrackWP_Consent_Profile::vendor_catalog();
        foreach ($this->observe($this->fixture_html()) as $o) {
            $own = strpos($o['id'], 'trackwp') === 0
                || strpos($o['path'], '/wp-content/plugins/trackwp/') === 0
                || strpos($o['text'], 'trackwpConsent') !== false
                || in_array($o['handle'], array('jquery', 'jquery-core', 'jquery-migrate', 'wc-cart-fragments', 'woocommerce', 'wc-add-to-cart'), true)
                || $o['host'] === 'www.googletagmanager.com'
                || strpos($o['text'], 'googletagmanager.com/gtm.js') !== false;
            if ($own) {
                $this->assertNull(self::match_catalog($o, $catalog), 'Catalog must not match ' . wp_json_encode(array($o['id'], $o['host'], $o['path'])));
            }
        }
    }

    /* ---------------- declaration from the derived scan ---------------- */

    public function test_active_vendors_contain_scanned_vendors_regardless_of_toggle() {
        $items = $this->scan_items_from_fixture();
        update_option('trackwp_blocker_scan', array('scanned_at' => time(), 'pages' => array('/'), 'items' => $items));
        $rules = array();
        foreach ($items as $it) {
            // Only order attribution is blocked; everything else stays allowed.
            $rules[$it['rule_id']] = array('block' => $it['vendor'] === 'wc_order_attribution', 'category' => $it['category_guess'], 'vendor' => $it['vendor']);
        }
        update_option('trackwp_blocker', array('mode' => 'off', 'rules' => $rules, 'custom_vendors' => array(), 'exceptions' => array('allow' => array(), 'paths' => array()), 'extra_paths' => array()));

        $v = TrackWP_Consent_Profile::active_vendors();
        foreach (array('meta', 'leadinfo', 'klaviyo', 'jetpack', 'wc_order_attribution', 'sleeknote') as $k) {
            $this->assertArrayHasKey($k, $v, $k);
            $this->assertTrue($v[$k]['via_blocker'], $k);
        }
        $this->assertTrue($v['wc_order_attribution']['blocked']);
        $this->assertFalse($v['klaviyo']['blocked']);
        $this->assertContains('unblocked_vendors', TrackWP_Consent_Profile::warnings());
        $unblocked = TrackWP_Consent_Profile::unblocked_vendors();
        $this->assertContains('leadinfo', $unblocked);
        $this->assertNotContains('wc_order_attribution', $unblocked);

        // known_cookies classifies the observed vendor cookies.
        $scan = TrackWP_Cookie_Scanner::scan(array('__kla_id', 'sbjs_first', 'tk_ai', 'unknown_x'));
        $names = function ($cat) use ($scan) {
            return implode(',', wp_list_pluck($scan[$cat], 'cookies'));
        };
        $this->assertStringContainsString('__kla_id', $names('marketing'));
        $this->assertStringContainsString('sbjs_first', $names('marketing'));
        $this->assertStringContainsString('tk_ai', $names('statistics'));
        $this->assertStringContainsString('unknown_x', $names('unclassified'));
    }
}
