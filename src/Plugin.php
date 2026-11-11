<?php

namespace Swapline;

use Swapline\Admin\Ajax;
use Swapline\Admin\BackupAjax;
use Swapline\Admin\BackupScreen;
use Swapline\Admin\Page;
use Swapline\Run\History;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public static function boot(): void {
		if ( is_admin() ) {
			add_action( 'admin_init', array( History::class, 'maybe_install' ) );
			Page::register();
			Ajax::register();
			BackupScreen::register();
			BackupAjax::register();
			add_filter( is_multisite() ? 'network_admin_plugin_action_links_' . plugin_basename( SWAPLINE_FILE ) : 'plugin_action_links_' . plugin_basename( SWAPLINE_FILE ), array( self::class, 'action_links' ) );
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'swapline', Cli\Command::class );
		}
	}

	public static function capability(): string {
		/**
		 * The capability needed to use Swapline.
		 *
		 * @param string $capability manage_network on multisite, otherwise manage_options.
		 */
		return (string) apply_filters( 'swapline_capability', is_multisite() ? 'manage_network' : 'manage_options' );
	}

	/**
	 * The capability for backups, downloads, imports and restores, and for runs on the users tables. It is
	 * not filterable: anyone who has it can read every password hash or become an administrator.
	 */
	public static function database_capability(): string {
		return is_multisite() ? 'manage_network' : 'manage_options';
	}

	/**
	 * Tables that hold accounts, roles and site settings. Rewriting them is enough to become an
	 * administrator (usermeta capabilities, the user_roles option, default_role, active_plugins).
	 *
	 * @param string[] $tables
	 * @return string[]
	 */
	public static function protected_tables( array $tables ): array {
		global $wpdb;

		$exact   = array( $wpdb->base_prefix . 'users', $wpdb->base_prefix . 'usermeta' );
		$options = '/^' . preg_quote( $wpdb->base_prefix, '/' ) . '(?:[0-9]+_)?options$/';
		if ( is_multisite() ) {
			$exact[] = $wpdb->base_prefix . 'sitemeta';
		}

		return array_values(
			array_filter(
				$tables,
				static function ( string $table ) use ( $exact, $options ): bool {
					return in_array( $table, $exact, true ) || 1 === preg_match( $options, $table );
				}
			)
		);
	}

	/**
	 * Whether the current user may see a run that touched these tables.
	 *
	 * @param string[] $tables
	 */
	public static function can_see_tables( array $tables ): bool {
		return current_user_can( self::database_capability() ) || array() === self::protected_tables( $tables );
	}

	/**
	 * @param string[] $links
	 * @return string[]
	 */
	public static function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( Page::url() ) . '">' . esc_html__( 'Open', 'swapline' ) . '</a>' );
		return $links;
	}

	public static function activate(): void {
		History::install();
	}
}
