<?php

namespace Swapline;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin state lives network-wide on multisite and is never autoloaded.
 */
final class Store {

	/**
	 * @param mixed $fallback
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		$value = is_multisite() ? get_site_option( $key, $fallback ) : get_option( $key, $fallback );
		return $value;
	}

	/**
	 * @param mixed $value
	 */
	public static function set( string $key, $value ): void {
		if ( is_multisite() ) {
			update_site_option( $key, $value );
			return;
		}
		update_option( $key, $value, false );
	}

	public static function delete( string $key ): void {
		if ( is_multisite() ) {
			delete_site_option( $key );
			return;
		}
		delete_option( $key );
	}
}
