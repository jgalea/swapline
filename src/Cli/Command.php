<?php

namespace Swapline\Cli;

use Swapline\Backup\Exporter;
use Swapline\Database\Tables;
use Swapline\Import\Importer;
use Swapline\Run\Config;
use Swapline\Run\Profiles;
use Swapline\Run\Runner;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Search and replace with Swapline's engine: serialized data, JSON-escaped
 * values and nested serialized strings, with runs recorded in the admin history.
 */
final class Command {

	/**
	 * Runs a search and replace.
	 *
	 * ## OPTIONS
	 *
	 * [<search>]
	 * : The string or pattern to search for. Optional with --profile.
	 *
	 * [<replace>]
	 * : The replacement. Optional with --profile.
	 *
	 * [<table>...]
	 * : Tables to process. Defaults to every table with the WordPress prefix.
	 *
	 * [--profile=<name>]
	 * : Load a profile saved in the admin. Arguments given on the command line override it.
	 *
	 * [--[no-]dry-run]
	 * : Report what would change without writing anything. Use --no-dry-run to override a profile saved as a dry run.
	 *
	 * [--case-insensitive]
	 * : Match regardless of case.
	 *
	 * [--regex]
	 * : Treat the search as a regular expression, entered without delimiters.
	 *
	 * [--include-guid]
	 * : Also replace in guid columns, which are skipped by default.
	 *
	 * [--page-size=<rows>]
	 * : Rows read per batch.
	 * ---
	 * default: 1000
	 * ---
	 *
	 * [--backup]
	 * : Back up the tables first. Ignored on a dry run.
	 *
	 * [--yes]
	 * : Don't ask for confirmation before writing.
	 *
	 * [--format=<format>]
	 * : Output format for the per-table report.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp swapline run https://old.example.com https://new.example.com --dry-run
	 *     wp swapline run https://old.example.com https://new.example.com --yes
	 *     wp swapline run 'https?://old\.example\.com/(\d+)' 'https://new.example.com/$1' wp_posts --regex
	 *     wp swapline run --profile="Staging to live"
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc_args
	 */
	public function run( array $args, array $assoc_args ): void {
		$input = array();

		if ( isset( $assoc_args['profile'] ) ) {
			$profile = Profiles::get( (string) $assoc_args['profile'] );
			if ( null === $profile ) {
				WP_CLI::error( sprintf( 'No profile named "%s".', $assoc_args['profile'] ) );
			}
			$input = $profile;
		}

		if ( isset( $args[0] ) ) {
			$input['search'] = $args[0];
		}
		if ( isset( $args[1] ) ) {
			$input['replace'] = $args[1];
		}
		if ( count( $args ) > 2 ) {
			$input['tables'] = array_slice( $args, 2 );
		} elseif ( empty( $input['tables'] ) ) {
			$input['tables'] = array_keys( Tables::all() );
		}
		if ( ! isset( $input['search'], $input['replace'] ) ) {
			WP_CLI::error( 'Give a search and a replacement, or a --profile.' );
		}

		foreach ( array(
			'dry-run'          => 'dry_run',
			'case-insensitive' => 'case_insensitive',
			'regex'            => 'regex',
		) as $flag => $field ) {
			if ( isset( $assoc_args[ $flag ] ) ) {
				$input[ $field ] = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, $flag, false );
			} elseif ( ! isset( $input[ $field ] ) ) {
				$input[ $field ] = false;
			}
		}
		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'include-guid', false ) ) {
			$input['skip_guid'] = false;
		}
		if ( isset( $assoc_args['page-size'] ) && ! isset( $assoc_args['profile'] ) ) {
			$input['page_size'] = (int) $assoc_args['page-size'];
		}

		$config = Config::from_input( $input );
		if ( is_wp_error( $config ) ) {
			WP_CLI::error( $config->get_error_message() );
		}

		if ( ! $config['dry_run'] ) {
			WP_CLI::confirm( sprintf( 'Replace in %d tables? This writes to the database.', count( $config['tables'] ) ), $assoc_args );
		}

		if ( ! $config['dry_run'] && WP_CLI\Utils\get_flag_value( $assoc_args, 'backup', false ) ) {
			$this->backup_tables( $config['tables'], null );
		}

		$job      = Runner::start( $config, 'cli' );
		$progress = WP_CLI\Utils\make_progress_bar( $config['dry_run'] ? 'Dry run' : 'Replacing', count( $config['tables'] ) );
		$index    = 0;

		while ( ! $job['done'] ) {
			$job = Runner::step( $job );
			while ( $index < $job['table_index'] ) {
				$progress->tick();
				++$index;
			}
		}
		$progress->finish();

		$run  = Runner::finish( $job );
		$rows = array();
		foreach ( $run['tables'] as $table => $counts ) {
			if ( 0 === $counts['cells_matched'] && 0 === $counts['failures'] ) {
				continue;
			}
			$rows[] = array_merge( array( 'table' => $table ), $counts );
		}

		if ( array() !== $rows ) {
			WP_CLI\Utils\format_items( $assoc_args['format'], $rows, array( 'table', 'rows_matched', 'cells_changed', 'replacements', 'failures', 'rows_not_written' ) );
		}

		$totals = $run['totals'];
		$verb   = $config['dry_run'] ? 'would change' : 'changed';
		WP_CLI::success( sprintf( '%d cells in %d rows %s (%d replacements). Run %s is in the admin history.', $totals['cells_changed'], $totals['rows_matched'], $verb, $totals['replacements'], $run['id'] ) );

		if ( $totals['failures'] > 0 ) {
			WP_CLI::warning( sprintf( '%d values contained the search but could not be parsed as serialized data or JSON, and were left unchanged.', $totals['failures'] ) );
		}
		if ( $totals['rows_not_written'] > 0 ) {
			WP_CLI::warning( sprintf( '%d rows could not be written.', $totals['rows_not_written'] ) );
		}
	}

	/**
	 * Backs up tables to a gzipped SQL file, optionally with a search and replace applied to the file only.
	 *
	 * Unlike wp db export, it needs no mysqldump binary, keeps the backup in
	 * the admin's backup list, and can rewrite the exported copy with the same
	 * serialization- and JSON-aware engine as `run`, leaving the live
	 * database untouched.
	 *
	 * ## OPTIONS
	 *
	 * [<table>...]
	 * : Tables to back up. Defaults to every table with the WordPress prefix.
	 *
	 * [--search=<search>]
	 * : Replace this in the exported copy.
	 *
	 * [--replace=<replace>]
	 * : The replacement for --search.
	 *
	 * [--case-insensitive]
	 * : Match regardless of case.
	 *
	 * [--regex]
	 * : Treat --search as a regular expression, entered without delimiters.
	 *
	 * [--include-guid]
	 * : Also replace in guid columns.
	 *
	 * [--porcelain]
	 * : Print only the path of the backup.
	 *
	 * ## EXAMPLES
	 *
	 *     wp swapline backup
	 *     wp swapline backup --search=https://old.example.com --replace=https://new.example.com
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc_args
	 */
	public function backup( array $args, array $assoc_args ): void {
		$tables = array() === $args ? array_keys( Tables::all() ) : Tables::validate( $args );
		if ( null === $tables || array() === $tables ) {
			WP_CLI::error( 'One or more tables do not exist.' );
		}

		$path = $this->backup_tables( $tables, $this->replace_config( $assoc_args, $tables ), (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'porcelain', false ) );

		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'porcelain', false ) ) {
			WP_CLI::line( $path );
		}
	}

	/**
	 * Imports a .sql or .sql.gz file through temporary tables.
	 *
	 * Unlike wp db import, every statement is checked first (only CREATE TABLE,
	 * DROP TABLE, INSERT, LOCK/UNLOCK and session SET are allowed), the data is
	 * loaded into temporary tables and swapped in with one RENAME TABLE, so a
	 * failure leaves the live database as it was. An optional search and
	 * replace runs on the imported data before the swap.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the .sql or .sql.gz file.
	 *
	 * [--search=<search>]
	 * : Replace this in the imported data.
	 *
	 * [--replace=<replace>]
	 * : The replacement for --search.
	 *
	 * [--case-insensitive]
	 * : Match regardless of case.
	 *
	 * [--regex]
	 * : Treat --search as a regular expression, entered without delimiters.
	 *
	 * [--include-guid]
	 * : Also replace in guid columns.
	 *
	 * [--keep-url]
	 * : Keep this site's siteurl and home instead of the values in the file.
	 *
	 * [--yes]
	 * : Don't ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp swapline import backup.sql.gz --search=https://old.example.com --replace=https://new.example.com
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc_args
	 */
	public function import( array $args, array $assoc_args ): void {
		$path = realpath( $args[0] );
		if ( false === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			WP_CLI::error( sprintf( 'Cannot read %s.', $args[0] ) );
		}

		$replace = $this->replace_config( $assoc_args, null );
		WP_CLI::confirm( 'Replace every table in the file with its contents?', $assoc_args );

		$job = Importer::start( $path, false, $replace, (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'keep-url', false ), 'cli' );
		if ( is_wp_error( $job ) ) {
			WP_CLI::error( $job->get_error_message() );
		}

		$phase = $job['phase'];
		WP_CLI::log( ucfirst( $phase ) . '…' );
		$job = Importer::run(
			$job,
			static function ( array $job ) use ( &$phase ): void {
				if ( $job['phase'] !== $phase && ! $job['done'] ) {
					$phase = $job['phase'];
					WP_CLI::log( ucfirst( $phase ) . '…' );
				}
			}
		);

		if ( null !== $job['error'] ) {
			foreach ( $job['rejected'] as $item ) {
				WP_CLI::warning( $item['reason'] . ' ' . $item['statement'] );
			}
			WP_CLI::error( $job['error'] . ' The live database was not changed.' );
		}

		$counts = $job['counts'];
		WP_CLI::success( sprintf( 'Imported %d tables and %d rows. %d statements skipped.', count( $job['tables'] ), $counts['rows'], $counts['skipped'] ) );
		if ( null !== $replace ) {
			WP_CLI::log( sprintf( 'The search and replace changed %d rows.', $counts['changed'] ) );
		}
		if ( $counts['failures'] > 0 ) {
			WP_CLI::warning( sprintf( '%d values contained the search but could not be parsed as serialized data or JSON, and were left unchanged.', $counts['failures'] ) );
		}
	}

	/**
	 * @param string[]                  $tables
	 * @param array<string, mixed>|null $replace
	 * @return string Path of the backup.
	 */
	private function backup_tables( array $tables, ?array $replace, bool $quiet = false ): string {
		$progress = $quiet ? null : WP_CLI\Utils\make_progress_bar( 'Backing up', count( $tables ) );
		$index    = 0;
		$job      = Exporter::run(
			$tables,
			$replace,
			'cli',
			static function ( array $job ) use ( $progress, &$index ): void {
				while ( null !== $progress && $index < $job['index'] ) {
					$progress->tick();
					++$index;
				}
			}
		);
		if ( null !== $progress ) {
			$progress->finish();
		}
		if ( is_wp_error( $job ) ) {
			WP_CLI::error( $job->get_error_message() );
		}

		$path = Exporter::path( $job );
		if ( ! $quiet ) {
			WP_CLI::success( sprintf( 'Backed up %d tables, %d rows, to %s', count( $tables ), $job['rows'], $path ) );
			if ( null !== $replace ) {
				WP_CLI::log( sprintf( '%d rows were changed in the file. The live database was not touched.', $job['changed'] ) );
			}
		}
		return $path;
	}

	/**
	 * @param array<string, string> $assoc_args
	 * @param string[]|null         $tables
	 * @return array<string, mixed>|null
	 */
	private function replace_config( array $assoc_args, ?array $tables ): ?array {
		if ( ! isset( $assoc_args['search'] ) ) {
			if ( isset( $assoc_args['replace'] ) ) {
				WP_CLI::error( '--replace needs --search.' );
			}
			return null;
		}

		$config = Config::from_input(
			array(
				'search'           => $assoc_args['search'],
				'replace'          => $assoc_args['replace'] ?? '',
				'tables'           => $tables ?? array(),
				'case_insensitive' => (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'case-insensitive', false ),
				'regex'            => (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'regex', false ),
				'skip_guid'        => ! WP_CLI\Utils\get_flag_value( $assoc_args, 'include-guid', false ),
				'dry_run'          => false,
			),
			null !== $tables
		);
		if ( is_wp_error( $config ) ) {
			WP_CLI::error( $config->get_error_message() );
		}
		return $config;
	}
}
