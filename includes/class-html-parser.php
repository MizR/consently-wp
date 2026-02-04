<?php
/**
 * HTML Parser class for Consently plugin.
 *
 * Parses page HTML to detect social media embeds, third-party services,
 * analytics scripts, tracking IDs, and double statistics.
 *
 * @package Consently
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HTML content parser for Plugin Audit v2.
 */
class Consently_HTML_Parser {

	/**
	 * Social media markers keyed by service slug.
	 *
	 * @var array
	 */
	private $social_media_markers = array(
		'facebook'  => array(
			'fbq(',
			'connect.facebook.net',
			'www.facebook.com/plugins',
			'fb-root',
			'Facebook Pixel Code',
			'facebook.com/plugins',
		),
		'instagram' => array(
			'instagram.com/embed',
			'instagram.com/p/',
			'platform.instagram.com',
			'instawidget.net',
		),
		'twitter'   => array(
			'platform.twitter.com',
			'twitter-widgets.js',
			'ads-twitter.com',
			'twitter.com/intent',
		),
		'linkedin'  => array(
			'platform.linkedin.com',
			'linkedin.com/embed',
			'snap.licdn.com',
			'insight.min.js',
		),
		'pinterest' => array(
			'assets.pinterest.com',
			'pinterest.com/pin/create',
		),
		'tiktok'    => array(
			'tiktok.com/embed',
			'analytics.tiktok.com',
			'www.tiktok.com/embed',
		),
		'snapchat'  => array(
			'snapchat.com',
			'sc-static.net',
		),
		'disqus'    => array(
			'disqus.com',
		),
	);

	/**
	 * Third-party service markers keyed by service slug.
	 *
	 * @var array
	 */
	private $thirdparty_markers = array(
		'youtube'              => array(
			'youtube.com/embed',
			'youtube-nocookie.com',
			'youtube.com/iframe_api',
			'youtu.be/',
			'www.youtube.com/watch',
		),
		'vimeo'                => array(
			'player.vimeo.com',
			'i.vimeocdn.com',
		),
		'google-maps'          => array(
			'maps.google.com',
			'google.com/maps',
			'maps.googleapis.com',
			'new google.maps.',
			'wp-google-maps',
		),
		'google-recaptcha'     => array(
			'google.com/recaptcha',
			'grecaptcha',
			'recaptcha/api',
			'recaptcha.js',
		),
		'google-fonts'         => array(
			'fonts.googleapis.com',
			'fonts.gstatic.com',
		),
		'spotify'              => array(
			'open.spotify.com/embed',
		),
		'soundcloud'           => array(
			'w.soundcloud.com/player',
			'api.soundcloud.com',
		),
		'dailymotion'          => array(
			'dailymotion.com/embed',
		),
		'hubspot'              => array(
			'js.hs-scripts.com',
			'js.hsforms.net',
			'hbspt.forms.create',
			'track.hubspot.com',
			'js.hs-analytics.net',
		),
		'calendly'             => array(
			'assets.calendly.com',
			'calendly.com/widget',
		),
		'typeform'             => array(
			'embed.typeform.com',
		),
		'intercom'             => array(
			'widget.intercom.io',
			'js.intercomcdn.com',
			'Intercom(',
		),
		'hotjar'               => array(
			'static.hotjar.com',
			'script.hotjar.com',
		),
		'livechat'             => array(
			'cdn.livechatinc.com',
		),
		'openstreetmaps'       => array(
			'openstreetmap.org',
		),
		'paypal'               => array(
			'www.paypal.com/tagmanager',
			'www.paypalobjects.com',
			'paypal.com/sdk',
		),
		'stripe'               => array(
			'js.stripe.com',
		),
		'addthis'              => array(
			'addthis.com',
			's7.addthis.com',
		),
		'addtoany'             => array(
			'static.addtoany.com',
		),
		'sharethis'            => array(
			'sharethis.com',
		),
		'microsoft-ads'        => array(
			'bat.bing.com',
		),
		'microsoft-clarity'    => array(
			'clarity.ms',
		),
		'adobe-fonts'          => array(
			'p.typekit.net',
			'use.typekit.net',
		),
		'twitch'               => array(
			'player.twitch.tv',
			'embed.twitch.tv',
		),
		'wistia'               => array(
			'fast.wistia.com',
			'wistia.net',
		),
		'loom'                 => array(
			'loom.com/embed',
		),
		'apple-podcasts'       => array(
			'embed.podcasts.apple.com',
		),
		'tawk-to'              => array(
			'embed.tawk.to',
		),
		'drift'                => array(
			'js.driftt.com',
		),
		'crisp'                => array(
			'client.crisp.chat',
		),
		'tidio'                => array(
			'code.tidio.co',
		),
		'cloudflare-turnstile' => array(
			'challenges.cloudflare.com/turnstile',
		),
		'hcaptcha'             => array(
			'hcaptcha.com',
			'js.hcaptcha.com',
		),
	);

	/**
	 * Statistics markers keyed by service slug.
	 *
	 * @var array
	 */
	private $stats_markers = array(
		'google-analytics'   => array(
			'google-analytics.com/ga.js',
			'www.google-analytics.com/analytics.js',
			'_getTracker',
			"gtag('js'",
			'gtag("js"',
			'googletagmanager.com/gtag/js',
		),
		'google-tag-manager' => array(
			'gtm.start',
			'gtm.js',
			'googletagmanager.com/gtm.js',
		),
		'matomo'             => array(
			'piwik.js',
			'matomo.js',
			'matomo.cloud',
		),
		'clicky'             => array(
			'static.getclicky.com/js',
			'clicky_site_ids',
		),
		'yandex'             => array(
			'mc.yandex.ru/metrika/watch.js',
			'mc.yandex.ru/metrika/tag.js',
		),
		'clarity'            => array(
			'clarity.ms/tag/',
		),
		'plausible'          => array(
			'plausible.io/js',
		),
		'fathom'             => array(
			'cdn.usefathom.com',
		),
		'heap'               => array(
			'cdn.heapanalytics.com',
		),
		'mixpanel'           => array(
			'cdn.mxpnl.com',
		),
		'amplitude'          => array(
			'cdn.amplitude.com',
		),
		'segment'            => array(
			'cdn.segment.com/analytics.js',
		),
	);

	/**
	 * Fetch and parse a page by URL.
	 *
	 * @param string $url The URL to fetch and parse.
	 * @return array Parsed results.
	 */
	public function parse_page( string $url ): array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 15,
				'sslverify'  => false,
				'user-agent' => 'Consently-Scanner/1.0',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->empty_result( $response->get_error_message() );
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code < 200 || $status_code >= 400 ) {
			return $this->empty_result(
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'HTTP error: %d', 'consently' ),
					$status_code
				)
			);
		}

		$html = wp_remote_retrieve_body( $response );

		if ( empty( $html ) ) {
			return $this->empty_result( __( 'Empty response body', 'consently' ) );
		}

		return $this->parse_html( $html );
	}

	/**
	 * Parse an HTML string for tracking and third-party services.
	 *
	 * @param string $html The HTML content to parse.
	 * @return array Parsed results.
	 */
	public function parse_html( string $html ): array {
		$result = $this->empty_result();

		$result['social_media'] = $this->detect_markers( $html, $this->social_media_markers );
		$result['thirdparty']   = $this->detect_markers( $html, $this->thirdparty_markers );
		$result['statistics']   = $this->detect_markers( $html, $this->stats_markers );
		$result['tracking_ids'] = $this->extract_tracking_ids( $html );
		$result['double_stats'] = $this->detect_double_stats( $html );

		return $result;
	}

	/**
	 * Detect markers in HTML content.
	 *
	 * Loops through each service and its markers. One match per service
	 * is enough; breaks after the first matching marker for each service.
	 *
	 * @param string $html    The HTML content.
	 * @param array  $markers Marker arrays keyed by service slug.
	 * @return array List of detected service slugs.
	 */
	private function detect_markers( string $html, array $markers ): array {
		$detected = array();

		foreach ( $markers as $service => $service_markers ) {
			foreach ( $service_markers as $marker ) {
				if ( false !== stripos( $html, $marker ) ) {
					$detected[] = $service;
					break;
				}
			}
		}

		return $detected;
	}

	/**
	 * Extract tracking IDs from HTML content using regex patterns.
	 *
	 * @param string $html The HTML content.
	 * @return array List of tracking ID arrays with type, id, and service keys.
	 */
	private function extract_tracking_ids( string $html ): array {
		$tracking_ids = array();

		// GTM IDs.
		if ( preg_match_all( '/GTM-[A-Z0-9]{4,8}/', $html, $matches ) ) {
			foreach ( array_unique( $matches[0] ) as $id ) {
				$tracking_ids[] = array(
					'type'    => 'gtm',
					'id'      => $id,
					'service' => 'Google Tag Manager',
				);
			}
		}

		// GA4 IDs.
		if ( preg_match_all( '/G-[A-Z0-9]{6,12}/', $html, $matches ) ) {
			foreach ( array_unique( $matches[0] ) as $id ) {
				$tracking_ids[] = array(
					'type'    => 'ga4',
					'id'      => $id,
					'service' => 'Google Analytics 4',
				);
			}
		}

		// Universal Analytics IDs.
		if ( preg_match_all( '/UA-[0-9]{4,10}-[0-9]{1,4}/', $html, $matches ) ) {
			foreach ( array_unique( $matches[0] ) as $id ) {
				$tracking_ids[] = array(
					'type'    => 'ua',
					'id'      => $id,
					'service' => 'Universal Analytics',
				);
			}
		}

		// Google Ads IDs.
		if ( preg_match_all( '/AW-[0-9]{8,12}/', $html, $matches ) ) {
			foreach ( array_unique( $matches[0] ) as $id ) {
				$tracking_ids[] = array(
					'type'    => 'google-ads',
					'id'      => $id,
					'service' => 'Google Ads',
				);
			}
		}

		// Facebook Pixel IDs.
		if ( preg_match_all( '/fbq\s*\(\s*[\'"]init[\'"]\s*,\s*[\'"]([0-9]{14,17})[\'"]/', $html, $matches ) ) {
			foreach ( array_unique( $matches[1] ) as $id ) {
				$tracking_ids[] = array(
					'type'    => 'facebook-pixel',
					'id'      => $id,
					'service' => 'Facebook Pixel',
				);
			}
		}

		// Hotjar IDs.
		if ( preg_match_all( '/h\._hjSettings\s*.*?hjid\s*:\s*([0-9]{6,8})/', $html, $matches ) ) {
			foreach ( array_unique( $matches[1] ) as $id ) {
				$tracking_ids[] = array(
					'type'    => 'hotjar',
					'id'      => $id,
					'service' => 'Hotjar',
				);
			}
		}

		// Microsoft Clarity IDs.
		if ( preg_match_all( '/clarity\.ms\/tag\/([a-z0-9]+)/', $html, $matches ) ) {
			foreach ( array_unique( $matches[1] ) as $id ) {
				$tracking_ids[] = array(
					'type'    => 'clarity',
					'id'      => $id,
					'service' => 'Microsoft Clarity',
				);
			}
		}

		// Matomo Site IDs.
		if ( preg_match_all( '/setSiteId\s*[\'",\s]*([0-9]{1,6})/', $html, $matches ) ) {
			foreach ( array_unique( $matches[1] ) as $id ) {
				$tracking_ids[] = array(
					'type'    => 'matomo',
					'id'      => $id,
					'service' => 'Matomo',
				);
			}
		}

		return $tracking_ids;
	}

	/**
	 * Detect double statistics implementations.
	 *
	 * Counts regex matches for each analytics service. If the total
	 * count for a service exceeds 1, it is flagged as a double.
	 *
	 * @param string $html The HTML content.
	 * @return array List of service names with duplicate implementations.
	 */
	private function detect_double_stats( string $html ): array {
		$doubles = array();

		// Google Analytics patterns.
		$ga_count = 0;
		$ga_count += preg_match_all( '/ga\.js/', $html, $matches );
		$ga_count += preg_match_all( '/analytics\.js/', $html, $matches );
		$ga_count += preg_match_all( '/gtag\/js/', $html, $matches );

		if ( $ga_count > 1 ) {
			$doubles[] = 'Google Analytics';
		}

		// Google Tag Manager patterns.
		$gtm_count = 0;
		$gtm_count += preg_match_all( '/gtm\.js/', $html, $matches );

		if ( $gtm_count > 1 ) {
			$doubles[] = 'Google Tag Manager';
		}

		// Matomo patterns.
		$matomo_count = 0;
		$matomo_count += preg_match_all( '/piwik\.js/', $html, $matches );
		$matomo_count += preg_match_all( '/matomo\.js/', $html, $matches );

		if ( $matomo_count > 1 ) {
			$doubles[] = 'Matomo';
		}

		// Clicky patterns.
		$clicky_count = 0;
		$clicky_count += preg_match_all( '/getclicky\.com\/js/', $html, $matches );

		if ( $clicky_count > 1 ) {
			$doubles[] = 'Clicky';
		}

		return $doubles;
	}

	/**
	 * Return an empty result structure.
	 *
	 * @param string|null $error Optional error message.
	 * @return array Empty result array.
	 */
	private function empty_result( ?string $error = null ): array {
		return array(
			'social_media' => array(),
			'thirdparty'   => array(),
			'statistics'   => array(),
			'tracking_ids' => array(),
			'double_stats' => array(),
			'error'        => $error,
		);
	}
}
