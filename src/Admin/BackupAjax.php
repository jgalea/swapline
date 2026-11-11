<?php

namespace Swapline\Admin;

use Swapline\Backup\Exporter;
use Swapline\Backup\Storage;
use Swapline\Clock;
use Swapline\Database\Tables;
use Swapline\Import\Importer;
use Swapline\Plugin;
use Swapline\Run\Config;

defined( 'ABSPATH' ) || exit;

final class BackupAjax {

	public const ACTIONS = array( 'backup_start', 'backup_step', 'backup_cancel', 'backup_delete', 'backup_settings', 'import_upload', 'import_start', 'import_step', 'import_cancel' );

	public static function register(): void {
		foreach ( self::ACTIONS as $action ) {
			add_action( 'wp_ajax_swapline_' . $action, array( self::class, 'handle_' . $action ) );
		}
		add_action( 'admin_post_swapline_download', array( self::class, 'download' ) );
	}

	private static function guard( string $action ): void {
		if ( ! check_ajax_referer( 'swapline_' . $action, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session has expired. Reload the page and try again.', 'swapline' ) ), 403 );
		}
		if ( ! current_user_can( Plugin::database_capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'swapline' ) ), 403 );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function config_input(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$raw = isset( $_POST['config'] ) ? (string) wp_unslash( $_POST['config'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decoded and validated field by field.
		// phpcs:enable
		$input = json_decode( $raw, true );
		return is_array( $input ) ? $input : array();
	}

	private static function post_string( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * Builds the optional search and replace from the form, or returns null when it's off.
	 *
	 * @param array<string, mixed> $input
	 * @param string[]|null        $tables
	 * @return array<string, mixed>|null
	 */
	private static function replace_config( array $input, ?array $tables ): ?array {
		if ( empty( $input['replace_enabled'] ) ) {
			return null;
		}
		$input['dry_run'] = false;
		if ( null !== $tables ) {
			$input['tables'] = $tables;
		}
		$config = Config::from_input( $input, null !== $tables );
		if ( is_wp_error( $config ) ) {
			wp_send_json_error( array( 'message' => $config->get_error_message() ), 400 );
		}
		return $config;
	}

	public static function handle_backup_start(): void {
		self::guard( 'backup_start' );

		$input  = self::config_input();
		$tables = 'selected' === ( $input['scope'] ?? 'all' ) && isset( $input['tables'] ) && is_array( $input['tables'] )
			? Tables::validate( array_map( 'strval', $input['tables'] ) )
			: array_keys( Tables::all() );

		if ( null === $tables ) {
			wp_send_json_error( array( 'message' => __( 'One or more selected tables do not exist.', 'swapline' ) ), 400 );
		}
		if ( array() === $tables ) {
			wp_send_json_error( array( 'message' => __( 'Select at least one table.', 'swapline' ) ), 400 );
		}

		$job = Exporter::start( $tables, self::replace_config( $input, $tables ), 'admin' );
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ), 500 );
		}
		Exporter::save( $job );

		wp_send_json_success( Exporter::progress( $job ) );
	}

	public static function handle_backup_step(): void {
		self::guard( 'backup_step' );

		$job = Exporter::current();
		if ( null === $job || self::post_string( 'job' ) !== $job['id'] ) {
			wp_send_json_error( array( 'message' => __( 'This backup is no longer running. Another one may have replaced it.', 'swapline' ) ), 409 );
		}

		$job = Exporter::step( $job, Clock::deadline() );

		if ( null !== $job['error'] ) {
			Exporter::discard( $job );
			wp_send_json_error( array( 'message' => $job['error'] ), 500 );
		}
		if ( $job['done'] ) {
			Exporter::discard( $job );
			wp_send_json_success(
				array(
					'progress' => Exporter::progress( $job ),
					'backup'   => array(
						'name'    => $job['file'],
						'rows'    => (int) $job['rows'],
						'changed' => (int) $job['changed'],
					),
					'backups'  => self::backup_rows(),
				)
			);
		}

		Exporter::save( $job );
		wp_send_json_success( array( 'progress' => Exporter::progress( $job ) ) );
	}

	public static function handle_backup_cancel(): void {
		self::guard( 'backup_cancel' );

		$job = Exporter::current();
		if ( null !== $job && self::post_string( 'job' ) === $job['id'] ) {
			Exporter::discard( $job );
		}
		wp_send_json_success();
	}

	public static function handle_backup_delete(): void {
		self::guard( 'backup_delete' );

		if ( ! Storage::delete( self::post_string( 'file' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'That backup could not be deleted.', 'swapline' ) ), 404 );
		}
		wp_send_json_success( array( 'backups' => self::backup_rows() ) );
	}

	public static function handle_backup_settings(): void {
		self::guard( 'backup_settings' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$keep = isset( $_POST['keep'] ) ? min( 1000, absint( $_POST['keep'] ) ) : Storage::DEFAULT_RETENTION;
		Storage::set_retention( $keep );
		Storage::apply_retention();

		wp_send_json_success(
			array(
				'keep'    => $keep,
				'backups' => self::backup_rows(),
			)
		);
	}

	public static function handle_import_upload(): void {
		self::guard( 'import_upload' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in guard(); the upload is validated below and only moved.
		$file = isset( $_FILES['file'] ) && is_array( $_FILES['file'] ) ? $_FILES['file'] : null;
		// phpcs:enable

		if ( null === $file || ! isset( $file['error'], $file['tmp_name'], $file['name'] ) || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'The file did not upload. It may be larger than the maximum upload size.', 'swapline' ) ), 400 );
		}

		Storage::sweep();

		$name = strtolower( sanitize_text_field( (string) $file['name'] ) );
		if ( ! preg_match( '/\.(sql|sql\.gz|gz)$/', $name, $m ) ) {
			wp_send_json_error( array( 'message' => __( 'Upload a .sql or .sql.gz file.', 'swapline' ) ), 400 );
		}

		$target = Storage::new_name( 'upload', 'sql' === $m[1] ? '.sql' : '.sql.gz' );
		$path   = Storage::path( $target, 'upload' );
		if ( null === $path || ! self::store_upload( $file, $path ) ) {
			wp_send_json_error( array( 'message' => __( 'The uploaded file could not be stored.', 'swapline' ) ), 500 );
		}

		wp_send_json_success( array( 'file' => $target ) );
	}

	/**
	 * Moves the upload into the private storage folder under the given name.
	 * The multisite media quota check is lifted for the call: the file never
	 * reaches the media library and is removed once the import finishes.
	 *
	 * @param array<string, mixed> $file
	 */
	private static function store_upload( array $file, string $path ): bool {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$dir       = dirname( $path );
		$name      = basename( $path );
		$to_store  = static function ( array $uploads ) use ( $dir ): array {
			$uploads['path']   = $dir;
			$uploads['url']    = '';
			$uploads['subdir'] = '';
			$uploads['error']  = false;
			return $uploads;
		};
		$quota     = is_multisite() ? has_filter( 'wp_handle_upload_prefilter', 'check_upload_size' ) : false;
		$overrides = array(
			'test_form'                => false,
			'test_type'                => false,
			'unique_filename_callback' => static function () use ( $name ): string {
				return $name;
			},
		);

		if ( false !== $quota ) {
			remove_filter( 'wp_handle_upload_prefilter', 'check_upload_size', $quota );
		}
		add_filter( 'upload_dir', $to_store, PHP_INT_MAX );
		$result = wp_handle_upload( $file, $overrides );
		remove_filter( 'upload_dir', $to_store, PHP_INT_MAX );
		if ( false !== $quota ) {
			add_filter( 'wp_handle_upload_prefilter', 'check_upload_size', $quota );
		}

		if ( isset( $result['error'] ) || ! isset( $result['file'] ) || $result['file'] !== $path ) {
			if ( isset( $result['file'] ) && is_string( $result['file'] ) ) {
				wp_delete_file( $result['file'] );
			}
			return false;
		}
		return is_file( $path );
	}

	public static function handle_import_start(): void {
		self::guard( 'import_start' );

		$input  = self::config_input();
		$source = 'backup' === ( $input['source'] ?? '' ) ? 'backup' : 'upload';
		$path   = Storage::path( (string) ( $input['file'] ?? '' ), $source );
		if ( null === $path || ! is_file( $path ) ) {
			wp_send_json_error( array( 'message' => __( 'That file is no longer available. Choose it again.', 'swapline' ) ), 404 );
		}

		$job = Importer::start( $path, 'upload' === $source, self::replace_config( $input, null ), ! empty( $input['preserve'] ), 'admin' );
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ), 500 );
		}
		Importer::save( $job );

		wp_send_json_success( Importer::progress( $job ) );
	}

	public static function handle_import_step(): void {
		self::guard( 'import_step' );

		$job = Importer::current();
		if ( null === $job || self::post_string( 'job' ) !== $job['id'] ) {
			wp_send_json_error( array( 'message' => __( 'This import is no longer running. Another one may have replaced it.', 'swapline' ) ), 409 );
		}

		$job = Importer::step( $job, Clock::deadline() );
		if ( $job['done'] ) {
			Importer::clear();
		} else {
			Importer::save( $job );
		}

		wp_send_json_success( Importer::progress( $job ) );
	}

	public static function handle_import_cancel(): void {
		self::guard( 'import_cancel' );

		$job = Importer::current();
		if ( null !== $job && self::post_string( 'job' ) === $job['id'] ) {
			Importer::fail( $job, __( 'Cancelled.', 'swapline' ) );
			Importer::clear();
		}
		wp_send_json_success();
	}

	/**
	 * Streams a backup to the browser. Files are never served by URL.
	 */
	public static function download(): void {
		if ( ! current_user_can( Plugin::database_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to download backups.', 'swapline' ), '', array( 'response' => 403 ) );
		}

		$name = isset( $_GET['file'] ) ? sanitize_text_field( wp_unslash( $_GET['file'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified on the next line.
		check_admin_referer( 'swapline_download_' . $name );

		$path = Storage::path( $name, 'backup' );
		if ( null === $path || ! is_file( $path ) ) {
			wp_die( esc_html__( 'That backup no longer exists.', 'swapline' ), '', array( 'response' => 404 ) );
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions -- streams a large file; WP_Filesystem has no streaming API.
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			wp_die( esc_html__( 'The backup could not be read.', 'swapline' ), '', array( 'response' => 500 ) );
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/gzip' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );

		fpassthru( $handle );
		fclose( $handle );
		// phpcs:enable
		exit;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function backup_rows(): array {
		$rows = array();
		foreach ( Storage::backups() as $backup ) {
			$note = '';
			if ( null !== $backup['replace'] ) {
				/* translators: 1: search string, 2: replacement. */
				$note = sprintf( __( 'Replaced "%1$s" with "%2$s"', 'swapline' ), self::excerpt( (string) $backup['replace']['search'] ), self::excerpt( (string) $backup['replace']['replace'] ) );
			}
			$rows[] = array(
				'name'     => $backup['name'],
				'created'  => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $backup['created'] ),
				'label'    => BackupScreen::backup_label( $backup ),
				'size'     => size_format( $backup['size'], 1 ),
				'tables'   => $backup['tables'] > 0 ? number_format_i18n( $backup['tables'] ) : '',
				'note'     => implode( ' · ', array_filter( array( $note, '' !== $backup['user'] ? sprintf( /* translators: %s: user login. */ __( 'By %s', 'swapline' ), $backup['user'] ) : '' ) ) ),
				'download' => Storage::download_url( $backup['name'] ),
				'restore'  => ! Tables::uses_sqlite(),
			);
		}
		return $rows;
	}

	private static function excerpt( string $value ): string {
		return mb_strlen( $value ) > 40 ? mb_substr( $value, 0, 37 ) . '…' : $value;
	}
}
