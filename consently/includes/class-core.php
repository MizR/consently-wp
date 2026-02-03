<?php
/**
 * Core class for Consently plugin.
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
	 * API instance.
	 *
	 * @var Consently_API
	 */
	public $api;

	/**
	 * Admin instance.
	 *
	 * @var Consently_Admin
	 */
	public $admin;

	/**
	 * Script instance.
	 *
	 * @var Consently_Script
	 */
	public $script;

	/**
	 * Audit instance.
	 *
	 * @var Consently_Audit
	 */
	public $audit;

	/**
	 * WP Consent instance.
	 *
	 * @var Consently_WP_Consent
	 */
	public $wp_consent;

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
		$this->api        = new Consently_API();
		$this->admin      = new Consently_Admin( $this );
		$this->script     = new Consently_Script( $this );
		$this->audit      = new Consently_Audit();
		$this->wp_consent = new Consently_WP_Consent( $this );
	}

	/**
	 * Initialize hooks.
	 */
	private function init_hooks() {
		// Admin notices.
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );

		// Settings link on plugins page.
		add_filter( 'plugin_action_links_' . CONSENTLY_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Display admin notices.
	 */
	public function admin_notices() {
		// Show test mode warning.
		if ( $this->is_test_mode() ) {
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Consently:', 'consently' ); ?></strong>
					<?php esc_html_e( 'Test mode is active. API validation is bypassed and a hardcoded banner ID is used. Disable CONSENTLY_TEST_MODE before going to production.', 'consently' ); ?>
				</p>
			</div>
			<?php
		}

		// Check if connected.
		if ( ! $this->is_connected() ) {
			$this->show_setup_notice();
			return;
		}

		// Check for domain change (skip in test mode).
		if ( ! $this->is_test_mode() ) {
			$this->check_domain_change();
		}

		// Check if banner is disabled.
		if ( ! $this->is_banner_enabled() ) {
			$this->show_banner_disabled_notice();
		}
	}

	/**
	 * Show setup notice for unconnected plugin.
	 */
	private function show_setup_notice() {
		// Don't show on settings page.
		$screen = get_current_screen();
		if ( $screen && 'settings_page_consently' === $screen->id ) {
			return;
		}

		// Check if dismissed.
		if ( get_option( 'consently_setup_notice_dismissed' ) ) {
			return;
		}

		$settings_url = admin_url( 'options-general.php?page=consently' );
		?>
		<div class="notice notice-info is-dismissible" data-consently-notice="setup">
			<p>
				<?php
				printf(
					/* translators: %s: Settings page URL */
					esc_html__( 'Consently is activated but not connected. %s to enable the consent banner.', 'consently' ),
					'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Connect your site', 'consently' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Check for domain change and show notice.
	 */
	private function check_domain_change() {
		$stored_home = get_option( 'consently_last_validated_home_host' );
		$current_home = $this->get_normalized_home_host();

		if ( $stored_home && $stored_home !== $current_home ) {
			$settings_url = admin_url( 'options-general.php?page=consently' );
			?>
			<div class="notice notice-warning">
				<p>
					<?php
					printf(
						/* translators: 1: Previous domain, 2: Current domain, 3: Settings page URL */
						esc_html__( 'Your site URL has changed from %1$s to %2$s. Please %3$s to update your Consently connection.', 'consently' ),
						'<strong>' . esc_html( $stored_home ) . '</strong>',
						'<strong>' . esc_html( $current_home ) . '</strong>',
						'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'reconnect', 'consently' ) . '</a>'
					);
					?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Show banner disabled warning.
	 */
	private function show_banner_disabled_notice() {
		// Only show on relevant pages.
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$show_on_screens = array( 'dashboard', 'settings_page_consently' );
		if ( ! in_array( $screen->id, $show_on_screens, true ) ) {
			return;
		}

		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'Consently:', 'consently' ); ?></strong>
				<?php esc_html_e( 'Consent banner disabled — your site may not be compliant.', 'consently' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Add plugin action links.
	 *
	 * @param array $links Existing links.
	 * @return array Modified links.
	 */
	public function plugin_action_links( $links ) {
		$settings_link = '<a href="' . admin_url( 'options-general.php?page=consently' ) . '">' . esc_html__( 'Settings', 'consently' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Check if test mode is enabled.
	 *
	 * @return bool True if test mode is active.
	 */
	public function is_test_mode() {
		return defined( 'CONSENTLY_TEST_MODE' ) && CONSENTLY_TEST_MODE;
	}

	/**
	 * Check if plugin is connected to Consently.
	 *
	 * @return bool True if connected.
	 */
	public function is_connected() {
		if ( $this->is_test_mode() ) {
			return true;
		}

		$site_id = get_option( 'consently_site_id' );
		return ! empty( $site_id );
	}

	/**
	 * Check if banner is enabled.
	 *
	 * @return bool True if banner is enabled.
	 */
	public function is_banner_enabled() {
		return (bool) get_option( 'consently_banner_enabled', true );
	}

	/**
	 * Check if banner should be shown to current user.
	 *
	 * @return bool True if banner should be shown.
	 */
	public function should_show_banner() {
		// Not connected.
		if ( ! $this->is_connected() ) {
			return false;
		}

		// Banner disabled.
		if ( ! $this->is_banner_enabled() ) {
			return false;
		}

		// Check admin visibility setting.
		if ( current_user_can( 'manage_options' ) && ! get_option( 'consently_show_to_admins', true ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Get the site ID (banner ID).
	 *
	 * @return string|false Site ID or false if not connected.
	 */
	public function get_site_id() {
		if ( $this->is_test_mode() ) {
			// Check for user-entered banner ID first, then fall back to constant.
			$custom_id = get_option( 'consently_test_banner_id' );
			if ( ! empty( $custom_id ) ) {
				return $custom_id;
			}
			return defined( 'CONSENTLY_TEST_BANNER_ID' ) ? CONSENTLY_TEST_BANNER_ID : false;
		}

		return get_option( 'consently_site_id' );
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
	 * Get normalized home URL for API calls.
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
	 * Get connection data.
	 *
	 * @return array Connection data.
	 */
	public function get_connection_data() {
		if ( $this->is_test_mode() ) {
			return array(
				'site_id'          => $this->get_site_id(),
				'plan'             => 'test',
				'canonical_domain' => $this->get_normalized_home_host(),
				'consent_model'    => 'optin',
			);
		}

		return array(
			'site_id'          => get_option( 'consently_site_id', '' ),
			'plan'             => get_option( 'consently_plan', '' ),
			'canonical_domain' => get_option( 'consently_canonical_domain', '' ),
			'consent_model'    => get_option( 'consently_consent_model', 'optin' ),
		);
	}

	/**
	 * Store connection data.
	 *
	 * @param array $data Connection data from API.
	 */
	public function store_connection_data( $data ) {
		// Store site-specific data (no autoload for security).
		update_option( 'consently_site_id', sanitize_text_field( $data['site_id'] ), false );
		update_option( 'consently_plan', sanitize_text_field( $data['plan'] ), false );
		update_option( 'consently_canonical_domain', sanitize_text_field( $data['canonical_domain'] ), false );
		update_option( 'consently_consent_model', sanitize_text_field( $data['consent_model'] ), false );

		// Store validated home host.
		update_option( 'consently_last_validated_home_host', $this->get_normalized_home_host(), false );
	}

	/**
	 * Clear connection data.
	 */
	public function clear_connection_data() {
		delete_option( 'consently_site_id' );
		delete_option( 'consently_plan' );
		delete_option( 'consently_canonical_domain' );
		delete_option( 'consently_consent_model' );
		delete_option( 'consently_last_validated_home_host' );
		delete_option( 'consently_api_key_encrypted' );
		delete_option( 'consently_encryption_key' );
		delete_option( 'consently_test_banner_id' );

		// Clear audit transients.
		delete_transient( 'consently_audit_results' );
	}

	/**
	 * Detect installed cache plugins.
	 *
	 * @return array Array of detected cache plugins.
	 */
	public function detect_cache_plugins() {
		$cache_plugins = array();

		$known_cache_plugins = array(
			'wp-rocket/wp-rocket.php'                       => 'WP Rocket',
			'w3-total-cache/w3-total-cache.php'             => 'W3 Total Cache',
			'litespeed-cache/litespeed-cache.php'           => 'LiteSpeed Cache',
			'wp-super-cache/wp-cache.php'                   => 'WP Super Cache',
			'sg-cachepress/sg-cachepress.php'               => 'SG Optimizer',
			'autoptimize/autoptimize.php'                   => 'Autoptimize',
			'wp-fastest-cache/wpFastestCache.php'           => 'WP Fastest Cache',
			'cache-enabler/cache-enabler.php'               => 'Cache Enabler',
			'hummingbird-performance/wp-hummingbird.php'    => 'Hummingbird',
			'nitropack/main.php'                            => 'NitroPack',
			'flying-press/flying-press.php'                 => 'FlyingPress',
			'perfmatters/perfmatters.php'                   => 'Perfmatters',
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
