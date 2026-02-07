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

	// Transients to delete.
	$transients = array(
		'consently_audit_results',
		'consently_audit_phase1',
		'consently_audit_phase2',
		'consently_live_scan_results',
		'consently_enqueued_scripts',
		'consently_plugin_hash',
		'consently_scan_started_at',
	);

	// Check if multisite.
	if ( is_multisite() ) {
		// Get all sites.
		$sites = get_sites( array(
			'fields' => 'ids',
		) );

		foreach ( $sites as $site_id ) {
			switch_to_blog( $site_id );

			// Delete transients.
			foreach ( $transients as $transient ) {
				delete_transient( $transient );
			}

			restore_current_blog();
		}
	} else {
		// Single site.

		// Delete transients.
		foreach ( $transients as $transient ) {
			delete_transient( $transient );
		}
	}
}

consently_uninstall();
