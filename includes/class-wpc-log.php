<?php
/**
 * Activity log in the plugin's own table, {prefix}wpc_activity_log.
 */

defined( 'ABSPATH' ) || exit;

class WPC_Log {

	public static function init() {
		add_action( WPC_Install::CRON, array( __CLASS__, 'cleanup' ) );
	}

	private static function table() {
		return WPC_Install::tables()['log'];
	}

	public static function add( $tool, $target, $ok, $message = '', $input = null, $is_write = false, $backup_id = null ) {
		global $wpdb;
		if ( is_array( $input ) ) {
			foreach ( array( 'content', 'meta' ) as $big ) {
				if ( isset( $input[ $big ] ) ) {
					$input[ $big ] = '[' . strlen( (string) wp_json_encode( $input[ $big ] ) ) . ' bytes]';
				}
			}
		}
		$wpdb->insert( // phpcs:ignore
			self::table(),
			array(
				'created'   => current_time( 'mysql', true ),
				'token'     => substr( WPC_Auth::current_name(), 0, 100 ),
				'tool'      => substr( (string) $tool, 0, 64 ),
				'target'    => substr( (string) $target, 0, 255 ),
				'ok'        => $ok ? 1 : 0,
				'is_write'  => $is_write ? 1 : 0,
				'message'   => substr( (string) $message, 0, 2000 ),
				'input'     => substr( (string) wp_json_encode( $input ), 0, 4000 ),
				'backup_id' => $backup_id,
			)
		);
	}

	public static function query( $args = array() ) {
		global $wpdb;
		$t     = self::table();
		$where = array( '1=1' );
		$vals  = array();
		if ( ! empty( $args['tool'] ) ) {
			$where[] = 'tool = %s';
			$vals[]  = $args['tool'];
		}
		if ( ! empty( $args['result'] ) && 'errors' === $args['result'] ) {
			$where[] = 'ok = 0';
		}
		if ( ! empty( $args['result'] ) && 'writes' === $args['result'] ) {
			$where[] = 'is_write = 1';
		}
		if ( ! empty( $args['since'] ) ) {
			$where[] = 'created >= %s';
			$vals[]  = $args['since'];
		}
		$limit = max( 1, min( 5000, (int) ( $args['limit'] ?? 100 ) ) );
		$sql   = "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) . " ORDER BY id DESC LIMIT {$limit}";
		$sql   = $vals ? $wpdb->prepare( $sql, $vals ) : $sql; // phpcs:ignore
		$rows  = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore
		return is_array( $rows ) ? $rows : array();
	}

	public static function stats_24h() {
		global $wpdb;
		$t     = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) calls, SUM(is_write) writes, SUM(ok=0) errors, MAX(created) last FROM {$t} WHERE created >= %s", $since ), ARRAY_A ); // phpcs:ignore
		return $row ? $row : array( 'calls' => 0, 'writes' => 0, 'errors' => 0, 'last' => null );
	}

	public static function tools_used() {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_col( "SELECT DISTINCT tool FROM {$t} ORDER BY tool" ); // phpcs:ignore
	}

	public static function clear() {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . self::table() ); // phpcs:ignore
	}

	/** Daily: keep only the newest N log rows. */
	public static function cleanup() {
		global $wpdb;
		$t    = self::table();
		$keep = max( 100, (int) WPC_Settings::get( 'log_keep' ) );
		$cut  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} ORDER BY id DESC LIMIT 1 OFFSET %d", $keep ) ); // phpcs:ignore
		if ( $cut ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE id <= %d", $cut ) ); // phpcs:ignore
		}
	}
}
