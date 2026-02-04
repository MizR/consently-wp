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
	const MAX_PAGES = 30;

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
	 * The list always contains the homepage and the login page, plus posts
	 * from every public post type (one per unique page template for pages),
	 * archive pages, shortcode pages, and any published WooCommerce special
	 * pages.  The result is capped at {@see MAX_PAGES}.
	 *
	 * @return array<int, array{id: string|int, url: string, label: string}>
	 */
	public function build_page_list(): array {
		$homepage        = $this->get_homepage();
		$post_type_pages = $this->get_post_type_pages();
		$template_pages  = $this->get_template_pages( array_merge( array( $homepage ), $post_type_pages ) );
		$shortcode_pages = $this->get_shortcode_pages( array_merge( array( $homepage ), $post_type_pages, $template_pages ) );
		$archive_pages   = $this->get_archive_pages();
		$woo_pages       = $this->get_woocommerce_pages( array_merge( array( $homepage ), $post_type_pages, $template_pages, $shortcode_pages ) );
		$login_page      = $this->get_login_page();

		$pages = array_merge(
			array( $homepage ),
			$post_type_pages,
			$template_pages,
			$shortcode_pages,
			$archive_pages,
			$woo_pages,
			array( $login_page )
		);

		if ( count( $pages ) > self::MAX_PAGES ) {
			$pages = $this->prioritise( $homepage, $post_type_pages, $template_pages, $shortcode_pages, $archive_pages, $woo_pages, $login_page );
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
	 * Collect one page per unique page template (up to 5).
	 *
	 * Different templates often load different plugins and scripts,
	 * so scanning one of each gives better coverage.
	 *
	 * @param array<int, array{id: string|int, url: string, label: string}> $existing Pages already in the list.
	 * @return array<int, array{id: int, url: string, label: string}>
	 */
	private function get_template_pages( array $existing ): array {
		$existing_ids = $this->extract_post_ids( $existing );
		$pages        = array();

		$page_posts = get_posts(
			array(
				'posts_per_page' => 50,
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$templates_found = array();

		foreach ( $page_posts as $post ) {
			if ( in_array( $post->ID, $existing_ids, true ) ) {
				continue;
			}

			$template = get_page_template_slug( $post );
			if ( empty( $template ) ) {
				$template = 'default';
			}

			if ( isset( $templates_found[ $template ] ) ) {
				continue;
			}

			$templates_found[ $template ] = true;
			$existing_ids[]               = $post->ID;

			$template_label = ( 'default' === $template ) ? 'Default' : basename( $template, '.php' );

			$pages[] = array(
				'id'    => $post->ID,
				'url'   => get_permalink( $post ),
				'label' => 'Page (' . $template_label . '): ' . $post->post_title,
			);

			if ( count( $pages ) >= 5 ) {
				break;
			}
		}

		return $pages;
	}

	/**
	 * Find pages containing shortcodes (forms, maps, embeds).
	 *
	 * Shortcodes often inject third-party scripts not present on
	 * other pages, so scanning a few gives broader coverage.
	 *
	 * @param array<int, array{id: string|int, url: string, label: string}> $existing Pages already in the list.
	 * @return array<int, array{id: int, url: string, label: string}>
	 */
	private function get_shortcode_pages( array $existing ): array {
		global $wpdb;

		$existing_ids = $this->extract_post_ids( $existing );
		$exclude_sql  = '';

		if ( ! empty( $existing_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $existing_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$exclude_sql = $wpdb->prepare( "AND ID NOT IN ($placeholders)", $existing_ids );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT ID, post_title, post_type
			 FROM {$wpdb->posts}
			 WHERE post_status = 'publish'
			   AND post_type IN ('post', 'page')
			   AND post_content LIKE '%[%'
			   $exclude_sql
			 ORDER BY ID DESC
			 LIMIT 10"
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$pages = array();

		foreach ( $rows as $row ) {
			$pages[] = array(
				'id'    => (int) $row->ID,
				'url'   => get_permalink( (int) $row->ID ),
				'label' => 'Shortcode: ' . $row->post_title,
			);

			if ( count( $pages ) >= 3 ) {
				break;
			}
		}

		return $pages;
	}

	/**
	 * Collect archive and search pages.
	 *
	 * Archive pages often load different scripts than single posts.
	 *
	 * @return array<int, array{id: string, url: string, label: string}>
	 */
	private function get_archive_pages(): array {
		$pages = array();

		// Largest category archive.
		$categories = get_categories(
			array(
				'number'  => 1,
				'orderby' => 'count',
				'order'   => 'DESC',
			)
		);

		if ( ! empty( $categories ) ) {
			$cat = $categories[0];
			$pages[] = array(
				'id'    => 'cat-' . $cat->term_id,
				'url'   => get_category_link( $cat->term_id ),
				'label' => 'Category Archive: ' . $cat->name,
			);
		}

		// Search results page.
		$pages[] = array(
			'id'    => 'search',
			'url'   => home_url( '/?s=test' ),
			'label' => 'Search Results',
		);

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

		$existing_ids = $this->extract_post_ids( $existing );
		$pages        = array();

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
	 * Priority order: homepage, WooCommerce pages, archive pages,
	 * template pages, shortcode pages, login page, then post type
	 * pages to fill the remaining slots.
	 *
	 * @param array $homepage        The homepage entry.
	 * @param array $post_type_pages Post type entries.
	 * @param array $template_pages  Template-variant entries.
	 * @param array $shortcode_pages Shortcode page entries.
	 * @param array $archive_pages   Archive page entries.
	 * @param array $woo_pages       WooCommerce entries.
	 * @param array $login_page      The login page entry.
	 * @return array<int, array{id: string|int, url: string, label: string}>
	 */
	private function prioritise( array $homepage, array $post_type_pages, array $template_pages, array $shortcode_pages, array $archive_pages, array $woo_pages, array $login_page ): array {
		$pages = array( $homepage );
		$pages = array_merge( $pages, $woo_pages );
		$pages = array_merge( $pages, $archive_pages );
		$pages = array_merge( $pages, $template_pages );
		$pages = array_merge( $pages, $shortcode_pages );
		$pages[] = $login_page;

		$remaining = self::MAX_PAGES - count( $pages );

		if ( $remaining > 0 ) {
			$pages = array_merge( $pages, array_slice( $post_type_pages, 0, $remaining ) );
		}

		return array_slice( $pages, 0, self::MAX_PAGES );
	}

	/**
	 * Extract integer post IDs from a page list.
	 *
	 * @param array<int, array{id: string|int, url: string, label: string}> $pages Page entries.
	 * @return int[]
	 */
	private function extract_post_ids( array $pages ): array {
		$ids = array();
		foreach ( $pages as $entry ) {
			if ( is_int( $entry['id'] ) ) {
				$ids[] = $entry['id'];
			}
		}
		return $ids;
	}
}
