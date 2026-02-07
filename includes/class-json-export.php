<?php
/**
 * JSON Export class for Consently Scanner.
 *
 * Transforms Phase 1 + Phase 2 scan results into the remote scanner
 * schema format for database compatibility.
 *
 * @package Consently
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JSON export transformation class.
 */
class Consently_JSON_Export {

	/**
	 * Audit instance for cookie classification.
	 *
	 * @var Consently_Audit
	 */
	private $audit;

	/**
	 * Known plugins database cache.
	 *
	 * @var array|null
	 */
	private $known_plugins_db = null;

	/**
	 * Service slug to vendor name map.
	 *
	 * @var array
	 */
	private static $vendor_map = array(
		'google-analytics'    => 'Google Analytics',
		'google-tag-manager'  => 'Google Tag Manager',
		'facebook'            => 'Facebook / Meta',
		'instagram'           => 'Instagram / Meta',
		'twitter'             => 'X (Twitter)',
		'linkedin'            => 'LinkedIn',
		'pinterest'           => 'Pinterest',
		'tiktok'              => 'TikTok',
		'snapchat'            => 'Snapchat',
		'disqus'              => 'Disqus',
		'youtube'             => 'YouTube / Google',
		'vimeo'               => 'Vimeo',
		'google-maps'         => 'Google Maps',
		'google-recaptcha'    => 'Google reCAPTCHA',
		'google-fonts'        => 'Google Fonts',
		'spotify'             => 'Spotify',
		'soundcloud'          => 'SoundCloud',
		'dailymotion'         => 'Dailymotion',
		'hubspot'             => 'HubSpot',
		'calendly'            => 'Calendly',
		'typeform'            => 'Typeform',
		'intercom'            => 'Intercom',
		'hotjar'              => 'Hotjar',
		'livechat'            => 'LiveChat',
		'openstreetmaps'      => 'OpenStreetMap',
		'paypal'              => 'PayPal',
		'stripe'              => 'Stripe',
		'addthis'             => 'AddThis',
		'addtoany'            => 'AddToAny',
		'sharethis'           => 'ShareThis',
		'microsoft-ads'       => 'Microsoft Advertising',
		'microsoft-clarity'   => 'Microsoft Clarity',
		'clarity'             => 'Microsoft Clarity',
		'adobe-fonts'         => 'Adobe Fonts',
		'twitch'              => 'Twitch',
		'wistia'              => 'Wistia',
		'loom'                => 'Loom',
		'apple-podcasts'      => 'Apple Podcasts',
		'tawk-to'             => 'Tawk.to',
		'drift'               => 'Drift',
		'crisp'               => 'Crisp',
		'tidio'               => 'Tidio',
		'cloudflare-turnstile' => 'Cloudflare Turnstile',
		'hcaptcha'            => 'hCaptcha',
		'matomo'              => 'Matomo',
		'clicky'              => 'Clicky',
		'yandex'              => 'Yandex Metrica',
		'plausible'           => 'Plausible Analytics',
		'fathom'              => 'Fathom Analytics',
		'heap'                => 'Heap Analytics',
		'mixpanel'            => 'Mixpanel',
		'amplitude'           => 'Amplitude',
		'segment'             => 'Segment',
	);

	/**
	 * Service slug to primary domain map.
	 *
	 * @var array
	 */
	private static $domain_map = array(
		'google-analytics'    => 'www.google-analytics.com',
		'google-tag-manager'  => 'www.googletagmanager.com',
		'facebook'            => 'www.facebook.com',
		'instagram'           => 'www.instagram.com',
		'twitter'             => 'platform.twitter.com',
		'linkedin'            => 'www.linkedin.com',
		'pinterest'           => 'www.pinterest.com',
		'tiktok'              => 'www.tiktok.com',
		'snapchat'            => 'www.snapchat.com',
		'disqus'              => 'disqus.com',
		'youtube'             => 'www.youtube.com',
		'vimeo'               => 'player.vimeo.com',
		'google-maps'         => 'maps.googleapis.com',
		'google-recaptcha'    => 'www.google.com',
		'google-fonts'        => 'fonts.googleapis.com',
		'spotify'             => 'open.spotify.com',
		'soundcloud'          => 'soundcloud.com',
		'dailymotion'         => 'www.dailymotion.com',
		'hubspot'             => 'js.hs-scripts.com',
		'calendly'            => 'calendly.com',
		'typeform'            => 'embed.typeform.com',
		'intercom'            => 'widget.intercom.io',
		'hotjar'              => 'static.hotjar.com',
		'livechat'            => 'cdn.livechatinc.com',
		'openstreetmaps'      => 'tile.openstreetmap.org',
		'paypal'              => 'www.paypal.com',
		'stripe'              => 'js.stripe.com',
		'addthis'             => 's7.addthis.com',
		'addtoany'            => 'static.addtoany.com',
		'sharethis'           => 'platform-api.sharethis.com',
		'microsoft-ads'       => 'bat.bing.com',
		'microsoft-clarity'   => 'www.clarity.ms',
		'clarity'             => 'www.clarity.ms',
		'adobe-fonts'         => 'use.typekit.net',
		'twitch'              => 'embed.twitch.tv',
		'wistia'              => 'fast.wistia.com',
		'loom'                => 'www.loom.com',
		'apple-podcasts'      => 'embed.podcasts.apple.com',
		'tawk-to'             => 'embed.tawk.to',
		'drift'               => 'js.driftt.com',
		'crisp'               => 'client.crisp.chat',
		'tidio'               => 'code.tidio.co',
		'cloudflare-turnstile' => 'challenges.cloudflare.com',
		'hcaptcha'            => 'js.hcaptcha.com',
		'matomo'              => 'cdn.matomo.cloud',
		'clicky'              => 'static.getclicky.com',
		'yandex'              => 'mc.yandex.ru',
		'plausible'           => 'plausible.io',
		'fathom'              => 'cdn.usefathom.com',
		'heap'                => 'cdn.heapanalytics.com',
		'mixpanel'            => 'cdn.mxpnl.com',
		'amplitude'           => 'cdn.amplitude.com',
		'segment'             => 'cdn.segment.com',
	);

	/**
	 * Services typically loaded via iframe.
	 *
	 * @var array
	 */
	private static $iframe_services = array(
		'youtube', 'vimeo', 'google-maps', 'spotify', 'soundcloud',
		'dailymotion', 'twitch', 'wistia', 'loom', 'apple-podcasts',
		'calendly', 'typeform',
	);

	/**
	 * Constructor.
	 *
	 * @param Consently_Audit $audit Audit instance.
	 */
	public function __construct( Consently_Audit $audit ) {
		$this->audit = $audit;
	}

	/**
	 * Generate the export JSON matching the remote scanner schema.
	 *
	 * @return array Schema-compatible scan results.
	 */
	public function generate() {
		$phase1 = get_transient( 'consently_audit_phase1' );
		$phase2 = get_transient( 'consently_audit_phase2' );

		if ( false === $phase1 ) {
			$phase1 = array();
		}
		if ( false === $phase2 ) {
			$phase2 = array();
		}

		$cookies             = $this->build_cookies( $phase1, $phase2 );
		$storage             = $this->build_storage( $phase2 );
		$third_party_scripts = $this->build_third_party_scripts( $phase1, $phase2 );
		$tag_managers        = $this->build_tag_managers( $phase2 );
		$fonts               = $this->build_fonts( $phase2 );
		$iframes             = $this->build_iframes( $phase2 );
		$trackers            = $this->build_trackers( $phase1, $phase2 );
		$script_cookie_map   = $this->build_script_cookie_map( $phase1 );
		$tracking_pixels     = $this->build_tracking_pixels( $phase2 );

		$started_at  = get_transient( 'consently_scan_started_at' );
		$completed_at = ! empty( $phase2['timestamp'] ) ? $phase2['timestamp'] : 0;

		$started_iso   = $started_at ? $started_at : '';
		$completed_iso = $completed_at ? gmdate( 'c', $completed_at ) : '';

		$duration_ms = 0;
		if ( $started_at && $completed_at ) {
			$start_ts    = strtotime( $started_at );
			$duration_ms = $start_ts ? ( $completed_at - $start_ts ) * 1000 : 0;
		}

		$stats = array(
			'total'             => count( $cookies ) + count( $storage ) + count( $trackers ),
			'cookies'           => count( $cookies ),
			'localStorage'      => $this->count_storage_by_type( $storage, 'localStorage' ),
			'sessionStorage'    => $this->count_storage_by_type( $storage, 'sessionStorage' ),
			'trackingPixels'    => count( $tracking_pixels ),
			'thirdPartyScripts' => count( $third_party_scripts ),
			'tagManagers'       => count( $tag_managers ),
			'fonts'             => count( $fonts ),
			'iframes'           => count( $iframes ),
			'trackers'          => count( $trackers ),
		);

		return array(
			'url'               => home_url( '/' ),
			'finalUrl'          => home_url( '/' ),
			'scanDuration'      => $duration_ms,
			'startedAt'         => $started_iso,
			'completedAt'       => $completed_iso,
			'stats'             => $stats,
			'cookies'           => $cookies,
			'storage'           => $storage,
			'trackingPixels'    => $tracking_pixels,
			'thirdPartyScripts' => $third_party_scripts,
			'tagManagers'       => $tag_managers,
			'fonts'             => $fonts,
			'iframes'           => $iframes,
			'scriptCookieMap'   => $script_cookie_map,
			'trackers'          => $trackers,
			'others'            => $this->build_others( $phase1 ),
			'redirectChain'     => array(),
			'totalRequests'     => 0,
			'blockedRequests'   => 0,
			'errors'            => array(),
			'wpMeta'            => $this->build_wp_meta( $phase1, $phase2 ),
		);
	}

	/**
	 * Build cookies array from Phase 1 + Phase 2 data.
	 *
	 * @param array $phase1 Phase 1 results.
	 * @param array $phase2 Phase 2 results.
	 * @return array Cookies in remote scanner schema format.
	 */
	private function build_cookies( $phase1, $phase2 ) {
		$cookies       = array();
		$seen_names    = array();
		$site_host     = wp_parse_url( home_url(), PHP_URL_HOST );

		// Phase 2 live cookies (confirmed).
		if ( ! empty( $phase2['live_cookies'] ) ) {
			foreach ( $phase2['live_cookies'] as $cookie ) {
				$name = $cookie['name'];
				$seen_names[ $name ] = true;

				$plugin_slug = $this->find_plugin_slug_for_cookie( $name );
				$service     = ! empty( $cookie['service'] ) ? $cookie['service'] : null;

				$cookies[] = array(
					'name'                  => $name,
					'value'                 => '',
					'domain'                => '',
					'path'                  => '/',
					'expires'               => '',
					'httpOnly'              => null,
					'secure'                => null,
					'sameSite'              => null,
					'source'                => 'javascript',
					'sourceScript'          => '',
					'isThirdParty'          => $this->is_third_party_service( $service, $site_host ),
					'category'              => ! empty( $cookie['category'] ) ? $cookie['category'] : 'unclassified',
					'vendor'                => $service,
					'description'           => ! empty( $cookie['purpose'] ) ? $cookie['purpose'] : null,
					'suggestedBlock'        => $this->build_suggested_block_for_service( $plugin_slug ),
					'frameContext'          => 'main-frame',
					'attribution'           => null,
					'tagManagerAttribution' => null,
					'scriptAttribution'     => null,
					'wpDetectionMethod'     => 'live_scan',
					'wpPagesFound'          => ! empty( $cookie['page'] ) ? $cookie['page'] : array(),
					'wpPluginSource'        => $service,
					'wpPluginSlug'          => $plugin_slug,
					'wpDuration'            => ! empty( $cookie['duration'] ) ? $cookie['duration'] : null,
				);
			}
		}

		// Phase 1 known plugin cookies (potential, not yet confirmed).
		if ( ! empty( $phase1['known_plugins'] ) ) {
			foreach ( $phase1['known_plugins'] as $plugin ) {
				if ( empty( $plugin['cookies'] ) ) {
					continue;
				}
				foreach ( $plugin['cookies'] as $cookie_def ) {
					$name = isset( $cookie_def['name'] ) ? $cookie_def['name'] : '';
					if ( empty( $name ) || isset( $seen_names[ $name ] ) ) {
						continue;
					}
					$seen_names[ $name ] = true;

					$cookies[] = array(
						'name'                  => $name,
						'value'                 => '',
						'domain'                => '',
						'path'                  => '/',
						'expires'               => '',
						'httpOnly'              => null,
						'secure'                => null,
						'sameSite'              => null,
						'source'                => 'known_database',
						'sourceScript'          => '',
						'isThirdParty'          => $this->is_third_party_service(
							isset( $plugin['name'] ) ? $plugin['name'] : '',
							$site_host
						),
						'category'              => isset( $cookie_def['category'] ) ? $cookie_def['category'] : ( isset( $plugin['category'] ) ? $plugin['category'] : 'unclassified' ),
						'vendor'                => isset( $plugin['name'] ) ? $plugin['name'] : null,
						'description'           => isset( $cookie_def['purpose'] ) ? $cookie_def['purpose'] : null,
						'suggestedBlock'        => $this->build_suggested_block_for_plugin( $plugin ),
						'frameContext'          => 'main-frame',
						'attribution'           => null,
						'tagManagerAttribution' => null,
						'scriptAttribution'     => null,
						'wpDetectionMethod'     => 'known_database',
						'wpPagesFound'          => array(),
						'wpPluginSource'        => isset( $plugin['name'] ) ? $plugin['name'] : null,
						'wpPluginSlug'          => isset( $plugin['file'] ) ? $plugin['file'] : null,
						'wpDuration'            => isset( $cookie_def['duration'] ) ? $cookie_def['duration'] : null,
					);
				}
			}
		}

		// WordPress core cookies.
		if ( ! empty( $phase1['wordpress_cookies'] ) ) {
			foreach ( $phase1['wordpress_cookies'] as $cookie_def ) {
				$name = isset( $cookie_def['name'] ) ? $cookie_def['name'] : '';
				if ( empty( $name ) || isset( $seen_names[ $name ] ) ) {
					continue;
				}
				$seen_names[ $name ] = true;

				$cookies[] = array(
					'name'                  => $name,
					'value'                 => '',
					'domain'                => $site_host,
					'path'                  => '/',
					'expires'               => '',
					'httpOnly'              => null,
					'secure'                => null,
					'sameSite'              => null,
					'source'                => 'wordpress_core',
					'sourceScript'          => '',
					'isThirdParty'          => false,
					'category'              => isset( $cookie_def['category'] ) ? $cookie_def['category'] : 'necessary',
					'vendor'                => 'WordPress',
					'description'           => isset( $cookie_def['purpose'] ) ? $cookie_def['purpose'] : null,
					'suggestedBlock'        => null,
					'frameContext'          => 'main-frame',
					'attribution'           => null,
					'tagManagerAttribution' => null,
					'scriptAttribution'     => null,
					'wpDetectionMethod'     => 'wordpress_core',
					'wpPagesFound'          => array(),
					'wpPluginSource'        => 'WordPress',
					'wpPluginSlug'          => null,
					'wpDuration'            => isset( $cookie_def['duration'] ) ? $cookie_def['duration'] : null,
					'adminOnly'             => ! empty( $cookie_def['admin_only'] ),
				);
			}
		}

		return $cookies;
	}

	/**
	 * Build storage array from Phase 2 data.
	 *
	 * @param array $phase2 Phase 2 results.
	 * @return array Storage items in remote scanner schema format.
	 */
	private function build_storage( $phase2 ) {
		$storage   = array();
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( empty( $phase2['live_storage'] ) ) {
			return $storage;
		}

		foreach ( $phase2['live_storage'] as $item ) {
			$plugin_slug = $this->find_plugin_slug_for_cookie( $item['name'] );

			$storage[] = array(
				'type'             => $item['type'],
				'key'              => $item['name'],
				'value'            => '',
				'origin'           => $site_host,
				'sourceScript'     => '',
				'category'         => ! empty( $item['category'] ) ? $item['category'] : 'unclassified',
				'vendor'           => ! empty( $item['service'] ) ? $item['service'] : null,
				'suggestedBlock'   => $this->build_suggested_block_for_service( $plugin_slug ),
				'wpPagesFound'     => ! empty( $item['page'] ) ? $item['page'] : array(),
				'wpPluginSource'   => ! empty( $item['service'] ) ? $item['service'] : null,
				'wpPluginSlug'     => $plugin_slug,
			);
		}

		return $storage;
	}

	/**
	 * Build tracking pixels from Phase 2 HTML-detected services.
	 *
	 * The WP scanner cannot intercept network requests, so we infer
	 * tracking pixels from known services detected in HTML.
	 *
	 * @param array $phase2 Phase 2 results.
	 * @return array Tracking pixel entries.
	 */
	private function build_tracking_pixels( $phase2 ) {
		$pixels = array();

		// Services that typically use tracking pixels.
		$pixel_services = array(
			'facebook'        => array( 'domain' => 'www.facebook.com', 'type' => 'img', 'category' => 'marketing' ),
			'google-analytics' => array( 'domain' => 'www.google-analytics.com', 'type' => 'beacon', 'category' => 'analytics' ),
			'linkedin'        => array( 'domain' => 'px.ads.linkedin.com', 'type' => 'img', 'category' => 'marketing' ),
			'pinterest'       => array( 'domain' => 'ct.pinterest.com', 'type' => 'img', 'category' => 'marketing' ),
			'tiktok'          => array( 'domain' => 'analytics.tiktok.com', 'type' => 'img', 'category' => 'marketing' ),
			'microsoft-ads'   => array( 'domain' => 'bat.bing.com', 'type' => 'img', 'category' => 'marketing' ),
			'microsoft-clarity' => array( 'domain' => 'c.clarity.ms', 'type' => 'img', 'category' => 'analytics' ),
			'clarity'         => array( 'domain' => 'c.clarity.ms', 'type' => 'img', 'category' => 'analytics' ),
			'hotjar'          => array( 'domain' => 'vars.hotjar.com', 'type' => 'img', 'category' => 'analytics' ),
			'snapchat'        => array( 'domain' => 'tr.snapchat.com', 'type' => 'img', 'category' => 'marketing' ),
		);

		$detected = $this->get_all_detected_services( $phase2 );

		foreach ( $pixel_services as $slug => $info ) {
			if ( in_array( $slug, $detected, true ) ) {
				$pixels[] = array(
					'url'               => $info['domain'],
					'domain'            => $info['domain'],
					'type'              => $info['type'],
					'sourceScript'      => null,
					'vendor'            => isset( self::$vendor_map[ $slug ] ) ? self::$vendor_map[ $slug ] : $slug,
					'category'          => $info['category'],
					'wpDetectionMethod' => 'html_parse',
				);
			}
		}

		return $pixels;
	}

	/**
	 * Build third-party scripts from Phase 1 enqueued scripts and Phase 2 HTML detection.
	 *
	 * @param array $phase1 Phase 1 results.
	 * @param array $phase2 Phase 2 results.
	 * @return array Third-party script entries.
	 */
	private function build_third_party_scripts( $phase1, $phase2 ) {
		$scripts    = array();
		$seen_domains = array();

		// Phase 1: enqueued tracking scripts.
		if ( ! empty( $phase1['enqueued_scripts'] ) ) {
			foreach ( $phase1['enqueued_scripts'] as $script ) {
				$domain = ! empty( $script['domain'] ) ? $script['domain'] : '';
				$scripts[] = array(
					'url'               => $script['src'],
					'domain'            => $domain,
					'initiator'         => home_url( '/' ),
					'wpHandle'          => ! empty( $script['handle'] ) ? $script['handle'] : null,
					'wpDetectionMethod' => 'enqueued_script',
				);
				$seen_domains[ $domain ] = true;
			}
		}

		// Phase 2: HTML-detected third-party services.
		$html_services = array_merge(
			! empty( $phase2['thirdparty'] ) ? $phase2['thirdparty'] : array(),
			! empty( $phase2['social_media'] ) ? $phase2['social_media'] : array(),
			! empty( $phase2['statistics'] ) ? $phase2['statistics'] : array()
		);

		foreach ( $html_services as $slug ) {
			$domain = isset( self::$domain_map[ $slug ] ) ? self::$domain_map[ $slug ] : '';
			if ( empty( $domain ) || isset( $seen_domains[ $domain ] ) ) {
				continue;
			}
			$seen_domains[ $domain ] = true;

			$scripts[] = array(
				'url'               => 'https://' . $domain,
				'domain'            => $domain,
				'initiator'         => home_url( '/' ),
				'wpHandle'          => null,
				'wpDetectionMethod' => 'html_parse',
			);
		}

		return $scripts;
	}

	/**
	 * Build tag managers list.
	 *
	 * @param array $phase2 Phase 2 results.
	 * @return array Tag manager entries.
	 */
	private function build_tag_managers( $phase2 ) {
		$managers = array();

		if ( ! empty( $phase2['tracking_ids'] ) ) {
			foreach ( $phase2['tracking_ids'] as $tid ) {
				if ( 'gtm' === $tid['type'] ) {
					$managers[] = array(
						'url'               => 'https://www.googletagmanager.com/gtm.js',
						'domain'            => 'www.googletagmanager.com',
						'name'              => 'Google Tag Manager',
						'wpDetectionMethod' => 'html_parse',
					);
					break; // One GTM entry is sufficient.
				}
			}
		}

		// Also detect from statistics array.
		$stats = ! empty( $phase2['statistics'] ) ? $phase2['statistics'] : array();
		if ( in_array( 'google-tag-manager', $stats, true ) && empty( $managers ) ) {
			$managers[] = array(
				'url'               => 'https://www.googletagmanager.com/gtm.js',
				'domain'            => 'www.googletagmanager.com',
				'name'              => 'Google Tag Manager',
				'wpDetectionMethod' => 'html_parse',
			);
		}

		return $managers;
	}

	/**
	 * Build fonts list.
	 *
	 * @param array $phase2 Phase 2 results.
	 * @return array Font entries.
	 */
	private function build_fonts( $phase2 ) {
		$fonts    = array();
		$detected = $this->get_all_detected_services( $phase2 );

		if ( in_array( 'google-fonts', $detected, true ) ) {
			$fonts[] = array(
				'url'               => 'https://fonts.googleapis.com',
				'domain'            => 'fonts.googleapis.com',
				'wpDetectionMethod' => 'html_parse',
			);
		}

		if ( in_array( 'adobe-fonts', $detected, true ) ) {
			$fonts[] = array(
				'url'               => 'https://use.typekit.net',
				'domain'            => 'use.typekit.net',
				'wpDetectionMethod' => 'html_parse',
			);
		}

		return $fonts;
	}

	/**
	 * Build iframes list from HTML-detected iframe-type services.
	 *
	 * @param array $phase2 Phase 2 results.
	 * @return array Iframe entries.
	 */
	private function build_iframes( $phase2 ) {
		$iframes  = array();
		$detected = $this->get_all_detected_services( $phase2 );

		foreach ( self::$iframe_services as $slug ) {
			if ( in_array( $slug, $detected, true ) ) {
				$domain = isset( self::$domain_map[ $slug ] ) ? self::$domain_map[ $slug ] : $slug . '.com';
				$vendor = isset( self::$vendor_map[ $slug ] ) ? self::$vendor_map[ $slug ] : ucfirst( $slug );

				$iframes[] = array(
					'src'               => 'https://' . $domain,
					'origin'            => $domain,
					'vendor'            => $vendor,
					'category'          => $this->get_category_for_slug( $slug, $phase2 ),
					'wpDetectionMethod' => 'html_parse',
				);
			}
		}

		return $iframes;
	}

	/**
	 * Build script-to-cookie map from Phase 1 known plugins.
	 *
	 * @param array $phase1 Phase 1 results.
	 * @return object Script domain to cookie names mapping.
	 */
	private function build_script_cookie_map( $phase1 ) {
		$map = array();

		if ( empty( $phase1['known_plugins'] ) ) {
			return (object) $map;
		}

		foreach ( $phase1['known_plugins'] as $plugin ) {
			if ( empty( $plugin['domains'] ) || empty( $plugin['cookies'] ) ) {
				continue;
			}

			$cookie_names = array();
			foreach ( $plugin['cookies'] as $cookie ) {
				if ( ! empty( $cookie['name'] ) ) {
					$cookie_names[] = $cookie['name'];
				}
			}

			if ( empty( $cookie_names ) ) {
				continue;
			}

			foreach ( $plugin['domains'] as $domain ) {
				if ( ! isset( $map[ $domain ] ) ) {
					$map[ $domain ] = array();
				}
				$map[ $domain ] = array_values( array_unique( array_merge( $map[ $domain ], $cookie_names ) ) );
			}
		}

		return (object) $map;
	}

	/**
	 * Build deduplicated trackers list.
	 *
	 * @param array $phase1 Phase 1 results.
	 * @param array $phase2 Phase 2 results.
	 * @return array Tracker entries.
	 */
	private function build_trackers( $phase1, $phase2 ) {
		$trackers  = array();
		$seen_keys = array();

		// From Phase 1 known plugins.
		if ( ! empty( $phase1['known_plugins'] ) ) {
			foreach ( $phase1['known_plugins'] as $plugin ) {
				$name = isset( $plugin['name'] ) ? $plugin['name'] : '';
				$key  = strtolower( $name );
				if ( empty( $key ) || isset( $seen_keys[ $key ] ) ) {
					continue;
				}
				$seen_keys[ $key ] = true;

				$domains = array();
				if ( ! empty( $plugin['domains'] ) ) {
					foreach ( $plugin['domains'] as $d ) {
						$domains[] = $d;
					}
				}

				$trackers[] = array(
					'url'               => ! empty( $domains ) ? 'https://' . $domains[0] : '',
					'domain'            => ! empty( $domains ) ? $domains[0] : '',
					'vendor'            => $name,
					'category'          => isset( $plugin['category'] ) ? $plugin['category'] : 'unclassified',
					'wpDetectionMethod' => 'known_database',
					'wpPluginSlug'      => isset( $plugin['file'] ) ? $plugin['file'] : null,
				);
			}
		}

		// From Phase 1 enqueued scripts.
		if ( ! empty( $phase1['enqueued_scripts'] ) ) {
			foreach ( $phase1['enqueued_scripts'] as $script ) {
				$domain = ! empty( $script['domain'] ) ? $script['domain'] : '';
				$key    = strtolower( $domain );
				if ( empty( $key ) || isset( $seen_keys[ $key ] ) ) {
					continue;
				}
				$seen_keys[ $key ] = true;

				$trackers[] = array(
					'url'               => $script['src'],
					'domain'            => $domain,
					'vendor'            => null,
					'category'          => 'unclassified',
					'wpDetectionMethod' => 'enqueued_script',
					'wpPluginSlug'      => null,
				);
			}
		}

		// From Phase 2 HTML detection.
		$html_services = array_merge(
			! empty( $phase2['statistics'] ) ? $phase2['statistics'] : array(),
			! empty( $phase2['social_media'] ) ? $phase2['social_media'] : array(),
			! empty( $phase2['thirdparty'] ) ? $phase2['thirdparty'] : array()
		);

		foreach ( $html_services as $slug ) {
			$key = strtolower( $slug );
			if ( isset( $seen_keys[ $key ] ) ) {
				continue;
			}
			$seen_keys[ $key ] = true;

			$domain = isset( self::$domain_map[ $slug ] ) ? self::$domain_map[ $slug ] : '';
			$vendor = isset( self::$vendor_map[ $slug ] ) ? self::$vendor_map[ $slug ] : ucfirst( str_replace( '-', ' ', $slug ) );

			$trackers[] = array(
				'url'               => ! empty( $domain ) ? 'https://' . $domain : '',
				'domain'            => $domain,
				'vendor'            => $vendor,
				'category'          => $this->get_category_for_slug( $slug, $phase2 ),
				'wpDetectionMethod' => 'html_parse',
				'wpPluginSlug'      => null,
			);
		}

		// From Phase 1 options tracking.
		if ( ! empty( $phase1['options_tracking'] ) ) {
			foreach ( $phase1['options_tracking'] as $opt ) {
				$service = isset( $opt['service'] ) ? $opt['service'] : '';
				$key     = strtolower( $service );
				if ( empty( $key ) || isset( $seen_keys[ $key ] ) ) {
					continue;
				}
				$seen_keys[ $key ] = true;

				$trackers[] = array(
					'url'               => '',
					'domain'            => '',
					'vendor'            => $service,
					'category'          => isset( $opt['category'] ) ? $opt['category'] : 'unclassified',
					'wpDetectionMethod' => 'options_table',
					'wpPluginSlug'      => isset( $opt['plugin_slug'] ) ? $opt['plugin_slug'] : null,
				);
			}
		}

		// From Phase 1 theme tracking.
		if ( ! empty( $phase1['theme_tracking'] ) ) {
			foreach ( $phase1['theme_tracking'] as $theme_match ) {
				$match = isset( $theme_match['match'] ) ? $theme_match['match'] : '';
				$key   = strtolower( $match );
				if ( empty( $key ) || isset( $seen_keys[ $key ] ) ) {
					continue;
				}
				$seen_keys[ $key ] = true;

				$trackers[] = array(
					'url'               => '',
					'domain'            => $match,
					'vendor'            => null,
					'category'          => 'unclassified',
					'wpDetectionMethod' => 'theme_scan',
					'wpPluginSlug'      => null,
				);
			}
		}

		return $trackers;
	}

	/**
	 * Build others section (first-party scripts).
	 *
	 * @param array $phase1 Phase 1 results.
	 * @return array Others section.
	 */
	private function build_others( $phase1 ) {
		return array(
			'scripts'     => array(),
			'iframes'     => array(),
			'googleFonts' => array(),
		);
	}

	/**
	 * Build WordPress-specific metadata.
	 *
	 * @param array $phase1 Phase 1 results.
	 * @param array $phase2 Phase 2 results.
	 * @return array WP meta block.
	 */
	private function build_wp_meta( $phase1, $phase2 ) {
		global $wp_version;

		$clean_plugins = array();
		if ( ! empty( $phase1['clean_plugins'] ) ) {
			foreach ( $phase1['clean_plugins'] as $p ) {
				$clean_plugins[] = isset( $p['name'] ) ? $p['name'] : '';
			}
		}

		$not_in_database = array();
		if ( ! empty( $phase1['not_in_database'] ) ) {
			foreach ( $phase1['not_in_database'] as $p ) {
				$not_in_database[] = isset( $p['name'] ) ? $p['name'] : '';
			}
		}

		$options_tracking = array();
		if ( ! empty( $phase1['options_tracking'] ) ) {
			foreach ( $phase1['options_tracking'] as $opt ) {
				$options_tracking[] = array(
					'service'  => isset( $opt['service'] ) ? $opt['service'] : '',
					'category' => isset( $opt['category'] ) ? $opt['category'] : '',
					'source'   => isset( $opt['source'] ) ? $opt['source'] : '',
				);
			}
		}

		$theme_tracking = array();
		if ( ! empty( $phase1['theme_tracking'] ) ) {
			foreach ( $phase1['theme_tracking'] as $tm ) {
				$theme_tracking[] = array(
					'theme'      => isset( $tm['theme'] ) ? $tm['theme'] : '',
					'file'       => isset( $tm['file'] ) ? $tm['file'] : '',
					'match'      => isset( $tm['match'] ) ? $tm['match'] : '',
					'match_type' => isset( $tm['match_type'] ) ? $tm['match_type'] : '',
				);
			}
		}

		return array(
			'scannerVersion'   => CONSENTLY_VERSION,
			'scanSource'       => 'wordpress_plugin',
			'wordpressVersion' => $wp_version,
			'phpVersion'       => PHP_VERSION,
			'siteUrl'          => home_url( '/' ),
			'activePlugins'    => count( get_option( 'active_plugins', array() ) ),
			'activeTheme'      => wp_get_theme()->get( 'Name' ),
			'pagesScanned'     => isset( $phase2['pages_scanned'] ) ? $phase2['pages_scanned'] : 0,
			'phase1ScanTime'   => isset( $phase1['scan_time'] ) ? $phase1['scan_time'] : 0,
			'cleanPlugins'     => $clean_plugins,
			'notInDatabase'    => $not_in_database,
			'doubleStats'      => isset( $phase2['double_stats'] ) ? $phase2['double_stats'] : array(),
			'optionsTracking'  => $options_tracking,
			'themeTracking'    => $theme_tracking,
			'cachePlugins'     => Consently_Core::get_instance()->detect_cache_plugins(),
		);
	}

	// ──────────────────────────────────────────────
	//  HELPER METHODS
	// ──────────────────────────────────────────────

	/**
	 * Find the plugin slug that sets a given cookie name.
	 *
	 * @param string $cookie_name Cookie name.
	 * @return string|null Plugin file slug or null.
	 */
	private function find_plugin_slug_for_cookie( $cookie_name ) {
		$db = $this->get_known_plugins_db();

		if ( empty( $db['plugins'] ) ) {
			return null;
		}

		foreach ( $db['plugins'] as $plugin_file => $plugin_info ) {
			if ( empty( $plugin_info['cookies'] ) ) {
				continue;
			}
			foreach ( $plugin_info['cookies'] as $cookie ) {
				$name    = isset( $cookie['name'] ) ? $cookie['name'] : '';
				$pattern = isset( $cookie['pattern'] ) ? $cookie['pattern'] : 'exact';

				if ( 'prefix' === $pattern ) {
					$prefix = rtrim( $name, '*.' );
					if ( '' !== $prefix && 0 === strpos( $cookie_name, $prefix ) ) {
						return $plugin_file;
					}
				} elseif ( $cookie_name === $name ) {
					return $plugin_file;
				}
			}
		}

		return null;
	}

	/**
	 * Check if a service is third-party relative to the site.
	 *
	 * @param string $service  Service name.
	 * @param string $site_host Site host.
	 * @return bool True if third-party.
	 */
	private function is_third_party_service( $service, $site_host ) {
		if ( empty( $service ) ) {
			return false;
		}

		$first_party = array( 'WordPress', '' );
		return ! in_array( $service, $first_party, true );
	}

	/**
	 * Build suggested block for a known plugin.
	 *
	 * @param array $plugin Plugin data with domains.
	 * @return array|null Suggested block or null.
	 */
	private function build_suggested_block_for_plugin( $plugin ) {
		if ( ! empty( $plugin['domains'] ) && is_array( $plugin['domains'] ) ) {
			return array(
				'type'  => 'domain',
				'value' => $plugin['domains'][0],
			);
		}
		return null;
	}

	/**
	 * Build suggested block from a plugin slug.
	 *
	 * @param string|null $plugin_slug Plugin file slug.
	 * @return array|null Suggested block or null.
	 */
	private function build_suggested_block_for_service( $plugin_slug ) {
		if ( empty( $plugin_slug ) ) {
			return null;
		}

		$db = $this->get_known_plugins_db();
		if ( isset( $db['plugins'][ $plugin_slug ] ) ) {
			$entry = $db['plugins'][ $plugin_slug ];
			if ( ! empty( $entry['domains'] ) && is_array( $entry['domains'] ) ) {
				return array(
					'type'  => 'domain',
					'value' => $entry['domains'][0],
				);
			}
		}

		return null;
	}

	/**
	 * Get all detected service slugs from Phase 2.
	 *
	 * @param array $phase2 Phase 2 results.
	 * @return array Unique service slugs.
	 */
	private function get_all_detected_services( $phase2 ) {
		return array_unique( array_merge(
			! empty( $phase2['social_media'] ) ? $phase2['social_media'] : array(),
			! empty( $phase2['thirdparty'] ) ? $phase2['thirdparty'] : array(),
			! empty( $phase2['statistics'] ) ? $phase2['statistics'] : array()
		) );
	}

	/**
	 * Get category for a service slug based on Phase 2 detection.
	 *
	 * @param string $slug   Service slug.
	 * @param array  $phase2 Phase 2 results.
	 * @return string Category string.
	 */
	private function get_category_for_slug( $slug, $phase2 ) {
		if ( ! empty( $phase2['statistics'] ) && in_array( $slug, $phase2['statistics'], true ) ) {
			return 'analytics';
		}
		if ( ! empty( $phase2['social_media'] ) && in_array( $slug, $phase2['social_media'], true ) ) {
			return 'marketing';
		}
		if ( ! empty( $phase2['thirdparty'] ) && in_array( $slug, $phase2['thirdparty'], true ) ) {
			return 'functional';
		}
		return 'unclassified';
	}

	/**
	 * Count storage items by type.
	 *
	 * @param array  $storage Storage items.
	 * @param string $type    Storage type.
	 * @return int Count.
	 */
	private function count_storage_by_type( $storage, $type ) {
		$count = 0;
		foreach ( $storage as $item ) {
			if ( isset( $item['type'] ) && $item['type'] === $type ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Get known plugins database (cached).
	 *
	 * @return array Known plugins data.
	 */
	private function get_known_plugins_db() {
		if ( null !== $this->known_plugins_db ) {
			return $this->known_plugins_db;
		}

		$json_file = CONSENTLY_PLUGIN_DIR . 'data/known-plugins.json';

		if ( ! file_exists( $json_file ) ) {
			$this->known_plugins_db = array();
			return $this->known_plugins_db;
		}

		$contents = file_get_contents( $json_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $contents ) {
			$this->known_plugins_db = array();
			return $this->known_plugins_db;
		}

		$data = json_decode( $contents, true );

		$this->known_plugins_db = is_array( $data ) ? $data : array();
		return $this->known_plugins_db;
	}
}
