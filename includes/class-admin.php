<?php
/**
 * Admin class for Consently Scanner plugin.
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
		add_action( 'wp_ajax_consently_run_audit', array( $this, 'ajax_run_audit' ) );
		add_action( 'wp_ajax_consently_start_live_scan', array( $this, 'ajax_start_live_scan' ) );
		add_action( 'wp_ajax_consently_export_json', array( $this, 'ajax_export_json' ) );
	}

	/**
	 * Add admin menu.
	 */
	public function add_admin_menu() {
		add_options_page(
			__( 'Consently Scanner', 'consently' ),
			__( 'Consently Scanner', 'consently' ),
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

		// Live scan orchestrator.
		wp_enqueue_script(
			'consently-admin-scan',
			CONSENTLY_PLUGIN_URL . 'assets/js/admin-scan.js',
			array(),
			CONSENTLY_VERSION,
			true
		);

		wp_localize_script(
			'consently-admin',
			'consentlyAdmin',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'restUrl'   => esc_url_raw( rest_url() ),
				'nonce'     => wp_create_nonce( 'consently_admin' ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'strings'   => array(
					'analyzing'        => __( 'Analyzing plugins...', 'consently' ),
					'startingLiveScan' => __( 'Starting live scan...', 'consently' ),
					'scanComplete'     => __( 'Scan complete!', 'consently' ),
					'exporting'        => __( 'Exporting...', 'consently' ),
					'exportFailed'     => __( 'Export failed. Please try again.', 'consently' ),
					'noResults'        => __( 'No scan results available. Please run an audit first.', 'consently' ),
				),
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

		?>
		<div class="wrap consently-admin-wrap">
			<h1><?php echo esc_html( sprintf( __( 'Consently Scanner %s', 'consently' ), 'v' . CONSENTLY_VERSION ) ); ?></h1>

			<div class="consently-tab-content">
				<?php include CONSENTLY_PLUGIN_DIR . 'admin/views/audit.php'; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX handler for plugin audit (Phase 1 - static analysis).
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

		// Record scan start time.
		set_transient( 'consently_scan_started_at', gmdate( 'c' ), DAY_IN_SECONDS );

		// Run Phase 1 static analysis.
		$audit   = $this->core->audit;
		$results = $audit->run_static();

		// Cache Phase 1 results.
		set_transient( 'consently_audit_phase1', $results, 7 * DAY_IN_SECONDS );

		wp_send_json_success(
			array(
				'message' => __( 'Static analysis completed.', 'consently' ),
				'results' => $results,
			)
		);
	}

	/**
	 * AJAX handler for starting live scan (Phase 2).
	 */
	public function ajax_start_live_scan() {
		// Verify nonce.
		if ( ! check_ajax_referer( 'consently_admin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'consently' ) ) );
		}

		// Check capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'consently' ) ) );
		}

		// Clear previous live scan results.
		$this->core->live_scan->clear_results();

		// Build page list.
		$crawler = new Consently_Page_Crawler();
		$pages   = $crawler->build_page_list();

		// Create scan token.
		$token = $this->core->audit->create_scan_token();

		wp_send_json_success(
			array(
				'message' => __( 'Live scan initialized.', 'consently' ),
				'pages'   => $pages,
				'token'   => $token,
			)
		);
	}

	/**
	 * AJAX handler for JSON export.
	 */
	public function ajax_export_json() {
		// Verify nonce.
		if ( ! check_ajax_referer( 'consently_admin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'consently' ) ) );
		}

		// Check capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'consently' ) ) );
		}

		$phase1 = get_transient( 'consently_audit_phase1' );
		$phase2 = get_transient( 'consently_audit_phase2' );

		if ( false === $phase1 && false === $phase2 ) {
			wp_send_json_error( array( 'message' => __( 'No scan results available. Please run an audit first.', 'consently' ) ) );
		}

		$exporter = new Consently_JSON_Export( $this->core->audit );
		$json     = $exporter->generate();

		$domain   = $this->core->get_normalized_home_host();
		$date     = gmdate( 'Y-m-d' );
		$filename = 'consently-scan-' . sanitize_file_name( $domain ) . '-' . $date . '.json';

		wp_send_json_success(
			array(
				'json'     => $json,
				'filename' => $filename,
			)
		);
	}

	/**
	 * Get diagnostics information.
	 *
	 * @return array Diagnostics data.
	 */
	public function get_diagnostics() {
		global $wp_version;

		$cache_plugins = $this->core->detect_cache_plugins();

		return array(
			'wordpress_version' => $wp_version,
			'php_version'       => PHP_VERSION,
			'home_url'          => $this->core->get_normalized_home_host(),
			'cache_plugins'     => ! empty( $cache_plugins ) ? implode( ', ', $cache_plugins ) : __( 'None detected', 'consently' ),
			'plugin_version'    => CONSENTLY_VERSION,
			'multisite'         => is_multisite() ? __( 'Yes', 'consently' ) : __( 'No', 'consently' ),
		);
	}
}
