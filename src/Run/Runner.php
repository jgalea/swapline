<?php

namespace Swapline\Run;

use Swapline\Database\Table;
use Swapline\Engine\Replacer;
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
	 * @param array<string, mixed> $config Validated configuration.
	 * @return array<string, mixed>
	 */
	public static function start( array $config, string $source ): array {
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

		$columns = $table->text_columns( (bool) $config['skip_guid'] );
		$limit   = (int) $config['page_size'];
		$rows    = array() === $columns ? array() : $table->fetch( $columns, Table::KEY_NONE === $table->key_type ? $job['offset'] : $job['cursor'], $limit );

		$engine = new Replacer( (string) $config['search'], (string) $config['replace'], (bool) $config['case_insensitive'], (bool) $config['regex'] );
		$counts = $job['tables'][ $name ];
		$cap    = History::max_recorded_changes();

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
						if ( $job['recorded'] < $cap && History::record( $job['id'], $name, $table->identify( $row ), $column, (string) $row[ $column ], $new, $replacements[ $column ] ) ) {
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
		$totals = self::empty_counts();
		foreach ( $job['tables'] as $counts ) {
			foreach ( $totals as $key => $value ) {
				$totals[ $key ] = $value + (int) $counts[ $key ];
			}
		}

		$user = get_userdata( (int) $job['user_id'] );
		$run  = array(
			'id'        => $job['id'],
			'started'   => $job['started'],
			'finished'  => time(),
			'user'      => $user ? $user->user_login : '',
			'source'    => $job['source'],
			'status'    => $cancelled ? 'cancelled' : 'complete',
			'config'    => Config::profile_fields( $job['config'] ),
			'tables'    => $job['tables'],
			'totals'    => $totals,
			'recorded'  => $job['recorded'],
			'truncated' => $totals['cells_changed'] > $job['recorded'],
		);

		History::add( $run );
		do_action( 'swapline_after_run', $run );

		return $run;
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
