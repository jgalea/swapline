<?php

namespace Swapline\Engine;

defined( 'ABSPATH' ) || exit;

final class Result {

	public function __construct(
		public string $value,
		public int $replacements,
		public int $failures,
		public bool $changed
	) {}
}
