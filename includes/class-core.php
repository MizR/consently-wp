<?php
/**
 * Core class for Consently Scanner plugin.
 *
 * @package Consently
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin core class.
 */
class Consently_Core {

	/**
	 * Single instance of the class.
	 *
	 * @var Consently_Core|null
	 */
	private static $instance = null;

	/**
	 * Admin instance.
	 *
	 * @var Consently_Admin
	 */
	public $admin;

	/**
	 * Audit instance.
	 *
	 * @var Consently_Audit
	 */
	public $audit;

	/**
	 * Live Scan instance.
	 *
	 * @var Consently_Live_Scan
	 */
	public $live_scan;

	/**
	 * Get single instance of the class.
	 *
	 * @return Consently_Core
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->init_components();
		$this->init_hooks();
	}

	/**
	 * Initialize plugin components.
	 */
	private function init_components() {
		$this->audit     = new Consently_Audit();
		$this->live_scan = new Consently_Live_Scan( $this->audit );
		$this->admin     = new Consently_Admin( $this );

		// Initialize live scan REST routes.
		$this->live_scan->init();
	}

	/**
	 * Initialize hooks.
	 */
	private function init_hooks() {
		// Settings link on plugins page.
		add_filter( 'plugin_action_links_' . CONSENTLY_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );

		// Frontend hooks.
		if ( ! is_admin() ) {
			$this->init_frontend_hooks();
		}
	}

	/**
	 * Initialize frontend hooks for scan mode and script capture.
	 */
	private function init_frontend_hooks() {
		// Scan mode: inject cookie collector when scan token is present.
		if ( $this->is_scan_request() ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scan_script' ), 1 );
		}

		// Capture enqueued tracking scripts for audit (delegates to audit class).
		add_action( 'wp_print_scripts', array( $this->audit, 'capture_enqueued_scripts' ), 9999 );
	}

	/**
	 * Check if this is a scan request.
	 *
	 * @return bool True if scan token parameter is present.
	 */
	private function is_scan_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['consently_scan_token'] ) && ! empty( $_GET['consently_scan_token'] );
	}

	/**
	 * Enqueue the scan cookie collector script.
	 */
	public function enqueue_scan_script() {
		wp_enqueue_script(
			'consently-scan-cookies',
			CONSENTLY_PLUGIN_URL . 'assets/js/scan-cookies.js',
			array(),
			CONSENTLY_VERSION,
			true
		);

		wp_localize_script(
			'consently-scan-cookies',
			'consentlyScanConfig',
			array(
				'restUrl' => esc_url_raw( rest_url() ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Add plugin action links.
	 *
	 * @param array $links Existing links.
	 * @return array Modified links.
	 */
	public function plugin_action_links( $links ) {
		$settings_link = '<a href="' . admin_url( 'options-general.php?page=consently' ) . '">' . esc_html__( 'Scanner', 'consently' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Get normalized home URL host.
	 *
	 * @return string Normalized host (lowercase, no www prefix).
	 */
	public function get_normalized_home_host() {
		$home_url = home_url();
		$parsed   = wp_parse_url( $home_url );
		$host     = isset( $parsed['host'] ) ? strtolower( $parsed['host'] ) : '';

		// Remove www prefix for consistency.
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		return $host;
	}

	/**
	 * Get normalized home URL.
	 *
	 * @return string Normalized URL (lowercase, no trailing slash).
	 */
	public function get_normalized_home_url() {
		$home_url = home_url();
		$home_url = strtolower( $home_url );
		$home_url = rtrim( $home_url, '/' );

		return $home_url;
	}

	/**
	 * Detect installed cache plugins.
	 *
	 * @return array Array of detected cache plugin names.
	 */
	public function detect_cache_plugins() {
		$cache_plugins = array();

		$known_cache_plugins = array(
			'wp-rocket/wp-rocket.php'                    => 'WP Rocket',
			'w3-total-cache/w3-total-cache.php'          => 'W3 Total Cache',
			'litespeed-cache/litespeed-cache.php'        => 'LiteSpeed Cache',
			'wp-super-cache/wp-cache.php'                => 'WP Super Cache',
			'sg-cachepress/sg-cachepress.php'            => 'SG Optimizer',
			'autoptimize/autoptimize.php'                => 'Autoptimize',
			'wp-fastest-cache/wpFastestCache.php'        => 'WP Fastest Cache',
			'cache-enabler/cache-enabler.php'            => 'Cache Enabler',
			'hummingbird-performance/wp-hummingbird.php' => 'Hummingbird',
			'nitropack/main.php'                         => 'NitroPack',
			'flying-press/flying-press.php'              => 'FlyingPress',
			'perfmatters/perfmatters.php'                => 'Perfmatters',
		);

		$active_plugins = get_option( 'active_plugins', array() );

		// Check for multisite network-activated plugins.
		if ( is_multisite() ) {
			$network_plugins = get_site_option( 'active_sitewide_plugins', array() );
			$active_plugins  = array_merge( $active_plugins, array_keys( $network_plugins ) );
		}

		foreach ( $known_cache_plugins as $plugin_path => $plugin_name ) {
			if ( in_array( $plugin_path, $active_plugins, true ) ) {
				$cache_plugins[] = $plugin_name;
			}
		}

		return $cache_plugins;
	}
}
