<?php
/**
 * Plugin Name:       Swapline
 * Description:       Search and replace across your database tables with serialized data and JSON handled safely, plus backups and safe imports for moving a site.
 * Version:           0.3.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Jean Galea
 * Author URI:        https://rebelcode.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       swapline
 * Network:           true
 */

defined( 'ABSPATH' ) || exit;

define( 'SWAPLINE_VERSION', '0.3.0' );
define( 'SWAPLINE_FILE', __FILE__ );

spl_autoload_register(
	static function ( string $class_name ): void {
		if ( 0 !== strpos( $class_name, 'Swapline\\' ) ) {
			return;
		}
		$path = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( 'Swapline\\' ) ) ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

register_activation_hook( __FILE__, array( Swapline\Plugin::class, 'activate' ) );

Swapline\Plugin::boot();
