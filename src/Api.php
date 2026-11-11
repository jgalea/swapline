<?php

namespace Swapline;

use Swapline\Backup\Exporter;
use Swapline\Backup\Storage;
use Swapline\Database\Table;
use Swapline\Database\Tables;
use Swapline\Import\Importer;
use Swapline\Run\Config;
use Swapline\Run\History;
use Swapline\Run\Runner;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Entry points for other plugins. Each call runs to completion in the current
 * request, so callers are responsible for checking capabilities first.
 */
final class Api {

	public const SAMPLES = 5;

	/**
	 * Dry run: counts per table and a few before/after samples. Nothing is changed or added to the run history.
	 *
	 * @param array<string, mixed> $input search, replace, tables (all when empty), case_insensitive, regex, skip_guid, samples.
	 * @return array{tables: array<string, array<string, int>>, totals: array<string, int>, samples: array<int, array<string, mixed>>}|WP_Error
	 */
	public static function preview( array $input ) {
		$config = self::config( $input, true );
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		History::maybe_install();
		$wanted = max( 0, min( 20, (int) ( $input['samples'] ?? self::SAMPLES ) ) );
		$job    = self::scan( $config, $wanted );

		$samples = array();
		foreach ( History::changes( $job['id'], 1, $wanted )['items'] as $item ) {
			$samples[] = array(
				'table'  => $item['table_name'],
				'row'    => json_decode( (string) $item['row_key'], true ),
				'column' => $item['column_name'],
				'before' => $item['old_value'],
				'after'  => $item['new_value'],
			);
		}
		History::prune( array_column( History::runs(), 'id' ) );

		return array(
			'tables'  => self::matched( $job ),
			'totals'  => self::totals( $job ),
			'samples' => $samples,
		);
	}

	/**
	 * Replaces in the database. By default the tables that contain a match are backed up first, and nothing
	 * changes if the backup cannot be made.
	 *
	 * @param array<string, mixed> $input  As for preview().
	 * @param bool                 $backup Back up the affected tables first.
	 * @return array{run: array<string, mixed>|null, backup: string|null}|WP_Error
	 */
	public static function replace( array $input, bool $backup = true ) {
		$config = self::config( $input, false );
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		History::maybe_install();
		$affected = array_keys( self::matched( self::scan( array_merge( $config, array( 'dry_run' => true ) ), 0 ) ) );
		History::prune( array_column( History::runs(), 'id' ) );
		if ( array() === $affected ) {
			return array(
				'run'    => null,
				'backup' => null,
			);
		}

		$name = null;
		if ( $backup ) {
			$made = self::backup( $affected );
			if ( is_wp_error( $made ) ) {
				return $made;
			}
			$name = $made['name'];
		}

		$config['tables'] = $affected;
		$job              = Runner::start( $config, 'api' );
		$job['backup']    = $name;
		while ( ! $job['done'] ) {
			$job = Runner::step( $job );
		}

		return array(
			'run'    => Runner::finish( $job ),
			'backup' => $name,
		);
	}

	/**
	 * @param string[] $tables Table names; all tables when empty.
	 * @return array{name: string, tables: int, rows: int}|WP_Error
	 */
	public static function backup( array $tables = array() ) {
		$valid = array() === $tables ? array_keys( Tables::all() ) : Tables::validate( $tables );
		if ( null === $valid ) {
			return new WP_Error( 'swapline_unknown_table', __( 'One or more selected tables do not exist.', 'swapline' ) );
		}

		$job = Exporter::run( $valid, null, 'api' );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		return array(
			'name'   => (string) $job['file'],
			'tables' => count( $valid ),
			'rows'   => (int) $job['rows'],
		);
	}

	/**
	 * Puts back the tables in one of Swapline's backups, keeping the current user logged in.
	 *
	 * @return true|WP_Error
	 */
	public static function restore( string $name ) {
		$path = Storage::path( $name, 'backup' );
		if ( null === $path || ! is_file( $path ) ) {
			return new WP_Error( 'swapline_backup_missing', __( 'That backup no longer exists.', 'swapline' ) );
		}

		$job = Importer::start( $path, false, null, true, 'api' );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		$job = Importer::run( $job );
		Importer::clear();

		return null === $job['error'] ? true : new WP_Error( 'swapline_restore', (string) $job['error'] );
	}

	/**
	 * The cells a run changed, from the run history. Complete is false when the history doesn't hold every
	 * changed cell in full: the run was pruned, it changed more cells than are recorded, or a value was too
	 * long to keep whole.
	 *
	 * @return array{complete: bool, cells: array<int, array{table: string, row: array<string, string|null>, column: string, before: string, after: string}>}
	 */
	public static function changes( string $run_id ): array {
		History::maybe_install();
		$run      = History::find( $run_id );
		$complete = null !== $run && empty( $run['truncated'] );
		$cells    = array();
		$page     = 1;
		do {
			$batch = History::changes( $run_id, $page, 500 )['items'];
			$count = count( $batch );
			foreach ( $batch as $item ) {
				$complete = $complete && empty( $item['truncated'] );
				$cells[]  = array(
					'table'  => (string) $item['table_name'],
					'row'    => (array) json_decode( (string) $item['row_key'], true ),
					'column' => (string) $item['column_name'],
					'before' => (string) $item['old_value'],
					'after'  => (string) $item['new_value'],
				);
			}
			++$page;
		} while ( 500 === $count );

		$changed = null === $run ? -1 : (int) ( $run['totals']['cells_changed'] ?? -1 );

		return array(
			'complete' => $complete && count( $cells ) === $changed,
			'cells'    => $cells,
		);
	}

	/**
	 * Puts back the before value of every cell a live run changed. Only cells that still hold the value the
	 * run wrote are touched, so edits made since stay. Refused when the history doesn't hold the run in full.
	 *
	 * @return array{restored: int, changed_since: int, missing: int}|WP_Error
	 */
	public static function undo( string $run_id ) {
		History::maybe_install();
		$run = History::find( $run_id );
		if ( null === $run ) {
			return new WP_Error( 'swapline_undo_missing', __( 'That run is no longer in the history.', 'swapline' ) );
		}
		if ( ! empty( $run['config']['dry_run'] ) || 'running' === ( $run['status'] ?? '' ) || ! empty( $run['undone'] ) ) {
			return new WP_Error( 'swapline_undo_unavailable', __( 'This run has nothing to undo.', 'swapline' ) );
		}

		$recorded = self::changes( $run_id );
		if ( ! $recorded['complete'] ) {
			return new WP_Error( 'swapline_undo_incomplete', __( 'The history does not hold every change of this run, so it cannot be undone cell by cell. Restore the backup taken before it instead.', 'swapline' ) );
		}

		$current = self::read_cells( $recorded['cells'] );
		if ( is_wp_error( $current ) ) {
			return $current;
		}

		$write = array();
		$out   = array(
			'restored'      => 0,
			'changed_since' => 0,
			'missing'       => 0,
		);
		foreach ( $recorded['cells'] as $i => $cell ) {
			if ( null === $current[ $i ] ) {
				++$out['missing'];
			} elseif ( $current[ $i ] !== $cell['after'] ) {
				++$out['changed_since'];
			} else {
				$write[] = array(
					'table'  => $cell['table'],
					'row'    => $cell['row'],
					'column' => $cell['column'],
					'value'  => $cell['before'],
				);
			}
		}

		$written = self::write_cells( $write );
		if ( is_wp_error( $written ) ) {
			return $written;
		}
		$out['restored'] = $written['written'];
		$out['missing'] += $written['missing'];

		$run['undone'] = time();
		History::add( $run );

		return $out;
	}

	/**
	 * The current value of each cell, addressed as in changes(): null where the row is gone.
	 *
	 * @param array<int, array{table: string, row: array<string, string|null>, column: string}> $cells
	 * @return array<int, string|null>|WP_Error In the order given.
	 */
	public static function read_cells( array $cells ) {
		$tables = self::tables_for( $cells );
		if ( is_wp_error( $tables ) ) {
			return $tables;
		}
		$out = array();
		foreach ( $cells as $cell ) {
			$out[] = is_array( $cell['row'] ?? null ) ? $tables[ (string) $cell['table'] ]->read_cell( $cell['row'], (string) $cell['column'] ) : null;
		}
		return $out;
	}

	/**
	 * Writes single cells, each in the row its recorded key points at, as returned by changes(). Writing
	 * the before values of a run's cells puts exactly those cells back and leaves every other row alone.
	 *
	 * @param array<int, array{table: string, row: array<string, string|null>, column: string, value: string}> $cells
	 * @return array{written: int, missing: int}|WP_Error Missing counts cells whose row is gone or can't be addressed.
	 */
	public static function write_cells( array $cells ) {
		$tables = self::tables_for( $cells );
		if ( is_wp_error( $tables ) ) {
			return $tables;
		}
		$out = array(
			'written' => 0,
			'missing' => 0,
		);
		foreach ( $cells as $cell ) {
			$ok = is_array( $cell['row'] ?? null ) && $tables[ (string) $cell['table'] ]->write_cell( $cell['row'], (string) $cell['column'], (string) ( $cell['value'] ?? '' ) );
			++$out[ $ok ? 'written' : 'missing' ];
		}
		return $out;
	}

	/**
	 * @param array<int, array<string, mixed>> $cells
	 * @return array<string, Table>|WP_Error
	 */
	private static function tables_for( array $cells ) {
		$names = array();
		foreach ( $cells as $cell ) {
			$names[ (string) ( $cell['table'] ?? '' ) ] = true;
		}
		if ( array() !== $names && null === Tables::validate( array_map( 'strval', array_keys( $names ) ) ) ) {
			return new WP_Error( 'swapline_unknown_table', __( 'One or more selected tables do not exist.', 'swapline' ) );
		}
		$tables = array();
		foreach ( array_keys( $names ) as $name ) {
			$tables[ (string) $name ] = new Table( (string) $name );
		}
		return $tables;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	private static function config( array $input, bool $dry_run ) {
		if ( ! isset( $input['tables'] ) || array() === $input['tables'] ) {
			$input['tables'] = array_keys( Tables::all() );
		}
		$input['dry_run'] = $dry_run;
		return Config::from_input( $input );
	}

	/**
	 * @param array<string, mixed> $config
	 * @param int                  $record Changed cells worth keeping.
	 * @return array<string, mixed>
	 */
	private static function scan( array $config, int $record ): array {
		$job = Runner::start( $config, 'api', $record );
		while ( ! $job['done'] ) {
			$job = Runner::step( $job );
		}
		return $job;
	}

	/**
	 * @param array<string, mixed> $job
	 * @return array<string, array<string, int>>
	 */
	private static function matched( array $job ): array {
		return array_filter(
			$job['tables'],
			static function ( array $counts ): bool {
				return $counts['cells_matched'] > 0;
			}
		);
	}

	/**
	 * @param array<string, mixed> $job
	 * @return array<string, int>
	 */
	private static function totals( array $job ): array {
		$totals = array();
		foreach ( $job['tables'] as $counts ) {
			foreach ( $counts as $key => $value ) {
				$totals[ $key ] = ( $totals[ $key ] ?? 0 ) + (int) $value;
			}
		}
		return $totals;
	}
}
