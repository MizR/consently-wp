<?php
/**
 * Uninstall handler for Consently plugin.
 *
 * Removes all plugin data when the plugin is uninstalled.
 *
 * @package Consently
 */

// Exit if accessed directly.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Clean up plugin data on uninstall.
 */
function consently_uninstall() {
	// Check if user has permission.
	if ( ! current_user_can( 'delete_plugins' ) ) {
		return;
	}

	// Options to delete.
	$options = array(
		// Connection data.
		'consently_site_id',
		'consently_plan',
		'consently_canonical_domain',
		'consently_consent_model',
		'consently_last_validated_home_host',
		'consently_api_key_encrypted',
		'consently_encryption_key',
		'consently_encryption_method',

		// Settings.
		'consently_banner_enabled',
		'consently_show_to_admins',

		// Notices.
		'consently_setup_notice_dismissed',
	);

	// Transients to delete.
	$transients = array(
		'consently_audit_results',
		'consently_rate_limit',
	);

	// Check if multisite.
	if ( is_multisite() ) {
		// Get all sites.
		$sites = get_sites( array(
			'fields' => 'ids',
		) );

		foreach ( $sites as $site_id ) {
			switch_to_blog( $site_id );

			// Delete options.
			foreach ( $options as $option ) {
				delete_option( $option );
			}

			// Delete transients.
			foreach ( $transients as $transient ) {
				delete_transient( $transient );
			}

			restore_current_blog();
		}
	} else {
		// Single site.

		// Delete options.
		foreach ( $options as $option ) {
			delete_option( $option );
		}

		// Delete transients.
		foreach ( $transients as $transient ) {
			delete_transient( $transient );
		}
	}
}

consently_uninstall();
