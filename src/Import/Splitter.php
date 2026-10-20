<?php

namespace Swapline\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Streams SQL statements out of a file handle, reading it in chunks.
 *
 * Statements end at a semicolon outside quoted strings, quoted identifiers
 * and comments. Quotes may be escaped with a backslash or by doubling them.
 * Returned statements keep their comments; Statement::normalize() strips them.
 * offset() is the byte position just after the last returned statement, where
 * a new Splitter can resume on the same file.
 */
final class Splitter {

	private const NORMAL = 0;
	private const QUOTED = 1;
	private const LINE   = 2;
	private const BLOCK  = 3;

	/**
	 * @var resource
	 */
	private $handle;

	private int $chunk;
	private string $buffer = '';
	private int $base;
	private bool $eof = false;

	/**
	 * @param resource $handle Readable stream, positioned where reading starts.
	 */
	public function __construct( $handle, int $chunk = 1048576 ) {
		$this->handle = $handle;
		$this->chunk  = max( 1, $chunk );
		$this->base   = (int) ftell( $handle );
	}

	public function offset(): int {
		return $this->base;
	}

	public function next(): ?string {
		$pos   = 0;
		$state = self::NORMAL;
		$quote = '';

		while ( true ) {
			$len = strlen( $this->buffer );

			if ( $pos >= $len ) {
				if ( $this->fill() ) {
					continue;
				}
				break;
			}

			if ( self::NORMAL === $state ) {
				$pos += strcspn( $this->buffer, "'\"`;-#/", $pos );
				if ( $pos >= $len ) {
					continue;
				}
				$char = $this->buffer[ $pos ];

				if ( ';' === $char ) {
					$statement = substr( $this->buffer, 0, $pos );
					$this->consume( $pos + 1 );
					if ( '' === trim( $statement ) ) {
						$pos = 0;
						continue;
					}
					return $statement;
				}
				if ( "'" === $char || '"' === $char || '`' === $char ) {
					$state = self::QUOTED;
					$quote = $char;
					++$pos;
					continue;
				}
				if ( '#' === $char ) {
					$state = self::LINE;
					++$pos;
					continue;
				}
				if ( $pos + 2 >= $len && ! $this->eof ) {
					$this->fill();
					continue;
				}
				$next = $this->buffer[ $pos + 1 ] ?? '';
				if ( '-' === $char && '-' === $next && ( ! isset( $this->buffer[ $pos + 2 ] ) || ord( $this->buffer[ $pos + 2 ] ) <= 32 ) ) {
					$state = self::LINE;
					$pos  += 2;
					continue;
				}
				if ( '/' === $char && '*' === $next && '!' !== ( $this->buffer[ $pos + 2 ] ?? '' ) ) {
					$state = self::BLOCK;
					$pos  += 2;
					continue;
				}
				++$pos;
				continue;
			}

			if ( self::QUOTED === $state ) {
				$pos += strcspn( $this->buffer, '`' === $quote ? '`' : '\\' . $quote, $pos );
				if ( $pos >= $len ) {
					continue;
				}
				if ( $pos + 1 >= $len && ! $this->eof ) {
					$this->fill();
					continue;
				}
				if ( '\\' === $this->buffer[ $pos ] ) {
					$pos += 2;
					continue;
				}
				if ( ( $this->buffer[ $pos + 1 ] ?? '' ) === $quote ) {
					$pos += 2;
					continue;
				}
				$state = self::NORMAL;
				++$pos;
				continue;
			}

			if ( self::LINE === $state ) {
				$end = strpos( $this->buffer, "\n", $pos );
				if ( false === $end ) {
					$pos = $len;
					continue;
				}
				$state = self::NORMAL;
				$pos   = $end + 1;
				continue;
			}

			$end = strpos( $this->buffer, '*/', $pos );
			if ( false === $end ) {
				$pos = max( $pos, $len - 1 );
				if ( ! $this->fill() ) {
					break;
				}
				continue;
			}
			$state = self::NORMAL;
			$pos   = $end + 2;
		}

		$rest = $this->buffer;
		$this->consume( strlen( $rest ) );
		return '' === trim( $rest ) ? null : $rest;
	}

	private function fill(): bool {
		if ( $this->eof ) {
			return false;
		}
		$data = fread( $this->handle, $this->chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- streaming read.
		if ( false === $data || '' === $data ) {
			$this->eof = true;
			return false;
		}
		$this->buffer .= $data;
		return true;
	}

	private function consume( int $bytes ): void {
		$this->buffer = (string) substr( $this->buffer, $bytes );
		$this->base  += $bytes;
	}
}
