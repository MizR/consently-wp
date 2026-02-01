<?php
/**
 * Audit class for Consently plugin.
 *
 * Handles local plugin analysis for tracking detection.
 *
 * @package Consently
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin audit class.
 */
class Consently_Audit {

	/**
	 * Maximum files to scan per plugin.
	 *
	 * @var int
	 */
	private $max_files_per_plugin = 100;

	/**
	 * Maximum total scan time in seconds.
	 *
	 * @var int
	 */
	private $max_scan_time = 30;

	/**
	 * Scan start time.
	 *
	 * @var float
	 */
	private $scan_start_time;

	/**
	 * Whether scan was partial.
	 *
	 * @var bool
	 */
	private $partial_scan = false;

	/**
	 * Known plugins data.
	 *
	 * @var array|null
	 */
	private $known_plugins_data = null;

	/**
	 * Run the plugin audit.
	 *
	 * @return array Audit results.
	 */
	public function run_audit() {
		$this->scan_start_time = microtime( true );
		$this->partial_scan    = false;

		$results = array(
			'tracking_plugins' => array(),
			'clean_plugins'    => array(),
			'partial_scan'     => false,
			'scan_time'        => 0,
		);

		// Get active plugins only.
		$active_plugins = get_option( 'active_plugins', array() );

		// Get known plugins database.
		$known_plugins = $this->get_known_plugins();

		foreach ( $active_plugins as $plugin_file ) {
			// Check time limit.
			if ( $this->is_time_exceeded() ) {
				$this->partial_scan = true;
				break;
			}

			$plugin_data = $this->analyze_plugin( $plugin_file, $known_plugins );

			if ( $plugin_data['tracking'] ) {
				$results['tracking_plugins'][] = $plugin_data;
			} else {
				$results['clean_plugins'][] = $plugin_data;
			}
		}

		$results['partial_scan'] = $this->partial_scan;
		$results['scan_time']    = round( microtime( true ) - $this->scan_start_time, 2 );

		return $results;
	}

	/**
	 * Analyze a single plugin.
	 *
	 * @param string $plugin_file Plugin file path.
	 * @param array  $known_plugins Known plugins data.
	 * @return array Plugin analysis data.
	 */
	private function analyze_plugin( $plugin_file, $known_plugins ) {
		$plugin_path = WP_PLUGIN_DIR . '/' . dirname( $plugin_file );
		$plugin_info = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file );

		$result = array(
			'file'      => $plugin_file,
			'name'      => $plugin_info['Name'],
			'tracking'  => false,
			'category'  => '',
			'domains'   => array(),
			'source'    => '',
		);

		// Check known plugins database first.
		if ( isset( $known_plugins['plugins'][ $plugin_file ] ) ) {
			$known = $known_plugins['plugins'][ $plugin_file ];
			$result['tracking'] = $known['tracking'];
			$result['category'] = $known['category'];
			$result['domains']  = $known['domains'];
			$result['source']   = 'known_database';
			return $result;
		}

		// Check via file pattern scan.
		$file_scan_result = $this->scan_plugin_files( $plugin_path, $known_plugins );
		if ( ! empty( $file_scan_result['domains'] ) ) {
			$result['tracking'] = true;
			$result['domains']  = $file_scan_result['domains'];
			$result['category'] = $this->categorize_domains( $file_scan_result['domains'] );
			$result['source']   = 'file_scan';
			return $result;
		}

		// Check options table for tracking IDs.
		$options_result = $this->check_options_for_tracking( $plugin_file );
		if ( ! empty( $options_result['tracking_ids'] ) ) {
			$result['tracking'] = true;
			$result['domains']  = $options_result['tracking_ids'];
			$result['category'] = 'analytics';
			$result['source']   = 'options_scan';
			return $result;
		}

		return $result;
	}

	/**
	 * Scan plugin files for tracking domains.
	 *
	 * @param string $plugin_path Plugin directory path.
	 * @param array  $known_plugins Known plugins data.
	 * @return array Scan result with detected domains.
	 */
	private function scan_plugin_files( $plugin_path, $known_plugins ) {
		$result = array(
			'domains' => array(),
		);

		if ( ! is_dir( $plugin_path ) ) {
			return $result;
		}

		$tracking_domains = isset( $known_plugins['tracking_domains'] ) ? $known_plugins['tracking_domains'] : array();

		if ( empty( $tracking_domains ) ) {
			return $result;
		}

		// Get PHP files.
		$files = $this->get_php_files( $plugin_path, $this->max_files_per_plugin );

		foreach ( $files as $file ) {
			// Check time limit.
			if ( $this->is_time_exceeded() ) {
				$this->partial_scan = true;
				break;
			}

			$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			if ( false === $contents ) {
				continue;
			}

			foreach ( $tracking_domains as $domain ) {
				if ( false !== strpos( $contents, $domain ) ) {
					if ( ! in_array( $domain, $result['domains'], true ) ) {
						$result['domains'][] = $domain;
					}
				}
			}
		}

		return $result;
	}

	/**
	 * Get PHP files from directory.
	 *
	 * @param string $dir Directory path.
	 * @param int    $max Maximum files to return.
	 * @return array Array of file paths.
	 */
	private function get_php_files( $dir, $max ) {
		$files = array();

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $file ) {
			if ( count( $files ) >= $max ) {
				break;
			}

			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}

		return $files;
	}

	/**
	 * Check options table for tracking IDs.
	 *
	 * @param string $plugin_file Plugin file.
	 * @return array Result with tracking IDs.
	 */
	private function check_options_for_tracking( $plugin_file ) {
		global $wpdb;

		$result = array(
			'tracking_ids' => array(),
		);

		$plugin_slug = dirname( $plugin_file );

		// Common tracking ID patterns.
		$patterns = array(
			'UA-',      // Universal Analytics.
			'G-',       // GA4.
			'GTM-',     // Google Tag Manager.
			'AW-',      // Google Ads.
			'DC-',      // DoubleClick.
		);

		// Search options that might be related to this plugin.
		$options = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options}
				WHERE option_name LIKE %s
				LIMIT 50",
				'%' . $wpdb->esc_like( $plugin_slug ) . '%'
			)
		);

		foreach ( $options as $option ) {
			$value = maybe_unserialize( $option->option_value );

			if ( is_string( $value ) ) {
				foreach ( $patterns as $pattern ) {
					if ( false !== strpos( $value, $pattern ) ) {
						$result['tracking_ids'][] = $pattern . '***';
						break;
					}
				}
			} elseif ( is_array( $value ) ) {
				$this->search_array_for_tracking_ids( $value, $patterns, $result['tracking_ids'] );
			}
		}

		return $result;
	}

	/**
	 * Recursively search array for tracking IDs.
	 *
	 * @param array $array Array to search.
	 * @param array $patterns Patterns to match.
	 * @param array $found Found tracking IDs (by reference).
	 */
	private function search_array_for_tracking_ids( $array, $patterns, &$found ) {
		foreach ( $array as $value ) {
			if ( is_string( $value ) ) {
				foreach ( $patterns as $pattern ) {
					if ( false !== strpos( $value, $pattern ) && ! in_array( $pattern . '***', $found, true ) ) {
						$found[] = $pattern . '***';
					}
				}
			} elseif ( is_array( $value ) ) {
				$this->search_array_for_tracking_ids( $value, $patterns, $found );
			}
		}
	}

	/**
	 * Categorize domains.
	 *
	 * @param array $domains Detected domains.
	 * @return string Category.
	 */
	private function categorize_domains( $domains ) {
		$analytics_domains = array(
			'google-analytics.com',
			'googletagmanager.com',
			'analytics.google.com',
			'hotjar.com',
			'clarity.ms',
			'plausible.io',
			'matomo.cloud',
		);

		$marketing_domains = array(
			'facebook.net',
			'connect.facebook.net',
			'facebook.com',
			'doubleclick.net',
			'googlesyndication.com',
			'googleadservices.com',
			'twitter.com',
			'ads-twitter.com',
			'linkedin.com',
			'snap.licdn.com',
			'tiktok.com',
			'pinterest.com',
		);

		foreach ( $domains as $domain ) {
			if ( in_array( $domain, $marketing_domains, true ) ) {
				return 'marketing';
			}
		}

		foreach ( $domains as $domain ) {
			if ( in_array( $domain, $analytics_domains, true ) ) {
				return 'analytics';
			}
		}

		return 'other';
	}

	/**
	 * Check if scan time exceeded.
	 *
	 * @return bool True if time exceeded.
	 */
	private function is_time_exceeded() {
		return ( microtime( true ) - $this->scan_start_time ) > $this->max_scan_time;
	}

	/**
	 * Get known plugins database.
	 *
	 * @return array Known plugins data.
	 */
	private function get_known_plugins() {
		if ( null !== $this->known_plugins_data ) {
			return $this->known_plugins_data;
		}

		$json_file = CONSENTLY_PLUGIN_DIR . 'data/known-plugins.json';

		if ( ! file_exists( $json_file ) ) {
			$this->known_plugins_data = array(
				'plugins'          => array(),
				'tracking_domains' => array(),
			);
			return $this->known_plugins_data;
		}

		$contents = file_get_contents( $json_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $contents ) {
			$this->known_plugins_data = array(
				'plugins'          => array(),
				'tracking_domains' => array(),
			);
			return $this->known_plugins_data;
		}

		$data = json_decode( $contents, true );

		if ( null === $data ) {
			$this->known_plugins_data = array(
				'plugins'          => array(),
				'tracking_domains' => array(),
			);
			return $this->known_plugins_data;
		}

		$this->known_plugins_data = $data;
		return $this->known_plugins_data;
	}

	/**
	 * Get cached audit results.
	 *
	 * @return array|false Cached results or false if not cached.
	 */
	public function get_cached_results() {
		return get_transient( 'consently_audit_results' );
	}
}
