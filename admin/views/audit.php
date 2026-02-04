<?php
/**
 * Plugin Audit v2 tab view.
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
			<?php esc_html_e( 'Analyze your site to identify cookies, tracking scripts, and third-party services. Phase 1 runs instant static analysis. Phase 2 performs a live scan of your pages to capture actual cookies set at runtime.', 'consently' ); ?>
		</p>

		<div class="consently-audit-disclaimer">
			<span class="dashicons dashicons-info"></span>
			<p>
				<?php esc_html_e( 'This audit checks for common tracking patterns. Results may be incomplete and do not imply regulatory non-compliance.', 'consently' ); ?>
			</p>
		</div>

		<!-- Ad blocker warning (shown via JS if ads.js fails to load) -->
		<div id="consently-adblocker-warning" class="consently-notice consently-notice-warning" style="display: none;">
			<span class="dashicons dashicons-warning"></span>
			<p><?php esc_html_e( 'An ad blocker is active. The live scan may miss some third-party scripts that are blocked. Consider disabling your ad blocker for accurate results.', 'consently' ); ?></p>
		</div>

		<div class="consently-audit-actions">
			<button type="button" id="consently-run-audit" class="button button-primary">
				<?php esc_html_e( 'Run Static Analysis', 'consently' ); ?>
			</button>
			<button type="button" id="consently-run-live-scan" class="button button-secondary" style="display: none;">
				<?php esc_html_e( 'Run Live Scan', 'consently' ); ?>
			</button>
		</div>
	</div>

	<!-- Phase 1 Results Container -->
	<div id="consently-phase1-results" class="consently-audit-results" style="display: none;"></div>

	<!-- Live Scan Progress -->
	<div id="consently-live-scan-progress" class="consently-card" style="display: none;">
		<h3><?php esc_html_e( 'Live Scan Progress', 'consently' ); ?></h3>
		<div class="consently-progress-wrapper">
			<div class="consently-progress-bar">
				<div id="consently-scan-progress-bar" class="consently-progress-fill" style="width: 0%;"></div>
			</div>
			<span id="consently-scan-progress-text" class="consently-progress-text">0 / 0 pages scanned</span>
		</div>
		<p id="consently-scan-status" class="consently-scan-status-text"></p>
	</div>

	<!-- Phase 2 Results Container -->
	<div id="consently-phase2-results" class="consently-audit-results" style="display: none;"></div>
</div>
