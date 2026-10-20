<?php

namespace Swapline\Engine;

use UnexpectedValueException;

defined( 'ABSPATH' ) || exit;

/**
 * Rebuilds a serialized string token by token, passing every string value
 * (never array keys or property names) through a callback and rewriting its
 * byte length. Objects are never instantiated, so any class survives intact.
 */
final class SerializedWalker {

	private string $data;
	private int $pos;
	private int $length;

	/**
	 * @var callable(string): string
	 */
	private $callback;

	public function __construct( callable $callback ) {
		$this->callback = $callback;
	}

	public function rebuild( string $data ): string {
		$this->data   = $data;
		$this->pos    = 0;
		$this->length = strlen( $data );

		$out = $this->value( true );

		if ( $this->pos !== $this->length ) {
			throw new UnexpectedValueException( 'Trailing data after serialized value.' );
		}

		return $out;
	}

	private function value( bool $replace ): string {
		$type = $this->peek();

		switch ( $type ) {
			case 'N':
				return $this->expect( 'N;' );

			case 'b':
			case 'i':
			case 'd':
			case 'r':
			case 'R':
				return $this->until( ';' );

			case 's':
				return $this->string_value( $replace );

			case 'S':
				return $this->escaped_string();

			case 'a':
				$this->expect( 'a:' );
				$count = $this->integer();
				$this->expect( ':{' );
				$out = 'a:' . $count . ':{' . $this->members( $count, $replace );
				return $out . $this->expect( '}' );

			case 'O':
				$this->expect( 'O:' );
				$class = $this->counted_bytes();
				$this->expect( ':' );
				$count = $this->integer();
				$this->expect( ':{' );
				$out = 'O:' . strlen( $class ) . ':"' . $class . '":' . $count . ':{' . $this->members( $count, $replace );
				return $out . $this->expect( '}' );

			case 'C':
				$start = $this->pos;
				$this->expect( 'C:' );
				$this->counted_bytes();
				$this->expect( ':' );
				$size = $this->integer();
				$this->expect( ':{' );
				$this->skip( $size );
				$this->expect( '}' );
				return substr( $this->data, $start, $this->pos - $start );

			case 'E':
				$start = $this->pos;
				$this->expect( 'E:' );
				$this->counted_bytes();
				$this->expect( ';' );
				return substr( $this->data, $start, $this->pos - $start );
		}

		throw new UnexpectedValueException( 'Unknown serialized token.' );
	}

	private function members( int $count, bool $replace ): string {
		$out = '';
		for ( $i = 0; $i < $count; $i++ ) {
			$out .= $this->value( false );
			$out .= $this->value( $replace );
		}
		return $out;
	}

	private function string_value( bool $replace ): string {
		$this->expect( 's:' );
		$payload = $this->counted_bytes();
		$this->expect( ';' );

		if ( $replace ) {
			$payload = ( $this->callback )( $payload );
		}

		return 's:' . strlen( $payload ) . ':"' . $payload . '";';
	}

	private function escaped_string(): string {
		$start = $this->pos;
		$this->expect( 'S:' );
		$chars = $this->integer();
		$this->expect( ':"' );
		for ( $i = 0; $i < $chars; $i++ ) {
			if ( $this->pos >= $this->length ) {
				throw new UnexpectedValueException( 'Unterminated escaped string.' );
			}
			$this->pos += '\\' === $this->data[ $this->pos ] ? 3 : 1;
		}
		$this->expect( '";' );
		return substr( $this->data, $start, $this->pos - $start );
	}

	/**
	 * Reads `N:"<N bytes>"` and returns the bytes.
	 */
	private function counted_bytes(): string {
		$size = $this->integer();
		$this->expect( ':"' );
		$bytes = (string) substr( $this->data, $this->pos, $size );
		$this->skip( $size );
		$this->expect( '"' );
		return $bytes;
	}

	private function integer(): int {
		$start = $this->pos;
		while ( $this->pos < $this->length && ctype_digit( $this->data[ $this->pos ] ) ) {
			++$this->pos;
		}
		if ( $start === $this->pos ) {
			throw new UnexpectedValueException( 'Expected a number.' );
		}
		return (int) substr( $this->data, $start, $this->pos - $start );
	}

	private function until( string $terminator ): string {
		$end = strpos( $this->data, $terminator, $this->pos );
		if ( false === $end ) {
			throw new UnexpectedValueException( 'Missing terminator.' );
		}
		$out       = substr( $this->data, $this->pos, $end - $this->pos + 1 );
		$this->pos = $end + 1;
		return $out;
	}

	private function expect( string $token ): string {
		if ( $this->pos + strlen( $token ) > $this->length || substr_compare( $this->data, $token, $this->pos, strlen( $token ) ) !== 0 ) {
			throw new UnexpectedValueException( 'Unexpected token.' );
		}
		$this->pos += strlen( $token );
		return $token;
	}

	private function skip( int $bytes ): void {
		if ( $this->pos + $bytes > $this->length ) {
			throw new UnexpectedValueException( 'Length runs past the end of the data.' );
		}
		$this->pos += $bytes;
	}

	private function peek(): string {
		if ( $this->pos >= $this->length ) {
			throw new UnexpectedValueException( 'Unexpected end of data.' );
		}
		return $this->data[ $this->pos ];
	}
}
