<?php

namespace Swapline\Tests;

use Swapline\Engine\Result;

/**
 * Control implementation: a bare str_replace. Running the suite with
 * SWAPLINE_NAIVE_ENGINE=1 swaps it in, and every test should fail.
 */
final class NaiveReplacer {

	public function __construct( private string $search, private string $replace ) {}

	public static function regex_error( string $pattern ): ?string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the control accepts every pattern.
		return null;
	}

	public function replace( string $subject ): Result {
		$count = 0;
		$out   = str_replace( $this->search, $this->replace, $subject, $count );
		return new Result( $out, $count, 0, $out !== $subject );
	}
}
