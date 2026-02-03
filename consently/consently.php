<?php
/**
 * Plugin Name: Consently - Cookie Consent & GDPR Compliance
 * Plugin URI: https://consently.net
 * Description: Connect your WordPress site to Consently.net for cookie consent management, GDPR/CCPA compliance, and privacy banner display.
 * Version: 1.0.0
 * Author: Consently
 * Author URI: https://consently.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: consently
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * This plugin connects to Consently.net, an external consent management service.
 * A Consently account is required for this plugin to function.
 *
 * @package Consently
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'CONSENTLY_VERSION', '1.0.0' );
define( 'CONSENTLY_PLUGIN_FILE', __FILE__ );
define( 'CONSENTLY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CONSENTLY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CONSENTLY_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// API constants.
define( 'CONSENTLY_API_URL', 'https://api.consently.net/v1' );
define( 'CONSENTLY_APP_URL', 'https://app.consently.net' );
define( 'CONSENTLY_CDN_SCRIPT', 'https://app.consently.net/consently.js' );

// Test mode: bypasses API validation and uses a hardcoded banner ID.
// TODO: Remove before production release.
define( 'CONSENTLY_TEST_MODE', true );
define( 'CONSENTLY_TEST_BANNER_ID', '6981c589faa5693ee3072986' );

/**
 * Minimum requirements check.
 *
 * @return bool True if requirements are met.
 */
function consently_requirements_met() {
	global $wp_version;

	$php_version = '7.4';
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
				esc_html__( 'Consently requires PHP %1$s and WordPress %2$s or higher. Please update your server configuration.', 'consently' ),
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
	require_once CONSENTLY_PLUGIN_DIR . 'includes/class-api.php';
	require_once CONSENTLY_PLUGIN_DIR . 'includes/class-admin.php';
	require_once CONSENTLY_PLUGIN_DIR . 'includes/class-script.php';
	require_once CONSENTLY_PLUGIN_DIR . 'includes/class-audit.php';
	require_once CONSENTLY_PLUGIN_DIR . 'includes/class-wp-consent.php';

	// Initialize core.
	Consently_Core::get_instance();
}

add_action( 'plugins_loaded', 'consently_init' );

/**
 * Plugin activation hook.
 */
function consently_activate() {
	// Set default options.
	if ( false === get_option( 'consently_banner_enabled' ) ) {
		add_option( 'consently_banner_enabled', true, '', false );
	}

	if ( false === get_option( 'consently_show_to_admins' ) ) {
		add_option( 'consently_show_to_admins', true, '', false );
	}

	// Flush rewrite rules.
	flush_rewrite_rules();
}

register_activation_hook( __FILE__, 'consently_activate' );

/**
 * Plugin deactivation hook.
 */
function consently_deactivate() {
	// Clear transients.
	delete_transient( 'consently_audit_results' );
	delete_transient( 'consently_rate_limit' );

	// Flush rewrite rules.
	flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, 'consently_deactivate' );
