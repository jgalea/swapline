<?php

namespace Swapline\Tests\Unit;

use Swapline\Run\Cells;
use Swapline\Run\Config;
use Swapline\Run\Runner;
use Swapline\Tests\Support\DbTestCase;

final class PairsTest extends DbTestCase {

	/**
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	private function config( array $extra ): array {
		$config = Config::from_input(
			array_merge(
				array(
					'tables'  => array( 'wp_posts' ),
					'dry_run' => false,
				),
				$extra
			)
		);
		$this->assertIsArray( $config );
		return $config;
	}

	private function run_config( array $config ): void {
		$job = Runner::start( $config, 'cli' );
		while ( ! $job['done'] ) {
			$job = Runner::step( $job );
		}
		Runner::finish( $job );
	}

	public function test_search_and_replace_alone_still_work(): void {
		$config = $this->config(
			array(
				'search'  => 'a',
				'replace' => 'b',
			)
		);

		$this->assertSame(
			array(
				array(
					'search'  => 'a',
					'replace' => 'b',
				),
			),
			$config['pairs']
		);
		$this->assertSame( 'bxb', Cells::engine( $config )->replace( 'axa' )->value );
	}

	public function test_pairs_run_in_order_each_on_the_previous_output(): void {
		$config = $this->config(
			array(
				'pairs' => array(
					array(
						'search'  => 'cat',
						'replace' => 'dog',
					),
					array(
						'search'  => 'dog',
						'replace' => 'fox',
					),
				),
			)
		);

		$result = Cells::engine( $config )->replace( 'a cat' );

		$this->assertSame( 'a fox', $result->value );
		$this->assertSame( 2, $result->replacements );
		$this->assertTrue( $result->changed );
		$this->assertSame( 'cat', $config['search'] );
	}

	public function test_empty_searches_are_dropped_and_a_bad_regex_in_any_pair_is_refused(): void {
		$config = $this->config(
			array(
				'pairs' => array(
					array(
						'search'  => '',
						'replace' => 'x',
					),
					array(
						'search'  => 'a',
						'replace' => 'b',
					),
				),
			)
		);
		$this->assertCount( 1, $config['pairs'] );

		$bad = Config::from_input(
			array(
				'tables' => array( 'wp_posts' ),
				'regex'  => true,
				'pairs'  => array(
					array(
						'search'  => 'ok',
						'replace' => '',
					),
					array(
						'search'  => '(',
						'replace' => '',
					),
				),
			)
		);
		$this->assertInstanceOf( \WP_Error::class, $bad );
		$this->assertSame( 'swapline_bad_regex', $bad->get_error_code() );
	}

	public function test_a_run_applies_every_pair_to_the_table(): void {
		$this->insert(
			'wp_posts',
			array(
				'ID'           => 1,
				'post_title'   => 't',
				'post_content' => 'old.test and red',
			)
		);

		$this->run_config(
			$this->config(
				array(
					'pairs' => array(
						array(
							'search'  => 'old.test',
							'replace' => 'new.test',
						),
						array(
							'search'  => 'red',
							'replace' => 'blue',
						),
					),
				)
			)
		);

		$this->assertSame( 'new.test and blue', $this->rows( 'wp_posts' )[0]['post_content'] );
	}
}
