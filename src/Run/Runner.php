<?php

namespace Swapline\Run;

use Swapline\Database\Table;
use Swapline\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a search/replace job one batch at a time. A job is a plain array so
 * the admin can persist it between AJAX requests and WP-CLI can loop over it.
 */
final class Runner {

	public const JOB_OPTION = 'swapline_job';

	/**
	 * The plugin's own state holds search strings and diffs, so it is never rewritten.
	 */
	public const OWN_OPTIONS = array( 'swapline_runs', 'swapline_profiles', 'swapline_job', 'swapline_db_version', 'swapline_backup_job', 'swapline_import_job', 'swapline_backups', 'swapline_backup_dir', 'swapline_backup_retention' );

	/**
	 * @param array<string, mixed> $config       Validated configuration.
	 * @param int|null             $record_limit Most changed cells to record, or null for the history setting.
	 * @return array<string, mixed>
	 */
	public static function start( array $config, string $source, ?int $record_limit = null ): array {
		$job = array(
			'id'          => wp_generate_uuid4(),
			'started'     => time(),
			'user_id'     => get_current_user_id(),
			'source'      => $source,
			'config'      => $config,
			'table_index' => 0,
			'cursor'      => null,
			'offset'      => 0,
			'table_rows'  => null,
			'recorded'    => 0,
			'record_cap'  => min( $record_limit ?? PHP_INT_MAX, History::max_recorded_changes( (bool) $config['dry_run'] ) ),
			'done'        => false,
			'tables'      => array(),
		);

		foreach ( $config['tables'] as $name ) {
			$job['tables'][ $name ] = self::empty_counts();
		}

		History::prune( array_merge( array_column( History::runs(), 'id' ), array( $job['id'] ) ) );

		do_action( 'swapline_before_run', $job );

		return $job;
	}

	/**
	 * Processes one batch and advances the job.
	 *
	 * @param array<string, mixed> $job
	 * @return array<string, mixed>
	 */
	public static function step( array $job ): array {
		$config = $job['config'];
		$tables = $config['tables'];

		if ( $job['done'] || ! isset( $tables[ $job['table_index'] ] ) ) {
			$job['done'] = true;
			return $job;
		}

		$name  = $tables[ $job['table_index'] ];
		$table = new Table( $name );

		if ( null === $job['table_rows'] ) {
			$job['table_rows'] = $table->count_rows();
		}

		$columns = $table->text_columns( (bool) $config['skip_guid'], (array) ( $config['columns'] ?? array() ), (array) ( $config['skip_columns'] ?? array() ) );
		$limit   = (int) $config['page_size'];
		$rows    = array() === $columns ? array() : $table->fetch( $columns, Table::KEY_NONE === $table->key_type ? $job['offset'] : $job['cursor'], $limit, (bool) $config['dry_run'] );

		$engine = Cells::engine( $config );
		$counts = $job['tables'][ $name ];
		$cap    = (int) ( $job['record_cap'] ?? History::max_recorded_changes() );

		$own = self::own_rows_column( $name );

		foreach ( $rows as $row ) {
			++$counts['rows_scanned'];
			if ( null !== $own && in_array( $row[ $own ] ?? null, self::OWN_OPTIONS, true ) ) {
				continue;
			}
			$changes      = array();
			$replacements = array();

			foreach ( $columns as $column ) {
				$value = $row[ $column ] ?? null;
				if ( null === $value ) {
					continue;
				}
				$result              = $engine->replace( (string) $value );
				$counts['failures'] += $result->failures;
				if ( $result->replacements > 0 ) {
					++$counts['cells_matched'];
					$counts['replacements'] += $result->replacements;
				}
				if ( $result->changed ) {
					$changes[ $column ]      = $result->value;
					$replacements[ $column ] = $result->replacements;
				}
			}

			if ( array() !== $changes ) {
				++$counts['rows_matched'];
				$written = true;
				if ( ! $config['dry_run'] ) {
					$written = $table->update( $row, $changes );
					if ( ! $written ) {
						++$counts['rows_not_written'];
					}
				}
				if ( $written ) {
					foreach ( $changes as $column => $new ) {
						++$counts['cells_changed'];
						if ( $job['recorded'] < $cap && ! History::is_sensitive( $name, $row, $column ) && History::record( $job['id'], $name, $table->identify( $row ), $column, (string) $row[ $column ], $new, $replacements[ $column ] ) ) {
							++$job['recorded'];
						}
					}
				}
			}
		}

		$job['tables'][ $name ] = $counts;
		$job['offset']         += count( $rows );

		if ( array() !== $rows ) {
			$job['cursor'] = $table->cursor_after( end( $rows ), $job['offset'] );
		}

		if ( count( $rows ) < $limit ) {
			++$job['table_index'];
			$job['cursor']     = null;
			$job['offset']     = 0;
			$job['table_rows'] = null;
			$job['done']       = ! isset( $tables[ $job['table_index'] ] );
		}

		return $job;
	}

	/**
	 * Stores the run summary in the history.
	 *
	 * @param array<string, mixed> $job
	 * @return array<string, mixed> The run summary.
	 */
	public static function finish( array $job, bool $cancelled = false ): array {
		$run             = self::summary( $job, $cancelled ? 'cancelled' : 'complete' );
		$run['finished'] = time();

		History::add( $run );
		do_action( 'swapline_after_run', $run );

		return $run;
	}

	/**
	 * Lists a job in the history while it runs, so its recorded cells survive and an interrupted
	 * run can be found again.
	 *
	 * @param array<string, mixed> $job
	 */
	public static function checkpoint( array $job ): void {
		History::add( self::summary( $job, 'running' ) );
	}

	/**
	 * @param array<string, mixed> $job
	 * @return array<string, mixed>
	 */
	private static function summary( array $job, string $status ): array {
		$totals = self::empty_counts();
		foreach ( $job['tables'] as $counts ) {
			foreach ( $totals as $key => $value ) {
				$totals[ $key ] = $value + (int) $counts[ $key ];
			}
		}

		$user = get_userdata( (int) $job['user_id'] );
		return array(
			'id'        => $job['id'],
			'started'   => $job['started'],
			'finished'  => 0,
			'user'      => $user ? $user->user_login : '',
			'source'    => $job['source'],
			'status'    => $status,
			'config'    => Config::profile_fields( $job['config'] ),
			'tables'    => $job['tables'],
			'totals'    => $totals,
			'recorded'  => $job['recorded'],
			'backup'    => $job['backup'] ?? null,
			'truncated' => $totals['cells_changed'] > $job['recorded'],
		);
	}

	/**
	 * @param array<string, mixed> $job
	 * @return array<string, mixed>
	 */
	public static function progress( array $job ): array {
		$tables = $job['config']['tables'];
		$total  = count( $tables );
		$index  = min( (int) $job['table_index'], $total );
		$rows   = (int) $job['table_rows'];
		$within = $rows > 0 ? min( 1, $job['offset'] / $rows ) : 0;

		return array(
			'id'          => $job['id'],
			'done'        => (bool) $job['done'],
			'table'       => $tables[ $index ] ?? '',
			'table_index' => $index,
			'table_count' => $total,
			'rows_done'   => (int) $job['offset'],
			'rows_total'  => $rows,
			'percent'     => $total > 0 ? round( ( ( $index + $within ) / $total ) * 100, 1 ) : 100,
		);
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

	public static function clear(): void {
		Store::delete( self::JOB_OPTION );
	}

	/**
	 * @param array<string, string|null> $row
	 */
	public static function is_own_row( string $table, array $row ): bool {
		$column = self::own_rows_column( $table );
		return null !== $column && in_array( $row[ $column ] ?? null, self::OWN_OPTIONS, true );
	}

	/**
	 * Column naming the option in options and sitemeta tables, where the plugin keeps its state.
	 */
	private static function own_rows_column( string $table ): ?string {
		global $wpdb;

		if ( $table === $wpdb->base_prefix . 'sitemeta' ) {
			return 'meta_key';
		}
		$pattern = '/^' . preg_quote( $wpdb->base_prefix, '/' ) . '([0-9]+_)?options$/';
		return preg_match( $pattern, $table ) ? 'option_name' : null;
	}

	/**
	 * @return array<string, int>
	 */
	private static function empty_counts(): array {
		return array(
			'rows_scanned'     => 0,
			'rows_matched'     => 0,
			'cells_matched'    => 0,
			'cells_changed'    => 0,
			'replacements'     => 0,
			'failures'         => 0,
			'rows_not_written' => 0,
		);
	}
}
