<?php

namespace Swapline\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Applies several search and replace pairs to a value, in order, each on the output of the one before.
 */
final class Sequence {

	/**
	 * @param Replacer[] $engines
	 */
	public function __construct( private array $engines ) {}

	public function replace( string $value ): Result {
		$current      = $value;
		$replacements = 0;
		$failures     = 0;

		foreach ( $this->engines as $engine ) {
			$result        = $engine->replace( $current );
			$current       = $result->value;
			$replacements += $result->replacements;
			$failures      = max( $failures, $result->failures );
		}

		return new Result( $current, $replacements, $failures, $current !== $value );
	}
}
