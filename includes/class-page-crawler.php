<?php
/**
 * Multi-page list builder for Plugin Audit v2 live scanning.
 *
 * Constructs a prioritised list of representative pages across the site
 * so the audit scanner can detect scripts loaded in different contexts.
 *
 * @package Consently
 * @since   0.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds a capped list of pages for the live audit scanner to visit.
 */
class Consently_Page_Crawler {

	/**
	 * Maximum number of pages returned by the crawler.
	 *
	 * @var int
	 */
	const MAX_PAGES = 20;

	/**
	 * Post types that should never appear in the scan list.
	 *
	 * @var string[]
	 */
	const EXCLUDED_POST_TYPES = array(
		'attachment',
		'revision',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'wp_template',
		'wp_template_part',
		'wp_navigation',
		'wp_block',
		'wp_global_styles',
	);

	/**
	 * WooCommerce page slugs to look up.
	 *
	 * @var string[]
	 */
	const WOO_PAGES = array(
		'shop',
		'cart',
		'checkout',
		'myaccount',
	);

	/**
	 * Build the full page list for the audit scanner.
	 *
	 * The list always contains the homepage and the login page, plus one
	 * recent published post from every public post type and any published
	 * WooCommerce special pages.  The result is capped at {@see MAX_PAGES}.
	 *
	 * @return array<int, array{id: string|int, url: string, label: string}>
	 */
	public function build_page_list(): array {
		$homepage        = $this->get_homepage();
		$post_type_pages = $this->get_post_type_pages();
		$woo_pages       = $this->get_woocommerce_pages( array_merge( array( $homepage ), $post_type_pages ) );
		$login_page      = $this->get_login_page();

		$pages = array_merge(
			array( $homepage ),
			$post_type_pages,
			$woo_pages,
			array( $login_page )
		);

		if ( count( $pages ) > self::MAX_PAGES ) {
			$pages = $this->prioritise( $homepage, $post_type_pages, $woo_pages, $login_page );
		}

		return $pages;
	}

	/**
	 * Return the homepage entry.
	 *
	 * @return array{id: string, url: string, label: string}
	 */
	private function get_homepage(): array {
		return array(
			'id'    => 'home',
			'url'   => home_url( '/' ),
			'label' => 'Homepage',
		);
	}

	/**
	 * Return the login page entry.
	 *
	 * @return array{id: string, url: string, label: string}
	 */
	private function get_login_page(): array {
		return array(
			'id'    => 'login',
			'url'   => wp_login_url(),
			'label' => 'Login Page',
		);
	}

	/**
	 * Collect one recent published post from each public post type.
	 *
	 * @return array<int, array{id: int, url: string, label: string}>
	 */
	private function get_post_type_pages(): array {
		$pages = array();

		$post_types = get_post_types( array( 'public' => true ), 'objects' );

		foreach ( $post_types as $post_type ) {
			if ( in_array( $post_type->name, self::EXCLUDED_POST_TYPES, true ) ) {
				continue;
			}

			$posts = get_posts(
				array(
					'posts_per_page' => 1,
					'post_type'      => $post_type->name,
					'post_status'    => 'publish',
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);

			if ( empty( $posts ) ) {
				continue;
			}

			$post    = $posts[0];
			$pages[] = array(
				'id'    => $post->ID,
				'url'   => get_permalink( $post ),
				'label' => $post_type->labels->singular_name . ': ' . $post->post_title,
			);
		}

		return $pages;
	}

	/**
	 * Collect published WooCommerce special pages, avoiding duplicates.
	 *
	 * @param array<int, array{id: string|int, url: string, label: string}> $existing Pages already in the list.
	 * @return array<int, array{id: int, url: string, label: string}>
	 */
	private function get_woocommerce_pages( array $existing ): array {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return array();
		}

		$existing_ids = array();
		foreach ( $existing as $entry ) {
			if ( is_int( $entry['id'] ) ) {
				$existing_ids[] = $entry['id'];
			}
		}

		$pages = array();

		foreach ( self::WOO_PAGES as $woo_page ) {
			$page_id = wc_get_page_id( $woo_page );

			if ( $page_id <= 0 ) {
				continue;
			}

			if ( in_array( $page_id, $existing_ids, true ) ) {
				continue;
			}

			$post = get_post( $page_id );

			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}

			$existing_ids[] = $page_id;

			$pages[] = array(
				'id'    => $page_id,
				'url'   => get_permalink( $post ),
				'label' => 'WooCommerce: ' . ucfirst( $woo_page ),
			);
		}

		return $pages;
	}

	/**
	 * Prioritise pages when the total exceeds the cap.
	 *
	 * Priority order: homepage, WooCommerce pages, login page, then post
	 * type pages to fill the remaining slots.
	 *
	 * @param array{id: string, url: string, label: string}                 $homepage        The homepage entry.
	 * @param array<int, array{id: int, url: string, label: string}>        $post_type_pages Post type entries.
	 * @param array<int, array{id: int, url: string, label: string}>        $woo_pages       WooCommerce entries.
	 * @param array{id: string, url: string, label: string}                 $login_page      The login page entry.
	 * @return array<int, array{id: string|int, url: string, label: string}>
	 */
	private function prioritise( array $homepage, array $post_type_pages, array $woo_pages, array $login_page ): array {
		$pages = array( $homepage );
		$pages = array_merge( $pages, $woo_pages );
		$pages[] = $login_page;

		$remaining = self::MAX_PAGES - count( $pages );

		if ( $remaining > 0 ) {
			$pages = array_merge( $pages, array_slice( $post_type_pages, 0, $remaining ) );
		}

		return $pages;
	}
}
