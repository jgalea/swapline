<?php
// phpcs:disable -- an SQLite-backed stand-in for $wpdb that understands the handful of MySQL statements the plugin issues.

namespace Swapline\Tests\Support;

use PDO;

class FakeWpdb {

	public string $base_prefix = 'wp_';
	public string $prefix      = 'wp_';
	public string $users       = 'wp_users';
	public string $usermeta    = 'wp_usermeta';
	public string $options     = 'wp_options';
	public string $sitemeta    = 'wp_sitemeta';
	public string $charset     = 'utf8mb4';
	public string $last_error  = '';
	public bool $check_current_query = true;

	public PDO $pdo;

	/** @var string[] */
	public array $log = array();

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT );
	}

	public function exec_sql( string $sql ): void {
		$this->pdo->exec( $sql );
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function suppress_errors( bool $suppress = true ): bool {
		return false;
	}

	/**
	 * @return string[]
	 */
	public function tables( string $scope = 'all', bool $prefix = true, $blog_id = 0 ): array {
		$blog   = array( 'posts', 'comments', 'links', 'options', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'commentmeta' );
		$global = array( 'users', 'usermeta' );
		$list   = 'blog' === $scope ? $blog : ( 'global' === $scope ? $global : array_merge( $blog, $global ) );
		if ( $prefix ) {
			$list = array_map( fn( $t ) => $this->prefix . $t, $list );
		}
		return array_combine( $list, $list );
	}

	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$args = array_values( $args );
		$i    = 0;
		return (string) preg_replace_callback(
			'/%(%|s|d|i|f)/',
			function ( array $m ) use ( &$i, $args ) {
				if ( '%' === $m[1] ) {
					return '%';
				}
				$value = $args[ $i++ ] ?? '';
				switch ( $m[1] ) {
					case 's':
						return "'" . str_replace( "'", "''", (string) $value ) . "'";
					case 'd':
						return (string) (int) $value;
					case 'f':
						return (string) (float) $value;
					default:
						return '`' . str_replace( '`', '``', (string) $value ) . '`';
				}
			},
			$query
		);
	}

	/**
	 * @return int|false
	 */
	public function query( string $sql ) {
		$this->log[]      = $sql;
		$this->last_error = '';
		$sql              = $this->translate( $sql );
		if ( preg_match( '/^UPDATE\s+(`[^`]+`)\s+SET\s+(.*?)\s+WHERE\s+(.*)\s+LIMIT 1$/s', $sql, $m ) ) {
			$sql = "UPDATE {$m[1]} SET {$m[2]} WHERE rowid = (SELECT rowid FROM {$m[1]} WHERE {$m[3]} LIMIT 1)";
		}
		$result = $this->pdo->exec( $sql );
		if ( false === $result ) {
			$this->last_error = (string) ( $this->pdo->errorInfo()[2] ?? 'error' );
			return false;
		}
		return $result;
	}

	public function insert( string $table, array $data, array $format = array() ) {
		$columns = implode( ', ', array_map( fn( $c ) => '`' . $c . '`', array_keys( $data ) ) );
		$marks   = implode( ', ', array_fill( 0, count( $data ), '?' ) );
		$stmt    = $this->pdo->prepare( "INSERT INTO `{$table}` ({$columns}) VALUES ({$marks})" );
		return $stmt && $stmt->execute( array_values( $data ) ) ? 1 : false;
	}

	public function get_var( string $sql ) {
		$rows = $this->select( $sql );
		return null === $rows || array() === $rows ? null : ( array_values( $rows[0] )[0] ?? null );
	}

	public function get_col( string $sql ): array {
		return array_map( fn( $r ) => array_values( $r )[0], $this->select( $sql ) ?? array() );
	}

	public function get_row( string $sql, $output = OBJECT ) {
		$rows = $this->select( $sql ) ?? array();
		if ( array() === $rows ) {
			return null;
		}
		return ARRAY_N === $output ? array_values( $rows[0] ) : $rows[0];
	}

	public function get_results( string $sql, $output = OBJECT ): array {
		return $this->select( $sql ) ?? array();
	}

	/**
	 * @return array<int, array<string, mixed>>|null
	 */
	private function select( string $sql ): ?array {
		$this->log[]      = $sql;
		$this->last_error = '';

		if ( preg_match( '/^SHOW TABLES(?: LIKE \'(.*)\')?$/s', $sql, $m ) ) {
			$like = isset( $m[1] ) ? str_replace( "''", "'", $m[1] ) : null;
			$rows = $this->pdo->query( "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name" )->fetchAll( PDO::FETCH_COLUMN );
			$out  = array();
			foreach ( $rows as $name ) {
				if ( null === $like || self::like( $like, $name ) ) {
					$out[] = array( 'Tables_in_db' => $name );
				}
			}
			return $out;
		}
		if ( preg_match( '/^SHOW TABLE STATUS LIKE \'(.*)\'$/s', $sql, $m ) ) {
			$out = array();
			foreach ( $this->select( "SHOW TABLES LIKE '{$m[1]}'" ) as $row ) {
				$name  = array_values( $row )[0];
				$out[] = array(
					'Name'         => $name,
					'Engine'       => 'InnoDB',
					'Rows'         => (int) $this->pdo->query( "SELECT COUNT(*) FROM `{$name}`" )->fetchColumn(),
					'Data_length'  => 16384,
					'Index_length' => 0,
				);
			}
			return $out;
		}
		if ( preg_match( '/^SHOW (?:FULL )?COLUMNS FROM `(.+)`$/s', $sql, $m ) ) {
			$out = array();
			foreach ( $this->pdo->query( 'PRAGMA table_info(`' . $m[1] . '`)' )->fetchAll( PDO::FETCH_ASSOC ) as $col ) {
				$out[] = array(
					'Field' => $col['name'],
					'Type'  => strtolower( $col['type'] ),
					'Null'  => $col['notnull'] ? 'NO' : 'YES',
					'Key'   => $col['pk'] ? 'PRI' : '',
					'Extra' => '',
				);
			}
			return $out;
		}
		if ( preg_match( '/^SHOW KEYS FROM `(.+)`$/s', $sql, $m ) ) {
			$out  = array();
			$info = $this->pdo->query( 'PRAGMA table_info(`' . $m[1] . '`)' )->fetchAll( PDO::FETCH_ASSOC );
			$pk   = array_filter( $info, fn( $c ) => $c['pk'] > 0 );
			usort( $pk, fn( $a, $b ) => $a['pk'] <=> $b['pk'] );
			foreach ( $pk as $col ) {
				$out[] = array(
					'Non_unique'   => '0',
					'Key_name'     => 'PRIMARY',
					'Seq_in_index' => (string) $col['pk'],
					'Column_name'  => $col['name'],
				);
			}
			foreach ( $this->pdo->query( 'PRAGMA index_list(`' . $m[1] . '`)' )->fetchAll( PDO::FETCH_ASSOC ) as $index ) {
				foreach ( $this->pdo->query( 'PRAGMA index_info(`' . $index['name'] . '`)' )->fetchAll( PDO::FETCH_ASSOC ) as $col ) {
					$out[] = array(
						'Non_unique'   => $index['unique'] ? '0' : '1',
						'Key_name'     => $index['name'],
						'Seq_in_index' => (string) ( $col['seqno'] + 1 ),
						'Column_name'  => $col['name'],
					);
				}
			}
			return $out;
		}

		$stmt = $this->pdo->query( $this->translate( $sql ) );
		if ( false === $stmt ) {
			$this->last_error = (string) ( $this->pdo->errorInfo()[2] ?? 'error' );
			return null;
		}
		return $stmt->fetchAll( PDO::FETCH_ASSOC );
	}

	private function translate( string $sql ): string {
		return str_replace( ' BINARY ', ' ', $sql );
	}

	private static function like( string $pattern, string $value ): bool {
		$regex = '';
		$len   = strlen( $pattern );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $pattern[ $i ];
			if ( '\\' === $c && $i + 1 < $len ) {
				$regex .= preg_quote( $pattern[ ++$i ], '/' );
			} elseif ( '%' === $c ) {
				$regex .= '.*';
			} elseif ( '_' === $c ) {
				$regex .= '.';
			} else {
				$regex .= preg_quote( $c, '/' );
			}
		}
		return 1 === preg_match( '/^' . $regex . '$/s', $value );
	}
}
