<?php

namespace Swapline\Tests;

/**
 * The obvious explode(';') splitter, kept to show the real one's tests catch it.
 */
final class NaiveSplitter {

	/**
	 * @var string[]
	 */
	private array $statements;

	private int $offset;

	/**
	 * @param resource $handle
	 */
	public function __construct( $handle, int $chunk = 1048576 ) {
		unset( $chunk );
		$this->offset     = (int) ftell( $handle );
		$this->statements = array_values( array_filter( explode( ';', (string) stream_get_contents( $handle ) ), static fn( $s ) => '' !== trim( $s ) ) );
	}

	public function offset(): int {
		return $this->offset;
	}

	public function next(): ?string {
		$statement = array_shift( $this->statements );
		if ( null !== $statement ) {
			$this->offset += strlen( $statement ) + 1;
		}
		return $statement;
	}
}
