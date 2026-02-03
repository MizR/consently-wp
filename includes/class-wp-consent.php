<?php
/**
 * WP Consent API bridge class for Consently plugin.
 *
 * Integrates with the WordPress Consent API.
 *
 * @package Consently
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP Consent API integration class.
 */
class Consently_WP_Consent {

	/**
	 * Core instance.
	 *
	 * @var Consently_Core
	 */
	private $core;

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
		// Only activate if WP Consent API is present.
		add_action( 'plugins_loaded', array( $this, 'maybe_init_wp_consent_api' ), 20 );
	}

	/**
	 * Initialize WP Consent API integration if available.
	 */
	public function maybe_init_wp_consent_api() {
		// Check if WP Consent API is active.
		if ( ! class_exists( 'WP_CONSENT_API' ) ) {
			return;
		}

		// Register as compliant plugin.
		add_filter( 'wp_consent_api_registered_' . CONSENTLY_PLUGIN_BASENAME, '__return_true' );

		// Output bridge script on frontend.
		add_action( 'wp_footer', array( $this, 'output_bridge_script' ), 20 );
	}

	/**
	 * Check if WP Consent API is active.
	 *
	 * @return bool True if active.
	 */
	public function is_wp_consent_api_active() {
		return class_exists( 'WP_CONSENT_API' );
	}

	/**
	 * Output the JavaScript bridge script.
	 */
	public function output_bridge_script() {
		// Only output if banner should be shown.
		if ( ! $this->core->should_show_banner() ) {
			return;
		}

		// Get consent model from connection data.
		$connection_data = $this->core->get_connection_data();
		$consent_model   = isset( $connection_data['consent_model'] ) ? $connection_data['consent_model'] : 'optin';
		?>
		<script id="consently-wp-consent-bridge">
		(function() {
			'use strict';

			// Debounce to prevent duplicate calls.
			var lastUpdate = null;
			var debounceTimer = null;

			/**
			 * Map Consently categories to WP Consent API categories.
			 */
			function mapCategory(consentlyCategory) {
				var mapping = {
					'analytics': 'statistics',
					'marketing': 'marketing',
					'functional': 'preferences'
				};
				return mapping[consentlyCategory] || consentlyCategory;
			}

			/**
			 * Update WP Consent API with consent state.
			 */
			function updateWpConsent(detail) {
				// Create signature for deduplication.
				var signature = JSON.stringify(detail);
				if (signature === lastUpdate) {
					return;
				}
				lastUpdate = signature;

				// Clear any pending updates.
				if (debounceTimer) {
					clearTimeout(debounceTimer);
				}

				// Debounce the update.
				debounceTimer = setTimeout(function() {
					// Check if wp_set_consent function exists.
					if (typeof wp_set_consent !== 'function') {
						return;
					}

					// Map and set each category.
					var categories = ['analytics', 'marketing', 'functional'];
					categories.forEach(function(category) {
						if (typeof detail[category] !== 'undefined') {
							var wpCategory = mapCategory(category);
							var value = detail[category] ? 'allow' : 'deny';
							wp_set_consent(wpCategory, value);
						}
					});
				}, 50);
			}

			/**
			 * Set initial consent state (deny all until user consents).
			 */
			function setInitialState() {
				if (typeof wp_set_consent !== 'function') {
					return;
				}

				// Default to deny for all categories until consent is given.
				wp_set_consent('statistics', 'deny');
				wp_set_consent('marketing', 'deny');
				wp_set_consent('preferences', 'deny');
			}

			// Set initial state.
			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', setInitialState);
			} else {
				setInitialState();
			}

			// Listen for Consently consent update events.
			document.addEventListener('consently:consent_update', function(event) {
				if (event.detail) {
					updateWpConsent(event.detail);
				}
			});
		})();
		</script>
		<?php
	}

	/**
	 * Get the consent model.
	 *
	 * @return string Consent model (optin or optout).
	 */
	public function get_consent_model() {
		$connection_data = $this->core->get_connection_data();
		return isset( $connection_data['consent_model'] ) ? $connection_data['consent_model'] : 'optin';
	}
}
