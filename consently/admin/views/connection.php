<?php
/**
 * Connection tab view.
 *
 * @package Consently
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_connected    = $this->core->is_connected();
$connection_data = $this->core->get_connection_data();
$diagnostics     = $this->get_diagnostics();
?>

<div class="consently-connection-tab">
	<?php if ( $this->core->is_test_mode() ) : ?>
		<!-- Test Mode Banner ID -->
		<div class="consently-card consently-test-mode-card" style="border-left: 4px solid #dba617;">
			<h2><?php esc_html_e( 'Test Mode', 'consently' ); ?></h2>
			<p><?php esc_html_e( 'API validation is bypassed. Enter a banner ID to test with.', 'consently' ); ?></p>
			<div class="consently-connect-form">
				<label for="consently-test-banner-id"><?php esc_html_e( 'Banner ID:', 'consently' ); ?></label>
				<div class="consently-input-group">
					<input type="text"
						   id="consently-test-banner-id"
						   class="regular-text"
						   value="<?php echo esc_attr( $this->core->get_site_id() ); ?>"
						   placeholder="<?php esc_attr_e( 'Enter banner ID', 'consently' ); ?>"
						   autocomplete="off" />
					<button type="button" id="consently-save-test-id" class="button button-primary">
						<?php esc_html_e( 'Save', 'consently' ); ?>
					</button>
				</div>
				<p class="description">
					<?php esc_html_e( 'This ID is used in the data-bannerid attribute of the injected script.', 'consently' ); ?>
				</p>
				<p id="consently-test-id-message" class="consently-inline-message" style="display: none;"></p>
			</div>
		</div>
	<?php endif; ?>

	<?php if ( ! $is_connected ) : ?>
		<!-- Not Connected State -->
		<div class="consently-card consently-connect-card">
			<h2><?php esc_html_e( 'Connect to Consently', 'consently' ); ?></h2>

			<div class="consently-connect-form">
				<label for="consently-api-key"><?php esc_html_e( 'API Key:', 'consently' ); ?></label>
				<div class="consently-input-group">
					<input type="text"
						   id="consently-api-key"
						   class="regular-text"
						   placeholder="<?php esc_attr_e( 'Enter your API key', 'consently' ); ?>"
						   autocomplete="off" />
					<button type="button" id="consently-connect-btn" class="button button-primary">
						<?php esc_html_e( 'Connect', 'consently' ); ?>
					</button>
				</div>
				<p class="consently-error-message" id="consently-connect-error" style="display: none;"></p>
			</div>

			<p class="consently-help-text">
				<?php esc_html_e( "Don't have an API key?", 'consently' ); ?>
				<a href="<?php echo esc_url( CONSENTLY_APP_URL ); ?>" target="_blank" rel="noopener">
					<?php esc_html_e( 'Get it from your Consently Dashboard', 'consently' ); ?>
				</a>
			</p>
		</div>

	<?php else : ?>
		<!-- Connected State -->
		<div class="consently-card consently-status-card">
			<div class="consently-status-header">
				<span class="consently-status-icon dashicons dashicons-yes-alt"></span>
				<h2><?php esc_html_e( 'Connected to Consently', 'consently' ); ?></h2>
			</div>

			<div class="consently-connection-info">
				<p>
					<strong><?php esc_html_e( 'Site:', 'consently' ); ?></strong>
					<?php echo esc_html( $connection_data['canonical_domain'] ); ?>
				</p>
				<p>
					<strong><?php esc_html_e( 'Plan:', 'consently' ); ?></strong>
					<?php echo esc_html( ucfirst( $connection_data['plan'] ) ); ?>
				</p>
			</div>
		</div>

		<!-- Dashboard Links (hidden in test mode) -->
		<?php if ( ! $this->core->is_test_mode() ) : ?>
		<div class="consently-card consently-dashboard-links">
			<h3><?php esc_html_e( 'Consently Dashboard', 'consently' ); ?></h3>
			<div class="consently-links-grid">
				<a href="<?php echo esc_url( $this->get_deep_link( 'banner' ) ); ?>" target="_blank" rel="noopener" class="consently-link-card">
					<span class="dashicons dashicons-art"></span>
					<div class="consently-link-content">
						<strong><?php esc_html_e( 'Banner Editor', 'consently' ); ?></strong>
						<span><?php esc_html_e( 'Customize your consent banner', 'consently' ); ?></span>
					</div>
				</a>

				<a href="<?php echo esc_url( $this->get_deep_link( 'scanner' ) ); ?>" target="_blank" rel="noopener" class="consently-link-card">
					<span class="dashicons dashicons-search"></span>
					<div class="consently-link-content">
						<strong><?php esc_html_e( 'Cookie Scanner', 'consently' ); ?></strong>
						<span><?php esc_html_e( 'Scan your site for cookies', 'consently' ); ?></span>
					</div>
				</a>

				<a href="<?php echo esc_url( $this->get_deep_link( 'logs' ) ); ?>" target="_blank" rel="noopener" class="consently-link-card">
					<span class="dashicons dashicons-list-view"></span>
					<div class="consently-link-content">
						<strong><?php esc_html_e( 'Consent Logs', 'consently' ); ?></strong>
						<span><?php esc_html_e( 'View visitor consent records', 'consently' ); ?></span>
					</div>
				</a>

				<a href="<?php echo esc_url( $this->get_deep_link( 'settings' ) ); ?>" target="_blank" rel="noopener" class="consently-link-card">
					<span class="dashicons dashicons-admin-generic"></span>
					<div class="consently-link-content">
						<strong><?php esc_html_e( 'Site Settings', 'consently' ); ?></strong>
						<span><?php esc_html_e( 'Configure site options', 'consently' ); ?></span>
					</div>
				</a>
			</div>
		</div>
		<?php endif; ?>

		<!-- Disconnect Button -->
		<div class="consently-disconnect-section">
			<button type="button" id="consently-disconnect-btn" class="button button-secondary">
				<?php esc_html_e( 'Disconnect', 'consently' ); ?>
			</button>
		</div>

		<!-- Diagnostics -->
		<div class="consently-card consently-diagnostics">
			<button type="button" class="consently-diagnostics-toggle" aria-expanded="false">
				<span class="dashicons dashicons-arrow-right-alt2"></span>
				<?php esc_html_e( 'Diagnostics', 'consently' ); ?>
			</button>
			<div class="consently-diagnostics-content" style="display: none;">
				<table class="consently-diagnostics-table">
					<tr>
						<td><?php esc_html_e( 'WordPress:', 'consently' ); ?></td>
						<td><?php echo esc_html( $diagnostics['wordpress_version'] ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'PHP:', 'consently' ); ?></td>
						<td><?php echo esc_html( $diagnostics['php_version'] ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Home URL:', 'consently' ); ?></td>
						<td><?php echo esc_html( $diagnostics['home_url'] ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Canonical Domain:', 'consently' ); ?></td>
						<td><?php echo esc_html( $diagnostics['canonical_domain'] ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Script Injected:', 'consently' ); ?></td>
						<td><?php echo esc_html( $diagnostics['script_injected'] ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Cache Plugin:', 'consently' ); ?></td>
						<td><?php echo esc_html( $diagnostics['cache_plugins'] ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Plugin Version:', 'consently' ); ?></td>
						<td><?php echo esc_html( $diagnostics['plugin_version'] ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Multisite:', 'consently' ); ?></td>
						<td><?php echo esc_html( $diagnostics['multisite'] ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'WP Consent API:', 'consently' ); ?></td>
						<td><?php echo esc_html( $diagnostics['wp_consent_api'] ); ?></td>
					</tr>
				</table>
				<button type="button" id="consently-copy-diagnostics" class="button button-secondary">
					<?php esc_html_e( 'Copy to clipboard', 'consently' ); ?>
				</button>
				<textarea id="consently-diagnostics-text" style="display: none;"><?php echo esc_textarea( $this->format_diagnostics_for_clipboard() ); ?></textarea>
			</div>
		</div>

	<?php endif; ?>
</div>
