<?php

namespace Swapline\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Side-by-side diff of one cell, built with core's wp_text_diff().
 */
final class Diff {

	public static function render( string $before, string $after ): string {
		$before = wp_check_invalid_utf8( $before, true );
		$after  = wp_check_invalid_utf8( $after, true );

		$diff = wp_text_diff(
			self::lines( $before ),
			self::lines( $after ),
			array(
				'title_left'      => __( 'Before', 'swapline' ),
				'title_right'     => __( 'After', 'swapline' ),
				'show_split_view' => true,
			)
		);

		if ( '' !== $diff ) {
			return $diff;
		}

		return '<p class="description">' . esc_html__( 'The change is whitespace only.', 'swapline' ) . '</p>';
	}

	/**
	 * Breaks long single-line values (serialized data, JSON, HTML) after
	 * structural characters so the diff can align them. Display only.
	 */
	private static function lines( string $value ): string {
		if ( strlen( $value ) <= 100 ) {
			return $value;
		}
		return (string) preg_replace( '/(;|\{|\}|,(?=["{\[])|>)(?!\n)/', "\$1\n", $value );
	}
}
