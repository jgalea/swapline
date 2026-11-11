<?php
// phpcs:disable WordPress.WP.AlternativeFunctions -- direct file access in the plugin's own private folder; WP_Filesystem has no streaming API.

namespace Swapline\Backup;

use Swapline\Store;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Backups and uploaded imports live in a randomly named folder under uploads,
 * closed to the web by .htaccess and web.config, and served only through the
 * admin download handler.
 */
final class Storage {

	public const DIR_OPTION        = 'swapline_backup_dir';
	public const META_OPTION       = 'swapline_backups';
	public const RETENTION_OPTION  = 'swapline_backup_retention';
	public const DEFAULT_RETENTION = 5;

	private const EXPOSED_TRANSIENT = 'swapline_folder_exposed';

	private const PATTERNS = array(
		'backup' => '/^backup-\d{8}-\d{6}-[a-f0-9]{12}\.sql\.gz$/',
		'upload' => '/^upload-[a-f0-9]{12}\.sql(?:\.gz)?$/',
		'work'   => '/^import-[a-f0-9]{8}\.sql$/',
	);

	private const HTACCESS = "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";

	private const WEB_CONFIG = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<security>\n\t\t\t<authorization>\n\t\t\t\t<remove users=\"*\" roles=\"\" verbs=\"\" />\n\t\t\t\t<add accessType=\"Deny\" users=\"*\" />\n\t\t\t</authorization>\n\t\t</security>\n\t</system.webServer>\n</configuration>\n";

	/**
	 * @return string|WP_Error Absolute path without a trailing slash.
	 */
	public static function dir() {
		$base = self::uploads_basedir();
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$name = (string) Store::get( self::DIR_OPTION, '' );
		if ( ! preg_match( '/^swapline-[a-f0-9]{20}$/', $name ) ) {
			$name = 'swapline-' . bin2hex( random_bytes( 10 ) );
			Store::set( self::DIR_OPTION, $name );
		}

		$dir = untrailingslashit( $base ) . '/' . $name;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'swapline_storage', __( 'The backup folder could not be created. Check that the uploads folder is writable.', 'swapline' ) );
		}

		$files = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'index.html' => '',
			'.htaccess'  => self::HTACCESS,
			'web.config' => self::WEB_CONFIG,
		);
		foreach ( $files as $file => $contents ) {
			if ( ! file_exists( $dir . '/' . $file ) && false === file_put_contents( $dir . '/' . $file, $contents ) ) {
				return new WP_Error( 'swapline_storage', __( 'The backup folder could not be protected. Check that the uploads folder is writable.', 'swapline' ) );
			}
		}

		return $dir;
	}

	/**
	 * Path of a stored file, or null when the name isn't one the plugin creates.
	 */
	public static function path( string $name, string $kind ): ?string {
		if ( ! isset( self::PATTERNS[ $kind ] ) || ! preg_match( self::PATTERNS[ $kind ], $name ) ) {
			return null;
		}
		$dir = self::dir();
		return is_wp_error( $dir ) ? null : $dir . '/' . $name;
	}

	public static function new_name( string $kind, string $extension = '.sql.gz' ): string {
		if ( 'backup' === $kind ) {
			return 'backup-' . gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 6 ) ) . '.sql.gz';
		}
		return 'upload-' . bin2hex( random_bytes( 6 ) ) . ( '.sql.gz' === $extension ? '.sql.gz' : '.sql' );
	}

	/**
	 * Completed backups, newest first.
	 *
	 * @return array<int, array{name: string, created: int, size: int, tables: int, replace: array<string, string>|null, user: string}>
	 */
	public static function backups(): array {
		$dir = self::dir();
		if ( is_wp_error( $dir ) ) {
			return array();
		}

		$meta = self::meta();
		$list = array();
		foreach ( (array) scandir( $dir ) as $name ) {
			$name = (string) $name;
			if ( ! preg_match( self::PATTERNS['backup'], $name ) ) {
				continue;
			}
			$info   = $meta[ $name ] ?? array();
			$list[] = array(
				'name'    => $name,
				'created' => (int) ( $info['created'] ?? filemtime( $dir . '/' . $name ) ),
				'size'    => (int) filesize( $dir . '/' . $name ),
				'tables'  => (int) ( $info['tables'] ?? 0 ),
				'replace' => isset( $info['replace'] ) && is_array( $info['replace'] ) ? $info['replace'] : null,
				'user'    => (string) ( $info['user'] ?? '' ),
			);
		}

		usort(
			$list,
			static function ( array $a, array $b ): int {
				return array( $b['created'], $b['name'] ) <=> array( $a['created'], $a['name'] );
			}
		);
		return $list;
	}

	/**
	 * @param array<string, mixed> $info
	 */
	public static function record( string $name, array $info ): void {
		$meta          = self::meta();
		$meta[ $name ] = $info;
		Store::set( self::META_OPTION, $meta );
	}

	public static function delete( string $name ): bool {
		$path = self::path( $name, 'backup' );
		if ( null === $path || ! is_file( $path ) ) {
			return false;
		}
		$deleted = wp_delete_file_from_directory( $path, dirname( $path ) );

		$meta = self::meta();
		unset( $meta[ $name ] );
		Store::set( self::META_OPTION, $meta );

		return $deleted;
	}

	public static function retention(): int {
		return max( 0, (int) Store::get( self::RETENTION_OPTION, self::DEFAULT_RETENTION ) );
	}

	public static function set_retention( int $keep ): void {
		Store::set( self::RETENTION_OPTION, max( 0, $keep ) );
	}

	/**
	 * Deletes the oldest backups beyond the retention setting.
	 */
	public static function apply_retention(): void {
		$keep = self::retention();
		if ( 0 === $keep ) {
			return;
		}
		foreach ( array_slice( self::backups(), $keep ) as $backup ) {
			self::delete( $backup['name'] );
		}
	}

	/**
	 * Removes a file the plugin created, by its absolute path.
	 */
	public static function remove( string $path ): void {
		$dir = self::dir();
		if ( is_wp_error( $dir ) || dirname( $path ) !== $dir || ! is_file( $path ) ) {
			return;
		}
		wp_delete_file_from_directory( $path, $dir );
	}

	/**
	 * Deletes uploads and unpacked imports that an abandoned import left behind.
	 */
	public static function sweep(): void {
		$dir = self::dir();
		if ( is_wp_error( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $name ) {
			$name = (string) $name;
			$path = $dir . '/' . $name;
			if ( ( preg_match( self::PATTERNS['upload'], $name ) || preg_match( self::PATTERNS['work'], $name ) || preg_match( '/^backup-.*\.part$/', $name ) ) && filemtime( $path ) < time() - DAY_IN_SECONDS ) {
				wp_delete_file_from_directory( $path, $dir );
			}
		}
	}

	/**
	 * Whether the web server hands out files from the private folder, as nginx does when it ignores the
	 * .htaccess rule. Asks the site's own address for a throwaway file and remembers the answer for a day.
	 */
	public static function is_exposed(): bool {
		$cached = get_transient( self::EXPOSED_TRANSIENT );
		if ( false !== $cached ) {
			return '1' === $cached;
		}

		$dir = self::dir();
		$url = self::uploads_basedir( 'baseurl' );
		if ( is_wp_error( $dir ) || is_wp_error( $url ) ) {
			return false;
		}

		$probe = $dir . '/swapline-probe.txt';
		if ( false === file_put_contents( $probe, 'swapline' ) ) {
			return false;
		}
		$response = wp_remote_head(
			untrailingslashit( $url ) . '/' . basename( $dir ) . '/swapline-probe.txt',
			array(
				'timeout'     => 3,
				'redirection' => 0,
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
			)
		);
		wp_delete_file( $probe );

		$exposed = ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response );
		set_transient( self::EXPOSED_TRANSIENT, $exposed ? '1' : '0', DAY_IN_SECONDS );
		return $exposed;
	}

	public static function download_url( string $name ): string {
		return add_query_arg(
			array(
				'action'   => 'swapline_download',
				'file'     => $name,
				'_wpnonce' => wp_create_nonce( 'swapline_download_' . $name ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function meta(): array {
		$meta = Store::get( self::META_OPTION, array() );
		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * The main site's uploads folder, so a network keeps one backup folder.
	 *
	 * @return string|WP_Error
	 */
	private static function uploads_basedir( string $key = 'basedir' ) {
		$switched = is_multisite() && ! is_main_site();
		if ( $switched ) {
			switch_to_blog( get_main_site_id() );
		}
		$uploads = wp_upload_dir( null, false );
		if ( $switched ) {
			restore_current_blog();
		}

		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'swapline_storage', (string) $uploads['error'] );
		}
		return (string) $uploads[ $key ];
	}
}
