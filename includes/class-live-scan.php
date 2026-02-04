<?php
/**
 * Live Scan class for Consently plugin.
 *
 * Handles Phase 2 of the Plugin Audit: live cookie/storage scanning
 * via iframe cookie collector and HTML page parsing.
 *
 * @package Consently
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Phase 2 live scan class.
 *
 * Provides REST API endpoints for storing scan results from the iframe
 * cookie collector, running HTML parsing across all pages, and merging
 * and deduplicating the combined results.
 */
class Consently_Live_Scan {

	/**
	 * Audit instance for accessing classify_cookie() and known plugins data.
	 *
	 * @var Consently_Audit
	 */
	private $audit;

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	private $namespace = 'consently/v1';

	/**
	 * Transient key for live scan results (cookies/storage collected per page).
	 *
	 * @var string
	 */
	private $live_results_transient = 'consently_live_scan_results';

	/**
	 * Transient key for combined Phase 2 results.
	 *
	 * @var string
	 */
	private $phase2_transient = 'consently_audit_phase2';

	/**
	 * TTL for live scan results transient (1 hour).
	 *
	 * @var int
	 */
	private $live_results_ttl = HOUR_IN_SECONDS;

	/**
	 * TTL for Phase 2 results transient (7 days).
	 *
	 * @var int
	 */
	private $phase2_ttl = 7 * DAY_IN_SECONDS;

	/**
	 * Constructor.
	 *
	 * @param Consently_Audit $audit Audit instance for cookie classification.
	 */
	public function __construct( Consently_Audit $audit ) {
		$this->audit = $audit;
	}

	/**
	 * Initialize hooks and REST API routes.
	 *
	 * Should be called during WordPress init.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		register_rest_route(
			$this->namespace,
			'/store-scan-cookies',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_store_scan_cookies' ),
				'permission_callback' => array( $this, 'check_manage_options' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/parse-pages-html',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_parse_pages_html' ),
				'permission_callback' => array( $this, 'check_manage_options' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/scan-status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_scan_status' ),
				'permission_callback' => array( $this, 'check_manage_options' ),
			)
		);
	}

	/**
	 * Permission callback: checks for manage_options capability.
	 *
	 * @return bool True if current user can manage options.
	 */
	public function check_manage_options() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Handle POST /store-scan-cookies endpoint.
	 *
	 * Receives cookie and storage data collected by the iframe cookie collector
	 * for a specific page, validates the scan token, sanitizes all input,
	 * and appends results to the live scan results transient.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function handle_store_scan_cookies( WP_REST_Request $request ) {
		$params = $request->get_json_params();

		// Validate required fields.
		if ( empty( $params['scanId'] ) || empty( $params['token'] ) ) {
			return new WP_Error(
				'missing_params',
				__( 'Missing required parameters.', 'consently' ),
				array( 'status' => 400 )
			);
		}

		$scan_id = sanitize_text_field( $params['scanId'] );
		$token   = sanitize_text_field( $params['token'] );

		// Validate the scan token.
		if ( ! $this->audit->validate_scan_token( $token ) ) {
			return new WP_Error(
				'invalid_token',
				__( 'Invalid scan token.', 'consently' ),
				array( 'status' => 403 )
			);
		}

		// Sanitize cookies array.
		$cookies = array();
		if ( ! empty( $params['cookies'] ) && is_array( $params['cookies'] ) ) {
			foreach ( $params['cookies'] as $cookie ) {
				$name = isset( $cookie['name'] ) ? sanitize_text_field( $cookie['name'] ) : '';

				if ( empty( $name ) ) {
					continue;
				}

				// Skip Consently's own cookies.
				if ( 0 === strpos( $name, 'cc_cookie' ) ) {
					continue;
				}

				// Skip WordPress internal cookies unless scanning the login page.
				if ( 0 === strpos( $name, 'wordpress_' ) && 'login' !== $scan_id ) {
					continue;
				}

				$cookies[] = array(
					'name'     => $name,
					'hasValue' => ! empty( $cookie['hasValue'] ),
				);
			}
		}

		// Sanitize localStorage array.
		$local_storage = array();
		if ( ! empty( $params['localStorage'] ) && is_array( $params['localStorage'] ) ) {
			foreach ( $params['localStorage'] as $item ) {
				$sanitized = sanitize_text_field( $item );
				if ( ! empty( $sanitized ) ) {
					$local_storage[] = $sanitized;
				}
			}
		}

		// Sanitize sessionStorage array.
		$session_storage = array();
		if ( ! empty( $params['sessionStorage'] ) && is_array( $params['sessionStorage'] ) ) {
			foreach ( $params['sessionStorage'] as $item ) {
				$sanitized = sanitize_text_field( $item );
				if ( ! empty( $sanitized ) ) {
					$session_storage[] = $sanitized;
				}
			}
		}

		// Build page result entry.
		$page_result = array(
			'scanId'         => $scan_id,
			'cookies'        => $cookies,
			'localStorage'   => $local_storage,
			'sessionStorage' => $session_storage,
			'timestamp'      => time(),
		);

		// Append to existing live scan results.
		$existing = get_transient( $this->live_results_transient );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$existing[] = $page_result;

		set_transient( $this->live_results_transient, $existing, $this->live_results_ttl );

		return rest_ensure_response(
			array(
				'status' => 'ok',
				'page'   => $scan_id,
			)
		);
	}

	/**
	 * Handle POST /parse-pages-html endpoint.
	 *
	 * Parses HTML for all provided pages using Consently_HTML_Parser,
	 * merges results with live scan cookie/storage data, classifies cookies,
	 * and stores the combined Phase 2 results.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function handle_parse_pages_html( WP_REST_Request $request ) {
		$params = $request->get_json_params();

		// Validate required fields.
		if ( empty( $params['pages'] ) || ! is_array( $params['pages'] ) || empty( $params['token'] ) ) {
			return new WP_Error(
				'missing_params',
				__( 'Missing required parameters.', 'consently' ),
				array( 'status' => 400 )
			);
		}

		$token = sanitize_text_field( $params['token'] );

		// Validate the scan token.
		if ( ! $this->audit->validate_scan_token( $token ) ) {
			return new WP_Error(
				'invalid_token',
				__( 'Invalid scan token.', 'consently' ),
				array( 'status' => 403 )
			);
		}

		// Initialize merged HTML parsing results.
		$merged_social_media  = array();
		$merged_thirdparty    = array();
		$merged_statistics    = array();
		$merged_tracking_ids  = array();
		$merged_double_stats  = array();
		$pages_scanned        = 0;

		$parser = new Consently_HTML_Parser();

		foreach ( $params['pages'] as $page ) {
			$page_id  = isset( $page['id'] ) ? sanitize_text_field( $page['id'] ) : '';
			$page_url = isset( $page['url'] ) ? esc_url_raw( $page['url'] ) : '';

			if ( empty( $page_url ) ) {
				continue;
			}

			// Skip login page for HTML parsing.
			if ( 'login' === $page_id ) {
				$pages_scanned++;
				continue;
			}

			$page_results = $parser->parse_page( $page_url );

			if ( is_array( $page_results ) ) {
				// Merge social_media.
				if ( ! empty( $page_results['social_media'] ) ) {
					$merged_social_media = array_unique(
						array_merge( $merged_social_media, $page_results['social_media'] )
					);
				}

				// Merge thirdparty.
				if ( ! empty( $page_results['thirdparty'] ) ) {
					$merged_thirdparty = array_unique(
						array_merge( $merged_thirdparty, $page_results['thirdparty'] )
					);
				}

				// Merge statistics.
				if ( ! empty( $page_results['statistics'] ) ) {
					$merged_statistics = array_unique(
						array_merge( $merged_statistics, $page_results['statistics'] )
					);
				}

				// Merge tracking_ids (deduplicate by type:id later).
				if ( ! empty( $page_results['tracking_ids'] ) ) {
					$merged_tracking_ids = array_merge( $merged_tracking_ids, $page_results['tracking_ids'] );
				}

				// Merge double_stats.
				if ( ! empty( $page_results['double_stats'] ) ) {
					$merged_double_stats = array_unique(
						array_merge( $merged_double_stats, $page_results['double_stats'] )
					);
				}
			}

			$pages_scanned++;
		}

		// Deduplicate tracking_ids by type:id combination.
		$merged_tracking_ids = $this->deduplicate_tracking_ids( $merged_tracking_ids );

		// Strip actual IDs from tracking_ids for stored results (privacy).
		$sanitized_tracking_ids = array();
		foreach ( $merged_tracking_ids as $tracking_id ) {
			$sanitized_tracking_ids[] = array(
				'type'    => isset( $tracking_id['type'] ) ? $tracking_id['type'] : '',
				'service' => isset( $tracking_id['service'] ) ? $tracking_id['service'] : '',
			);
		}

		// Process live scan results: classify cookies and storage.
		$live_cookies = array();
		$live_storage = array();

		$live_results = $this->get_live_results();

		if ( is_array( $live_results ) ) {
			// Collect all cookies across pages with page tracking.
			$cookie_pages  = array();
			$storage_pages = array();

			foreach ( $live_results as $page_data ) {
				$page_label = isset( $page_data['scanId'] ) ? $page_data['scanId'] : 'unknown';

				// Process cookies.
				if ( ! empty( $page_data['cookies'] ) && is_array( $page_data['cookies'] ) ) {
					foreach ( $page_data['cookies'] as $cookie ) {
						$name = $cookie['name'];
						if ( ! isset( $cookie_pages[ $name ] ) ) {
							$cookie_pages[ $name ] = array();
						}
						if ( ! in_array( $page_label, $cookie_pages[ $name ], true ) ) {
							$cookie_pages[ $name ][] = $page_label;
						}
					}
				}

				// Process localStorage.
				if ( ! empty( $page_data['localStorage'] ) && is_array( $page_data['localStorage'] ) ) {
					foreach ( $page_data['localStorage'] as $item ) {
						$key = $item;
						if ( ! isset( $storage_pages[ 'local:' . $key ] ) ) {
							$storage_pages[ 'local:' . $key ] = array(
								'name'  => $key,
								'type'  => 'localStorage',
								'pages' => array(),
							);
						}
						if ( ! in_array( $page_label, $storage_pages[ 'local:' . $key ]['pages'], true ) ) {
							$storage_pages[ 'local:' . $key ]['pages'][] = $page_label;
						}
					}
				}

				// Process sessionStorage.
				if ( ! empty( $page_data['sessionStorage'] ) && is_array( $page_data['sessionStorage'] ) ) {
					foreach ( $page_data['sessionStorage'] as $item ) {
						$key = $item;
						if ( ! isset( $storage_pages[ 'session:' . $key ] ) ) {
							$storage_pages[ 'session:' . $key ] = array(
								'name'  => $key,
								'type'  => 'sessionStorage',
								'pages' => array(),
							);
						}
						if ( ! in_array( $page_label, $storage_pages[ 'session:' . $key ]['pages'], true ) ) {
							$storage_pages[ 'session:' . $key ]['pages'][] = $page_label;
						}
					}
				}
			}

			// Classify each unique cookie.
			foreach ( $cookie_pages as $cookie_name => $pages ) {
				$classification = $this->audit->classify_cookie( $cookie_name );

				$live_cookies[] = array(
					'name'     => $cookie_name,
					'type'     => 'cookie',
					'page'     => array_values( $pages ),
					'category' => isset( $classification['category'] ) ? $classification['category'] : 'unknown',
					'service'  => isset( $classification['service'] ) ? $classification['service'] : '',
					'duration' => isset( $classification['duration'] ) ? $classification['duration'] : '',
					'purpose'  => isset( $classification['purpose'] ) ? $classification['purpose'] : '',
					'source'   => 'confirmed',
				);
			}

			// Build live storage entries.
			foreach ( $storage_pages as $storage_entry ) {
				$classification = $this->audit->classify_cookie( $storage_entry['name'] );

				$live_storage[] = array(
					'name'     => $storage_entry['name'],
					'type'     => $storage_entry['type'],
					'page'     => array_values( $storage_entry['pages'] ),
					'category' => isset( $classification['category'] ) ? $classification['category'] : 'unknown',
					'service'  => isset( $classification['service'] ) ? $classification['service'] : '',
				);
			}
		}

		// Build combined Phase 2 results.
		$combined = array(
			'live_cookies'   => $live_cookies,
			'live_storage'   => $live_storage,
			'social_media'   => array_values( $merged_social_media ),
			'thirdparty'     => array_values( $merged_thirdparty ),
			'statistics'     => array_values( $merged_statistics ),
			'tracking_ids'   => $sanitized_tracking_ids,
			'double_stats'   => array_values( $merged_double_stats ),
			'pages_scanned'  => $pages_scanned,
			'timestamp'      => time(),
		);

		// Store Phase 2 results.
		set_transient( $this->phase2_transient, $combined, $this->phase2_ttl );

		// Clean up live scan results transient.
		delete_transient( $this->live_results_transient );

		return rest_ensure_response( $combined );
	}

	/**
	 * Handle GET /scan-status endpoint.
	 *
	 * Returns the current scan progress based on live results collected so far.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response Response with scan status.
	 */
	public function handle_scan_status( WP_REST_Request $request ) {
		$live_results = $this->get_live_results();

		$pages_scanned = 0;
		if ( is_array( $live_results ) ) {
			$pages_scanned = count( $live_results );
		}

		// Check if Phase 2 results already exist.
		$phase2 = $this->get_phase2_results();

		return rest_ensure_response(
			array(
				'pages_scanned' => $pages_scanned,
				'has_results'   => false !== $phase2,
				'timestamp'     => false !== $phase2 && isset( $phase2['timestamp'] ) ? $phase2['timestamp'] : null,
			)
		);
	}

	/**
	 * Get live scan results from transient.
	 *
	 * @return array|false Live scan results array or false if not set.
	 */
	public function get_live_results() {
		return get_transient( $this->live_results_transient );
	}

	/**
	 * Get Phase 2 combined results from transient.
	 *
	 * @return array|false Phase 2 results array or false if not set.
	 */
	public function get_phase2_results() {
		return get_transient( $this->phase2_transient );
	}

	/**
	 * Clear all live scan and Phase 2 result transients.
	 *
	 * @return void
	 */
	public function clear_results() {
		delete_transient( $this->live_results_transient );
		delete_transient( $this->phase2_transient );
	}

	/**
	 * Deduplicate tracking IDs by type:id combination.
	 *
	 * @param array $tracking_ids Array of tracking ID entries.
	 * @return array Deduplicated tracking IDs.
	 */
	private function deduplicate_tracking_ids( array $tracking_ids ) {
		$seen  = array();
		$unique = array();

		foreach ( $tracking_ids as $entry ) {
			$type = isset( $entry['type'] ) ? $entry['type'] : '';
			$id   = isset( $entry['id'] ) ? $entry['id'] : '';
			$key  = $type . ':' . $id;

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$unique[]     = $entry;
		}

		return $unique;
	}

	/**
	 * Deduplicate items by their 'name' key, merging 'page' values.
	 *
	 * When duplicate names are found, the page arrays are merged and
	 * deduplicated. Other properties are kept from the first occurrence.
	 *
	 * @param array $items Array of items with 'name' and optionally 'page' keys.
	 * @return array Deduplicated items with merged page arrays.
	 */
	public function deduplicate_by_name( array $items ) {
		$index  = array();
		$result = array();

		foreach ( $items as $item ) {
			$name = isset( $item['name'] ) ? $item['name'] : '';

			if ( empty( $name ) ) {
				continue;
			}

			if ( isset( $index[ $name ] ) ) {
				// Merge page arrays.
				$existing_key = $index[ $name ];
				$existing_pages = isset( $result[ $existing_key ]['page'] ) ? $result[ $existing_key ]['page'] : array();
				$new_pages      = isset( $item['page'] ) ? $item['page'] : array();

				$result[ $existing_key ]['page'] = array_values(
					array_unique( array_merge( $existing_pages, $new_pages ) )
				);
			} else {
				$index[ $name ] = count( $result );
				$result[]       = $item;
			}
		}

		return array_values( $result );
	}
}
