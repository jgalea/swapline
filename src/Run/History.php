<?php

namespace Swapline\Run;

use Swapline\Store;

defined( 'ABSPATH' ) || exit;

/**
 * The last few runs: summaries in an option, changed cells in a table.
 */
final class History {

	public const OPTION     = 'swapline_runs';
	public const DB_VERSION = '1';

	private const MAX_VALUE_BYTES = 262144;

	public static function table(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'swapline_changes';
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				run_id varchar(36) NOT NULL,
				table_name varchar(191) NOT NULL,
				row_key text NOT NULL,
				column_name varchar(191) NOT NULL,
				old_value longtext NOT NULL,
				new_value longtext NOT NULL,
				replacements int(10) unsigned NOT NULL DEFAULT 0,
				truncated tinyint(1) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY run_id (run_id)
			) {$charset};"
		);

		Store::set( 'swapline_db_version', self::DB_VERSION );
	}

	public static function maybe_install(): void {
		if ( self::DB_VERSION !== Store::get( 'swapline_db_version' ) ) {
			self::install();
		}
	}

	public static function size(): int {
		return max( 1, (int) apply_filters( 'swapline_history_size', 5 ) );
	}

	public static function max_recorded_changes(): int {
		return max( 0, (int) apply_filters( 'swapline_max_recorded_changes', 2000 ) );
	}

	/**
	 * Cells holding credentials are changed but never copied into the history.
	 *
	 * @param array<string, string|null> $row
	 */
	public static function is_sensitive( string $table, array $row, string $column ): bool {
		$columns = (array) apply_filters( 'swapline_sensitive_columns', array( 'user_pass', 'user_activation_key' ) );
		if ( in_array( $column, $columns, true ) ) {
			return true;
		}
		return 'session_tokens' === ( $row['meta_key'] ?? null ) && 'meta_value' === $column && str_ends_with( $table, 'usermeta' );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function runs(): array {
		$runs = Store::get( self::OPTION, array() );
		return is_array( $runs ) ? array_values( $runs ) : array();
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function find( string $run_id ): ?array {
		foreach ( self::runs() as $run ) {
			if ( ( $run['id'] ?? '' ) === $run_id ) {
				return $run;
			}
		}
		return null;
	}

	/**
	 * Adds a run, or replaces the entry with the same id. Runs still in progress never count against
	 * the history size, so a flood of short runs can't push them out.
	 *
	 * @param array<string, mixed> $run
	 */
	public static function add( array $run ): void {
		$runs  = self::runs();
		$found = false;
		foreach ( $runs as $i => $existing ) {
			if ( ( $existing['id'] ?? '' ) === $run['id'] ) {
				$runs[ $i ] = $run;
				$found      = true;
			}
		}
		if ( ! $found ) {
			array_unshift( $runs, $run );
		}

		$kept  = array();
		$count = 0;
		foreach ( $runs as $item ) {
			if ( 'running' === ( $item['status'] ?? '' ) || $count < self::size() ) {
				$kept[] = $item;
				$count += 'running' === ( $item['status'] ?? '' ) ? 0 : 1;
			}
		}
		Store::set( self::OPTION, $kept );
		self::prune( array_column( $kept, 'id' ) );
	}

	/**
	 * Deletes recorded cells for every run not in the keep list.
	 *
	 * @param string[] $keep
	 */
	public static function prune( array $keep ): void {
		global $wpdb;

		$table = self::table();
		if ( array() === $keep ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own log table.
			return;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $keep ), '%s' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE run_id NOT IN ({$placeholders})", array_merge( array( $table ), $keep ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built from the list.
	}

	/**
	 * @param array<string, string|null> $row_key
	 */
	public static function record( string $run_id, string $table, array $row_key, string $column, string $before, string $after, int $replacements ): bool {
		global $wpdb;

		$truncated = strlen( $before ) > self::MAX_VALUE_BYTES || strlen( $after ) > self::MAX_VALUE_BYTES;
		if ( $truncated ) {
			$common = strspn( $before ^ $after, "\0" );
			$start  = max( 0, $common - 1024 );
			$before = self::cut( $before, $start );
			$after  = self::cut( $after, $start );
		}

		return false !== $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'run_id'       => $run_id,
				'table_name'   => $table,
				'row_key'      => (string) wp_json_encode( $row_key ),
				'column_name'  => $column,
				'old_value'    => $before,
				'new_value'    => $after,
				'replacements' => $replacements,
				'truncated'    => $truncated ? 1 : 0,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d' )
		);
	}

	/**
	 * @return array{total: int, items: array<int, array<string, mixed>>}
	 */
	public static function changes( string $run_id, int $page, int $per_page, string $table = '' ): array {
		global $wpdb;

		$changes = self::table();
		$where   = 'run_id = %s';
		$args    = array( $run_id );
		if ( '' !== $table ) {
			$where .= ' AND table_name = %s';
			$args[] = $table;
		}

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where}", array_merge( array( $changes ), $args ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where is built only from fixed clauses, with matching placeholders.

		$args[] = $per_page;
		$args[] = max( 0, ( $page - 1 ) * $per_page );
		$items  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE {$where} ORDER BY id ASC LIMIT %d OFFSET %d", array_merge( array( $changes ), $args ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where is built only from fixed clauses, with matching placeholders.

		return array(
			'total' => $total,
			'items' => $items,
		);
	}

	public static function uninstall(): void {
		global $wpdb;
		$table = self::table();
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- removes the plugin's own table.
	}

	private static function cut( string $value, int $start ): string {
		if ( function_exists( 'mb_strcut' ) ) {
			return mb_strcut( $value, $start, self::MAX_VALUE_BYTES, 'UTF-8' );
		}
		return (string) substr( $value, $start, self::MAX_VALUE_BYTES );
	}
}
