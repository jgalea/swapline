<?php
// phpcs:disable -- records what the CLI command asks WP-CLI to do.

namespace {
	if ( ! class_exists( 'WP_CLI' ) ) {
		class WP_CLI {
			/** @var string[] */
			public static array $calls = array();
			public static bool $answer = true;

			public static function confirm( $question, $assoc_args = array() ) {
				self::$calls[] = 'confirm';
				if ( empty( $assoc_args['yes'] ) && ! self::$answer ) {
					throw new \RuntimeException( 'declined' );
				}
			}
			public static function error( $message ) {
				throw new \RuntimeException( (string) $message );
			}
			public static function success( $message ) {
				self::$calls[] = 'success';
			}
			public static function warning( $message ) {}
			public static function log( $message ) {}
		}
	}
}

namespace WP_CLI\Utils {
	function get_flag_value( $assoc_args, $flag, $default = null ) {
		return $assoc_args[ $flag ] ?? $default;
	}
	function make_progress_bar( $label, $count ) {
		return new class() {
			public function tick() {}
			public function finish() {}
		};
	}
	function format_items( $format, $items, $fields ) {}
}
