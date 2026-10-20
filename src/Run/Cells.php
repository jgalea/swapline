<?php

namespace Swapline\Run;

use Swapline\Engine\Replacer;

defined( 'ABSPATH' ) || exit;

final class Cells {

	/**
	 * @param string[]                   $columns Text columns to rewrite.
	 * @param array<string, string|null> $row
	 * @return array{changes: array<string, string>, replacements: int, failures: int}
	 */
	public static function replace( Replacer $engine, array $columns, array $row ): array {
		$out = array(
			'changes'      => array(),
			'replacements' => 0,
			'failures'     => 0,
		);

		foreach ( $columns as $column ) {
			$value = $row[ $column ] ?? null;
			if ( null === $value ) {
				continue;
			}
			$result               = $engine->replace( (string) $value );
			$out['failures']     += $result->failures;
			$out['replacements'] += $result->replacements;
			if ( $result->changed ) {
				$out['changes'][ $column ] = $result->value;
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $config Validated Config.
	 */
	public static function engine( array $config ): Replacer {
		return new Replacer( (string) $config['search'], (string) $config['replace'], (bool) $config['case_insensitive'], (bool) $config['regex'] );
	}
}
