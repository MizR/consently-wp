<?php
/**
 * Script class for Consently plugin.
 *
 * Handles CDN script injection.
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
		// Primary injection method via wp_enqueue_scripts.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_script' ), 1 );

		// Fallback via wp_head.
		add_action( 'wp_head', array( $this, 'output_script_fallback' ), 1 );

		// Admin notice for duplicate script detection.
		add_action( 'admin_notices', array( $this, 'check_duplicate_script' ) );
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
			'<script src="%s" data-bannerid="%s"></script>' . "\n",
			esc_url( CONSENTLY_CDN_SCRIPT ),
			esc_attr( $site_id )
		);

		$this->script_output = true;
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
		$duplicate_detected = $this->detect_manual_script();

		if ( $duplicate_detected ) {
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
	 * Detect if manual Consently script exists.
	 *
	 * @return bool True if manual script detected.
	 */
	private function detect_manual_script() {
		// Check theme header.php and footer.php for consently.js.
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
				if ( false !== strpos( $contents, 'consently.js' ) ) {
					return true;
				}
			}
		}

		return false;
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
