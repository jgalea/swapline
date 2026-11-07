<?php

namespace Swapline;

use Swapline\Backup\Exporter;
use Swapline\Backup\Storage;
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
		$job = self::scan( $config );

		$samples = array();
		foreach ( History::changes( $job['id'], 1, max( 0, min( 20, (int) ( $input['samples'] ?? self::SAMPLES ) ) ) )['items'] as $item ) {
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
		$affected = array_keys( self::matched( self::scan( array_merge( $config, array( 'dry_run' => true ) ) ) ) );
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
	 * @return array<string, mixed>
	 */
	private static function scan( array $config ): array {
		$job = Runner::start( $config, 'api' );
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
