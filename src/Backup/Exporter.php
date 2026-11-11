<?php
// phpcs:disable WordPress.WP.AlternativeFunctions -- streams gzip output in the plugin's private folder; WP_Filesystem has no streaming API.

namespace Swapline\Backup;

use Swapline\Clock;
use Swapline\Database\Table;
use Swapline\Database\Tables;
use Swapline\Run\Cells;
use Swapline\Run\Runner;
use Swapline\Store;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Writes tables to a gzipped SQL file a batch at a time, optionally running
 * a search and replace on the exported copy. The live tables are only read.
 */
final class Exporter {

	public const JOB_OPTION = 'swapline_backup_job';

	private const STATEMENT_BYTES = 1048576;
	private const PAGE_SIZE       = 1000;

	/**
	 * @param string[]                  $tables  Validated table names.
	 * @param array<string, mixed>|null $replace Validated Config to apply to the copy, or null.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function start( array $tables, ?array $replace, string $source ) {
		global $wpdb;

		$dir = Storage::dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$previous = self::current();
		if ( null !== $previous ) {
			if ( Clock::is_recent( $previous ) && ( 'admin' !== $source || (int) $previous['user_id'] !== get_current_user_id() ) ) {
				return new WP_Error( 'swapline_busy', __( 'Another backup is running. Wait for it to finish, or try again in a few minutes.', 'swapline' ) );
			}
			self::discard( $previous );
		}

		$name = Storage::new_name( 'backup' );
		$job  = array(
			'id'         => wp_generate_uuid4(),
			'file'       => $name,
			'started'    => time(),
			'user_id'    => get_current_user_id(),
			'source'     => $source,
			'tables'     => array_values( $tables ),
			'replace'    => $replace,
			'index'      => 0,
			'schema'     => false,
			'cursor'     => null,
			'offset'     => 0,
			'table_rows' => 0,
			'rows'       => 0,
			'changed'    => 0,
			'failures'   => 0,
			'done'       => false,
			'error'      => null,
		);

		$charset = preg_match( '/^[a-z0-9_]+$/', (string) $wpdb->charset ) ? $wpdb->charset : 'utf8mb4';
		$header  = '-- Swapline ' . SWAPLINE_VERSION . " backup\n"
			. '-- Created ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n"
			. ( null !== $replace ? "-- A search and replace was applied to this copy.\n" : '' )
			. "\nSET NAMES {$charset};\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";

		$handle = gzopen( self::part( $dir, $name ), 'wb6' );
		if ( false === $handle || false === gzwrite( $handle, $header ) ) {
			return new WP_Error( 'swapline_backup_write', __( 'The backup file could not be written. Check the free disk space.', 'swapline' ) );
		}
		gzclose( $handle );

		return $job;
	}

	/**
	 * Writes until the deadline or the end of the last table.
	 *
	 * @param array<string, mixed> $job
	 * @return array<string, mixed>
	 */
	public static function step( array $job, float $deadline ): array {
		global $wpdb;

		$dir = Storage::dir();
		if ( is_wp_error( $dir ) ) {
			return self::fail( $job, $dir->get_error_message() );
		}

		$handle = gzopen( self::part( $dir, $job['file'] ), 'ab6' );
		if ( false === $handle ) {
			return self::fail( $job, __( 'The backup file could not be opened for writing.', 'swapline' ) );
		}

		$engine = null === $job['replace'] ? null : Cells::engine( $job['replace'] );
		$tables = array();

		do {
			$name = $job['tables'][ $job['index'] ] ?? null;
			if ( null === $name ) {
				$job['done'] = true;
				break;
			}

			if ( ! isset( $tables[ $name ] ) ) {
				$tables[ $name ] = new Table( $name );
			}
			$table = $tables[ $name ];

			if ( ! $job['schema'] ) {
				$quoted = Tables::quote( $name );
				$create = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $name ), ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- live schema read for the dump.
				if ( ! is_array( $create ) || empty( $create[1] ) ) {
					gzclose( $handle );
					/* translators: %s: table name. */
					return self::fail( $job, sprintf( __( 'Could not read the structure of %s.', 'swapline' ), $name ) );
				}
				$sql = "\n--\n-- Table {$quoted}\n--\n\nDROP TABLE IF EXISTS {$quoted};\n" . $create[1] . ";\n\n";
				if ( ! self::write( $handle, $sql ) ) {
					gzclose( $handle );
					return self::fail( $job, __( 'The backup file could not be written. Check the free disk space.', 'swapline' ) );
				}
				$job['schema']     = true;
				$job['table_rows'] = $table->count_rows();
				continue;
			}

			$columns = array_values( array_diff( array_keys( $table->columns ), $table->generated ) );
			$cursor  = Table::KEY_NONE === $table->key_type ? $job['offset'] : $job['cursor'];
			$rows    = array() === $columns ? array() : $table->fetch( $columns, $cursor, self::PAGE_SIZE );
			$text    = null === $engine ? array() : $table->text_columns( (bool) $job['replace']['skip_guid'] );
			$kinds   = array();
			foreach ( $columns as $column ) {
				$kinds[ $column ] = SqlWriter::kind( $table->columns[ $column ] );
			}

			$tuples = array();
			$bytes  = 0;
			$ok     = true;
			foreach ( $rows as $row ) {
				if ( Runner::is_own_row( $name, $row ) ) {
					continue;
				}
				if ( null !== $engine ) {
					$result           = Cells::replace( $engine, $text, $row );
					$job['failures'] += $result['failures'];
					if ( array() !== $result['changes'] ) {
						++$job['changed'];
						$row = array_merge( $row, $result['changes'] );
					}
				}
				$tuple = array();
				foreach ( $columns as $column ) {
					$tuple[] = SqlWriter::value( $row[ $column ], $kinds[ $column ] );
				}
				$tuples[] = $tuple;
				$bytes   += array_sum( array_map( 'strlen', $tuple ) );
				++$job['rows'];

				if ( $bytes >= self::STATEMENT_BYTES ) {
					$ok     = $ok && self::write( $handle, SqlWriter::insert( $name, $columns, $tuples ) );
					$tuples = array();
					$bytes  = 0;
				}
			}
			if ( array() !== $tuples ) {
				$ok = $ok && self::write( $handle, SqlWriter::insert( $name, $columns, $tuples ) );
			}
			if ( ! $ok ) {
				gzclose( $handle );
				return self::fail( $job, __( 'The backup file could not be written. Check the free disk space.', 'swapline' ) );
			}

			$job['offset'] += count( $rows );
			if ( array() !== $rows ) {
				$job['cursor'] = $table->cursor_after( end( $rows ), $job['offset'] );
			}
			if ( count( $rows ) < self::PAGE_SIZE ) {
				++$job['index'];
				$job['schema'] = false;
				$job['cursor'] = null;
				$job['offset'] = 0;
			}
		} while ( microtime( true ) < $deadline );

		gzclose( $handle );

		if ( $job['done'] ) {
			return self::finish( $job, $dir );
		}
		return $job;
	}

	/**
	 * @param array<string, mixed> $job
	 * @return array<string, mixed>
	 */
	public static function progress( array $job ): array {
		$total  = count( $job['tables'] );
		$index  = min( (int) $job['index'], $total );
		$rows   = (int) $job['table_rows'];
		$within = $rows > 0 ? min( 1, $job['offset'] / $rows ) : 0;

		return array(
			'id'          => $job['id'],
			'done'        => (bool) $job['done'],
			'error'       => $job['error'],
			'table'       => $job['tables'][ $index ] ?? '',
			'table_index' => $index,
			'table_count' => $total,
			'rows_done'   => (int) $job['offset'],
			'rows_total'  => $rows,
			'percent'     => $total > 0 ? round( ( ( $index + $within ) / $total ) * 100, 1 ) : 100,
		);
	}

	/**
	 * Runs a whole backup in one go, for WP-CLI.
	 *
	 * @param string[]                  $tables
	 * @param array<string, mixed>|null $replace
	 * @param callable|null             $tick    Called with the job after each step.
	 * @return array<string, mixed>|WP_Error The finished job.
	 */
	public static function run( array $tables, ?array $replace, string $source, ?callable $tick = null ) {
		$job = self::start( $tables, $replace, $source );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		self::save( $job );
		while ( ! $job['done'] && null === $job['error'] ) {
			$job = self::step( $job, microtime( true ) + 5 );
			self::save( $job );
			if ( null !== $tick ) {
				$tick( $job );
			}
		}
		Store::delete( self::JOB_OPTION );
		if ( null !== $job['error'] ) {
			return new WP_Error( 'swapline_backup', (string) $job['error'] );
		}
		return $job;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function current(): ?array {
		$job = Store::get( self::JOB_OPTION );
		return is_array( $job ) ? $job : null;
	}

	/**
	 * @param array<string, mixed> $job
	 */
	public static function save( array $job ): void {
		$job['touched'] = time();
		Store::set( self::JOB_OPTION, $job );
	}

	/**
	 * Abandons a job and removes its partial file.
	 *
	 * @param array<string, mixed> $job
	 */
	public static function discard( array $job ): void {
		$dir = Storage::dir();
		if ( ! is_wp_error( $dir ) ) {
			Storage::remove( self::part( $dir, (string) $job['file'] ) );
		}
		Store::delete( self::JOB_OPTION );
	}

	public static function path( array $job ): string {
		$dir = Storage::dir();
		return is_wp_error( $dir ) ? '' : $dir . '/' . $job['file'];
	}

	/**
	 * @param array<string, mixed> $job
	 * @return array<string, mixed>
	 */
	private static function finish( array $job, string $dir ): array {
		$part = self::part( $dir, $job['file'] );
		if ( ! rename( $part, $dir . '/' . $job['file'] ) ) {
			return self::fail( $job, __( 'The finished backup could not be saved.', 'swapline' ) );
		}

		$user = get_userdata( (int) $job['user_id'] );
		Storage::record(
			$job['file'],
			array(
				'created' => (int) $job['started'],
				'tables'  => count( $job['tables'] ),
				'rows'    => (int) $job['rows'],
				'replace' => null === $job['replace'] ? null : array(
					'search'  => (string) $job['replace']['search'],
					'replace' => (string) $job['replace']['replace'],
				),
				'user'    => $user ? $user->user_login : '',
			)
		);
		Storage::apply_retention();

		return $job;
	}

	/**
	 * @param array<string, mixed> $job
	 * @return array<string, mixed>
	 */
	private static function fail( array $job, string $message ): array {
		$dir = Storage::dir();
		if ( ! is_wp_error( $dir ) ) {
			Storage::remove( self::part( $dir, (string) $job['file'] ) );
		}
		$job['error'] = $message;
		$job['done']  = true;
		return $job;
	}

	/**
	 * @param resource $handle
	 */
	private static function write( $handle, string $data ): bool {
		return strlen( $data ) === gzwrite( $handle, $data );
	}

	private static function part( string $dir, string $name ): string {
		return $dir . '/' . $name . '.part';
	}
}
