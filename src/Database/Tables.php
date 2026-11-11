<?php

namespace Swapline\Database;

use Swapline\Run\History;

defined( 'ABSPATH' ) || exit;

final class Tables {

	/**
	 * Tables with the WordPress prefix, excluding views and the plugin's own tables.
	 *
	 * @return array<string, array{name: string, rows: int, size: int, core: bool, site: int}>
	 */
	public static function all(): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $wpdb->base_prefix ) . '%' ),
			ARRAY_A
		);

		$excluded = (array) apply_filters( 'swapline_excluded_tables', array( History::table() ) );
		$core     = self::core_names();
		$tables   = array();
		$global   = is_multisite() ? array_values( $wpdb->tables( 'global', true ) ) : array();

		foreach ( (array) $rows as $row ) {
			$name = (string) $row['Name'];
			if ( empty( $row['Engine'] ) || in_array( $name, $excluded, true ) || self::is_scratch( $name ) ) {
				continue;
			}
			$tables[ $name ] = array(
				'name' => $name,
				'rows' => (int) $row['Rows'],
				'size' => (int) $row['Data_length'] + (int) $row['Index_length'],
				'core' => isset( $core[ $name ] ),
				'site' => in_array( $name, $global, true ) ? 0 : self::site_of( $name ),
			);
		}

		ksort( $tables, SORT_NATURAL );
		return $tables;
	}

	/**
	 * Returns the requested names that exist, in the order given, or null if any are unknown.
	 *
	 * @param string[] $requested
	 * @return string[]|null
	 */
	public static function validate( array $requested ): ?array {
		global $wpdb;

		$existing = array_flip( (array) $wpdb->get_col( 'SHOW TABLES' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$allowed  = self::all();
		$valid    = array();

		foreach ( $requested as $name ) {
			$name = (string) $name;
			if ( ! isset( $existing[ $name ], $allowed[ $name ] ) ) {
				return null;
			}
			$valid[ $name ] = $name;
		}

		return array_values( $valid );
	}

	/**
	 * Start of the names an import gives its temporary and replaced tables. It carries this site's prefix, so
	 * two installs in one database never touch each other's.
	 */
	public static function scratch_prefix( string $kind ): string {
		global $wpdb;
		return $wpdb->base_prefix . 'swapline_' . $kind . '_';
	}

	public static function is_scratch( string $name ): bool {
		global $wpdb;
		return 1 === preg_match( '/^' . preg_quote( $wpdb->base_prefix, '/' ) . 'swapline_(?:tmp|old)_[a-f0-9]{8}_\d+$/', $name );
	}

	public static function quote( string $identifier ): string {
		return '`' . str_replace( '`', '``', $identifier ) . '`';
	}

	/**
	 * @return array<string, true>
	 */
	private static function core_names(): array {
		global $wpdb;

		$blog_suffixes   = $wpdb->tables( 'blog', false );
		$global_suffixes = $wpdb->tables( 'global', false );
		$names           = array();

		foreach ( $global_suffixes as $suffix ) {
			$names[ $wpdb->base_prefix . $suffix ] = true;
		}
		foreach ( $blog_suffixes as $suffix ) {
			$names[ $wpdb->base_prefix . $suffix ] = true;
		}

		if ( is_multisite() ) {
			$pattern = '/^' . preg_quote( $wpdb->base_prefix, '/' ) . '[0-9]+_(' . implode( '|', array_map( 'preg_quote', $blog_suffixes ) ) . ')$/';
			$all     = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->base_prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( $all as $name ) {
				if ( preg_match( $pattern, $name ) ) {
					$names[ $name ] = true;
				}
			}
		}

		return $names;
	}

	private static function site_of( string $name ): int {
		global $wpdb;

		if ( ! is_multisite() ) {
			return 0;
		}
		$pattern = '/^' . preg_quote( $wpdb->base_prefix, '/' ) . '([0-9]+)_/';
		if ( preg_match( $pattern, $name, $m ) ) {
			return (int) $m[1];
		}
		return (int) get_main_site_id();
	}
}
