<?php
/**
 * Plugin Audit tab view.
 *
 * @package Consently
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="consently-audit-tab">
	<div class="consently-card">
		<h2><?php esc_html_e( 'Plugin Audit', 'consently' ); ?></h2>

		<p class="consently-audit-description">
			<?php esc_html_e( 'Analyze your active plugins to identify which ones may set tracking cookies. This is informational only and not a guarantee of compliance.', 'consently' ); ?>
		</p>

		<div class="consently-audit-disclaimer">
			<span class="dashicons dashicons-info"></span>
			<p>
				<?php esc_html_e( 'Note: This audit checks for common tracking patterns in active plugins. Results may be incomplete and do not imply regulatory non-compliance. Always verify with the Consently Cookie Scanner for accurate results.', 'consently' ); ?>
			</p>
		</div>

		<div class="consently-audit-actions">
			<button type="button" id="consently-run-audit" class="button button-primary">
				<?php esc_html_e( 'Analyze Plugins', 'consently' ); ?>
			</button>
		</div>
	</div>

	<!-- Results Container -->
	<div id="consently-audit-results" class="consently-audit-results" style="display: none;"></div>
</div>
