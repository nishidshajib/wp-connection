<?php
/**
 * One set of SEO fields for Yoast SEO, Rank Math and All in One SEO.
 *
 * Fields: title, description, focus_keyword, canonical, noindex, nofollow,
 * og_title, og_description, og_image.
 *
 * - Rank Math: post and term meta (rank_math_*).
 * - Yoast SEO: post meta (_yoast_wpseo_*), term values in wpseo_taxonomy_meta.
 *   After writing, the post/term is re-saved so Yoast rebuilds its indexable
 *   (Yoast reads titles from indexables, not straight from meta).
 * - All in One SEO: its own wp_aioseo_posts table through AIOSEO's Post model.
 *   Plain post meta has no effect there. Terms need AIOSEO Pro, so they're not
 *   handled here.
 */

defined( 'ABSPATH' ) || exit;

class WPC_SEO {

	const FIELDS = array( 'title', 'description', 'focus_keyword', 'canonical', 'noindex', 'nofollow', 'og_title', 'og_description', 'og_image' );

	/** Active SEO plugins, in priority order: yoast, rankmath, aioseo. */
	public static function active() {
		$a = array();
		if ( defined( 'WPSEO_VERSION' ) ) {
			$a['yoast'] = 'Yoast SEO ' . WPSEO_VERSION;
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			$a['rankmath'] = 'Rank Math ' . ( defined( 'RANK_MATH_VERSION' ) ? RANK_MATH_VERSION : '' );
		}
		if ( defined( 'AIOSEO_VERSION' ) && class_exists( '\AIOSEO\Plugin\Common\Models\Post' ) ) {
			$a['aioseo'] = 'All in One SEO ' . AIOSEO_VERSION;
		}
		return $a;
	}

	/** The plugin to use: the one asked for (if active), else the only/first active one. */
	public static function pick( $wanted = '' ) {
		$a = self::active();
		if ( ! $a ) {
			return new WP_Error( 'wpc_seo_none', 'No supported SEO plugin is active (Yoast SEO, Rank Math or All in One SEO).' );
		}
		if ( $wanted ) {
			return isset( $a[ $wanted ] ) ? $wanted : new WP_Error( 'wpc_seo_inactive', $wanted . ' is not active. Active: ' . implode( ', ', array_keys( $a ) ) );
		}
		return array_key_first( $a );
	}

	/* -------------------------------------------------------------- posts */

	public static function get_post( $id, $plugin ) {
		switch ( $plugin ) {
			case 'yoast':
				$m = function ( $k ) use ( $id ) {
					return (string) get_post_meta( $id, '_yoast_wpseo_' . $k, true );
				};
				return array(
					'title'          => $m( 'title' ),
					'description'    => $m( 'metadesc' ),
					'focus_keyword'  => $m( 'focuskw' ),
					'canonical'      => $m( 'canonical' ),
					'noindex'        => '1' === $m( 'meta-robots-noindex' ) ? true : ( '2' === $m( 'meta-robots-noindex' ) ? false : null ),
					'nofollow'       => '1' === $m( 'meta-robots-nofollow' ),
					'og_title'       => $m( 'opengraph-title' ),
					'og_description' => $m( 'opengraph-description' ),
					'og_image'       => $m( 'opengraph-image' ),
				);
			case 'rankmath':
				$robots = (array) get_post_meta( $id, 'rank_math_robots', true );
				return array(
					'title'          => (string) get_post_meta( $id, 'rank_math_title', true ),
					'description'    => (string) get_post_meta( $id, 'rank_math_description', true ),
					'focus_keyword'  => (string) get_post_meta( $id, 'rank_math_focus_keyword', true ),
					'canonical'      => (string) get_post_meta( $id, 'rank_math_canonical_url', true ),
					'noindex'        => in_array( 'noindex', $robots, true ) ? true : ( in_array( 'index', $robots, true ) ? false : null ),
					'nofollow'       => in_array( 'nofollow', $robots, true ),
					'og_title'       => (string) get_post_meta( $id, 'rank_math_facebook_title', true ),
					'og_description' => (string) get_post_meta( $id, 'rank_math_facebook_description', true ),
					'og_image'       => (string) get_post_meta( $id, 'rank_math_facebook_image', true ),
				);
			case 'aioseo':
				$p  = \AIOSEO\Plugin\Common\Models\Post::getPost( $id );
				$kp = is_string( $p->keyphrases ) ? json_decode( $p->keyphrases, true ) : json_decode( wp_json_encode( $p->keyphrases ), true );
				return array(
					'title'          => (string) $p->title,
					'description'    => (string) $p->description,
					'focus_keyword'  => (string) ( $kp['focus']['keyphrase'] ?? '' ),
					'canonical'      => (string) $p->canonical_url,
					'noindex'        => $p->robots_default ? null : (bool) $p->robots_noindex,
					'nofollow'       => ! $p->robots_default && (bool) $p->robots_nofollow,
					'og_title'       => (string) $p->og_title,
					'og_description' => (string) $p->og_description,
					'og_image'       => 'custom_image' === $p->og_image_type ? (string) $p->og_image_custom_url : '',
				);
		}
		return array();
	}

	/** $v: only the fields to change. '' clears a text field; noindex null = plugin default. */
	public static function update_post( $id, $plugin, $v ) {
		$has = function ( $k ) use ( $v ) {
			return array_key_exists( $k, $v );
		};
		switch ( $plugin ) {
			case 'yoast':
				$map = array( 'title' => 'title', 'description' => 'metadesc', 'focus_keyword' => 'focuskw', 'canonical' => 'canonical', 'og_title' => 'opengraph-title', 'og_description' => 'opengraph-description', 'og_image' => 'opengraph-image' );
				foreach ( $map as $f => $k ) {
					if ( $has( $f ) ) {
						self::meta( $id, '_yoast_wpseo_' . $k, (string) $v[ $f ] );
					}
				}
				if ( $has( 'og_image' ) ) {
					delete_post_meta( $id, '_yoast_wpseo_opengraph-image-id' );
				}
				if ( $has( 'noindex' ) ) {
					self::meta( $id, '_yoast_wpseo_meta-robots-noindex', null === $v['noindex'] ? '' : ( $v['noindex'] ? '1' : '2' ) );
				}
				if ( $has( 'nofollow' ) ) {
					self::meta( $id, '_yoast_wpseo_meta-robots-nofollow', $v['nofollow'] ? '1' : '' );
				}
				self::resave( $id ); // Rebuild Yoast's indexable from the new meta.
				return true;

			case 'rankmath':
				$map = array( 'title' => 'rank_math_title', 'description' => 'rank_math_description', 'focus_keyword' => 'rank_math_focus_keyword', 'canonical' => 'rank_math_canonical_url', 'og_title' => 'rank_math_facebook_title', 'og_description' => 'rank_math_facebook_description', 'og_image' => 'rank_math_facebook_image' );
				foreach ( $map as $f => $k ) {
					if ( $has( $f ) ) {
						self::meta( $id, $k, (string) $v[ $f ] );
					}
				}
				if ( $has( 'og_image' ) ) {
					delete_post_meta( $id, 'rank_math_facebook_image_id' );
				}
				if ( $has( 'noindex' ) || $has( 'nofollow' ) ) {
					$r = array_values( array_diff( (array) get_post_meta( $id, 'rank_math_robots', true ), array( 'index', 'noindex', 'follow', 'nofollow', '' ) ) );
					$noindex  = $has( 'noindex' ) ? $v['noindex'] : null;
					$nofollow = $has( 'nofollow' ) ? (bool) $v['nofollow'] : false;
					if ( true === $noindex ) {
						$r[] = 'noindex';
					} elseif ( false === $noindex ) {
						$r[] = 'index';
					}
					if ( $nofollow ) {
						$r[] = 'nofollow';
					}
					if ( $r ) {
						update_post_meta( $id, 'rank_math_robots', $r );
					} else {
						delete_post_meta( $id, 'rank_math_robots' );
					}
				}
				self::resave( $id );
				return true;

			case 'aioseo':
				$p    = \AIOSEO\Plugin\Common\Models\Post::getPost( $id );
				$p->post_id = $id;
				$map  = array( 'title' => 'title', 'description' => 'description', 'canonical' => 'canonical_url', 'og_title' => 'og_title', 'og_description' => 'og_description' );
				foreach ( $map as $f => $k ) {
					if ( $has( $f ) ) {
						$p->$k = sanitize_text_field( (string) $v[ $f ] );
					}
				}
				if ( $has( 'focus_keyword' ) ) {
					$kp = is_string( $p->keyphrases ) ? json_decode( $p->keyphrases, true ) : json_decode( wp_json_encode( $p->keyphrases ), true );
					$kp = is_array( $kp ) ? $kp : array();
					$kp['focus'] = array_merge( (array) ( $kp['focus'] ?? array() ), array( 'keyphrase' => sanitize_text_field( (string) $v['focus_keyword'] ) ) );
					$kp['additional'] = $kp['additional'] ?? array();
					$p->keyphrases = wp_json_encode( $kp );
				}
				if ( $has( 'noindex' ) || $has( 'nofollow' ) ) {
					$custom = ( $has( 'noindex' ) && null !== $v['noindex'] ) || ! empty( $v['nofollow'] );
					$p->robots_default  = ! $custom;
					$p->robots_noindex  = $has( 'noindex' ) && true === $v['noindex'];
					$p->robots_nofollow = ! empty( $v['nofollow'] );
				}
				if ( $has( 'og_image' ) ) {
					$p->og_image_type       = $v['og_image'] ? 'custom_image' : 'default';
					$p->og_image_custom_url = esc_url_raw( (string) $v['og_image'] );
				}
				$p->updated = gmdate( 'Y-m-d H:i:s' );
				$p->save();
				clean_post_cache( $id );
				return true;
		}
		return new WP_Error( 'wpc_seo', 'Unsupported SEO plugin.' );
	}

	/* -------------------------------------------------------------- terms */

	public static function get_term( $term, $plugin ) {
		if ( 'rankmath' === $plugin ) {
			$robots = (array) get_term_meta( $term->term_id, 'rank_math_robots', true );
			return array(
				'title'         => (string) get_term_meta( $term->term_id, 'rank_math_title', true ),
				'description'   => (string) get_term_meta( $term->term_id, 'rank_math_description', true ),
				'focus_keyword' => (string) get_term_meta( $term->term_id, 'rank_math_focus_keyword', true ),
				'canonical'     => (string) get_term_meta( $term->term_id, 'rank_math_canonical_url', true ),
				'noindex'       => in_array( 'noindex', $robots, true ) ? true : ( in_array( 'index', $robots, true ) ? false : null ),
				'nofollow'      => in_array( 'nofollow', $robots, true ),
			);
		}
		if ( 'yoast' === $plugin ) {
			$all = get_option( 'wpseo_taxonomy_meta', array() );
			$m   = $all[ $term->taxonomy ][ $term->term_id ] ?? array();
			return array(
				'title'         => (string) ( $m['wpseo_title'] ?? '' ),
				'description'   => (string) ( $m['wpseo_desc'] ?? '' ),
				'focus_keyword' => (string) ( $m['wpseo_focuskw'] ?? '' ),
				'canonical'     => (string) ( $m['wpseo_canonical'] ?? '' ),
				'noindex'       => 'noindex' === ( $m['wpseo_noindex'] ?? '' ) ? true : ( 'index' === ( $m['wpseo_noindex'] ?? '' ) ? false : null ),
			);
		}
		return new WP_Error( 'wpc_seo_terms', 'Category and tag SEO fields are supported for Yoast SEO and Rank Math only.' );
	}

	public static function update_term( $term, $plugin, $v ) {
		$has = function ( $k ) use ( $v ) {
			return array_key_exists( $k, $v );
		};
		if ( 'rankmath' === $plugin ) {
			$map = array( 'title' => 'rank_math_title', 'description' => 'rank_math_description', 'focus_keyword' => 'rank_math_focus_keyword', 'canonical' => 'rank_math_canonical_url' );
			foreach ( $map as $f => $k ) {
				if ( $has( $f ) ) {
					'' === (string) $v[ $f ] ? delete_term_meta( $term->term_id, $k ) : update_term_meta( $term->term_id, $k, wp_slash( (string) $v[ $f ] ) );
				}
			}
			if ( $has( 'noindex' ) || $has( 'nofollow' ) ) {
				$r = array_values( array_diff( (array) get_term_meta( $term->term_id, 'rank_math_robots', true ), array( 'index', 'noindex', 'follow', 'nofollow', '' ) ) );
				if ( $has( 'noindex' ) && null !== $v['noindex'] ) {
					$r[] = $v['noindex'] ? 'noindex' : 'index';
				}
				if ( ! empty( $v['nofollow'] ) ) {
					$r[] = 'nofollow';
				}
				$r ? update_term_meta( $term->term_id, 'rank_math_robots', $r ) : delete_term_meta( $term->term_id, 'rank_math_robots' );
			}
			return true;
		}
		if ( 'yoast' === $plugin ) {
			$all = get_option( 'wpseo_taxonomy_meta', array() );
			$all = is_array( $all ) ? $all : array();
			$m   = $all[ $term->taxonomy ][ $term->term_id ] ?? array();
			$map = array( 'title' => 'wpseo_title', 'description' => 'wpseo_desc', 'focus_keyword' => 'wpseo_focuskw', 'canonical' => 'wpseo_canonical' );
			foreach ( $map as $f => $k ) {
				if ( $has( $f ) ) {
					$m[ $k ] = sanitize_text_field( (string) $v[ $f ] );
				}
			}
			if ( $has( 'noindex' ) ) {
				$m['wpseo_noindex'] = null === $v['noindex'] ? 'default' : ( $v['noindex'] ? 'noindex' : 'index' );
			}
			$all[ $term->taxonomy ][ $term->term_id ] = array_filter( $m, 'strlen' );
			update_option( 'wpseo_taxonomy_meta', $all );
			// Let Yoast rebuild the term's indexable.
			wp_update_term( $term->term_id, $term->taxonomy, array() );
			return true;
		}
		return new WP_Error( 'wpc_seo_terms', 'Category and tag SEO fields are supported for Yoast SEO and Rank Math only.' );
	}

	/* ------------------------------------------------------------ helpers */

	private static function meta( $id, $key, $value ) {
		if ( '' === $value ) {
			delete_post_meta( $id, $key );
		} else {
			update_post_meta( $id, $key, wp_slash( $value ) );
		}
	}

	/** Re-save the post so SEO plugins that cache data on save_post (Yoast) pick up meta changes. */
	public static function resave( $id ) {
		clean_post_cache( $id );
		wp_update_post( array( 'ID' => (int) $id ) );
	}

	/** True if a meta key belongs to Yoast, so upsert-post can trigger a re-save. */
	public static function is_yoast_key( $k ) {
		return 0 === strpos( (string) $k, '_yoast_wpseo_' );
	}
}
