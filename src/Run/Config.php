<?php

namespace Swapline\Run;

use Swapline\Database\Tables;
use Swapline\Engine\Replacer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Validates and normalises a search/replace configuration from any source.
 */
final class Config {

	public const MIN_PAGE_SIZE     = 10;
	public const MAX_PAGE_SIZE     = 50000;
	public const DEFAULT_PAGE_SIZE = 1000;

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'search'           => '',
			'replace'          => '',
			'tables'           => array(),
			'case_insensitive' => false,
			'regex'            => false,
			'skip_guid'        => true,
			'dry_run'          => true,
			'page_size'        => self::DEFAULT_PAGE_SIZE,
		);
	}

	/**
	 * @param array<string, mixed> $input          Unslashed raw input.
	 * @param bool                 $require_tables False when the tables aren't known yet, as for an import.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function from_input( array $input, bool $require_tables = true ) {
		$config = self::defaults();

		foreach ( array( 'search', 'replace' ) as $field ) {
			if ( isset( $input[ $field ] ) && is_scalar( $input[ $field ] ) ) {
				$config[ $field ] = (string) $input[ $field ];
			}
		}
		foreach ( array( 'case_insensitive', 'regex', 'skip_guid', 'dry_run' ) as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$config[ $field ] = rest_sanitize_boolean( $input[ $field ] );
			}
		}
		if ( isset( $input['page_size'] ) ) {
			$config['page_size'] = min( self::MAX_PAGE_SIZE, max( self::MIN_PAGE_SIZE, absint( $input['page_size'] ) ) );
		}

		$tables = isset( $input['tables'] ) && is_array( $input['tables'] ) ? array_map( 'strval', $input['tables'] ) : array();

		if ( '' === $config['search'] ) {
			return new WP_Error( 'swapline_empty_search', __( 'Enter something to search for.', 'swapline' ) );
		}
		if ( $config['regex'] ) {
			$error = Replacer::regex_error( $config['search'] );
			if ( null !== $error ) {
				/* translators: %s: the PCRE error message. */
				return new WP_Error( 'swapline_bad_regex', sprintf( __( 'The regular expression is not valid: %s', 'swapline' ), $error ) );
			}
		}
		if ( ! $require_tables ) {
			$config['tables'] = array();
			return $config;
		}
		if ( array() === $tables ) {
			return new WP_Error( 'swapline_no_tables', __( 'Select at least one table.', 'swapline' ) );
		}

		$valid = Tables::validate( $tables );
		if ( null === $valid ) {
			return new WP_Error( 'swapline_unknown_table', __( 'One or more selected tables do not exist.', 'swapline' ) );
		}
		$config['tables'] = $valid;

		return $config;
	}

	/**
	 * @param array<string, mixed> $config
	 * @return array<string, mixed>
	 */
	public static function profile_fields( array $config ): array {
		return array_intersect_key( array_merge( self::defaults(), $config ), self::defaults() );
	}
}
