<?php
/**
 * Settings tab view.
 *
 * @package Consently
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$banner_enabled  = get_option( 'consently_banner_enabled', true );
$show_to_admins  = get_option( 'consently_show_to_admins', true );
$cache_plugins   = $this->core->detect_cache_plugins();
$exclusion_rules = $this->core->script->get_cache_exclusion_rules();
?>

<div class="consently-settings-tab">
	<form id="consently-settings-form">
		<div class="consently-card">
			<h2><?php esc_html_e( 'Banner Settings', 'consently' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Banner', 'consently' ); ?></th>
					<td>
						<label>
							<input type="checkbox"
								   name="banner_enabled"
								   id="consently-banner-enabled"
								   value="1"
								   <?php checked( $banner_enabled ); ?> />
							<?php esc_html_e( 'Display consent banner on the site', 'consently' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Disabling the banner may affect your site\'s compliance with privacy regulations.', 'consently' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Admin Preview', 'consently' ); ?></th>
					<td>
						<label>
							<input type="checkbox"
								   name="show_to_admins"
								   id="consently-show-to-admins"
								   value="1"
								   <?php checked( $show_to_admins ); ?> />
							<?php esc_html_e( 'Show banner to logged-in administrators', 'consently' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Useful for testing and previewing banner changes.', 'consently' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" id="consently-save-settings" class="button button-primary">
					<?php esc_html_e( 'Save Settings', 'consently' ); ?>
				</button>
				<span id="consently-settings-message" class="consently-inline-message" style="display: none;"></span>
			</p>
		</div>
	</form>

	<!-- Cache Plugin Compatibility -->
	<div class="consently-card consently-cache-compat">
		<h2><?php esc_html_e( 'Cache Compatibility', 'consently' ); ?></h2>

		<?php if ( ! empty( $cache_plugins ) ) : ?>
			<p>
				<strong><?php esc_html_e( 'Detected cache plugins:', 'consently' ); ?></strong>
				<?php echo esc_html( implode( ', ', $cache_plugins ) ); ?>
			</p>

			<div class="consently-cache-warning">
				<span class="dashicons dashicons-info"></span>
				<p>
					<?php esc_html_e( 'Cache and optimization plugins may interfere with consent management. To ensure proper functionality, add these exclusion rules to your cache plugin settings:', 'consently' ); ?>
				</p>
			</div>

			<div class="consently-exclusion-rules">
				<h4><?php esc_html_e( 'Exclude from JavaScript optimization:', 'consently' ); ?></h4>
				<code class="consently-copy-text" data-copy="<?php echo esc_attr( $exclusion_rules['js_exclude'] ); ?>">
					<?php echo esc_html( $exclusion_rules['js_exclude'] ); ?>
				</code>

				<h4><?php esc_html_e( 'Exclude from Delay JS:', 'consently' ); ?></h4>
				<code class="consently-copy-text" data-copy="<?php echo esc_attr( $exclusion_rules['delay_exclude'] ); ?>">
					<?php echo esc_html( $exclusion_rules['delay_exclude'] ); ?>
				</code>
			</div>

			<p class="description">
				<?php esc_html_e( 'Click on the code snippets above to copy them to your clipboard.', 'consently' ); ?>
			</p>

		<?php else : ?>
			<p>
				<span class="dashicons dashicons-yes"></span>
				<?php esc_html_e( 'No cache optimization plugins detected.', 'consently' ); ?>
			</p>
		<?php endif; ?>

		<div class="consently-cache-tips">
			<h4><?php esc_html_e( 'General Guidelines:', 'consently' ); ?></h4>
			<ul>
				<li><?php esc_html_e( 'Do not defer or delay the Consently script', 'consently' ); ?></li>
				<li><?php esc_html_e( 'Do not minify or combine the Consently script with other files', 'consently' ); ?></li>
				<li><?php esc_html_e( 'Ensure the script loads before any tracking scripts', 'consently' ); ?></li>
			</ul>
		</div>
	</div>

	<!-- WP Consent API Status -->
	<div class="consently-card consently-wp-consent-status">
		<h2><?php esc_html_e( 'WP Consent API', 'consently' ); ?></h2>

		<?php if ( class_exists( 'WP_CONSENT_API' ) ) : ?>
			<p>
				<span class="dashicons dashicons-yes"></span>
				<?php esc_html_e( 'WP Consent API is active. Consently will automatically sync consent preferences with other compatible plugins.', 'consently' ); ?>
			</p>
		<?php else : ?>
			<p>
				<span class="dashicons dashicons-info"></span>
				<?php
				printf(
					/* translators: %s: Plugin URL */
					esc_html__( 'The %s is not installed. Installing it allows Consently to communicate consent preferences to other compatible plugins.', 'consently' ),
					'<a href="https://wordpress.org/plugins/wp-consent-api/" target="_blank" rel="noopener">' . esc_html__( 'WP Consent API plugin', 'consently' ) . '</a>'
				);
				?>
			</p>
		<?php endif; ?>
	</div>
</div>
