<?php

namespace Swapline\Engine;

use UnexpectedValueException;

defined( 'ABSPATH' ) || exit;

/**
 * Search and replace that understands serialized PHP and JSON.
 *
 * Serialized values are rebuilt with corrected byte lengths at any depth,
 * including serialized strings nested inside serialized strings. JSON
 * documents are decoded and re-encoded only when that round trip is lossless;
 * otherwise the JSON-escaped forms of the search are replaced in the raw text
 * and the result must still decode.
 */
final class Replacer {

	private const MAX_DEPTH = 32;

	private const JSON_FLAG_SETS = array(
		0,
		JSON_UNESCAPED_SLASHES,
		JSON_UNESCAPED_UNICODE,
		JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
	);

	private string $search;
	private string $replace;
	private bool $case_insensitive;
	private bool $regex;
	private int $replacements = 0;
	private int $failures     = 0;

	/**
	 * @var array<string, array{0: string, 1: string}>|null
	 */
	private ?array $json_variants = null;

	public function __construct( string $search, string $replace, bool $case_insensitive = false, bool $regex = false ) {
		if ( '' === $search ) {
			throw new \InvalidArgumentException( 'The search string cannot be empty.' );
		}
		if ( $regex ) {
			$error = self::regex_error( $search );
			if ( null !== $error ) {
				throw new \InvalidArgumentException( $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never rendered; callers validate first.
			}
		}

		$this->search           = $search;
		$this->replace          = $replace;
		$this->case_insensitive = $case_insensitive;
		$this->regex            = $regex;
	}

	/**
	 * Returns a readable error for an invalid pattern, or null when it compiles.
	 * The pattern is entered without delimiters.
	 */
	public static function regex_error( string $pattern ): ?string {
		if ( '' === $pattern ) {
			return 'The pattern is empty.';
		}

		$compiled = self::compile( $pattern, '', self::is_utf8( $pattern ) );
		$message  = null;

		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
			static function ( int $errno, string $errstr ) use ( &$message ): bool {
				$message = preg_replace( '/^preg_match\(\): /', '', $errstr );
				return true;
			}
		);
		$ok = preg_match( $compiled, '' );
		restore_error_handler();

		if ( false === $ok ) {
			return $message ?? preg_last_error_msg();
		}
		if ( 1 === $ok ) {
			return 'The pattern matches an empty string, so it would insert the replacement between every character.';
		}

		return null;
	}

	public function replace( string $subject ): Result {
		$this->replacements = 0;
		$this->failures     = 0;

		$value = $this->process( $subject, 0 );

		return new Result( $value, $this->replacements, $this->failures, $value !== $subject );
	}

	private function process( string $value, int $depth ): string {
		if ( $depth > self::MAX_DEPTH ) {
			return $value;
		}

		if ( self::looks_serialized( $value ) ) {
			return $this->process_serialized( $value, $depth );
		}

		if ( self::looks_like_json( $value ) ) {
			return $this->process_json( $value, $depth );
		}

		return $this->replace_plain( $value );
	}

	private function process_serialized( string $value, int $depth ): string {
		$trimmed = trim( $value );
		$lead    = substr( $value, 0, (int) strpos( $value, $trimmed[0] ) );
		$trail   = (string) substr( $value, strlen( $lead ) + strlen( $trimmed ) );

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		$decoded = @unserialize( $trimmed, array( 'allowed_classes' => false ) );

		if ( false === $decoded && 'b:0;' !== $trimmed ) {
			$this->count_failure_if_matching( $value );
			return $value;
		}

		$walker = new SerializedWalker(
			function ( string $payload ) use ( $depth ): string {
				return $this->process( $payload, $depth + 1 );
			}
		);

		$before_replacements = $this->replacements;
		$before_failures     = $this->failures;

		try {
			$rebuilt = $walker->rebuild( $trimmed );
			if ( $walker->saw_escaped_string() ) {
				throw new UnexpectedValueException( 'S: strings carry character counts that are not rebuilt.' );
			}
		} catch ( UnexpectedValueException $e ) {
			$this->replacements = $before_replacements;
			$this->failures     = $before_failures;
			$this->count_failure_if_matching( $value );
			return $value;
		}

		return $lead . $rebuilt . $trail;
	}

	private function process_json( string $value, int $depth ): string {
		$decoded = json_decode( $value );

		if ( JSON_ERROR_NONE !== json_last_error() || ! ( is_array( $decoded ) || is_object( $decoded ) ) ) {
			return $this->replace_plain( $value );
		}

		$flags = $this->lossless_json_flags( $value, $decoded );

		if ( null !== $flags ) {
			$walked = $this->walk_json( $decoded, $depth );
			$out    = self::json( $walked, $flags );
			return null === $out ? $value : $out;
		}

		$before = $this->replacements;
		$out    = $this->regex ? $this->replace_plain( $value ) : $this->replace_json_text( $value );

		if ( $out !== $value ) {
			$after = json_decode( $out );
			if ( JSON_ERROR_NONE !== json_last_error() || ! self::serialized_leaves_survive( $decoded, $after ) ) {
				$this->replacements = $before;
				++$this->failures;
				return $value;
			}
		}

		return $out;
	}

	/**
	 * A text replace can't fix byte lengths, so every string leaf that
	 * unserialized before must still unserialize, leaf by leaf in order.
	 *
	 * @param mixed $before
	 * @param mixed $after
	 */
	private static function serialized_leaves_survive( $before, $after ): bool {
		$old = self::string_leaves( $before );
		$new = self::string_leaves( $after );
		if ( count( $old ) !== count( $new ) ) {
			return false;
		}
		foreach ( $old as $i => $leaf ) {
			if ( $leaf !== $new[ $i ] && self::unserializes( $leaf ) && ! self::unserializes( $new[ $i ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param mixed $node
	 * @return string[]
	 */
	private static function string_leaves( $node ): array {
		if ( is_string( $node ) ) {
			return array( $node );
		}
		if ( is_object( $node ) ) {
			$node = get_object_vars( $node );
		}
		$out = array();
		if ( is_array( $node ) ) {
			foreach ( $node as $child ) {
				array_push( $out, ...self::string_leaves( $child ) );
			}
		}
		return $out;
	}

	private static function unserializes( string $value ): bool {
		$trimmed = trim( $value );
		if ( ! self::looks_serialized( $trimmed ) ) {
			return false;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		return 'b:0;' === $trimmed || false !== @unserialize( $trimmed, array( 'allowed_classes' => false ) );
	}

	/**
	 * @param mixed $decoded
	 */
	private function lossless_json_flags( string $value, $decoded ): ?int {
		foreach ( self::JSON_FLAG_SETS as $flags ) {
			foreach ( array( $flags, $flags | JSON_PRESERVE_ZERO_FRACTION, $flags | JSON_PRETTY_PRINT, $flags | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION ) as $candidate ) {
				if ( self::json( $decoded, $candidate ) === $value ) {
					return $candidate;
				}
			}
		}
		return null;
	}

	/**
	 * @param mixed $node
	 * @return mixed
	 */
	private function walk_json( $node, int $depth ) {
		if ( is_string( $node ) ) {
			return $this->process( $node, $depth + 1 );
		}
		if ( is_array( $node ) ) {
			foreach ( $node as $key => $child ) {
				$node[ $key ] = $this->walk_json( $child, $depth );
			}
			return $node;
		}
		if ( is_object( $node ) ) {
			foreach ( get_object_vars( $node ) as $key => $child ) {
				$node->$key = $this->walk_json( $child, $depth );
			}
		}
		return $node;
	}

	/**
	 * Replaces the JSON-escaped spellings of the search (escaped slashes,
	 * \u escapes) with the same spelling of the replacement, in one pass.
	 */
	private function replace_json_text( string $value ): string {
		if ( null === $this->json_variants ) {
			$this->json_variants = array();
			foreach ( self::JSON_FLAG_SETS as $flags ) {
				$search  = self::json( $this->search, $flags );
				$replace = self::json( $this->replace, $flags );
				if ( null === $search || null === $replace ) {
					continue;
				}
				$search = substr( $search, 1, -1 );
				$key    = $this->case_insensitive ? self::lower( $search ) : $search;
				if ( '' !== $search && ! isset( $this->json_variants[ $key ] ) ) {
					$this->json_variants[ $key ] = array( $search, substr( $replace, 1, -1 ) );
				}
			}
		}

		$alternatives = array();
		$replacements = array();
		foreach ( $this->json_variants as $pair ) {
			$alternatives[] = '(' . preg_quote( $pair[0], '/' ) . ')';
			$replacements[] = $pair[1];
		}

		$utf8    = self::is_utf8( $value );
		$pattern = '/' . implode( '|', $alternatives ) . '/' . ( $this->case_insensitive ? 'i' : '' ) . ( $utf8 ? 'u' : '' );
		$count   = 0;
		$out     = preg_replace_callback(
			$pattern,
			static function ( array $m ) use ( $replacements ): string {
				$groups = count( $m );
				for ( $i = 1; $i < $groups; $i++ ) {
					if ( '' !== $m[ $i ] ) {
						return $replacements[ $i - 1 ];
					}
				}
				return $m[0];
			},
			$value,
			-1,
			$count
		);

		if ( null === $out ) {
			++$this->failures;
			return $value;
		}

		$this->replacements += $count;
		return $out;
	}

	private function replace_plain( string $value ): string {
		$count = 0;

		if ( $this->regex ) {
			$out = preg_replace( $this->pattern_for( $value ), $this->replace, $value, -1, $count );
			if ( null === $out ) {
				++$this->failures;
				return $value;
			}
		} elseif ( $this->case_insensitive ) {
			if ( self::is_utf8( $value ) && self::is_utf8( $this->search ) ) {
				$replace = $this->replace;
				$out     = preg_replace_callback(
					'/' . preg_quote( $this->search, '/' ) . '/iu',
					static function () use ( $replace ): string {
						return $replace;
					},
					$value,
					-1,
					$count
				);
				if ( null === $out ) {
					++$this->failures;
					return $value;
				}
			} else {
				$out = str_ireplace( $this->search, $this->replace, $value, $count );
			}
		} else {
			$out = str_replace( $this->search, $this->replace, $value, $count );
		}

		$this->replacements += $count;
		return $out;
	}

	private function count_failure_if_matching( string $value ): void {
		$before = $this->replacements;
		if ( $this->replace_plain( $value ) !== $value || $this->replacements > $before ) {
			++$this->failures;
		}
		$this->replacements = $before;
	}

	private function pattern_for( string $subject ): string {
		$utf8 = self::is_utf8( $subject ) && self::is_utf8( $this->search );
		return self::compile( $this->search, $this->case_insensitive ? 'i' : '', $utf8 );
	}

	private static function compile( string $pattern, string $flags, bool $utf8 ): string {
		$delimiter = '/';
		foreach ( array( '/', '#', '~', '%', '@', '!', ';', '`' ) as $candidate ) {
			if ( false === strpos( $pattern, $candidate ) ) {
				$delimiter = $candidate;
				break;
			}
		}
		if ( false !== strpos( $pattern, $delimiter ) ) {
			$pattern = preg_replace( '/(?<!\\\\)((?:\\\\\\\\)*)\//', '$1\\/', $pattern );
		}
		return $delimiter . $pattern . $delimiter . $flags . ( $utf8 ? 'u' : '' );
	}

	public static function looks_serialized( string $data ): bool {
		$data = trim( $data );
		if ( 'N;' === $data ) {
			return true;
		}
		if ( strlen( $data ) < 4 || ':' !== $data[1] ) {
			return false;
		}
		$last = substr( $data, -1 );
		if ( ';' !== $last && '}' !== $last ) {
			return false;
		}
		$token = $data[0];
		switch ( $token ) {
			case 's':
			case 'S':
				if ( '"' !== substr( $data, -2, 1 ) ) {
					return false;
				}
				return (bool) preg_match( "/^{$token}:[0-9]+:/s", $data );
			case 'a':
			case 'O':
			case 'C':
			case 'E':
				return (bool) preg_match( "/^{$token}:[0-9]+:/s", $data );
			case 'b':
			case 'i':
			case 'd':
				return (bool) preg_match( "/^{$token}:[0-9.E+-]+;\$/", $data );
		}
		return false;
	}

	public static function looks_like_json( string $data ): bool {
		$data = trim( $data );
		if ( strlen( $data ) < 2 ) {
			return false;
		}
		$pair = $data[0] . substr( $data, -1 );
		return '{}' === $pair || '[]' === $pair;
	}

	/**
	 * @param mixed $value
	 */
	private static function json( $value, int $flags ): ?string {
		$out = json_encode( $value, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- byte-exact output is required to compare against stored JSON.
		return false === $out ? null : $out;
	}

	private static function is_utf8( string $value ): bool {
		return 1 === preg_match( '//u', $value );
	}

	private static function lower( string $value ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}
}
