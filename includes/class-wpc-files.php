<?php
/**
 * Sandboxed file access with backups of overwritten files.
 */

defined( 'ABSPATH' ) || exit;

class WPC_Files {

	const BLOCKED = '#(^|/)(wp-config\.php|\.htaccess|\.user\.ini|php\.ini|\.env)$#i';

	/** root => [path, writable, label]. */
	public static function roots() {
		$s     = WPC_Settings::all();
		$roots = array();
		if ( $s['theme_on'] && $s['theme_slug'] ) {
			$roots['theme'] = array( get_theme_root() . '/' . sanitize_file_name( $s['theme_slug'] ), true, 'themes/' . $s['theme_slug'] );
		}
		if ( $s['calc_on'] && $s['calc_slug'] ) {
			$roots['calculator'] = array( WP_PLUGIN_DIR . '/' . sanitize_file_name( $s['calc_slug'] ), true, 'plugins/' . $s['calc_slug'] );
		}
		if ( $s['mu_on'] ) {
			$roots['mu-plugins'] = array( WPMU_PLUGIN_DIR, true, 'mu-plugins (' . $s['mu_prefix'] . '*.php)' );
		}
		foreach ( WPC_Settings::lines( 'extra_roots' ) as $i => $rel ) {
			$rel = trim( str_replace( '\\', '/', $rel ), '/' );
			if ( '' === $rel || preg_match( '#(^|/)\.\.(/|$)#', $rel ) || preg_match( '#^(uploads|upgrade)(/|$)#', $rel ) ) {
				continue;
			}
			$roots[ 'extra-' . ( $i + 1 ) ] = array( WP_CONTENT_DIR . '/' . $rel, true, $rel );
		}
		if ( $s['read_content'] ) {
			$roots['content'] = array( WP_CONTENT_DIR, false, 'wp-content (read-only)' );
		}
		return $roots;
	}

	public static function resolve( $root, $rel, $for_write ) {
		$roots = self::roots();
		if ( ! isset( $roots[ $root ] ) ) {
			return new WP_Error( 'wpc_bad_root', 'Unknown or disabled folder. Available: ' . implode( ', ', array_keys( $roots ) ) );
		}
		list( $base, $writable ) = $roots[ $root ];
		if ( $for_write && ! $writable ) {
			return new WP_Error( 'wpc_read_only', "Folder '$root' is read-only." );
		}
		$rel = ltrim( str_replace( '\\', '/', (string) $rel ), '/' );
		if ( false !== strpos( $rel, "\0" ) || preg_match( '#(^|/)\.\.(/|$)#', $rel ) ) {
			return new WP_Error( 'wpc_bad_path', 'Path may not contain ".." or null bytes.' );
		}
		if ( preg_match( self::BLOCKED, $rel ) ) {
			return new WP_Error( 'wpc_bad_path', 'That file is always blocked.' );
		}
		if ( 'content' === $root && preg_match( '#^uploads(/|$)#', $rel ) ) {
			return new WP_Error( 'wpc_bad_path', 'Use the media tools for uploads.' );
		}
		if ( 'mu-plugins' === $root && $for_write ) {
			$p = (string) WPC_Settings::get( 'mu_prefix' );
			if ( false !== strpos( $rel, '/' ) || ( $p && 0 !== strpos( $rel, $p ) ) || '.php' !== substr( $rel, -4 ) ) {
				return new WP_Error( 'wpc_bad_path', "mu-plugins files must be top-level, start with \"$p\" and end in .php." );
			}
		}
		$abs = rtrim( $base, '/' ) . ( '' === $rel ? '' : '/' . $rel );
		// Symlink escape check: the deepest existing part must stay inside the root.
		$check = $abs;
		while ( ! file_exists( $check ) && dirname( $check ) !== $check ) {
			$check = dirname( $check );
		}
		$rc = realpath( $check );
		$rb = file_exists( $base ) ? realpath( $base ) : null;
		if ( $rb && $rc ) {
			$inside   = 0 === strpos( rtrim( $rc, '/' ) . '/', rtrim( $rb, '/' ) . '/' );
			$ancestor = 0 === strpos( rtrim( $rb, '/' ) . '/', rtrim( $rc, '/' ) . '/' );
			if ( ! $inside && ! $ancestor ) {
				return new WP_Error( 'wpc_bad_path', 'Path resolves outside the allowed folder.' );
			}
		}
		return $abs;
	}

	public static function rel( $root, $abs ) {
		$base = rtrim( self::roots()[ $root ][0], '/' );
		return ltrim( substr( $abs, strlen( $base ) ), '/' );
	}

	public static function php_syntax_ok( $code ) {
		try {
			token_get_all( $code, TOKEN_PARSE );
			return true;
		} catch ( ParseError $e ) {
			return new WP_Error( 'wpc_php_syntax', 'PHP syntax error on line ' . $e->getLine() . ': ' . $e->getMessage() );
		}
	}

	/** Store the current file as a backup; returns backup id or null. */
	private static function backup( $root, $rel, $abs ) {
		global $wpdb;
		if ( ! WPC_Settings::get( 'backups_on' ) || ! is_file( $abs ) ) {
			return null;
		}
		$t    = WPC_Install::tables()['backups'];
		$data = file_get_contents( $abs ); // phpcs:ignore
		$wpdb->insert( $t, array( 'created' => current_time( 'mysql', true ), 'root' => $root, 'path' => $rel, 'bytes' => strlen( $data ), 'md5' => md5( $data ), 'content' => $data ) ); // phpcs:ignore
		$id   = (int) $wpdb->insert_id;
		$keep = max( 1, (int) WPC_Settings::get( 'backups_keep' ) );
		$old  = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE root = %s AND path = %s ORDER BY id DESC LIMIT 1000 OFFSET %d", $root, $rel, $keep ) ); // phpcs:ignore
		if ( $old ) {
			$wpdb->query( "DELETE FROM {$t} WHERE id IN (" . implode( ',', array_map( 'intval', $old ) ) . ')' ); // phpcs:ignore
		}
		return $id;
	}

	public static function write( $root, $rel, $content, $encoding = 'utf8' ) {
		$abs = self::resolve( $root, $rel, true );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		if ( 'base64' === $encoding ) {
			$content = base64_decode( (string) $content, true );
			if ( false === $content ) {
				return new WP_Error( 'wpc_bad_base64', 'Content is not valid base64.' );
			}
		}
		$max = max( 1, (int) WPC_Settings::get( 'max_mb' ) ) * 1048576;
		if ( strlen( $content ) > $max ) {
			return new WP_Error( 'wpc_too_big', 'File exceeds the size limit.' );
		}
		if ( WPC_Settings::get( 'php_check' ) && preg_match( '/\.php$/i', $abs ) ) {
			$ok = self::php_syntax_ok( $content );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
		}
		if ( ! wp_mkdir_p( dirname( $abs ) ) ) {
			return new WP_Error( 'wpc_mkdir', 'Could not create folder (check permissions).' );
		}
		$rel_clean = self::rel( $root, $abs );
		$backup    = self::backup( $root, $rel_clean, $abs );
		$tmp       = $abs . '.wpc-tmp';
		if ( false === file_put_contents( $tmp, $content ) ) { // phpcs:ignore
			return new WP_Error( 'wpc_write', 'Could not write file (check permissions).' );
		}
		rename( $tmp, $abs );
		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $abs, true ); // phpcs:ignore
		}
		return array( 'root' => $root, 'path' => $rel_clean, 'bytes' => strlen( $content ), 'md5' => md5( $content ), 'backup_id' => $backup );
	}

	public static function read( $root, $rel, $max = 1048576 ) {
		$abs = self::resolve( $root, $rel, false );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		if ( ! is_file( $abs ) ) {
			return new WP_Error( 'wpc_not_found', 'File not found.' );
		}
		$size = filesize( $abs );
		$data = file_get_contents( $abs, false, null, 0, min( $size, $max ) ); // phpcs:ignore
		$text = mb_check_encoding( $data, 'UTF-8' );
		return array( 'path' => self::rel( $root, $abs ), 'bytes' => $size, 'truncated' => $size > $max, 'encoding' => $text ? 'utf8' : 'base64', 'content' => $text ? $data : base64_encode( $data ), 'md5' => md5_file( $abs ) );
	}

	public static function delete( $root, $rel ) {
		$abs = self::resolve( $root, $rel, true );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		if ( is_dir( $abs ) ) {
			if ( count( scandir( $abs ) ) > 2 ) {
				return new WP_Error( 'wpc_not_empty', 'Folder is not empty; delete its files first.' );
			}
			rmdir( $abs );
			return array( 'deleted' => self::rel( $root, $abs ), 'type' => 'dir' );
		}
		if ( ! is_file( $abs ) ) {
			return new WP_Error( 'wpc_not_found', 'File not found.' );
		}
		$rel_clean = self::rel( $root, $abs );
		$backup    = self::backup( $root, $rel_clean, $abs );
		unlink( $abs ); // phpcs:ignore
		return array( 'deleted' => $rel_clean, 'type' => 'file', 'backup_id' => $backup );
	}

	public static function restore( $backup_id ) {
		global $wpdb;
		$t   = WPC_Install::tables()['backups'];
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $backup_id ), ARRAY_A ); // phpcs:ignore
		if ( ! $row ) {
			return new WP_Error( 'wpc_not_found', 'Backup not found.' );
		}
		return self::write( $row['root'], $row['path'], $row['content'] );
	}

	public static function backups_for( $root, $rel ) {
		global $wpdb;
		$t = WPC_Install::tables()['backups'];
		return $wpdb->get_results( $wpdb->prepare( "SELECT id, created, bytes, md5 FROM {$t} WHERE root = %s AND path = %s ORDER BY id DESC", $root, $rel ), ARRAY_A ); // phpcs:ignore
	}

	public static function list_dir( $root, $rel, $recursive, $limit = 2000 ) {
		$abs = self::resolve( $root, $rel, false );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		if ( ! is_dir( $abs ) ) {
			return array( 'exists' => false, 'entries' => array() );
		}
		$out = array();
		if ( $recursive ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $abs, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
			$it->setMaxDepth( 8 );
		} else {
			$it = new FilesystemIterator( $abs, FilesystemIterator::SKIP_DOTS );
		}
		foreach ( $it as $f ) {
			if ( count( $out ) >= $limit ) {
				break;
			}
			$p = $f->getPathname();
			if ( 'content' === $root && preg_match( '#^/?uploads(/|$)#', substr( $p, strlen( WP_CONTENT_DIR ) ) ) ) {
				continue;
			}
			$out[] = array( 'path' => self::rel( $root, $p ), 'type' => $f->isDir() ? 'dir' : 'file', 'size' => $f->isFile() ? $f->getSize() : null, 'modified' => gmdate( 'c', $f->getMTime() ) );
		}
		return array( 'exists' => true, 'truncated' => count( $out ) >= $limit, 'entries' => $out );
	}
}
