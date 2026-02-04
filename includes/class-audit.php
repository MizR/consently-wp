<?php
/**
 * Plugin Audit v2 Phase 1 - Static Analysis.
 *
 * Performs local static analysis of installed plugins, themes, and
 * options to detect tracking cookies, scripts, and services without
 * making any external requests.
 *
 * @package Consently
 * @since   0.1.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static audit class for Phase 1 tracking detection.
 *
 * Analyses active plugins, theme files, enqueued scripts, and the
 * options table against a known-plugins database and heuristic
 * patterns.  All work happens server-side; no external HTTP calls
 * are made.
 */
class Consently_Audit {

	// ──────────────────────────────────────────────
	//  Constants / limits
	// ──────────────────────────────────────────────

	/**
	 * Maximum PHP files to scan per plugin.
	 *
	 * @var int
	 */
	private const MAX_FILES_PER_PLUGIN = 200;

	/**
	 * Maximum file size in bytes (500 KB).
	 *
	 * @var int
	 */
	private const MAX_FILE_SIZE = 524288;

	/**
	 * Maximum total scan time in seconds.
	 *
	 * @var int
	 */
	private const MAX_SCAN_TIME = 60;

	/**
	 * Directories to skip when scanning plugin sources.
	 *
	 * @var string[]
	 */
	private const SKIP_DIRS = array(
		'vendor',
		'node_modules',
		'assets',
		'build',
		'tests',
		'languages',
	);

	/**
	 * Transient key for cached audit results.
	 *
	 * @var string
	 */
	private const TRANSIENT_RESULTS = 'consently_audit_results';

	/**
	 * Transient key for enqueued-scripts capture.
	 *
	 * @var string
	 */
	private const TRANSIENT_SCRIPTS = 'consently_enqueued_scripts';

	/**
	 * Transient key for plugin hash.
	 *
	 * @var string
	 */
	private const TRANSIENT_HASH = 'consently_plugin_hash';

	/**
	 * Cache duration for audit results (1 hour).
	 *
	 * @var int
	 */
	private const CACHE_TTL = 3600;

	// ──────────────────────────────────────────────
	//  Instance state
	// ──────────────────────────────────────────────

	/**
	 * Scan start timestamp (microtime).
	 *
	 * @var float
	 */
	private $scan_start_time = 0.0;

	/**
	 * Whether the scan was cut short by time or file limits.
	 *
	 * @var bool
	 */
	private $partial_scan = false;

	/**
	 * In-memory cache of the decoded known-plugins JSON.
	 *
	 * @var array|null
	 */
	private $known_plugins_data = null;

	/**
	 * Consent / privacy plugins that should never be flagged as
	 * trackers even though they reference tracking domains in
	 * their source code.
	 *
	 * @var string[]
	 */
	private $skip_slugs = array(
		'consently',
		'cookie-law-info',
		'cookieyes',
		'iubenda-cookie-law-solution',
		'complianz-gdpr',
		'real-cookie-banner',
		'cookie-notice',
		'gdpr-cookie-compliance',
		'wp-consent-api',
		'uk-cookie-consent',
	);

	/**
	 * Tracking-ID patterns and their service labels.
	 * Keys are regex-safe prefixes; values are human labels.
	 *
	 * @var array<string, string>
	 */
	private $tracking_id_patterns = array(
		'UA-'  => 'Google Universal Analytics',
		'G-'   => 'Google Analytics 4',
		'GTM-' => 'Google Tag Manager',
		'AW-'  => 'Google Ads',
		'DC-'  => 'DoubleClick / Floodlight',
	);

	/**
	 * Facebook Pixel ID regex (numeric, 15-16 digits).
	 *
	 * @var string
	 */
	private $fb_pixel_regex = '/\b\d{15,16}\b/';

	// ══════════════════════════════════════════════
	//  PUBLIC API
	// ══════════════════════════════════════════════

	/**
	 * Run all Phase 1 static analysis methods.
	 *
	 * Executes the five detection passes and returns a merged result
	 * array.  Results are stored as a transient for later retrieval.
	 *
	 * @return array {
	 *     @type array  $known_plugins      Plugins matched in the known DB.
	 *     @type array  $source_cookies      Cookies found via PHP source scan.
	 *     @type array  $enqueued_scripts    Scripts captured by the hook (may be empty on first call).
	 *     @type array  $options_tracking    Tracking-related option detections.
	 *     @type array  $theme_tracking      Theme file tracking detections.
	 *     @type array  $wordpress_cookies   WordPress core cookies from the DB.
	 *     @type array  $clean_plugins       Known plugins with tracking === false.
	 *     @type array  $not_in_database     Unknown plugins where no tracking was found.
	 *     @type float  $scan_time           Wall-clock seconds elapsed.
	 *     @type bool   $partial_scan        True if limits were hit.
	 *     @type string $plugin_hash         MD5 of active_plugins for staleness.
	 * }
	 */
	public function run_static(): array {
		$this->scan_start_time = microtime( true );
		$this->partial_scan    = false;

		// 1 – Known plugins.
		$known_result = $this->detect_known_plugins();

		// 2 – PHP source scan (only for plugins NOT in known DB).
		$source_cookies = $this->scan_php_sources( $known_result['known_files'] );

		// 3 – Enqueued scripts (read from transient; hook writes it).
		$enqueued_scripts = $this->inspect_enqueued_scripts();

		// 4 – Options table.
		$options_tracking = $this->scan_options_table();

		// 5 – Theme files.
		$theme_tracking = $this->scan_theme();

		// WordPress core cookies from the JSON database.
		$db                = $this->get_known_plugins();
		$wordpress_cookies = isset( $db['wordpress_core_cookies'] ) ? $db['wordpress_core_cookies'] : array();

		$plugin_hash = $this->compute_plugin_hash();

		$results = array(
			'known_plugins'      => $known_result['known_plugins'],
			'source_cookies'     => $source_cookies,
			'enqueued_scripts'   => $enqueued_scripts,
			'options_tracking'   => $options_tracking,
			'theme_tracking'     => $theme_tracking,
			'wordpress_cookies'  => $wordpress_cookies,
			'clean_plugins'      => $known_result['clean_plugins'],
			'not_in_database'    => $known_result['not_in_database'],
			'scan_time'          => round( microtime( true ) - $this->scan_start_time, 3 ),
			'partial_scan'       => $this->partial_scan,
			'plugin_hash'        => $plugin_hash,
		);

		// Cache results.
		set_transient( self::TRANSIENT_RESULTS, $results, self::CACHE_TTL );
		set_transient( self::TRANSIENT_HASH, $plugin_hash, self::CACHE_TTL );

		return $results;
	}

	/**
	 * Get page list for iframe-based scanning (Phase 2 placeholder).
	 *
	 * @return array List of page URLs to scan.
	 */
	public function get_page_list(): array {
		// Phase 2: will be filled in with page crawler logic.
		return array();
	}

	/**
	 * Create a nonce-based token that authorises iframe scanning.
	 *
	 * @return string Scan token.
	 */
	public function create_scan_token(): string {
		return wp_create_nonce( 'consently_scan_token' );
	}

	/**
	 * Validate a previously issued scan token.
	 *
	 * @param string $token Token to validate.
	 * @return bool True if valid.
	 */
	public function validate_scan_token( string $token ): bool {
		return (bool) wp_verify_nonce( $token, 'consently_scan_token' );
	}

	/**
	 * Classify a cookie name against the known database and heuristics.
	 *
	 * Checks in order:
	 *  1. Known-plugin cookies (exact then prefix match).
	 *  2. WordPress core cookies.
	 *  3. Cookie heuristics from JSON.
	 *  4. Unclassified fallback.
	 *
	 * @param string $name Cookie name (e.g. "_ga", "_hjSessionUser_12345").
	 * @return array {
	 *     @type string $category    analytics | marketing | functional | necessary | unclassified.
	 *     @type string $service     Human-readable service name.
	 *     @type string $purpose     One-liner purpose description (empty for heuristics).
	 *     @type string $duration    Duration string (empty if unknown).
	 *     @type string $match_type  exact | prefix | heuristic | unclassified.
	 * }
	 */
	public function classify_cookie( string $name ): array {
		$db = $this->get_known_plugins();

		// ── 1. Known-plugin cookies ──────────────────────────
		if ( ! empty( $db['plugins'] ) && is_array( $db['plugins'] ) ) {
			foreach ( $db['plugins'] as $plugin_file => $plugin_info ) {
				if ( empty( $plugin_info['cookies'] ) || ! is_array( $plugin_info['cookies'] ) ) {
					continue;
				}
				foreach ( $plugin_info['cookies'] as $cookie ) {
					$cookie_name = isset( $cookie['name'] ) ? $cookie['name'] : '';
					$pattern     = isset( $cookie['pattern'] ) ? $cookie['pattern'] : 'exact';

					if ( 'prefix' === $pattern ) {
						// Strip trailing wildcard characters (* / .) for prefix comparison.
						$prefix = rtrim( $cookie_name, '*.' );
						if ( '' !== $prefix && 0 === strpos( $name, $prefix ) ) {
							return array(
								'category'   => isset( $cookie['category'] ) ? $cookie['category'] : 'unclassified',
								'service'    => isset( $plugin_info['name'] ) ? $plugin_info['name'] : '',
								'purpose'    => isset( $cookie['purpose'] ) ? $cookie['purpose'] : '',
								'duration'   => isset( $cookie['duration'] ) ? $cookie['duration'] : '',
								'match_type' => 'prefix',
							);
						}
					} elseif ( $name === $cookie_name ) {
						return array(
							'category'   => isset( $cookie['category'] ) ? $cookie['category'] : 'unclassified',
							'service'    => isset( $plugin_info['name'] ) ? $plugin_info['name'] : '',
							'purpose'    => isset( $cookie['purpose'] ) ? $cookie['purpose'] : '',
							'duration'   => isset( $cookie['duration'] ) ? $cookie['duration'] : '',
							'match_type' => 'exact',
						);
					}
				}
			}
		}

		// ── 2. WordPress core cookies ────────────────────────
		if ( ! empty( $db['wordpress_core_cookies'] ) && is_array( $db['wordpress_core_cookies'] ) ) {
			foreach ( $db['wordpress_core_cookies'] as $cookie ) {
				$cookie_name = isset( $cookie['name'] ) ? $cookie['name'] : '';
				$pattern     = isset( $cookie['pattern'] ) ? $cookie['pattern'] : 'exact';

				if ( 'prefix' === $pattern ) {
					$prefix = rtrim( $cookie_name, '*.' );
					if ( '' !== $prefix && 0 === strpos( $name, $prefix ) ) {
						return array(
							'category'   => isset( $cookie['category'] ) ? $cookie['category'] : 'necessary',
							'service'    => 'WordPress',
							'purpose'    => isset( $cookie['purpose'] ) ? $cookie['purpose'] : '',
							'duration'   => isset( $cookie['duration'] ) ? $cookie['duration'] : '',
							'match_type' => 'prefix',
						);
					}
				} elseif ( $name === $cookie_name ) {
					return array(
						'category'   => isset( $cookie['category'] ) ? $cookie['category'] : 'necessary',
						'service'    => 'WordPress',
						'purpose'    => isset( $cookie['purpose'] ) ? $cookie['purpose'] : '',
						'duration'   => isset( $cookie['duration'] ) ? $cookie['duration'] : '',
						'match_type' => 'exact',
					);
				}
			}
		}

		// ── 3. Cookie heuristics ─────────────────────────────
		if ( ! empty( $db['cookie_heuristics'] ) && is_array( $db['cookie_heuristics'] ) ) {
			foreach ( $db['cookie_heuristics'] as $hint => $meta ) {
				if ( 0 === strpos( $name, $hint ) ) {
					return array(
						'category'   => isset( $meta['category'] ) ? $meta['category'] : 'unclassified',
						'service'    => isset( $meta['service'] ) ? $meta['service'] : '',
						'purpose'    => '',
						'duration'   => '',
						'match_type' => 'heuristic',
					);
				}
			}
		}

		// ── 4. Unclassified ──────────────────────────────────
		return array(
			'category'   => 'unclassified',
			'service'    => '',
			'purpose'    => '',
			'duration'   => '',
			'match_type' => 'unclassified',
		);
	}

	/**
	 * Check whether the cached audit is stale.
	 *
	 * Staleness is determined by comparing the MD5 of the current
	 * active_plugins list against the hash stored with the last scan.
	 *
	 * @return bool True if the cache is stale or missing.
	 */
	public function is_cache_stale(): bool {
		$stored_hash = get_transient( self::TRANSIENT_HASH );

		if ( false === $stored_hash ) {
			return true;
		}

		return $stored_hash !== $this->compute_plugin_hash();
	}

	/**
	 * Clear all audit-related transients.
	 *
	 * @return void
	 */
	public function clear_cache(): void {
		delete_transient( self::TRANSIENT_RESULTS );
		delete_transient( self::TRANSIENT_SCRIPTS );
		delete_transient( self::TRANSIENT_HASH );
	}

	/**
	 * Get cached audit results.
	 *
	 * @return array|false Cached results or false if not cached.
	 */
	public function get_cached_results() {
		return get_transient( self::TRANSIENT_RESULTS );
	}

	/**
	 * Hook callback for wp_print_scripts at priority 9999.
	 *
	 * Captures all enqueued script URLs, compares them against the
	 * tracking_domains list from known-plugins.json, and stores
	 * matches in a transient.
	 *
	 * @return void
	 */
	public function capture_enqueued_scripts(): void {
		global $wp_scripts;

		if ( ! ( $wp_scripts instanceof WP_Scripts ) ) {
			return;
		}

		$db               = $this->get_known_plugins();
		$tracking_domains = isset( $db['tracking_domains'] ) ? $db['tracking_domains'] : array();

		if ( empty( $tracking_domains ) ) {
			return;
		}

		$matches = array();

		foreach ( $wp_scripts->queue as $handle ) {
			if ( ! isset( $wp_scripts->registered[ $handle ] ) ) {
				continue;
			}

			$src = $wp_scripts->registered[ $handle ]->src;

			if ( empty( $src ) || ! is_string( $src ) ) {
				continue;
			}

			// Parse the host out of the URL.
			$parsed = wp_parse_url( $src );
			$host   = isset( $parsed['host'] ) ? strtolower( $parsed['host'] ) : '';

			if ( '' === $host ) {
				continue;
			}

			foreach ( $tracking_domains as $domain ) {
				// Check if the script host matches or is a subdomain of the tracking domain.
				if ( $host === $domain || $this->string_ends_with( $host, '.' . $domain ) ) {
					$matches[] = array(
						'handle' => sanitize_text_field( $handle ),
						'src'    => esc_url_raw( $src ),
						'domain' => sanitize_text_field( $domain ),
					);
					break; // One match per script is sufficient.
				}
			}
		}

		set_transient( self::TRANSIENT_SCRIPTS, $matches, self::CACHE_TTL );
	}

	// ══════════════════════════════════════════════
	//  PHASE 1 DETECTION METHODS (private)
	// ══════════════════════════════════════════════

	/**
	 * 1. Detect known plugins.
	 *
	 * Cross-references active_plugins against known-plugins.json.
	 * Returns full cookie/domain data for matches.
	 *
	 * @return array {
	 *     @type array    $known_plugins   Tracking plugins from DB.
	 *     @type array    $clean_plugins   Non-tracking plugins from DB.
	 *     @type array    $not_in_database Plugins not in the DB.
	 *     @type string[] $known_files     Plugin files that were in the DB (used to skip source scan).
	 * }
	 */
	private function detect_known_plugins(): array {
		$db              = $this->get_known_plugins();
		$plugins_db      = isset( $db['plugins'] ) ? $db['plugins'] : array();
		$active_plugins  = get_option( 'active_plugins', array() );

		$known_plugins   = array();
		$clean_plugins   = array();
		$not_in_database = array();
		$known_files     = array();

		foreach ( $active_plugins as $plugin_file ) {
			if ( $this->should_skip_plugin( $plugin_file ) ) {
				continue;
			}

			if ( isset( $plugins_db[ $plugin_file ] ) ) {
				$known_files[] = $plugin_file;
				$entry         = $plugins_db[ $plugin_file ];
				$tracking      = isset( $entry['tracking'] ) ? (bool) $entry['tracking'] : false;

				$record = array(
					'file'         => $plugin_file,
					'name'         => isset( $entry['name'] ) ? $entry['name'] : dirname( $plugin_file ),
					'category'     => isset( $entry['category'] ) ? $entry['category'] : '',
					'source'       => 'known_database',
					'cookies'      => isset( $entry['cookies'] ) ? $entry['cookies'] : array(),
					'localStorage' => isset( $entry['localStorage'] ) ? $entry['localStorage'] : array(),
					'domains'      => isset( $entry['domains'] ) ? $entry['domains'] : array(),
				);

				if ( $tracking ) {
					$known_plugins[] = $record;
				} else {
					$clean_plugins[] = $record;
				}
			} else {
				// Gather basic plugin header data for unknown plugins.
				$full_path = WP_PLUGIN_DIR . '/' . $plugin_file;
				$name      = dirname( $plugin_file );

				if ( function_exists( 'get_plugin_data' ) && file_exists( $full_path ) ) {
					$header = get_plugin_data( $full_path, false, false );
					if ( ! empty( $header['Name'] ) ) {
						$name = $header['Name'];
					}
				}

				$not_in_database[] = array(
					'file' => $plugin_file,
					'name' => $name,
				);
			}
		}

		return array(
			'known_plugins'   => $known_plugins,
			'clean_plugins'   => $clean_plugins,
			'not_in_database' => $not_in_database,
			'known_files'     => $known_files,
		);
	}

	/**
	 * 2. Scan PHP source files for cookie-setting calls.
	 *
	 * For every active plugin that is NOT already in the known
	 * database, scans its PHP files for:
	 *  - setcookie()
	 *  - setrawcookie()
	 *  - $_COOKIE assignments
	 *  - header('Set-Cookie:')
	 *
	 * @param string[] $known_files Plugin files already matched in the known DB.
	 * @return array[] List of source_cookie entries.
	 */
	private function scan_php_sources( array $known_files ): array {
		$active_plugins = get_option( 'active_plugins', array() );
		$results        = array();

		foreach ( $active_plugins as $plugin_file ) {
			if ( $this->is_time_exceeded() ) {
				$this->partial_scan = true;
				break;
			}

			if ( $this->should_skip_plugin( $plugin_file ) ) {
				continue;
			}

			// Skip plugins already in the known database.
			if ( in_array( $plugin_file, $known_files, true ) ) {
				continue;
			}

			$plugin_dir = WP_PLUGIN_DIR . '/' . dirname( $plugin_file );

			if ( ! is_dir( $plugin_dir ) ) {
				continue;
			}

			// Resolve plugin display name.
			$plugin_name = dirname( $plugin_file );
			$full_path   = WP_PLUGIN_DIR . '/' . $plugin_file;

			if ( function_exists( 'get_plugin_data' ) && file_exists( $full_path ) ) {
				$header = get_plugin_data( $full_path, false, false );
				if ( ! empty( $header['Name'] ) ) {
					$plugin_name = $header['Name'];
				}
			}

			$php_files = $this->get_php_files( $plugin_dir, self::MAX_FILES_PER_PLUGIN );

			foreach ( $php_files as $file ) {
				if ( $this->is_time_exceeded() ) {
					$this->partial_scan = true;
					break 2;
				}

				// Respect per-file size limit.
				$size = filesize( $file );
				if ( false === $size || $size > self::MAX_FILE_SIZE ) {
					continue;
				}

				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$contents = file_get_contents( $file );

				if ( false === $contents ) {
					continue;
				}

				$lines    = explode( "\n", $contents );
				$rel_file = str_replace( WP_PLUGIN_DIR . '/', '', $file );

				foreach ( $lines as $line_num => $line ) {
					$found = $this->detect_cookie_call( $line );

					if ( null === $found ) {
						continue;
					}

					$results[] = array(
						'cookie_name' => $found['cookie_name'],
						'plugin_file' => $plugin_file,
						'plugin_name' => $plugin_name,
						'found_in'    => $rel_file,
						'line'        => $line_num + 1,
						'method'      => $found['method'],
					);
				}
			}
		}

		return $results;
	}

	/**
	 * 3. Inspect enqueued scripts.
	 *
	 * Because script registration happens at render time this method
	 * reads results from a transient populated by
	 * {@see capture_enqueued_scripts()}, which should be hooked to
	 * `wp_print_scripts` at priority 9999.
	 *
	 * The hook is registered here so it fires on the next front-end
	 * page load.
	 *
	 * @return array Tracking scripts captured on the last page load.
	 */
	private function inspect_enqueued_scripts(): array {
		// Ensure the hook is registered for the next front-end load.
		if ( ! has_action( 'wp_print_scripts', array( $this, 'capture_enqueued_scripts' ) ) ) {
			add_action( 'wp_print_scripts', array( $this, 'capture_enqueued_scripts' ), 9999 );
		}

		$cached = get_transient( self::TRANSIENT_SCRIPTS );

		return is_array( $cached ) ? $cached : array();
	}

	/**
	 * 4. Scan the options table for tracking evidence.
	 *
	 * Two passes:
	 *  a) Check for known option_keys from the JSON database.
	 *  b) Search for tracking-ID patterns (UA-, G-, GTM-, AW-, DC-,
	 *     FB pixel IDs) in options whose names match active plugin
	 *     slugs.  Actual IDs are never stored.
	 *
	 * @return array[] List of tracking detections.
	 */
	private function scan_options_table(): array {
		global $wpdb;

		$db          = $this->get_known_plugins();
		$option_keys = isset( $db['option_keys'] ) ? $db['option_keys'] : array();
		$results     = array();

		// ── a) Known option keys ────────────────────────────
		if ( ! empty( $option_keys ) ) {
			$placeholders = array();
			$values       = array();

			foreach ( array_keys( $option_keys ) as $key ) {
				$placeholders[] = '%s';
				$values[]       = $key;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name IN (" . implode( ',', $placeholders ) . ')',
					...$values
				)
			);

			if ( $rows ) {
				foreach ( $rows as $row ) {
					$key  = $row->option_name;
					$meta = $option_keys[ $key ];

					$results[] = array(
						'option_key' => sanitize_text_field( $key ),
						'service'    => isset( $meta['service'] ) ? sanitize_text_field( $meta['service'] ) : '',
						'category'   => isset( $meta['category'] ) ? sanitize_text_field( $meta['category'] ) : '',
						'source'     => 'known_option_key',
					);
				}
			}
		}

		// ── b) Tracking-ID patterns in plugin-related options ──
		$active_plugins = get_option( 'active_plugins', array() );

		foreach ( $active_plugins as $plugin_file ) {
			if ( $this->should_skip_plugin( $plugin_file ) ) {
				continue;
			}

			$slug = dirname( $plugin_file );

			if ( '.' === $slug || '' === $slug ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$options = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 50",
					'%' . $wpdb->esc_like( $slug ) . '%'
				)
			);

			if ( ! $options ) {
				continue;
			}

			foreach ( $options as $option ) {
				$value = maybe_unserialize( $option->option_value );
				$ids   = $this->find_tracking_ids_in_value( $value );

				foreach ( $ids as $id_info ) {
					$results[] = array(
						'option_key'  => sanitize_text_field( $option->option_name ),
						'service'     => sanitize_text_field( $id_info['service'] ),
						'category'    => 'analytics',
						'pattern'     => sanitize_text_field( $id_info['pattern'] ),
						'plugin_slug' => sanitize_text_field( $slug ),
						'source'      => 'tracking_id_pattern',
					);
				}
			}
		}

		return $results;
	}

	/**
	 * 5. Scan active theme for tracking domains and hardcoded IDs.
	 *
	 * Checks header.php, footer.php, and functions.php for both the
	 * child theme and, if applicable, the parent theme.
	 *
	 * @return array[] List of theme tracking detections.
	 */
	private function scan_theme(): array {
		$results = array();
		$db      = $this->get_known_plugins();

		$tracking_domains = isset( $db['tracking_domains'] ) ? $db['tracking_domains'] : array();
		$target_files     = array( 'header.php', 'footer.php', 'functions.php' );

		// Determine theme directories to scan.
		$theme_dirs = array();
		$stylesheet = get_stylesheet_directory();
		$template   = get_template_directory();

		$theme_dirs[] = array(
			'path'  => $stylesheet,
			'label' => wp_get_theme()->get( 'Name' ),
			'type'  => 'child',
		);

		// If child theme is active, also scan the parent.
		if ( $stylesheet !== $template ) {
			$parent_theme = wp_get_theme( get_template() );
			$theme_dirs[] = array(
				'path'  => $template,
				'label' => $parent_theme->get( 'Name' ),
				'type'  => 'parent',
			);
		}

		foreach ( $theme_dirs as $theme ) {
			foreach ( $target_files as $filename ) {
				if ( $this->is_time_exceeded() ) {
					$this->partial_scan = true;
					return $results;
				}

				$filepath = $theme['path'] . '/' . $filename;

				if ( ! file_exists( $filepath ) || ! is_readable( $filepath ) ) {
					continue;
				}

				$size = filesize( $filepath );

				if ( false === $size || $size > self::MAX_FILE_SIZE ) {
					continue;
				}

				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$contents = file_get_contents( $filepath );

				if ( false === $contents ) {
					continue;
				}

				// Check for tracking domains.
				foreach ( $tracking_domains as $domain ) {
					if ( false !== strpos( $contents, $domain ) ) {
						$results[] = array(
							'theme'      => sanitize_text_field( $theme['label'] ),
							'theme_type' => sanitize_key( $theme['type'] ),
							'file'       => sanitize_file_name( $filename ),
							'match'      => sanitize_text_field( $domain ),
							'match_type' => 'tracking_domain',
						);
					}
				}

				// Check for hardcoded tracking IDs (line-by-line).
				$lines = explode( "\n", $contents );

				foreach ( $lines as $line_num => $line ) {
					foreach ( $this->tracking_id_patterns as $prefix => $service ) {
						// Match prefix followed by alphanumeric ID.
						$pattern = '/' . preg_quote( $prefix, '/' ) . '[A-Z0-9]{4,15}/i';

						if ( preg_match( $pattern, $line ) ) {
							$results[] = array(
								'theme'      => sanitize_text_field( $theme['label'] ),
								'theme_type' => sanitize_key( $theme['type'] ),
								'file'       => sanitize_file_name( $filename ),
								'line'       => $line_num + 1,
								'match'      => sanitize_text_field( $prefix . '***' ),
								'service'    => sanitize_text_field( $service ),
								'match_type' => 'tracking_id',
							);
							break; // One match per prefix per line.
						}
					}
				}
			}
		}

		return $results;
	}

	// ══════════════════════════════════════════════
	//  HELPERS
	// ══════════════════════════════════════════════

	/**
	 * Check if a plugin should be skipped during the audit.
	 *
	 * Skips consent-management plugins that reference tracking
	 * domains but are not trackers, and also skips ourselves.
	 *
	 * @param string $plugin_file Plugin file path (e.g. "slug/file.php").
	 * @return bool True if plugin should be skipped.
	 */
	private function should_skip_plugin( string $plugin_file ): bool {
		$slug = dirname( $plugin_file );

		if ( in_array( $slug, $this->skip_slugs, true ) ) {
			return true;
		}

		if ( defined( 'CONSENTLY_PLUGIN_BASENAME' ) && $plugin_file === CONSENTLY_PLUGIN_BASENAME ) {
			return true;
		}

		return false;
	}

	/**
	 * Load and cache known-plugins.json.
	 *
	 * @return array Decoded JSON data with keys: plugins,
	 *               tracking_domains, option_keys,
	 *               wordpress_core_cookies, cookie_heuristics.
	 */
	private function get_known_plugins(): array {
		if ( null !== $this->known_plugins_data ) {
			return $this->known_plugins_data;
		}

		$defaults = array(
			'plugins'                => array(),
			'tracking_domains'       => array(),
			'option_keys'            => array(),
			'wordpress_core_cookies' => array(),
			'cookie_heuristics'      => array(),
		);

		$json_file = CONSENTLY_PLUGIN_DIR . 'data/known-plugins.json';

		if ( ! file_exists( $json_file ) || ! is_readable( $json_file ) ) {
			$this->known_plugins_data = $defaults;
			return $this->known_plugins_data;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = file_get_contents( $json_file );

		if ( false === $contents ) {
			$this->known_plugins_data = $defaults;
			return $this->known_plugins_data;
		}

		$data = json_decode( $contents, true );

		if ( ! is_array( $data ) ) {
			$this->known_plugins_data = $defaults;
			return $this->known_plugins_data;
		}

		$this->known_plugins_data = array_merge( $defaults, $data );

		return $this->known_plugins_data;
	}

	/**
	 * Recursively collect PHP files from a directory.
	 *
	 * Skips vendor, node_modules, assets, build, tests, and
	 * languages directories.
	 *
	 * @param string $dir Directory path.
	 * @param int    $max Maximum number of files to return.
	 * @return string[] Absolute file paths.
	 */
	private function get_php_files( string $dir, int $max ): array {
		$files = array();

		if ( ! is_dir( $dir ) || ! is_readable( $dir ) ) {
			return $files;
		}

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveCallbackFilterIterator(
					new RecursiveDirectoryIterator(
						$dir,
						RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS
					),
					function ( $current, $key, $iterator ) {
						// If directory, skip blacklisted names.
						if ( $current->isDir() ) {
							return ! in_array( $current->getFilename(), self::SKIP_DIRS, true );
						}
						return true;
					}
				),
				RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $iterator as $file ) {
				if ( count( $files ) >= $max ) {
					$this->partial_scan = true;
					break;
				}

				if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
					$files[] = $file->getPathname();
				}
			}
		} catch ( \UnexpectedValueException $e ) {
			// Inaccessible directory; silently continue.
			return $files;
		}

		return $files;
	}

	/**
	 * Detect a cookie-setting call in a single line of PHP.
	 *
	 * @param string $line Source line.
	 * @return array|null {cookie_name, method} or null.
	 */
	private function detect_cookie_call( string $line ): ?array {
		$line_trimmed = trim( $line );

		// Ignore commented-out lines.
		if ( 0 === strpos( $line_trimmed, '//' ) || 0 === strpos( $line_trimmed, '#' ) || 0 === strpos( $line_trimmed, '*' ) ) {
			return null;
		}

		// setcookie( 'name', ... ) or setcookie( "name", ... )
		if ( preg_match( '/\bsetcookie\s*\(\s*[\'"]([^\'"]+)[\'"]/i', $line, $m ) ) {
			return array(
				'cookie_name' => $m[1],
				'method'      => 'setcookie',
			);
		}

		// setrawcookie( 'name', ... )
		if ( preg_match( '/\bsetrawcookie\s*\(\s*[\'"]([^\'"]+)[\'"]/i', $line, $m ) ) {
			return array(
				'cookie_name' => $m[1],
				'method'      => 'setrawcookie',
			);
		}

		// $_COOKIE['name'] = ...  (assignment, not just read)
		if ( preg_match( '/\$_COOKIE\s*\[\s*[\'"]([^\'"]+)[\'"]\s*\]\s*=/', $line, $m ) ) {
			return array(
				'cookie_name' => $m[1],
				'method'      => '$_COOKIE',
			);
		}

		// header( 'Set-Cookie: name=...' )  or  header( "Set-Cookie: name=..." )
		if ( preg_match( '/\bheader\s*\(\s*[\'"]Set-Cookie:\s*([^=\'"]+)=/i', $line, $m ) ) {
			return array(
				'cookie_name' => trim( $m[1] ),
				'method'      => 'header',
			);
		}

		return null;
	}

	/**
	 * Search a value (string or nested array) for tracking-ID patterns.
	 *
	 * Actual IDs are never stored; the pattern prefix with `***` is
	 * recorded instead.
	 *
	 * @param mixed $value Option value (string, array, or other).
	 * @return array[] List of { pattern, service } pairs found.
	 */
	private function find_tracking_ids_in_value( $value ): array {
		$found = array();

		if ( is_string( $value ) ) {
			foreach ( $this->tracking_id_patterns as $prefix => $service ) {
				if ( false !== strpos( $value, $prefix ) ) {
					$key = $prefix . '***';
					if ( ! isset( $found[ $key ] ) ) {
						$found[ $key ] = array(
							'pattern' => $key,
							'service' => $service,
						);
					}
				}
			}
		} elseif ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				$sub = $this->find_tracking_ids_in_value( $item );
				foreach ( $sub as $k => $v ) {
					if ( ! isset( $found[ $k ] ) ) {
						$found[ $k ] = $v;
					}
				}
			}
		}

		return array_values( $found );
	}

	/**
	 * Check if the scan time limit has been exceeded.
	 *
	 * @return bool True if time exceeded.
	 */
	private function is_time_exceeded(): bool {
		if ( 0.0 === $this->scan_start_time ) {
			return false;
		}

		return ( microtime( true ) - $this->scan_start_time ) > self::MAX_SCAN_TIME;
	}

	/**
	 * Compute an MD5 hash of the active_plugins option.
	 *
	 * Used to detect when the plugin list changes so cached audit
	 * results can be invalidated.
	 *
	 * @return string 32-character hex hash.
	 */
	private function compute_plugin_hash(): string {
		$active_plugins = get_option( 'active_plugins', array() );
		sort( $active_plugins );

		return md5( wp_json_encode( $active_plugins ) );
	}

	/**
	 * Helper: check if a string ends with a given suffix.
	 *
	 * Compatible with PHP 7.4 (str_ends_with requires 8.0).
	 *
	 * @param string $haystack String to search.
	 * @param string $needle   Suffix to look for.
	 * @return bool True if $haystack ends with $needle.
	 */
	private function string_ends_with( string $haystack, string $needle ): bool {
		if ( '' === $needle ) {
			return true;
		}

		$length = strlen( $needle );

		return substr( $haystack, -$length ) === $needle;
	}
}
