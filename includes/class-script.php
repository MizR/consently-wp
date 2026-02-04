<?php
/**
 * Script class for Consently plugin.
 *
 * Handles CDN script injection and scan mode.
 *
 * @package Consently
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Script injection class.
 */
class Consently_Script {

	/**
	 * Core instance.
	 *
	 * @var Consently_Core
	 */
	private $core;

	/**
	 * Whether script has been output.
	 *
	 * @var bool
	 */
	private $script_output = false;

	/**
	 * Constructor.
	 *
	 * @param Consently_Core $core Core instance.
	 */
	public function __construct( $core ) {
		$this->core = $core;
		$this->init_hooks();
	}

	/**
	 * Initialize hooks.
	 */
	private function init_hooks() {
		if ( is_admin() ) {
			// Admin-only: duplicate detection.
			add_action( 'admin_notices', array( $this, 'check_duplicate_script' ) );
			return;
		}

		// Check if this is a scan request.
		if ( $this->is_scan_request() ) {
			$this->init_scan_mode();
			return;
		}

		// Primary injection method via wp_enqueue_scripts.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_script' ), 1 );

		// Fallback via wp_head.
		add_action( 'wp_head', array( $this, 'output_script_fallback' ), 1 );

		// Enqueued script capture for audit.
		add_action( 'wp_print_scripts', array( $this, 'capture_enqueued_scripts' ), 9999 );
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
	 * Initialize scan mode.
	 *
	 * In scan mode, we inject the cookie collector script.
	 * The normal banner script is still loaded so cookies from it are captured.
	 */
	private function init_scan_mode() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scan_script' ), 1 );

		// Still load the normal banner so cookies are captured.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_script' ), 1 );
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
	 * Enqueue the Consently CDN script.
	 */
	public function enqueue_script() {
		// Check if we should show banner.
		if ( ! $this->core->should_show_banner() ) {
			return;
		}

		$site_id = $this->core->get_site_id();

		if ( ! $site_id ) {
			return;
		}

		// Register and enqueue the script.
		wp_register_script(
			'consently-cmp',
			CONSENTLY_CDN_SCRIPT,
			array(),
			null, // No version to prevent caching issues with CDN.
			false // Load in head.
		);

		// Add data-bannerid attribute.
		wp_script_add_data( 'consently-cmp', 'data-bannerid', esc_attr( $site_id ) );

		wp_enqueue_script( 'consently-cmp' );

		// Add the data-bannerid attribute via filter.
		add_filter( 'script_loader_tag', array( $this, 'add_script_attributes' ), 10, 2 );

		$this->script_output = true;
	}

	/**
	 * Add custom attributes to the script tag.
	 *
	 * @param string $tag    Script HTML tag.
	 * @param string $handle Script handle.
	 * @return string Modified script tag.
	 */
	public function add_script_attributes( $tag, $handle ) {
		if ( 'consently-cmp' !== $handle ) {
			return $tag;
		}

		$site_id = $this->core->get_site_id();

		// Add data-bannerid attribute.
		$tag = str_replace( ' src=', ' data-bannerid="' . esc_attr( $site_id ) . '" src=', $tag );

		// Remove defer/async if present (CMP needs to run synchronously).
		$tag = str_replace( array( ' defer', ' async' ), '', $tag );

		return $tag;
	}

	/**
	 * Output script as fallback if not already output.
	 */
	public function output_script_fallback() {
		// Skip if already output via enqueue.
		if ( $this->script_output ) {
			return;
		}

		// Check if we should show banner.
		if ( ! $this->core->should_show_banner() ) {
			return;
		}

		$site_id = $this->core->get_site_id();

		if ( ! $site_id ) {
			return;
		}

		// Output script directly.
		printf(
			'<script src="%s" data-bannerid="%s"></script>' . "\n", // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript
			esc_url( CONSENTLY_CDN_SCRIPT ),
			esc_attr( $site_id )
		);

		$this->script_output = true;
	}

	/**
	 * Capture enqueued scripts for audit inspection.
	 *
	 * Runs at wp_print_scripts priority 9999 to capture all registered scripts.
	 * Stores matching tracking scripts in a transient.
	 */
	public function capture_enqueued_scripts() {
		global $wp_scripts;

		if ( ! $wp_scripts ) {
			return;
		}

		// Only capture once per hour.
		$cached = get_transient( 'consently_enqueued_scripts' );
		if ( false !== $cached ) {
			return;
		}

		$known_data = $this->get_known_plugins_data();
		if ( empty( $known_data['tracking_domains'] ) ) {
			return;
		}

		$tracking_scripts = array();

		foreach ( $wp_scripts->registered as $handle => $script ) {
			if ( empty( $script->src ) ) {
				continue;
			}

			$src = $script->src;

			// Make relative URLs absolute.
			if ( 0 === strpos( $src, '//' ) ) {
				$src = 'https:' . $src;
			} elseif ( 0 === strpos( $src, '/' ) ) {
				$src = home_url( $src );
			}

			foreach ( $known_data['tracking_domains'] as $domain ) {
				if ( false !== strpos( $src, $domain ) ) {
					$tracking_scripts[] = array(
						'handle' => $handle,
						'src'    => $src,
						'domain' => $domain,
					);
					break;
				}
			}
		}

		set_transient( 'consently_enqueued_scripts', $tracking_scripts, HOUR_IN_SECONDS );
	}

	/**
	 * Check for duplicate script and show admin warning.
	 */
	public function check_duplicate_script() {
		// Only check on settings page.
		$screen = get_current_screen();
		if ( ! $screen || 'settings_page_consently' !== $screen->id ) {
			return;
		}

		// Only warn if connected.
		if ( ! $this->core->is_connected() ) {
			return;
		}

		// Check for manual script in theme.
		if ( $this->has_duplicate_in_theme() ) {
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Consently:', 'consently' ); ?></strong>
					<?php esc_html_e( 'A manually added Consently script was detected. Please remove the manual snippet from your theme to avoid a double banner.', 'consently' ); ?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Check for duplicate script in theme files.
	 *
	 * @return bool True if duplicate found.
	 */
	public function has_duplicate_in_theme() {
		$theme_files = array(
			get_template_directory() . '/header.php',
			get_template_directory() . '/footer.php',
		);

		// Also check child theme.
		if ( get_stylesheet_directory() !== get_template_directory() ) {
			$theme_files[] = get_stylesheet_directory() . '/header.php';
			$theme_files[] = get_stylesheet_directory() . '/footer.php';
		}

		foreach ( $theme_files as $file ) {
			if ( file_exists( $file ) ) {
				$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				if ( false !== $contents && false !== strpos( $contents, 'consently.js' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Get known plugins data.
	 *
	 * @return array Known plugins data.
	 */
	private function get_known_plugins_data() {
		$json_file = CONSENTLY_PLUGIN_DIR . 'data/known-plugins.json';

		if ( ! file_exists( $json_file ) ) {
			return array(
				'plugins'          => array(),
				'tracking_domains' => array(),
			);
		}

		$contents = file_get_contents( $json_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $contents ) {
			return array(
				'plugins'          => array(),
				'tracking_domains' => array(),
			);
		}

		$data = json_decode( $contents, true );

		if ( null === $data ) {
			return array(
				'plugins'          => array(),
				'tracking_domains' => array(),
			);
		}

		return $data;
	}

	/**
	 * Get exclusion rules for cache plugins.
	 *
	 * @return array Exclusion rules.
	 */
	public function get_cache_exclusion_rules() {
		return array(
			'js_exclude'    => 'app.consently.net/consently.js',
			'delay_exclude' => 'consently',
		);
	}
}
