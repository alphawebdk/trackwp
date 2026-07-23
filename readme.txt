=== TrackWP - Server-Side Tracking & Consent ===
Contributors: trackwp
Tags: analytics, tracking, ga4, meta pixel, consent, gdpr, server-side, google ads, cookie consent, consent mode v2, gtm, google tag manager
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Server-side tracking proxy med indbygget cookie consent og Consent Mode v2. Understøtter GA4, Google Ads, Meta og GTM. Ingen eksterne services, ingen månedlige gebyrer.

== Description ==

TrackWP er et gratis, open-source WordPress-plugin der leverer server-side tracking, cookie consent og Consent Mode v2 — uden eksterne services og uden månedlige omkostninger.

**Hvad det erstatter:**

* Stape / Addingwell / Taggrs (server-side tracking) — $20-150/måned
* Cookiebot / CookieYes / Osano (cookie consent) — $10-40/måned
* Samlet besparelse: $360-2.280/år pr. site

**Funktioner:**

* **Server-side tracking proxy** — Events videresendes fra dit eget domæne via REST API
* **GA4 Measurement Protocol** — Server-side event-forwarding til Google Analytics 4
* **Google Ads konverteringer** — Med Consent Mode v2 signaler
* **Meta Conversions API (CAPI)** — Server-side med deduplikering
* **NYT i 1.1: Google Tag Manager** — Container ID indtastes i admin, snippet injiceres automatisk
* **NYT i 1.1: GTM-aware Consent Mode v2 timing** — Defaults emitteres FØR GTM, garanteret rækkefølge
* **NYT i 1.1: Editable endpoint slug** — Omgå adblockers ved at ændre `/wp-json/trackwp/v1/event` til fx `/metrics`
* **NYT i 1.1: Dedup-strategi** — 3 tilstande (klient+server, kun server, kun klient) + "Jeg bruger GTM"-genvej
* **NYT i 1.1: GA4 event_id** propagation for browser↔server dedup
* **Cookie consent banner** — 3 stile: centreret pop-up, bjælke i bunden, diskret hjørne-pop-up
* **Consent Mode v2** — Fuld implementering med cookieless pings ved afvisning
* **Auto-detect tracking** — Telefon-links, e-mail-links, formularindsendelser
* **Form-support** — Contact Form 7, WPForms, Fluent Forms, Gravity Forms, HTML fallback
* **Enhanced Conversions** — SHA-256 hashing klient-side for bedre attribution
* **Førsteparts-cookies** — Server-sat for bedre Safari ITP-håndtering
* **Event-dedup** — Forhindrer dobbelttælling via event_id på tværs af klient og server
* **NYT i 1.1: Build pipeline** — esbuild + cssnano med transparent .min fallback
* **Lille fodaftryk** — ~7 KB total frontend (vs. 120 KB+ for GTM + Cookiebot + pixels)

**Understøttede platforme:**

* Google Analytics 4 (GA4)
* Google Ads
* Meta / Facebook
* Google Tag Manager (NYT i 1.1)

**Understøttede formular-plugins:**

* Contact Form 7
* WPForms (free + pro)
* Fluent Forms
* Gravity Forms
* Standard HTML form (fallback)

== Installation ==

1. Upload `trackwp`-mappen til `/wp-content/plugins/`
2. Aktivér pluginet via 'Plugins'-menuen i WordPress
3. Gå til TrackWP i admin-menuen
4. Indtast dit GA4 Measurement ID og/eller Meta Pixel ID (eller GTM Container ID hvis du foretrækker GTM)
5. Konfigurér dine begivenheder og samtykke-banner
6. Færdig — tracking starter automatisk

== Frequently Asked Questions ==

= Skal jeg bruge Google Tag Manager? =

Nej — det er valgfrit. TrackWP håndterer GA4, Google Ads og Meta uden GTM. Men siden v1.1 kan du også indtaste et GTM Container ID hvis du foretrækker at styre tags via GTM. Consent Mode v2 defaults emitteres altid FØR GTM-snippet, så samtykke-status respekteres fra første millisekund.

= Er det GDPR-compliant? =

Ja. TrackWP indeholder cookie consent-banner med Consent Mode v2. Ingen tracking-cookies sættes uden samtykke. Cookieless pings sendes ved afvisning så Google kan modellere konverteringer (lovligt og GDPR-compliant).

= Virker det med adblockers? =

Ja. Da events sendes via dit eget domæne (først-parts), kan adblockers ikke skelne tracking-requests fra normale site-requests. I v1.1 kan du desuden ændre endpoint-slug fra `/event` til noget eget, hvilket gør adblocker-bypass endnu mere robust.

= Hvordan virker Consent Mode v2? =

Når en bruger afviser samtykke, sender TrackWP cookieless pings til Google. Google bruger machine learning til at estimere ~70-85% af konverteringsdata fra afviste brugere. Dette er fuldt lovligt og GDPR-compliant.

= Vil det forsinke mit site? =

Nej. Det totale frontend-fodaftryk er ~7 KB (vs. 120 KB+ for GTM + Cookiebot + pixel-scripts). Alle scripts indlæses asynkront. Siden v1.1 produceres minified `.min.*` filer via en indbygget build pipeline (esbuild + cssnano).

= Understøtter det WooCommerce? =

WooCommerce-integration er planlagt til v1.5 med fuld e-commerce event tracking.

= Kan jeg tilpasse consent-banneret? =

Ja. Farver, tekst, stil (centreret pop-up / bjælke i bunden / diskret hjørne-pop-up) og samtykke-kategorier er alle konfigurerbare i admin-panelet.

= Hvordan gemmes API-secrets? =

API-secrets gemmes base64-encoded i WordPress' options-tabel. Databasen er beskyttet af WordPress-authentication.

= Server-side vs. klient-side dedup? =

I v1.1 kan du vælge mellem 3 dedup-tilstande: "Klient + server" (default — begge fyrer, dedup via `event_id`), "Kun server" (anbefalet hvis du bruger GTM), eller "Kun klient" (spring server-proxy GA4/Meta over). "Jeg bruger GTM"-checkboxen auto-vælger "Kun server".

== Screenshots ==

1. Platform-indstillinger — Indtast GA4, Google Ads, Meta og GTM-credentials
2. Begivenheds-konfiguration — Konfigurér hvilke events der trackes og deres værdier
3. Samtykke-banner — Cookiebot-style consent banner med tilpasselig design
4. Avancerede indstillinger — Endpoint-slug, førsteparts-cookies, Consent Mode v2, dedup-strategi, debug

== Changelog ==

= 1.8.0 =
* Ny: Automatiske opdateringer via GitHub Releases (Plugin Update Checker v5.7) — opdateringsnotifikation og 1-klik-opdatering i wp-admin som standard-plugins
* Offentligt GitHub-repo — opdateringer kræver ingen token (TRACKWP_GITHUB_TOKEN understøttes fortsat, men er unødvendig)

= 1.7.2 =
* Fix: GA4-sessionstitching — understøtter det nye GS2 _ga_*-cookieformat (udrullet af Google maj 2025)
* Fix: Events respekterer nu send_to-platformvalget server-side (GA4/Meta/Google Ads)
* Fix: Google Ads CAPI kræver nu at Google Ads-hovedkontakten er slået til, og kun events med Google Ads-flag uploades
* Fix: GA4-batchkøens cron-flush virker nu i WP-Cron-kontekst (callback registreres ved bootstrap)
* Fix: Meta CustomEvent-dedup — Pixel og CAPI sender nu samme eventnavn
* Fix: GA4 user_data (hashet PII) udelades når marketing-samtykke er afvist
* Fix: client_only-dedup-mode sender nu GA4-events via gtag
* Fix: js_event-triggeren og custom-navngivne formular-events er nu funktionelle
* Fix: E-mail-hash-paritet i navigations-fallback (Meta-hash bygges ikke længere på Gmail-normaliseret adresse)
* Consent: versionstjek håndhæves nu også i PHP og Meta Pixel-inline (policy-ændringer invaliderer gammelt samtykke overalt)
* Consent: "Kræv aktivt samtykke" og "Cookieløse pings" (basic/advanced Consent Mode) er nu funktionelle indstillinger
* Consent: _twp_cid sættes ikke længere klient-side uden statistik-samtykke; tilbagetrækning udløber nu også _ga/_ga_*/_gcl_aw
* Consent: marketing-cookies fornys med maks. 90 dage (matcher banner-deklarationen); tidlig consent-genopretning i head fjerner race for tilbagevendende besøgende
* Sikkerhed: origin-tjek + rate limit på consent-endpoints; collect-proxy videresender kun hits til sitets eget GA4-property; no-store-headers på keepalive/my-data; logmappe beskyttes med .htaccess
* Diverse: døde ydelses-toggles fjernet fra UI; oprydning i uninstall (cron + alle logfiler)

= 1.7.1 =
* Fix: Settings-import korrumperer ikke laengere API-noegler (dobbelt-base64)
* Fix: "Ryd"-knappen kan nu slette gemte secrets
* Fix: Formular-tracking race-condition (async hashing) rettet
* Fix: GA4-batching bevarer nu consent-state pr. event
* Fix: Telefon-hash sendes i E.164-format til Google — enhanced conversions matcher nu
* Fix: GA4 enhanced conversions bruger korrekte user_data-felter
* Fix: Google Ads partial failures opdages og logges nu
* Fix: Server-side Google Ads-dispatch aktiveret for default-events (send_to-migration — koerer automatisk ved opgradering)
* Fix: Cookie-fornyelse/-sletning bruger nu korrekt cookie-domaene paa www- og subdomaener (_ga, _fbp, _fbc, _gcl_au); gamle host-only-duplikater ryddes ved withdraw
* Fix: Ukendte cookies klassificeres som "Uklassificerede" i cookie-deklarationen i stedet for "Noedvendige"
* Fix: file_download fyrer ikke laengere alle events ved eet klik
* Fix: Bot-filter matcher ikke laengere rigtige enheder med "bot" i navnet (fx CUBOT)
* Fix: Foersteparts collect-proxy returnerer altid 2xx — ingen console-fejl ved upstream-fejl
* Fix: GA4 flush-cron ryddes ved deaktivering; uninstall sletter nu ogsaa stats og cookie-deklarationer
* Diverse mindre rettelser

= 1.5.0 =
* Ny: Foersteparts-loader (option fandtes allerede under Avanceret) — gtag.js serveres nu via eget domaene (`/wp-json/trackwp/v1/loader`, 12 timers cache) og GA4-hits proxy'es via `/wp-json/trackwp/v1/c/g/collect` med transport_url. Omgaar domaene-baserede adblockers (path-baserede regler kan stadig ramme)
* Ny: Google Ads server-side conversions (fase 2) — OAuth2 refresh-token-flow (nye felter: OAuth Client ID/Secret/Refresh Token under Google Ads) og reel dispatch til uploadClickConversions (Google Ads API v21, filterbar via `trackwp_google_ads_api_version`). Bemaerk: Google lukker UploadClickConversions for NYE developer tokens fra 15. juni 2026 (afloeser: Data Manager API) — eksisterende tokens fortsaetter
* Ny: Meta Pixel klient-side (option "Klient-side Pixel" under Meta, default til) — consent-gated (indlaeses foerst ved marketing-samtykke), events fyres med samme event_id som CAPI saa Meta selv dedupliker (bedre match quality)
* Cleanup: doed render_gtag_config-metode fjernet

= 1.4.0 =
* Critical fix: Server-side GA4/Meta-dispatch brugte fire-and-forget wp_remote_post (timeout 0.01s) — requests blev afbrudt under TLS-handshake og naaede aldrig Google/Meta. Nu altid blocking med 2s timeout (REST-kaldet er allerede async fra browseren)
* Critical fix: Nonce-krav paa /v1/event fjernet — sidecache serverede stale nonces og gav 403 paa alle events efter ~24 timer. Origin-check + rate limit beholdes; klient-JS sender ikke laengere X-WP-Nonce
* Ny: gtag.js-rendering (option `ga4_gtag_enabled`, default true) — indlaeser Googles gtag.js med GA4 config (sidevisninger/sessions) og Google Ads config (klient-konverteringer virker nu). Springes over naar GTM er aktiveret eller "Jeg bruger GTM" er sat
* Fix: client_id og session_id genereres nu i GA4-kompatible numeriske formater (<int>.<timestamp> / unix-timestamp) i stedet for twp_/ses_-prefix; _ga-cookien foretraekkes som client_id; server accepterer begge formater; derive_session_id sender kun numeriske ga_session_id
* Fix: _ga-cookien genudstedes ikke laengere server-side — host-only-genudstedelse skabte duplikatcookie ved siden af gtags domain-wide cookie
* Aendring: GPC/DNT auto-reject fjernet — banneret vises altid ved manglende consent (consent er stadig denied-by-default)
* Fix: Rate limiter bruger nu fast 2-sekunders vindue (foer kunne taelleren aldrig nulstilles ved jaevn trafik, og delte IP'er kunne faa 429)
* Performance: Halveret antal database-skrivninger pr. tracking-event (stats opdateres i een omgang)

= 1.3.1 =
* Fix: Cookie consent-preview reagerer nu paa banner_style-dropdown (Cookiebot / Dialog / Bottombar) — viser layout-specifikke tabs, kategorier og bjaelke-rendering

= 1.3.0 =
* Banner styles: vaelg mellem Cookiebot-stil, Dialog, eller Bjaelke
* Migration: bar_bottom → bottombar, corner_popup → dialog
* Tilfoejet 'personalisation' kategori i alle styles

= 1.2.1 =
* Critical fix: floatval sanitize_callback bug der gav HTTP 500 paa alle /v1/event calls paa PHP 8+
* JS: synkron dispatch for nav-clicks (mailto/tel/outbound) — undgaa event-loss ved page-unload
* JS: event delegation paa css_click triggers — dynamisk indlaeste links (AJAX/SPA) bliver nu ogsaa tracked
* JS: format-agnostisk `_ga_<X>` cookie-scan — virker for G-, GT-, GTM- maleinstrumenter
* GA4 server-side: pre-check af G- prefix i Measurement Protocol — skip + log hvis ikke G-format
* Settings UI: live warning naar GT-/GTM- indtastes i GA4 Measurement ID-feltet
* Proxy: accept `ga_session_cookies` array fra JS (multi-property support)

= 1.2.0 =
* Consent Mode v2 signaler (ad_user_data, ad_personalization) i GA4 + Meta CAPI body
* GA4: user_id + user_properties for logged-in brugere
* GA4: session_id mapping fra `_ga_<MID>` cookie
* GA4: event batching via wp_cron (op til 25 events pr. request)
* Meta CAPI: external_id, test_event_code, konfigurerbar API version, LDU mode ved no-consent
* Google Ads CAPI skeleton — settings + GCLID-capture (dispatcher venter paa OAuth2 PHASE 2)
* Hash-normalisering: telefon rene cifre (ingen +), Gmail+ aliasing, accent ASCII-translit
* Forms: client-side SHA-256 hashing FOER POST til proxy
* Proxy: `restore_third_party_cookies` (consent-gated) — Safari ITP cookie-renewal
* Proxy: `normalize_enhanced` paa indkommende payload — graceful rehash hvis client mangler SubtleCrypto
* Consent-log: UUID, IP-hash (SHA256+salt), user-agent, banner-version, fulde valg som JSON
* Consent: ny `DELETE /v1/consent` withdraw endpoint
* Banner UI: 4. kategori "personalisation", vendor-liste pr. kategori, GPC + DNT auto-reject, floating "Cookie-indstillinger"-knap, accept/reject symmetri
* Settings: 9 nye felter, version 1.2.0 migration, eksisterende settings bevares via `+=`

= 1.1.0 =
* Google Tag Manager container ID-support — snippet og noscript-iframe injiceres automatisk
* GTM-aware Consent Mode v2 timing — defaults emitteres på wp_head pri 1, strengt før GTM (pri 5)
* Editable REST endpoint slug — adblocker-bypass (default `event`, kun a-z/0-9/bindestreger)
* GTM / proxy dedup-strategi — 3 tilstande + "Jeg bruger GTM"-genvejscheckbox
* GA4 event_id propagation til Measurement Protocol params for browser↔server dedup
* Build pipeline — esbuild (JS) + cssnano (CSS) via `npm run build`; transparent fallback til unminified
* Dansk som standard UI-sprog — alle source strings oversat (engelsk kommer som .mo)
* Sanitize: GTM ID valideres med regex `^GTM-[A-Z0-9]{4,10}$`
* Migration: v1.0 → v1.1 konverterer automatisk legacy endpoint_path og tilføjer dedup_mode default

= 1.0.0 =
* Initial release
* GA4 Measurement Protocol server-side forwarding
* Google Ads conversion tracking with Consent Mode v2
* Meta Conversions API (CAPI) server-side forwarding
* Cookie consent banner (dialog, bar, corner popup styles)
* Consent Mode v2 with cookieless pings on denial
* Auto-detect phone links, email links, form submissions
* Form support: Contact Form 7, WPForms, Fluent Forms, Gravity Forms, HTML fallback
* Event configuration in admin panel (name, value, trigger, routing)
* First-party cookies (server-set)
* Event deduplication for GA4 and Meta
* Enhanced Conversions with SHA-256 hashing
* Admin panel with 5 configuration tabs

== Upgrade Notice ==

= 1.1.0 =
Tilføjer GTM-support, editable endpoint slug, dedup-strategi og build pipeline. Migration kører automatisk på plugins_loaded. Ingen brugerhandling påkrævet.

= 1.0.0 =
Initial release.
