<?php

namespace Swapline\Tests\Unit;

use Swapline\Run\Config;
use Swapline\Run\History;
use Swapline\Run\Runner;
use Swapline\Tests\Support\DbTestCase;

final class RunnerTest extends DbTestCase {

	/**
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	private function config( array $extra = array() ): array {
		$config = Config::from_input(
			array_merge(
				array(
					'search'  => 'old.test',
					'replace' => 'new.test',
					'tables'  => array( 'wp_posts' ),
					'dry_run' => false,
				),
				$extra
			)
		);
		$this->assertIsArray( $config );
		return $config;
	}

	/**
	 * @param array<string, mixed> $job
	 * @return array<string, mixed>
	 */
	private function run_all( array $job ): array {
		while ( ! $job['done'] ) {
			$job = Runner::step( $job );
		}
		return $job;
	}

	public function test_live_run_rewrites_rows_and_records_the_cells(): void {
		$this->insert(
			'wp_posts',
			array(
				'ID'           => 1,
				'post_title'   => 'a',
				'post_content' => 'see http://old.test/x',
			)
		);
		$this->insert(
			'wp_posts',
			array(
				'ID'           => 2,
				'post_title'   => 'b',
				'post_content' => 'nothing',
			)
		);

		$job = $this->run_all( Runner::start( $this->config(), 'admin' ) );
		$run = Runner::finish( $job );

		$this->assertSame( 'see http://new.test/x', $this->rows( 'wp_posts' )[0]['post_content'] );
		$this->assertSame( 1, $run['totals']['cells_changed'] );
		$changes = History::changes( $run['id'], 1, 10 );
		$this->assertSame( 1, $changes['total'] );
		$this->assertSame( 'see http://old.test/x', $changes['items'][0]['old_value'] );
	}

	public function test_dry_run_writes_nothing(): void {
		$this->insert(
			'wp_posts',
			array(
				'ID'           => 1,
				'post_title'   => 'a',
				'post_content' => 'http://old.test',
			)
		);

		$job = $this->run_all( Runner::start( $this->config( array( 'dry_run' => true ) ), 'admin' ) );

		$this->assertSame( 'http://old.test', $this->rows( 'wp_posts' )[0]['post_content'] );
		$this->assertSame( 1, Runner::finish( $job )['totals']['cells_changed'] );
	}
}
