<?php
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.DB.DirectDatabaseQuery -- streams files in the plugin's private folder and runs validated SQL against temporary tables.

namespace Swapline\Import;

use Swapline\Backup\Storage;
use Swapline\Database\Table;
use Swapline\Database\Tables;
use Swapline\Run\Cells;
use Swapline\Run\History;
use Swapline\Run\Runner;
use Swapline\Store;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Imports a SQL file through temporary tables.
 *
 * Phases: inflate (gzip only), scan (every statement is checked before
 * anything runs), load (tables are created under temporary names), replace
 * (optional search and replace on the temporary tables) and swap (one atomic
 * RENAME TABLE). Any failure drops the temporary tables, so the live
 * database is only touched by the final rename.
 */
final class Importer {

	public const JOB_OPTION = 'swapline_import_job';

	private const TEMP   = 'swapline_tmp_';
	private const OLD    = 'swapline_old_';
	private const CHUNK  = 1048576;
	private const LISTED = 20;

	/**
	 * @param string                    $path     Absolute path of the .sql or .sql.gz file.
	 * @param bool                      $owned    Whether the file is a temporary upload to delete afterwards.
	 * @param array<string, mixed>|null $replace  Validated Config to run on the imported tables, or null.
	 * @param bool                      $preserve Keep the current user logged in and this site's siteurl and home.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function start( string $path, bool $owned, ?array $replace, bool $preserve, string $source ) {
		$previous = self::current();
		if ( null !== $previous ) {
			self::cleanup( $previous );
			Store::delete( self::JOB_OPTION );
		}
		self::drop_leftovers();

		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			return new WP_Error( 'swapline_import_read', __( 'The file could not be read.', 'swapline' ) );
		}
		$magic = (string) fread( $handle, 2 );
		fclose( $handle );

		$id      = bin2hex( random_bytes( 4 ) );
		$gzipped = "\x1f\x8b" === $magic;
		$work    = $path;
		if ( $gzipped ) {
			$dir = Storage::dir();
			if ( is_wp_error( $dir ) ) {
				return $dir;
			}
			$work = $dir . '/import-' . $id . '.sql';
		}

		return array(
			'id'       => $id,
			'started'  => time(),
			'user_id'  => get_current_user_id(),
			'source'   => $source,
			'file'     => $path,
			'owned'    => $owned,
			'work'     => $work,
			'phase'    => $gzipped ? 'inflate' : 'scan',
			'offset'   => 0,
			'size'     => (int) filesize( $path ),
			'tables'   => array(),
			'sets'     => array(),
			'counts'   => array(
				'statements' => 0,
				'rejected'   => 0,
				'executed'   => 0,
				'skipped'    => 0,
				'rows'       => 0,
				'changed'    => 0,
				'failures'   => 0,
			),
			'rejected' => array(),
			'replace'  => $replace,
			'cursor'   => array(
				'index'  => 0,
				'cursor' => null,
				'offset' => 0,
			),
			'preserve' => $preserve,
			'done'     => false,
			'error'    => null,
		);
	}

	/**
	 * Works on the current phase until the deadline.
	 *
	 * @param array<string, mixed> $job
	 * @return array<string, mixed>
	 */
	public static function step( array $job, float $deadline ): array {
		global $wpdb;

		if ( $job['done'] ) {
			return $job;
		}

		$suppressed = $wpdb->suppress_errors( true );
		try {
			switch ( $job['phase'] ) {
				case 'inflate':
					$job = self::inflate( $job, $deadline );
					break;
				case 'scan':
					$job = self::scan( $job, $deadline );
					break;
				case 'load':
					$job = self::load( $job, $deadline );
					break;
				case 'replace':
					$job = self::replace( $job, $deadline );
					break;
				case 'swap':
					$job = self::swap( $job );
					break;
			}
		} catch ( ImportException $e ) {
			$job = self::fail( $job, $e->getMessage() );
		}
		$wpdb->suppress_errors( $suppressed );

		return $job;
	}

	/**
	 * Abandons the import and removes everything it created.
	 *
	 * @param array<string, mixed> $job
	 * @return array<string, mixed>
	 */
	public static function fail( array $job, string $message ): array {
		self::cleanup( $job );
		$job['error'] = $message;
		$job['done']  = true;
		return $job;
	}

	/**
	 * @param array<string, mixed> $job
	 * @return array<string, mixed>
	 */
	public static function progress( array $job ): array {
		$phases = array(
			'inflate' => array( 0, 5 ),
			'scan'    => array( 5, 15 ),
			'load'    => array( 20, null === $job['replace'] ? 75 : 50 ),
			'replace' => array( 70, 25 ),
			'swap'    => array( 95, 5 ),
		);
		$phase  = $phases[ $job['phase'] ] ?? array( 100, 0 );
		$within = 0.0;
		if ( in_array( $job['phase'], array( 'inflate', 'scan', 'load' ), true ) && $job['size'] > 0 ) {
			$within = min( 1, $job['offset'] / $job['size'] );
		} elseif ( 'replace' === $job['phase'] && array() !== $job['tables'] ) {
			$within = min( 1, $job['cursor']['index'] / count( $job['tables'] ) );
		}

		return array(
			'id'       => $job['id'],
			'phase'    => $job['phase'],
			'done'     => (bool) $job['done'],
			'error'    => $job['error'],
			'percent'  => $job['done'] && null === $job['error'] ? 100 : round( $phase[0] + $phase[1] * $within, 1 ),
			'tables'   => array_keys( $job['tables'] ),
			'counts'   => $job['counts'],
			'rejected' => $job['rejected'],
		);
	}

	/**
	 * Runs a whole import in one go, for WP-CLI.
	 *
	 * @param array<string, mixed> $job
	 * @param callable|null        $tick
	 * @return array<string, mixed>
	 */
	public static function run( array $job, ?callable $tick = null ): array {
		while ( ! $job['done'] ) {
			$job = self::step( $job, microtime( true ) + 5 );
			if ( null !== $tick ) {
				$tick( $job );
			}
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
		Store::set( self::JOB_OPTION, $job );
	}

	public static function clear(): void {
		Store::delete( self::JOB_OPTION );
	}

	/**
	 * Drops temporary tables left by imports that never finished.
	 */
	public static function drop_leftovers(): void {
		global $wpdb;

		foreach ( array( self::TEMP, self::OLD ) as $prefix ) {
			$names = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
			foreach ( $names as $name ) {
				if ( preg_match( '/^' . $prefix . '[a-f0-9]{8}_\d+$/', $name ) ) {
					$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- name matched against the plugin's own pattern.
				}
			}
		}
	}

	/**
	 * @param array<string, mixed> $job
	 * @return array<string, mixed>
	 */
	private static function inflate( array $job, float $deadline ): array {
		$in = gzopen( $job['file'], 'rb' );
		if ( false === $in ) {
			self::abort( __( 'The gzip file could not be opened.', 'swapline' ) );
		}
		if ( $job['offset'] > 0 && 0 !== gzseek( $in, (int) $job['offset'] ) ) {
			gzclose( $in );
			self::abort( __( 'The gzip file could not be read.', 'swapline' ) );
		}
		$out = fopen( $job['work'], $job['offset'] > 0 ? 'ab' : 'wb' );
		if ( false === $out ) {
			gzclose( $in );
			self::abort( __( 'The file could not be unpacked. Check the free disk space.', 'swapline' ) );
		}

		$finished = false;
		do {
			$data = gzread( $in, self::CHUNK );
			if ( false === $data ) {
				fclose( $out );
				gzclose( $in );
				self::abort( __( 'The gzip file is damaged.', 'swapline' ) );
			}
			if ( '' === $data ) {
				$finished = true;
				break;
			}
			if ( strlen( $data ) !== fwrite( $out, $data ) ) {
				fclose( $out );
				gzclose( $in );
				self::abort( __( 'The file could not be unpacked. Check the free disk space.', 'swapline' ) );
			}
			$job['offset'] += strlen( $data );
		} while ( microtime( true ) < $deadline );

		fclose( $out );
		gzclose( $in );

		if ( $finished ) {
			$job['phase']  = 'scan';
			$job['offset'] = 0;
			$job['size']   = (int) filesize( $job['work'] );
		}
		return $job;
	}

	/**
	 * Checks every statement and maps each created table to a temporary name.
	 *
	 * @param array<string, mixed> $job
	 * @return array<string, mixed>
	 */
	private static function scan( array $job, float $deadline ): array {
		$own = History::table();

		$job = self::each_statement(
			$job,
			$deadline,
			static function ( Statement $statement, array $job ) use ( $own ): array {
				++$job['counts']['statements'];

				$problem = null;
				if ( Statement::REJECT === $statement->type ) {
					$problem = $statement->reason;
				} elseif ( Statement::CREATE === $statement->type && $statement->table !== $own ) {
					if ( isset( $job['tables'][ $statement->table ] ) ) {
						/* translators: %s: table name. */
						$problem = sprintf( __( 'The file creates %s more than once.', 'swapline' ), $statement->table );
					} else {
						$job['tables'][ $statement->table ] = self::TEMP . $job['id'] . '_' . count( $job['tables'] );
					}
				} elseif ( Statement::INSERT === $statement->type && $statement->table !== $own && ! isset( $job['tables'][ $statement->table ] ) ) {
					/* translators: %s: table name. */
					$problem = sprintf( __( 'The file inserts rows into %s before creating it.', 'swapline' ), $statement->table );
				}

				if ( null !== $problem ) {
					if ( count( $job['rejected'] ) < self::LISTED ) {
						$job['rejected'][] = array(
							'statement' => self::excerpt( $statement->sql ),
							'reason'    => $problem,
						);
					}
					++$job['counts']['rejected'];
				}
				return $job;
			}
		);

		if ( 'scan' !== $job['phase'] || ! $job['eof'] ) {
			return $job;
		}
		unset( $job['eof'] );

		if ( ! empty( $job['counts']['rejected'] ) ) {
			return self::fail(
				$job,
				sprintf(
					/* translators: %s: number of statements. */
					_n( 'Nothing was imported: %s statement is not allowed.', 'Nothing was imported: %s statements are not allowed.', (int) $job['counts']['rejected'], 'swapline' ),
					number_format_i18n( (int) $job['counts']['rejected'] )
				)
			);
		}
		if ( array() === $job['tables'] ) {
			return self::fail( $job, __( 'The file does not create any tables, so there is nothing to import.', 'swapline' ) );
		}

		foreach ( $job['tables'] as $table => $temp ) {
			if ( strlen( $table ) > 64 ) {
				/* translators: %s: table name. */
				return self::fail( $job, sprintf( __( 'The table name %s is too long.', 'swapline' ), $table ) );
			}
		}
		$job['phase']  = 'load';
		$job['offset'] = 0;
		return $job;
	}

	/**
	 * Runs the checked statements against the temporary tables.
	 *
	 * @param array<string, mixed> $job
	 * @return array<string, mixed>
	 */
	private static function load( array $job, float $deadline ): array {
		self::safe_session();
		foreach ( $job['sets'] as $set ) {
			self::query( $set );
		}
		self::safe_session();

		$job = self::each_statement(
			$job,
			$deadline,
			static function ( Statement $statement, array $job ): array {
				$temp = $job['tables'][ $statement->table ] ?? null;

				switch ( $statement->type ) {
					case Statement::REJECT:
						self::abort( $statement->reason );
						break;
					case Statement::CREATE:
					case Statement::INSERT:
						if ( null === $temp ) {
							++$job['counts']['skipped'];
							break;
						}
						$rows = self::query( $statement->with_table_name( $temp ) );
						++$job['counts']['executed'];
						if ( Statement::INSERT === $statement->type ) {
							$job['counts']['rows'] += $rows;
						}
						break;
					case Statement::SET:
						if ( $statement->execute ) {
							self::query( $statement->sql );
							self::safe_session();
							$job['sets'][] = $statement->sql;
							++$job['counts']['executed'];
						} else {
							++$job['counts']['skipped'];
						}
						break;
					case Statement::DROP:
					case Statement::SKIP:
						++$job['counts']['skipped'];
						break;
				}
				return $job;
			}
		);

		if ( 'load' !== $job['phase'] || ! $job['eof'] ) {
			return $job;
		}
		unset( $job['eof'] );

		$job['phase'] = null === $job['replace'] ? 'swap' : 'replace';
		return $job;
	}

	/**
	 * @param array<string, mixed> $job
	 * @return array<string, mixed>
	 */
	private static function each_statement( array $job, float $deadline, callable $handle ): array {
		$file = fopen( $job['work'], 'rb' );
		if ( false === $file ) {
			self::abort( __( 'The file could not be read.', 'swapline' ) );
		}
		fseek( $file, (int) $job['offset'] );

		$splitter   = new Splitter( $file, self::CHUNK );
		$prefix     = $GLOBALS['wpdb']->base_prefix;
		$job['eof'] = false;

		do {
			$raw = $splitter->next();
			if ( null === $raw ) {
				$job['eof'] = true;
				break;
			}
			$job           = $handle( Statement::parse( $raw, $prefix ), $job );
			$job['offset'] = $splitter->offset();
		} while ( microtime( true ) < $deadline );

		fclose( $file );
		return $job;
	}

	/**
	 * Runs the search and replace on the temporary tables.
	 *
	 * @param array<string, mixed> $job
	 * @return array<string, mixed>
	 */
	private static function replace( array $job, float $deadline ): array {
		$engine  = Cells::engine( $job['replace'] );
		$logical = array_keys( $job['tables'] );
		$state   = $job['cursor'];
		$limit   = (int) $job['replace']['page_size'];

		do {
			$name = $logical[ $state['index'] ] ?? null;
			if ( null === $name ) {
				$job['phase'] = 'swap';
				break;
			}

			$table   = new Table( $job['tables'][ $name ] );
			$columns = $table->text_columns( (bool) $job['replace']['skip_guid'] );
			$rows    = array() === $columns ? array() : $table->fetch( $columns, Table::KEY_NONE === $table->key_type ? $state['offset'] : $state['cursor'], $limit );

			foreach ( $rows as $row ) {
				if ( Runner::is_own_row( $name, $row ) ) {
					continue;
				}
				$result                     = Cells::replace( $engine, $columns, $row );
				$job['counts']['failures'] += $result['failures'];
				if ( array() !== $result['changes'] ) {
					if ( ! $table->update( $row, $result['changes'] ) ) {
						self::abort( self::db_error( __( 'A replaced value could not be written.', 'swapline' ) ) );
					}
					++$job['counts']['changed'];
				}
			}

			$state['offset'] += count( $rows );
			if ( array() !== $rows ) {
				$state['cursor'] = $table->cursor_after( end( $rows ), $state['offset'] );
			}
			if ( count( $rows ) < $limit ) {
				++$state['index'];
				$state['cursor'] = null;
				$state['offset'] = 0;
			}
		} while ( microtime( true ) < $deadline );

		$job['cursor'] = $state;
		return $job;
	}

	/**
	 * Copies the rows that must survive into the temporary tables, then renames them all in one statement.
	 *
	 * @param array<string, mixed> $job
	 * @return array<string, mixed>
	 */
	private static function swap( array $job ): array {
		global $wpdb;

		self::keep_own_state( $job );
		if ( $job['preserve'] ) {
			self::keep_site_address( $job );
			self::keep_user( $job );
		}

		$renames = array();
		$old     = array();
		foreach ( array_keys( $job['tables'] ) as $n => $table ) {
			if ( self::exists( $table ) ) {
				$old[]     = self::OLD . $job['id'] . '_' . $n;
				$renames[] = Tables::quote( $table ) . ' TO ' . Tables::quote( end( $old ) );
			}
			$renames[] = Tables::quote( $job['tables'][ $table ] ) . ' TO ' . Tables::quote( $table );
		}

		self::query( 'RENAME TABLE ' . implode( ', ', $renames ) );

		foreach ( $old as $name ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- the plugin's own temporary name.
		}

		wp_cache_flush();

		$job['tables'] = array_fill_keys( array_keys( $job['tables'] ), '' );
		self::cleanup( $job );
		$job['phase'] = 'done';
		$job['done']  = true;
		return $job;
	}

	/**
	 * The plugin's own options always come from the live site, never from the file.
	 *
	 * @param array<string, mixed> $job
	 */
	private static function keep_own_state( array $job ): void {
		global $wpdb;

		if ( is_multisite() ) {
			self::sync_rows( $job, $wpdb->sitemeta, 'meta_key', Runner::OWN_OPTIONS, array( 'site_id' => get_current_network_id() ) );
			return;
		}
		self::sync_rows( $job, $wpdb->options, 'option_name', Runner::OWN_OPTIONS );
	}

	/**
	 * @param array<string, mixed> $job
	 */
	private static function keep_site_address( array $job ): void {
		global $wpdb;

		$pattern = '/^' . preg_quote( $wpdb->base_prefix, '/' ) . '(?:[0-9]+_)?options$/';
		foreach ( array_keys( $job['tables'] ) as $table ) {
			if ( preg_match( $pattern, $table ) ) {
				self::sync_rows( $job, $table, 'option_name', array( 'siteurl', 'home' ) );
			}
		}
	}

	/**
	 * Keeps the importing user's account, sessions and roles so they stay logged in.
	 *
	 * @param array<string, mixed> $job
	 */
	private static function keep_user( array $job ): void {
		global $wpdb;

		$user_id = (int) $job['user_id'];
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user ) {
			return;
		}

		$users = $job['tables'][ $wpdb->users ] ?? null;
		if ( null !== $users ) {
			self::query( $wpdb->prepare( 'DELETE FROM ' . Tables::quote( $users ) . ' WHERE user_login = %s AND ID <> %d', $user->user_login, $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- temporary table name.
			self::sync_rows( $job, $wpdb->users, 'ID', array( (string) $user_id ) );
		}

		$keys = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE user_id = %d AND ( meta_key = %s OR meta_key LIKE %s OR meta_key LIKE %s )", $user_id, 'session_tokens', '%' . $wpdb->esc_like( 'capabilities' ), '%' . $wpdb->esc_like( 'user_level' ) ) );
		self::sync_rows( $job, $wpdb->usermeta, 'meta_key', $keys, array( 'user_id' => $user_id ) );

		$sitemeta = is_multisite() ? ( $job['tables'][ $wpdb->sitemeta ] ?? null ) : null;
		if ( null === $sitemeta || ! is_super_admin( $user_id ) ) {
			return;
		}
		$quoted  = Tables::quote( $sitemeta );
		$network = get_current_network_id();
		$admins  = $wpdb->get_var( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE meta_key = %s AND site_id = %d', $sitemeta, 'site_admins', $network ) );
		$list    = is_string( $admins ) && is_serialized( $admins ) ? unserialize( $admins, array( 'allowed_classes' => false ) ) : array(); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- objects are refused.
		$list    = is_array( $list ) ? array_values( $list ) : array();
		if ( in_array( $user->user_login, $list, true ) ) {
			return;
		}
		$list[] = $user->user_login;
		$value  = serialize( $list ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- WordPress stores this option serialized.
		if ( null === $admins ) {
			self::query( $wpdb->prepare( "INSERT INTO {$quoted} (site_id, meta_key, meta_value) VALUES (%d, %s, %s)", $network, 'site_admins', $value ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- temporary table name.
			return;
		}
		self::query( $wpdb->prepare( "UPDATE {$quoted} SET meta_value = %s WHERE meta_key = %s AND site_id = %d", $value, 'site_admins', $network ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- temporary table name.
	}

	/**
	 * Makes the rows with the given keys in an imported table match the live table:
	 * updated in place where both have them, added or removed otherwise.
	 *
	 * @param array<string, mixed>      $job
	 * @param string[]                  $keys
	 * @param array<string, int|string> $scope Extra column => value conditions, such as a user ID.
	 */
	private static function sync_rows( array $job, string $table, string $key, array $keys, array $scope = array() ): void {
		global $wpdb;

		$temp = $job['tables'][ $table ] ?? null;
		if ( null === $temp || array() === $keys || ! self::exists( $table ) ) {
			return;
		}

		$live    = Tables::quote( $table );
		$copy    = Tables::quote( $temp );
		$columns = array_diff( self::shared_columns( $table, $temp ), array( $key ), array_keys( $scope ) );
		$insert  = implode( ', ', array_map( array( Tables::class, 'quote' ), array_merge( array( $key ), array_keys( $scope ), $columns ) ) );
		$select  = implode( ', ', array_map( static fn( string $column ): string => 'l.' . Tables::quote( $column ), array_merge( array( $key ), array_keys( $scope ), $columns ) ) );
		$k       = Tables::quote( $key );
		$in      = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		$match   = "t.{$k} = l.{$k}";
		$filter  = "l.{$k} IN ({$in})";
		$t_where = "t.{$k} IN ({$in})";
		$args    = array_values( $keys );
		foreach ( $scope as $column => $value ) {
			$quoted   = Tables::quote( $column );
			$match   .= " AND t.{$quoted} = l.{$quoted}";
			$filter  .= " AND l.{$quoted} = %s";
			$t_where .= " AND t.{$quoted} = %s";
			$args[]   = (string) $value;
		}

		self::query( $wpdb->prepare( "DELETE t FROM {$copy} t WHERE {$t_where} AND NOT EXISTS (SELECT 1 FROM {$live} l WHERE {$match})", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- quoted identifiers; placeholders built from the key list.
		if ( array() !== $columns ) {
			$set = implode( ', ', array_map( static fn( string $column ): string => 't.' . Tables::quote( $column ) . ' = l.' . Tables::quote( $column ), $columns ) );
			self::query( $wpdb->prepare( "UPDATE {$copy} t JOIN {$live} l ON {$match} SET {$set} WHERE {$filter}", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- quoted identifiers; placeholders built from the key list.
		}
		self::query( $wpdb->prepare( "INSERT INTO {$copy} ({$insert}) SELECT {$select} FROM {$live} l WHERE {$filter} AND NOT EXISTS (SELECT 1 FROM {$copy} t WHERE {$match})", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- quoted identifiers; placeholders built from the key list.
	}

	/**
	 * Columns both tables have, except auto-increment and generated ones.
	 *
	 * @return string[]
	 */
	private static function shared_columns( string $live, string $temp ): array {
		global $wpdb;

		$live_columns = (array) $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $live ), ARRAY_A );
		$temp_columns = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $temp ) );
		$shared       = array();

		foreach ( $live_columns as $column ) {
			$extra = (string) $column['Extra'];
			if ( in_array( $column['Field'], $temp_columns, true ) && false === stripos( $extra, 'auto_increment' ) && ! Table::is_generated( $extra ) ) {
				$shared[] = (string) $column['Field'];
			}
		}
		if ( array() === $shared ) {
			self::abort( __( 'An imported table does not match the live table it replaces.', 'swapline' ) );
		}
		return $shared;
	}

	/**
	 * Keeps the session in the modes and character set the statement checks assume,
	 * whatever the server default or an imported SET left behind.
	 */
	private static function safe_session(): void {
		global $wpdb;

		$session = $wpdb->get_row( 'SELECT @@SESSION.sql_mode AS mode, @@SESSION.character_set_client AS client', ARRAY_A );
		if ( ! is_array( $session ) ) {
			self::abort( self::db_error( __( 'The database session could not be checked.', 'swapline' ) ) );
		}
		$mode = (string) $session['mode'];
		$safe = Statement::safe_sql_mode( $mode );
		if ( $safe !== $mode ) {
			self::query( $wpdb->prepare( 'SET SESSION sql_mode = %s', $safe ) );
		}
		if ( ! Statement::safe_charset( (string) $session['client'] ) || 'default' === strtolower( (string) $session['client'] ) ) {
			self::abort( __( 'The database connection uses a character set that imports do not support.', 'swapline' ) );
		}
	}

	private static function exists( string $table ): bool {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * @return int Rows affected.
	 */
	private static function query( string $sql ): int {
		global $wpdb;

		$wpdb->check_current_query = false;
		$result                    = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- validated statement or a query built from quoted identifiers and placeholders.
		if ( false === $result ) {
			self::abort( self::db_error( __( 'A statement failed.', 'swapline' ) ) );
		}
		return (int) $result;
	}

	private static function db_error( string $fallback ): string {
		global $wpdb;
		if ( '' === (string) $wpdb->last_error ) {
			return $fallback;
		}
		/* translators: %s: the database error. */
		return sprintf( __( 'The database reported an error: %s', 'swapline' ), $wpdb->last_error );
	}

	/**
	 * Drops the job's temporary tables and deletes its working files.
	 *
	 * @param array<string, mixed> $job
	 */
	private static function cleanup( array $job ): void {
		global $wpdb;

		foreach ( (array) $job['tables'] as $temp ) {
			if ( '' !== $temp ) {
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $temp ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- the plugin's own temporary name.
			}
		}
		if ( $job['work'] !== $job['file'] ) {
			Storage::remove( (string) $job['work'] );
		}
		if ( $job['owned'] ) {
			Storage::remove( (string) $job['file'] );
		}
	}

	private static function abort( string $message ): void {
		throw new ImportException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- reported as text, never as HTML.
	}

	private static function excerpt( string $sql ): string {
		$sql = (string) preg_replace( '/\s+/', ' ', $sql );
		return strlen( $sql ) > 160 ? mb_strcut( $sql, 0, 157, 'UTF-8' ) . '…' : $sql;
	}
}
