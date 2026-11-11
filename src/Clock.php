<?php

namespace Swapline;

defined( 'ABSPATH' ) || exit;

/**
 * How long one batched request may keep working before it hands back to the browser.
 */
final class Clock {

	public static function deadline(): float {
		$limit  = (int) ini_get( 'max_execution_time' );
		$budget = $limit > 0 ? min( 8, max( 1, intdiv( $limit, 3 ) ) ) : 8;
		return microtime( true ) + (float) apply_filters( 'swapline_step_seconds', $budget );
	}

	/**
	 * Whether a saved job was worked on in the last few minutes, so a second job must not replace it.
	 *
	 * @param array<string, mixed> $job
	 */
	public static function is_recent( array $job ): bool {
		return time() - (int) ( $job['touched'] ?? 0 ) < 300;
	}
}
