<?php

namespace Swapline\Run;

use Swapline\Store;

defined( 'ABSPATH' ) || exit;

final class Profiles {

	public const OPTION = 'swapline_profiles';

	/**
	 * @return array<string, array<string, mixed>> Name => saved configuration.
	 */
	public static function all(): array {
		$profiles = Store::get( self::OPTION, array() );
		if ( ! is_array( $profiles ) ) {
			return array();
		}
		ksort( $profiles, SORT_NATURAL | SORT_FLAG_CASE );
		return $profiles;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get( string $name ): ?array {
		$profiles = self::all();
		return $profiles[ $name ] ?? null;
	}

	/**
	 * @param array<string, mixed> $config
	 */
	public static function save( string $name, array $config ): void {
		$profiles          = self::all();
		$profiles[ $name ] = Config::profile_fields( $config );
		Store::set( self::OPTION, $profiles );
	}

	public static function delete( string $name ): bool {
		$profiles = self::all();
		if ( ! isset( $profiles[ $name ] ) ) {
			return false;
		}
		unset( $profiles[ $name ] );
		Store::set( self::OPTION, $profiles );
		return true;
	}
}
