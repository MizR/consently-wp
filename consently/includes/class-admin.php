<?php
/**
 * Admin class for Consently plugin.
 *
 * Handles admin pages and AJAX actions.
 *
 * @package Consently
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin functionality class.
 */
class Consently_Admin {

	/**
	 * Core instance.
	 *
	 * @var Consently_Core
	 */
	private $core;

	/**
	 * Rate limit option name.
	 *
	 * @var string
	 */
	private $rate_limit_option = 'consently_rate_limit';

	/**
	 * Max connect attempts per window.
	 *
	 * @var int
	 */
	private $max_attempts = 5;

	/**
	 * Rate limit window in seconds.
	 *
	 * @var int
	 */
	private $rate_limit_window = 600; // 10 minutes.

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
		// Admin menu.
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );

		// Admin assets.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// AJAX handlers.
		add_action( 'wp_ajax_consently_connect', array( $this, 'ajax_connect' ) );
		add_action( 'wp_ajax_consently_disconnect', array( $this, 'ajax_disconnect' ) );
		add_action( 'wp_ajax_consently_run_audit', array( $this, 'ajax_run_audit' ) );
		add_action( 'wp_ajax_consently_save_settings', array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_consently_dismiss_notice', array( $this, 'ajax_dismiss_notice' ) );
		add_action( 'wp_ajax_consently_save_test_banner_id', array( $this, 'ajax_save_test_banner_id' ) );

		// Settings registration.
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add admin menu.
	 */
	public function add_admin_menu() {
		add_options_page(
			__( 'Consently Settings', 'consently' ),
			__( 'Consently', 'consently' ),
			'manage_options',
			'consently',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'settings_page_consently' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'consently-admin',
			CONSENTLY_PLUGIN_URL . 'admin/assets/admin.css',
			array(),
			CONSENTLY_VERSION
		);

		wp_enqueue_script(
			'consently-admin',
			CONSENTLY_PLUGIN_URL . 'admin/assets/admin.js',
			array( 'jquery' ),
			CONSENTLY_VERSION,
			true
		);

		wp_localize_script(
			'consently-admin',
			'consentlyAdmin',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'consently_admin' ),
				'strings'   => array(
					'connecting'       => __( 'Connecting...', 'consently' ),
					'disconnecting'    => __( 'Disconnecting...', 'consently' ),
					'analyzing'        => __( 'Analyzing plugins...', 'consently' ),
					'saving'           => __( 'Saving...', 'consently' ),
					'confirmDisconnect' => __( 'This will disable the consent banner on your site. Are you sure you want to disconnect?', 'consently' ),
					'copied'           => __( 'Copied to clipboard!', 'consently' ),
					'copyFailed'       => __( 'Failed to copy. Please select and copy manually.', 'consently' ),
				),
			)
		);
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		register_setting(
			'consently_settings',
			'consently_banner_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			)
		);

		register_setting(
			'consently_settings',
			'consently_show_to_admins',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			)
		);
	}

	/**
	 * Render admin page.
	 */
	public function render_admin_page() {
		// Check capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'consently' ) );
		}

		// Get current tab.
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'connection';
		$tabs        = array(
			'connection' => __( 'Connection', 'consently' ),
			'audit'      => __( 'Plugin Audit', 'consently' ),
			'settings'   => __( 'Settings', 'consently' ),
		);

		// Check domain change.
		$domain_changed = false;
		$stored_home    = get_option( 'consently_last_validated_home_host' );
		$current_home   = $this->core->get_normalized_home_host();
		if ( $stored_home && $stored_home !== $current_home ) {
			$domain_changed = true;
		}

		?>
		<div class="wrap consently-admin-wrap">
			<h1><?php esc_html_e( 'Consently Settings', 'consently' ); ?></h1>

			<p class="consently-disclosure">
				<?php
				printf(
					/* translators: %s: Privacy policy URL */
					esc_html__( 'This plugin connects to consently.net to provide consent management. By using this plugin, you agree to the %s.', 'consently' ),
					'<a href="https://consently.net/privacy" target="_blank" rel="noopener">' . esc_html__( 'Consently Privacy Policy', 'consently' ) . '</a>'
				);
				?>
			</p>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $tab_key => $tab_label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'options-general.php?page=consently&tab=' . $tab_key ) ); ?>"
					   class="nav-tab <?php echo $current_tab === $tab_key ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $tab_label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="consently-tab-content">
				<?php
				switch ( $current_tab ) {
					case 'audit':
						include CONSENTLY_PLUGIN_DIR . 'admin/views/audit.php';
						break;
					case 'settings':
						include CONSENTLY_PLUGIN_DIR . 'admin/views/settings.php';
						break;
					default:
						include CONSENTLY_PLUGIN_DIR . 'admin/views/connection.php';
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX handler for connection.
	 */
	public function ajax_connect() {
		// Verify nonce.
		if ( ! check_ajax_referer( 'consently_admin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'consently' ) ) );
		}

		// Check capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'consently' ) ) );
		}

		// Check rate limit.
		if ( $this->is_rate_limited() ) {
			wp_send_json_error( array( 'message' => __( 'Too many connection attempts. Please wait 10 minutes before trying again.', 'consently' ) ) );
		}

		// Get and validate API key.
		$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

		if ( empty( $api_key ) ) {
			$this->record_rate_limit_attempt();
			wp_send_json_error( array( 'message' => __( 'Please enter an API key.', 'consently' ) ) );
		}

		// Attempt connection.
		$response = $this->core->api->connect( $api_key );

		if ( is_wp_error( $response ) ) {
			$this->record_rate_limit_attempt();
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		// Store connection data.
		$this->core->store_connection_data( $response );

		// Clear rate limit on success.
		delete_transient( $this->rate_limit_option );

		wp_send_json_success(
			array(
				'message' => __( 'Successfully connected to Consently!', 'consently' ),
				'data'    => array(
					'site_id'          => $response['site_id'],
					'plan'             => $response['plan'],
					'canonical_domain' => $response['canonical_domain'],
				),
			)
		);
	}

	/**
	 * AJAX handler for disconnection.
	 */
	public function ajax_disconnect() {
		// Verify nonce.
		if ( ! check_ajax_referer( 'consently_admin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'consently' ) ) );
		}

		// Check capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'consently' ) ) );
		}

		// Clear connection data.
		$this->core->clear_connection_data();

		wp_send_json_success(
			array(
				'message' => __( 'Successfully disconnected from Consently.', 'consently' ),
			)
		);
	}

	/**
	 * AJAX handler for plugin audit.
	 */
	public function ajax_run_audit() {
		// Verify nonce.
		if ( ! check_ajax_referer( 'consently_admin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'consently' ) ) );
		}

		// Check capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'consently' ) ) );
		}

		// Run audit.
		$audit   = $this->core->audit;
		$results = $audit->run_audit();

		// Cache results.
		set_transient( 'consently_audit_results', $results, DAY_IN_SECONDS );

		wp_send_json_success(
			array(
				'message' => __( 'Audit completed.', 'consently' ),
				'results' => $results,
			)
		);
	}

	/**
	 * AJAX handler for saving settings.
	 */
	public function ajax_save_settings() {
		// Verify nonce.
		if ( ! check_ajax_referer( 'consently_admin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'consently' ) ) );
		}

		// Check capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'consently' ) ) );
		}

		// Get and sanitize settings.
		$banner_enabled  = isset( $_POST['banner_enabled'] ) ? rest_sanitize_boolean( wp_unslash( $_POST['banner_enabled'] ) ) : true;
		$show_to_admins  = isset( $_POST['show_to_admins'] ) ? rest_sanitize_boolean( wp_unslash( $_POST['show_to_admins'] ) ) : true;

		// Update settings.
		update_option( 'consently_banner_enabled', $banner_enabled );
		update_option( 'consently_show_to_admins', $show_to_admins );

		wp_send_json_success(
			array(
				'message' => __( 'Settings saved successfully.', 'consently' ),
			)
		);
	}

	/**
	 * AJAX handler for dismissing notices.
	 */
	public function ajax_dismiss_notice() {
		// Verify nonce.
		if ( ! check_ajax_referer( 'consently_admin', 'nonce', false ) ) {
			wp_send_json_error();
		}

		// Check capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		$notice = isset( $_POST['notice'] ) ? sanitize_key( $_POST['notice'] ) : '';

		if ( 'setup' === $notice ) {
			update_option( 'consently_setup_notice_dismissed', true );
		}

		wp_send_json_success();
	}

	/**
	 * AJAX handler for saving test banner ID.
	 */
	public function ajax_save_test_banner_id() {
		// Verify nonce.
		if ( ! check_ajax_referer( 'consently_admin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'consently' ) ) );
		}

		// Check capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'consently' ) ) );
		}

		// Only allow in test mode.
		if ( ! $this->core->is_test_mode() ) {
			wp_send_json_error( array( 'message' => __( 'Test mode is not active.', 'consently' ) ) );
		}

		$banner_id = isset( $_POST['banner_id'] ) ? sanitize_text_field( wp_unslash( $_POST['banner_id'] ) ) : '';

		if ( empty( $banner_id ) ) {
			// Clear custom ID, fall back to constant.
			delete_option( 'consently_test_banner_id' );
		} else {
			update_option( 'consently_test_banner_id', $banner_id, false );
		}

		wp_send_json_success(
			array(
				'message'   => __( 'Banner ID saved.', 'consently' ),
				'banner_id' => $this->core->get_site_id(),
			)
		);
	}

	/**
	 * Check if rate limited.
	 *
	 * @return bool True if rate limited.
	 */
	private function is_rate_limited() {
		$attempts = get_transient( $this->rate_limit_option );

		if ( false === $attempts ) {
			return false;
		}

		return $attempts >= $this->max_attempts;
	}

	/**
	 * Record rate limit attempt.
	 */
	private function record_rate_limit_attempt() {
		$attempts = get_transient( $this->rate_limit_option );

		if ( false === $attempts ) {
			$attempts = 0;
		}

		$attempts++;

		set_transient( $this->rate_limit_option, $attempts, $this->rate_limit_window );
	}

	/**
	 * Get deep link URL for Consently dashboard.
	 *
	 * @param string $page Dashboard page.
	 * @return string URL.
	 */
	public function get_deep_link( $page ) {
		$site_id = $this->core->get_site_id();

		if ( ! $site_id ) {
			return CONSENTLY_APP_URL;
		}

		$pages = array(
			'banner'   => '/sites/%s/banner',
			'scanner'  => '/sites/%s/scanner',
			'logs'     => '/sites/%s/logs',
			'settings' => '/sites/%s/settings',
		);

		if ( ! isset( $pages[ $page ] ) ) {
			return CONSENTLY_APP_URL;
		}

		return CONSENTLY_APP_URL . sprintf( $pages[ $page ], $site_id );
	}

	/**
	 * Get diagnostics information.
	 *
	 * @return array Diagnostics data.
	 */
	public function get_diagnostics() {
		global $wp_version;

		$connection_data = $this->core->get_connection_data();
		$cache_plugins   = $this->core->detect_cache_plugins();

		return array(
			'wordpress_version'  => $wp_version,
			'php_version'        => PHP_VERSION,
			'home_url'           => $this->core->get_normalized_home_host(),
			'canonical_domain'   => $connection_data['canonical_domain'],
			'script_injected'    => $this->core->should_show_banner() ? __( 'Yes', 'consently' ) : __( 'No', 'consently' ),
			'cache_plugins'      => ! empty( $cache_plugins ) ? implode( ', ', $cache_plugins ) : __( 'None detected', 'consently' ),
			'plugin_version'     => CONSENTLY_VERSION,
			'multisite'          => is_multisite() ? __( 'Yes', 'consently' ) : __( 'No', 'consently' ),
			'wp_consent_api'     => class_exists( 'WP_CONSENT_API' ) ? __( 'Active', 'consently' ) : __( 'Not installed', 'consently' ),
		);
	}

	/**
	 * Format diagnostics for clipboard.
	 *
	 * @return string Formatted diagnostics.
	 */
	public function format_diagnostics_for_clipboard() {
		$diagnostics = $this->get_diagnostics();

		$output = "Consently Diagnostics\n";
		$output .= "=====================\n";
		$output .= "WordPress: {$diagnostics['wordpress_version']}\n";
		$output .= "PHP: {$diagnostics['php_version']}\n";
		$output .= "Home URL: {$diagnostics['home_url']}\n";
		$output .= "Canonical Domain: {$diagnostics['canonical_domain']}\n";
		$output .= "Script Injected: {$diagnostics['script_injected']}\n";
		$output .= "Cache Plugins: {$diagnostics['cache_plugins']}\n";
		$output .= "Plugin Version: {$diagnostics['plugin_version']}\n";
		$output .= "Multisite: {$diagnostics['multisite']}\n";
		$output .= "WP Consent API: {$diagnostics['wp_consent_api']}\n";

		return $output;
	}
}
