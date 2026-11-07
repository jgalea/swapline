<?php

namespace Swapline\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a table in batches and writes single cells back.
 *
 * Rows are addressed by the primary key, else by a unique key with no
 * nullable columns, else by the full original row (`LIMIT 1`), which is how
 * tables without any key still work. Key columns are never rewritten.
 */
final class Table {

	public const KEY_PRIMARY = 'primary';
	public const KEY_UNIQUE  = 'unique';
	public const KEY_NONE    = 'none';

	public string $name;

	/**
	 * @var array<string, string> Column name => SQL type.
	 */
	public array $columns = array();

	/**
	 * @var string[]
	 */
	public array $key = array();

	/**
	 * @var string[] Columns MySQL computes, which can't be written.
	 */
	public array $generated = array();

	public string $key_type = self::KEY_NONE;

	public function __construct( string $name ) {
		global $wpdb;

		$this->name = $name;

		$columns  = (array) $wpdb->get_results( $wpdb->prepare( 'SHOW FULL COLUMNS FROM %i', $name ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- live schema read; caching would go stale mid-run.
		$nullable = array();
		foreach ( $columns as $column ) {
			$this->columns[ $column['Field'] ] = strtolower( (string) $column['Type'] );
			$nullable[ $column['Field'] ]      = 'YES' === $column['Null'];
			if ( self::is_generated( (string) $column['Extra'] ) ) {
				$this->generated[] = $column['Field'];
			}
		}

		$keys   = (array) $wpdb->get_results( $wpdb->prepare( 'SHOW KEYS FROM %i', $name ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- live schema read; caching would go stale mid-run.
		$unique = array();
		foreach ( $keys as $key ) {
			if ( '0' !== (string) $key['Non_unique'] ) {
				continue;
			}
			$unique[ $key['Key_name'] ][ (int) $key['Seq_in_index'] ] = $key['Column_name'];
		}

		if ( isset( $unique['PRIMARY'] ) ) {
			ksort( $unique['PRIMARY'] );
			$this->key      = array_values( $unique['PRIMARY'] );
			$this->key_type = self::KEY_PRIMARY;
			return;
		}

		foreach ( $unique as $columns_in_key ) {
			ksort( $columns_in_key );
			$candidate = array_values( $columns_in_key );
			$has_null  = false;
			foreach ( $candidate as $column ) {
				$has_null = $has_null || ! empty( $nullable[ $column ] );
			}
			if ( ! $has_null ) {
				$this->key      = $candidate;
				$this->key_type = self::KEY_UNIQUE;
				return;
			}
		}
	}

	/**
	 * True for virtual and stored generated columns. MySQL 8 also reports
	 * DEFAULT_GENERATED for ordinary columns with an expression default.
	 */
	public static function is_generated( string $extra ): bool {
		return 1 === preg_match( '/\b(?:VIRTUAL|STORED|PERSISTENT)\s+GENERATED\b/i', $extra );
	}

	/**
	 * Text columns that may be rewritten.
	 *
	 * @return string[]
	 */
	public function text_columns( bool $skip_guid ): array {
		$out = array();
		foreach ( $this->columns as $name => $type ) {
			if ( in_array( $name, $this->key, true ) || in_array( $name, $this->generated, true ) || ( $skip_guid && 'guid' === strtolower( $name ) ) ) {
				continue;
			}
			if ( preg_match( '/char|text|json/', $type ) ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	public function count_rows(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- live row count.
	}

	/**
	 * Fetches the next batch after the cursor. The cursor is the last key
	 * seen (keyed tables) or a row offset (tables without a usable key).
	 *
	 * @param string[]                  $columns
	 * @param array<int, string>|int|null $cursor
	 * @return array<int, array<string, string|null>>
	 */
	public function fetch( array $columns, $cursor, int $limit ): array {
		global $wpdb;

		$table = Tables::quote( $this->name );

		if ( self::KEY_NONE === $this->key_type ) {
			return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i LIMIT %d, %d', $this->name, (int) $cursor, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- batched read of live rows.
		}

		$select = implode( ', ', array_map( array( Tables::class, 'quote' ), array_unique( array_merge( $this->key, $columns ) ) ) );
		$order  = implode( ', ', array_map( array( Tables::class, 'quote' ), $this->key ) );
		$where  = '';
		$args   = array();

		if ( is_array( $cursor ) ) {
			$where = ' WHERE (' . $order . ') > (' . implode( ', ', array_fill( 0, count( $this->key ), '%s' ) ) . ')';
			$args  = array_values( $cursor );
		}
		$args[] = $limit;

		$sql = self::escape_percent( "SELECT {$select} FROM {$table}" ) . $where . self::escape_percent( " ORDER BY {$order}" ) . ' LIMIT %d';
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifiers are validated and quoted, values are placeholders.
	}

	/**
	 * @param array<string, string|null> $row
	 * @return array<int, string>|int
	 */
	public function cursor_after( array $row, int $offset ) {
		if ( self::KEY_NONE === $this->key_type ) {
			return $offset;
		}
		$cursor = array();
		foreach ( $this->key as $column ) {
			$cursor[] = (string) $row[ $column ];
		}
		return $cursor;
	}

	/**
	 * @param array<string, string|null> $row Original row as read.
	 * @return array<string, string|null> Key column => value, or every column for keyless tables.
	 */
	public function identify( array $row ): array {
		if ( self::KEY_NONE === $this->key_type ) {
			return $row;
		}
		return array_intersect_key( $row, array_flip( $this->key ) );
	}

	/**
	 * @param array<string, string|null> $row     Original row as read.
	 * @param array<string, string>      $changes Column => new value.
	 */
	public function update( array $row, array $changes ): bool {
		global $wpdb;

		$set  = array();
		$args = array();
		foreach ( $changes as $column => $value ) {
			$set[]  = Tables::quote( $column ) . ' = %s';
			$args[] = $value;
		}

		$sql = 'UPDATE ' . Tables::quote( $this->name ) . ' SET ' . implode( ', ', $set ) . ' WHERE ' . self::where( $this->identify( $row ), $args );
		if ( self::KEY_NONE === $this->key_type ) {
			$sql .= ' LIMIT 1';
		}

		$sql    = $wpdb->prepare( self::escape_percent_outside_placeholders( $sql ), $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifiers are validated and quoted, values are placeholders.
		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared on the line above.
		return false !== $result && $result > 0;
	}

	/**
	 * The value of one cell in the row a recorded key points at, null when the row is gone. In tables
	 * without a usable key the row is matched on every column except the one being read.
	 *
	 * @param array<string, string|null> $key As returned by identify().
	 */
	public function read_cell( array $key, string $column ): ?string {
		global $wpdb;

		$columns = $this->match( $key, $column );
		if ( null === $columns || ! isset( $this->columns[ $column ] ) ) {
			return null;
		}
		$args = array();
		$sql  = 'SELECT ' . Tables::quote( $column ) . ' FROM ' . Tables::quote( $this->name ) . ' WHERE ' . self::where( $columns, $args ) . ' LIMIT 1';
		$row  = $wpdb->get_row( $wpdb->prepare( self::escape_percent_outside_placeholders( $sql ), $args ), ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifiers are validated and quoted, values are placeholders.
		return is_array( $row ) && null !== $row[0] ? (string) $row[0] : null;
	}

	/**
	 * @param array<string, string|null> $key
	 * @return array<string, string|null>|null
	 */
	private function match( array $key, string $column ): ?array {
		if ( self::KEY_NONE === $this->key_type ) {
			$match = array_diff_key( $key, array( $column => true ) );
		} else {
			$match = array_intersect_key( $key, array_flip( $this->key ) );
			if ( count( $match ) !== count( $this->key ) ) {
				return null;
			}
		}
		return array() === $match ? null : $match;
	}

	/**
	 * Sets one cell in the row a recorded key points at. In tables without a usable key the key is the
	 * full original row, matched on every column except the one being written.
	 *
	 * @param array<string, string|null> $key As returned by identify().
	 * @return bool True when the row holds the value afterwards.
	 */
	public function write_cell( array $key, string $column, string $value ): bool {
		global $wpdb;

		$columns = $this->match( $key, $column );
		if ( null === $columns || ! in_array( $column, $this->text_columns( false ), true ) ) {
			return false;
		}

		$args  = array( $value );
		$where = self::where( $columns, $args );
		$sql   = 'UPDATE ' . Tables::quote( $this->name ) . ' SET ' . Tables::quote( $column ) . ' = %s WHERE ' . $where;
		if ( self::KEY_NONE === $this->key_type ) {
			$sql .= ' LIMIT 1';
		}
		$result = $wpdb->query( $wpdb->prepare( self::escape_percent_outside_placeholders( $sql ), $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifiers are validated and quoted, values are placeholders.
		if ( false === $result ) {
			return false;
		}
		if ( $result > 0 ) {
			return true;
		}

		$args   = array();
		$where  = self::where( $columns, $args );
		$args[] = $value;
		$sql    = 'SELECT COUNT(*) FROM ' . Tables::quote( $this->name ) . ' WHERE ' . $where . ' AND BINARY ' . Tables::quote( $column ) . ' = %s';
		return (int) $wpdb->get_var( $wpdb->prepare( self::escape_percent_outside_placeholders( $sql ), $args ) ) > 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifiers are validated and quoted, values are placeholders.
	}

	/**
	 * @param array<string, string|null> $columns Column => value; null matches NULL.
	 * @param array<int, string>         $args    Placeholder values, appended to.
	 */
	private static function where( array $columns, array &$args ): string {
		$where = array();
		foreach ( $columns as $column => $value ) {
			if ( null === $value ) {
				$where[] = Tables::quote( (string) $column ) . ' IS NULL';
				continue;
			}
			$where[] = Tables::quote( (string) $column ) . ' = %s';
			$args[]  = (string) $value;
		}
		return implode( ' AND ', $where );
	}

	private static function escape_percent( string $sql ): string {
		return str_replace( '%', '%%', $sql );
	}

	private static function escape_percent_outside_placeholders( string $sql ): string {
		return (string) preg_replace( '/%(?!s\b)/', '%%', $sql );
	}
}
