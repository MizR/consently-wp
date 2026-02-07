<?php
/**
 * Scanner view.
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
		<h2><?php esc_html_e( 'Site Scanner', 'consently' ); ?></h2>

		<p class="consently-audit-description">
			<?php esc_html_e( 'Scans your site to detect cookies, tracking scripts, and third-party services. Results can be exported as JSON.', 'consently' ); ?>
		</p>

		<div class="consently-audit-disclaimer">
			<span class="dashicons dashicons-info"></span>
			<p>
				<?php esc_html_e( 'This audit checks for common tracking patterns. Results may be incomplete and do not imply regulatory non-compliance.', 'consently' ); ?>
			</p>
		</div>

		<div class="consently-audit-actions">
			<button type="button" id="consently-run-audit" class="button button-primary">
				<?php esc_html_e( 'Run Audit', 'consently' ); ?>
			</button>
			<button type="button" id="consently-export-json" class="button button-secondary" style="display: none;">
				<?php esc_html_e( 'Export JSON', 'consently' ); ?>
			</button>
		</div>

		<!-- Progress section (shown during scan) -->
		<div id="consently-scan-progress" class="consently-scan-progress" style="display: none;">
			<div class="consently-progress-wrapper">
				<div class="consently-progress-bar">
					<div id="consently-progress-fill" class="consently-progress-fill" style="width: 0%;"></div>
				</div>
				<span id="consently-progress-percent" class="consently-progress-text">0%</span>
			</div>
			<p id="consently-progress-status" class="consently-scan-status-text"></p>
		</div>

		<!-- Per-page scan log (shown during live scan) -->
		<div id="consently-scan-log" class="consently-scan-log" style="display: none;">
			<details>
				<summary><?php esc_html_e( 'Scan details', 'consently' ); ?></summary>
				<ul id="consently-scan-log-list"></ul>
			</details>
		</div>
	</div>

	<!-- Results Container (both phases render here progressively) -->
	<div id="consently-audit-results" class="consently-audit-results" style="display: none;"></div>
</div>
