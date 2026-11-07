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
