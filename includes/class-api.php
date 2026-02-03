<?php
/**
 * API class for Consently plugin.
 *
 * Handles communication with the Consently API.
 *
 * @package Consently
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * API communication class.
 */
class Consently_API {

	/**
	 * API base URL.
	 *
	 * @var string
	 */
	private $api_url;

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	private $timeout = 30;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->api_url = CONSENTLY_API_URL;
	}

	/**
	 * Connect to Consently API.
	 *
	 * @param string $api_key API key from user.
	 * @return array|WP_Error Response data or error.
	 */
	public function connect( $api_key ) {
		$core = Consently_Core::get_instance();

		$body = array(
			'site_url'  => $core->get_normalized_home_url(),
			'site_name' => get_bloginfo( 'name' ),
		);

		$response = $this->request(
			'/connect',
			array(
				'method'  => 'POST',
				'body'    => wp_json_encode( $body ),
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Check response status.
		if ( ! isset( $response['status'] ) ) {
			return new WP_Error(
				'invalid_response',
				__( 'Invalid response from Consently API.', 'consently' )
			);
		}

		// Handle different status responses.
		switch ( $response['status'] ) {
			case 'ok':
				// Store encrypted API key for future use.
				$this->store_api_key( $api_key );
				return $response;

			case 'invalid_key':
				return new WP_Error(
					'invalid_key',
					__( 'Invalid API key. Please check your key and try again.', 'consently' )
				);

			case 'domain_mismatch':
				return new WP_Error(
					'domain_mismatch',
					__( 'Domain mismatch. The site URL does not match the registered domain in your Consently account.', 'consently' )
				);

			case 'plan_limit':
				return new WP_Error(
					'plan_limit',
					__( 'Your Consently account has reached its site limit. Please upgrade your plan to add more sites.', 'consently' )
				);

			default:
				return new WP_Error(
					'unknown_status',
					/* translators: %s: Status code from API */
					sprintf( __( 'Unexpected status from API: %s', 'consently' ), $response['status'] )
				);
		}
	}

	/**
	 * Check account status.
	 *
	 * @return array|WP_Error Response data or error.
	 */
	public function check_status() {
		$api_key = $this->get_api_key();

		if ( ! $api_key ) {
			return new WP_Error(
				'no_api_key',
				__( 'No API key stored. Please reconnect.', 'consently' )
			);
		}

		$response = $this->request(
			'/account/status',
			array(
				'method'  => 'GET',
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
				),
			)
		);

		return $response;
	}

	/**
	 * Make an API request.
	 *
	 * @param string $endpoint API endpoint.
	 * @param array  $args     Request arguments.
	 * @return array|WP_Error Response data or error.
	 */
	private function request( $endpoint, $args = array() ) {
		$url = $this->api_url . $endpoint;

		$default_args = array(
			'timeout'   => $this->timeout,
			'sslverify' => true,
			'headers'   => array(
				'Accept'     => 'application/json',
				'User-Agent' => 'Consently-WordPress/' . CONSENTLY_VERSION,
			),
		);

		$args = wp_parse_args( $args, $default_args );

		// Merge headers.
		if ( isset( $args['headers'] ) ) {
			$args['headers'] = array_merge( $default_args['headers'], $args['headers'] );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'api_error',
				/* translators: %s: Error message */
				sprintf( __( 'API request failed: %s', 'consently' ), $response->get_error_message() )
			);
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		// Handle HTTP errors.
		if ( 401 === $response_code ) {
			return new WP_Error(
				'unauthorized',
				__( 'Connection expired or API key revoked. Please reconnect.', 'consently' )
			);
		}

		if ( $response_code >= 500 ) {
			return new WP_Error(
				'server_error',
				__( 'Consently service is temporarily unavailable. Please try again later.', 'consently' )
			);
		}

		if ( $response_code >= 400 ) {
			return new WP_Error(
				'api_error',
				/* translators: %d: HTTP response code */
				sprintf( __( 'API request failed with status: %d', 'consently' ), $response_code )
			);
		}

		// Parse JSON response.
		$data = json_decode( $response_body, true );

		if ( null === $data && '' !== $response_body ) {
			return new WP_Error(
				'invalid_json',
				__( 'Invalid JSON response from API.', 'consently' )
			);
		}

		return $data;
	}

	/**
	 * Store API key with encryption.
	 *
	 * @param string $api_key API key to store.
	 * @return bool True on success.
	 */
	private function store_api_key( $api_key ) {
		// Try to use libsodium if available (PHP 7.2+).
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			return $this->store_api_key_sodium( $api_key );
		}

		// Fallback to OpenSSL AES-256-GCM.
		if ( function_exists( 'openssl_encrypt' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) {
			return $this->store_api_key_openssl( $api_key );
		}

		// Last resort: use WordPress auth keys for basic obfuscation.
		// Not ideal but better than plaintext.
		return $this->store_api_key_basic( $api_key );
	}

	/**
	 * Store API key using libsodium.
	 *
	 * @param string $api_key API key to store.
	 * @return bool True on success.
	 */
	private function store_api_key_sodium( $api_key ) {
		// Generate or retrieve encryption key.
		$key = $this->get_or_create_encryption_key( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );

		// Generate nonce.
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		// Encrypt.
		$ciphertext = sodium_crypto_secretbox( $api_key, $nonce, $key );

		// Store as base64 encoded string with nonce prepended.
		$encrypted = base64_encode( $nonce . $ciphertext );

		update_option( 'consently_api_key_encrypted', $encrypted, false );
		update_option( 'consently_encryption_method', 'sodium', false );

		// Clear memory.
		sodium_memzero( $api_key );
		sodium_memzero( $key );

		return true;
	}

	/**
	 * Store API key using OpenSSL AES-256-GCM.
	 *
	 * @param string $api_key API key to store.
	 * @return bool True on success.
	 */
	private function store_api_key_openssl( $api_key ) {
		// Generate or retrieve encryption key.
		$key = $this->get_or_create_encryption_key( 32 ); // 256 bits.

		// Generate IV.
		$iv = openssl_random_pseudo_bytes( 12 ); // 96 bits for GCM.

		// Encrypt.
		$tag        = '';
		$ciphertext = openssl_encrypt( $api_key, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );

		if ( false === $ciphertext ) {
			return false;
		}

		// Store as base64 encoded string: iv + tag + ciphertext.
		$encrypted = base64_encode( $iv . $tag . $ciphertext );

		update_option( 'consently_api_key_encrypted', $encrypted, false );
		update_option( 'consently_encryption_method', 'openssl', false );

		return true;
	}

	/**
	 * Store API key with basic obfuscation.
	 *
	 * @param string $api_key API key to store.
	 * @return bool True on success.
	 */
	private function store_api_key_basic( $api_key ) {
		// Use WordPress auth key for XOR obfuscation.
		$auth_key = defined( 'AUTH_KEY' ) && AUTH_KEY ? AUTH_KEY : 'consently-default-key';
		$key      = hash( 'sha256', $auth_key, true );

		// Simple XOR.
		$encrypted = '';
		for ( $i = 0; $i < strlen( $api_key ); $i++ ) {
			$encrypted .= $api_key[ $i ] ^ $key[ $i % strlen( $key ) ];
		}

		update_option( 'consently_api_key_encrypted', base64_encode( $encrypted ), false );
		update_option( 'consently_encryption_method', 'basic', false );

		return true;
	}

	/**
	 * Get stored API key.
	 *
	 * @return string|false API key or false if not found.
	 */
	public function get_api_key() {
		$encrypted = get_option( 'consently_api_key_encrypted' );
		$method    = get_option( 'consently_encryption_method' );

		if ( ! $encrypted ) {
			return false;
		}

		switch ( $method ) {
			case 'sodium':
				return $this->decrypt_api_key_sodium( $encrypted );
			case 'openssl':
				return $this->decrypt_api_key_openssl( $encrypted );
			case 'basic':
				return $this->decrypt_api_key_basic( $encrypted );
			default:
				return false;
		}
	}

	/**
	 * Decrypt API key using libsodium.
	 *
	 * @param string $encrypted Encrypted data.
	 * @return string|false Decrypted API key or false on failure.
	 */
	private function decrypt_api_key_sodium( $encrypted ) {
		$key = $this->get_or_create_encryption_key( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );

		$decoded = base64_decode( $encrypted );
		if ( false === $decoded || strlen( $decoded ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return false;
		}

		$nonce      = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		$plaintext = sodium_crypto_secretbox_open( $ciphertext, $nonce, $key );

		// Clear memory.
		sodium_memzero( $key );

		return false !== $plaintext ? $plaintext : false;
	}

	/**
	 * Decrypt API key using OpenSSL.
	 *
	 * @param string $encrypted Encrypted data.
	 * @return string|false Decrypted API key or false on failure.
	 */
	private function decrypt_api_key_openssl( $encrypted ) {
		$key = $this->get_or_create_encryption_key( 32 );

		$decoded = base64_decode( $encrypted );
		if ( false === $decoded || strlen( $decoded ) < 28 ) { // 12 (iv) + 16 (tag) minimum.
			return false;
		}

		$iv         = substr( $decoded, 0, 12 );
		$tag        = substr( $decoded, 12, 16 );
		$ciphertext = substr( $decoded, 28 );

		$plaintext = openssl_decrypt( $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );

		return false !== $plaintext ? $plaintext : false;
	}

	/**
	 * Decrypt API key with basic obfuscation.
	 *
	 * @param string $encrypted Encrypted data.
	 * @return string|false Decrypted API key or false on failure.
	 */
	private function decrypt_api_key_basic( $encrypted ) {
		$auth_key = defined( 'AUTH_KEY' ) && AUTH_KEY ? AUTH_KEY : 'consently-default-key';
		$key      = hash( 'sha256', $auth_key, true );

		$decoded = base64_decode( $encrypted );
		if ( false === $decoded ) {
			return false;
		}

		// Simple XOR (same operation decrypts).
		$decrypted = '';
		for ( $i = 0; $i < strlen( $decoded ); $i++ ) {
			$decrypted .= $decoded[ $i ] ^ $key[ $i % strlen( $key ) ];
		}

		return $decrypted;
	}

	/**
	 * Get or create encryption key.
	 *
	 * @param int $length Key length in bytes.
	 * @return string Encryption key.
	 */
	private function get_or_create_encryption_key( $length ) {
		$stored_key = get_option( 'consently_encryption_key' );

		if ( $stored_key ) {
			$key = base64_decode( $stored_key );
			if ( strlen( $key ) === $length ) {
				return $key;
			}
		}

		// Generate new key.
		$key = random_bytes( $length );
		update_option( 'consently_encryption_key', base64_encode( $key ), false );

		return $key;
	}
}
