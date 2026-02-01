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

$cached_results = $this->core->audit->get_cached_results();
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
			<?php if ( $cached_results ) : ?>
				<span class="consently-cache-notice">
					<?php esc_html_e( 'Showing cached results.', 'consently' ); ?>
				</span>
			<?php endif; ?>
		</div>
	</div>

	<!-- Results Container -->
	<div id="consently-audit-results" class="consently-audit-results" <?php echo $cached_results ? '' : 'style="display: none;"'; ?>>
		<?php if ( $cached_results ) : ?>
			<?php $this->render_audit_results( $cached_results ); ?>
		<?php endif; ?>
	</div>
</div>

<?php
/**
 * Render audit results.
 *
 * @param array $results Audit results.
 */
function consently_render_audit_results_template( $results ) {
	?>
	<?php if ( $results['partial_scan'] ) : ?>
		<div class="consently-notice consently-notice-warning">
			<span class="dashicons dashicons-warning"></span>
			<?php esc_html_e( 'Partial scan completed. Some plugins may not have been fully analyzed due to time or file limits.', 'consently' ); ?>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $results['tracking_plugins'] ) ) : ?>
		<div class="consently-card consently-tracking-plugins">
			<h3>
				<span class="dashicons dashicons-warning"></span>
				<?php
				printf(
					/* translators: %d: Number of plugins */
					esc_html( _n(
						'%d plugin may set tracking cookies',
						'%d plugins may set tracking cookies',
						count( $results['tracking_plugins'] ),
						'consently'
					) ),
					count( $results['tracking_plugins'] )
				);
				?>
			</h3>
			<table class="consently-audit-table widefat">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Plugin', 'consently' ); ?></th>
						<th><?php esc_html_e( 'Detected Trackers', 'consently' ); ?></th>
						<th><?php esc_html_e( 'Category', 'consently' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $results['tracking_plugins'] as $plugin ) : ?>
						<tr>
							<td><?php echo esc_html( $plugin['name'] ); ?></td>
							<td>
								<?php
								if ( ! empty( $plugin['domains'] ) ) {
									echo esc_html( implode( ', ', $plugin['domains'] ) );
								} else {
									echo '<em>' . esc_html__( 'Pattern detected', 'consently' ) . '</em>';
								}
								?>
							</td>
							<td>
								<span class="consently-category consently-category-<?php echo esc_attr( $plugin['category'] ); ?>">
									<?php echo esc_html( ucfirst( $plugin['category'] ) ); ?>
								</span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $results['clean_plugins'] ) ) : ?>
		<div class="consently-card consently-clean-plugins">
			<h3>
				<span class="dashicons dashicons-yes"></span>
				<?php
				printf(
					/* translators: %d: Number of plugins */
					esc_html( _n(
						'%d plugin without detected tracking',
						'%d plugins without detected tracking',
						count( $results['clean_plugins'] ),
						'consently'
					) ),
					count( $results['clean_plugins'] )
				);
				?>
			</h3>
			<details>
				<summary><?php esc_html_e( 'Show clean plugins', 'consently' ); ?></summary>
				<ul class="consently-clean-list">
					<?php foreach ( $results['clean_plugins'] as $plugin ) : ?>
						<li><?php echo esc_html( $plugin['name'] ); ?></li>
					<?php endforeach; ?>
				</ul>
			</details>
		</div>
	<?php endif; ?>

	<?php if ( empty( $results['tracking_plugins'] ) && empty( $results['clean_plugins'] ) ) : ?>
		<div class="consently-card">
			<p><?php esc_html_e( 'No active plugins found to analyze.', 'consently' ); ?></p>
		</div>
	<?php endif; ?>

	<p class="consently-scan-time">
		<?php
		printf(
			/* translators: %s: Scan time in seconds */
			esc_html__( 'Scan completed in %s seconds.', 'consently' ),
			esc_html( $results['scan_time'] )
		);
		?>
	</p>
	<?php
}

// Store function reference for use in class.
if ( ! method_exists( 'Consently_Admin', 'render_audit_results' ) ) {
	/**
	 * Add render method to admin class.
	 */
	Consently_Admin::class;
}

// Call the render function if cached results exist.
if ( $cached_results ) {
	consently_render_audit_results_template( $cached_results );
}
?>

<script type="text/html" id="tmpl-consently-audit-results">
	<?php consently_render_audit_results_template( array( 'tracking_plugins' => array(), 'clean_plugins' => array(), 'partial_scan' => false, 'scan_time' => 0 ) ); ?>
</script>
