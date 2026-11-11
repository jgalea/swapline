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
	public const MAX_PAIRS         = 50;

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'search'           => '',
			'replace'          => '',
			'pairs'            => array(),
			'tables'           => array(),
			'case_insensitive' => false,
			'regex'            => false,
			'skip_guid'        => true,
			'columns'          => array(),
			'skip_columns'     => array(),
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
		$pairs = array();
		if ( isset( $input['pairs'] ) && is_array( $input['pairs'] ) ) {
			if ( count( $input['pairs'] ) > self::MAX_PAIRS ) {
				/* translators: %s: the most search and replace pairs one run takes. */
				return new WP_Error( 'swapline_too_many_pairs', sprintf( __( 'A run takes up to %s search and replace pairs. Split the list over more than one run.', 'swapline' ), number_format_i18n( self::MAX_PAIRS ) ) );
			}
			foreach ( $input['pairs'] as $pair ) {
				if ( is_array( $pair ) && isset( $pair['search'] ) && is_scalar( $pair['search'] ) && '' !== (string) $pair['search'] ) {
					$pairs[] = array(
						'search'  => (string) $pair['search'],
						'replace' => isset( $pair['replace'] ) && is_scalar( $pair['replace'] ) ? (string) $pair['replace'] : '',
					);
				}
			}
		}
		if ( array() !== $pairs ) {
			$config['search']  = $pairs[0]['search'];
			$config['replace'] = $pairs[0]['replace'];
		} elseif ( '' !== $config['search'] ) {
			$pairs[] = array(
				'search'  => $config['search'],
				'replace' => $config['replace'],
			);
		}
		$config['pairs'] = $pairs;

		foreach ( array( 'case_insensitive', 'regex', 'skip_guid', 'dry_run' ) as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$config[ $field ] = rest_sanitize_boolean( $input[ $field ] );
			}
		}
		foreach ( array( 'columns', 'skip_columns' ) as $field ) {
			$names = $input[ $field ] ?? array();
			$names = is_string( $names ) ? explode( ',', $names ) : (array) $names;
			$list  = array();
			foreach ( $names as $name ) {
				$name = trim( (string) $name );
				if ( '' === $name ) {
					continue;
				}
				if ( ! preg_match( '/^[A-Za-z0-9_$]{1,64}$/', $name ) ) {
					/* translators: %s: the text entered as a column name. */
					return new WP_Error( 'swapline_bad_column', sprintf( __( '"%s" is not a column name.', 'swapline' ), $name ) );
				}
				$list[] = $name;
			}
			$config[ $field ] = array_values( array_unique( $list ) );
		}
		if ( isset( $input['page_size'] ) ) {
			$config['page_size'] = min( self::MAX_PAGE_SIZE, max( self::MIN_PAGE_SIZE, absint( $input['page_size'] ) ) );
		}

		$tables = isset( $input['tables'] ) && is_array( $input['tables'] ) ? array_map( 'strval', $input['tables'] ) : array();

		if ( '' === $config['search'] ) {
			return new WP_Error( 'swapline_empty_search', __( 'Enter something to search for.', 'swapline' ) );
		}
		if ( $config['regex'] ) {
			foreach ( $config['pairs'] as $pair ) {
				$error = Replacer::regex_error( $pair['search'] );
				if ( null !== $error ) {
					/* translators: %s: the PCRE error message. */
					return new WP_Error( 'swapline_bad_regex', sprintf( __( 'The regular expression is not valid: %s', 'swapline' ), $error ) );
				}
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
