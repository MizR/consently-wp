<?php
/**
 * Plugin Name: Consently Scanner
 * Plugin URI: https://consently.net
 * Description: Scans your WordPress site for cookies, tracking scripts, and third-party services. Export results as JSON for use with Consently or other consent management tools.
 * Version: 0.1.2
 * Author: Consently
 * Author URI: https://consently.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: consently
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package Consently
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'CONSENTLY_VERSION', '0.1.2' );
define( 'CONSENTLY_PLUGIN_FILE', __FILE__ );
define( 'CONSENTLY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CONSENTLY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CONSENTLY_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Minimum requirements check.
 *
 * @return bool True if requirements are met.
 */
function consently_requirements_met() {
	global $wp_version;

	$php_version         = '7.4';
	$wp_version_required = '5.8';

	if ( version_compare( PHP_VERSION, $php_version, '<' ) ) {
		return false;
	}

	if ( version_compare( $wp_version, $wp_version_required, '<' ) ) {
		return false;
	}

	return true;
}

/**
 * Display admin notice for unmet requirements.
 */
function consently_requirements_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			printf(
				/* translators: 1: PHP version required, 2: WordPress version required */
				esc_html__( 'Consently Scanner requires PHP %1$s and WordPress %2$s or higher. Please update your server configuration.', 'consently' ),
				'7.4',
				'5.8'
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Initialize the plugin.
 */
function consently_init() {
	// Check requirements.
	if ( ! consently_requirements_met() ) {
		add_action( 'admin_notices', 'consently_requirements_notice' );
		return;
	}

	// Load text domain.
	load_plugin_textdomain( 'consently', false, dirname( CONSENTLY_PLUGIN_BASENAME ) . '/languages' );

	// Include required files.
	require_once CONSENTLY_PLUGIN_DIR . 'includes/class-core.php';
	require_once CONSENTLY_PLUGIN_DIR . 'includes/class-admin.php';
	require_once CONSENTLY_PLUGIN_DIR . 'includes/class-audit.php';
	require_once CONSENTLY_PLUGIN_DIR . 'includes/class-page-crawler.php';
	require_once CONSENTLY_PLUGIN_DIR . 'includes/class-html-parser.php';
	require_once CONSENTLY_PLUGIN_DIR . 'includes/class-live-scan.php';
	require_once CONSENTLY_PLUGIN_DIR . 'includes/class-json-export.php';

	// Initialize core.
	Consently_Core::get_instance();
}

add_action( 'plugins_loaded', 'consently_init' );

/**
 * Plugin activation hook.
 */
function consently_activate() {
	flush_rewrite_rules();
}

register_activation_hook( __FILE__, 'consently_activate' );

/**
 * Plugin deactivation hook.
 */
function consently_deactivate() {
	// Clear transients.
	delete_transient( 'consently_audit_results' );
	delete_transient( 'consently_audit_phase1' );
	delete_transient( 'consently_audit_phase2' );
	delete_transient( 'consently_live_scan_results' );
	delete_transient( 'consently_enqueued_scripts' );
	delete_transient( 'consently_plugin_hash' );
	delete_transient( 'consently_scan_started_at' );

	// Flush rewrite rules.
	flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, 'consently_deactivate' );
