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

	public function test_credentials_are_changed_but_not_copied_into_the_history(): void {
		$this->db->exec_sql( 'CREATE TABLE wp_users (ID INTEGER PRIMARY KEY, user_login TEXT, user_pass TEXT)' );
		$this->db->exec_sql( 'CREATE TABLE wp_usermeta (umeta_id INTEGER PRIMARY KEY, user_id INTEGER, meta_key TEXT, meta_value TEXT)' );
		$this->insert(
			'wp_users',
			array(
				'ID'         => 1,
				'user_login' => 'old-login',
				'user_pass'  => 'old-hash',
			)
		);
		$this->insert(
			'wp_usermeta',
			array(
				'umeta_id'   => 1,
				'user_id'    => 1,
				'meta_key'   => 'session_tokens',
				'meta_value' => 'old-token',
			)
		);
		$this->insert(
			'wp_usermeta',
			array(
				'umeta_id'   => 2,
				'user_id'    => 1,
				'meta_key'   => 'nickname',
				'meta_value' => 'old-nick',
			)
		);

		$job = $this->run_all(
			Runner::start(
				$this->config(
					array(
						'search'  => 'old',
						'replace' => 'new',
						'tables'  => array( 'wp_users', 'wp_usermeta' ),
					)
				),
				'admin'
			)
		);
		$run = Runner::finish( $job );

		$this->assertSame( 'new-hash', $this->rows( 'wp_users' )[0]['user_pass'] );
		$this->assertSame( 'new-token', $this->rows( 'wp_usermeta' )[0]['meta_value'] );
		$this->assertSame( 4, $run['totals']['cells_changed'] );
		$kept = History::changes( $run['id'], 1, 10 )['items'];
		$this->assertEqualsCanonicalizing( array( 'user_login', 'meta_value' ), array_column( $kept, 'column_name' ) );
		$this->assertSame( array( 'old-nick' ), array_column( array_filter( $kept, fn( $i ) => 'meta_value' === $i['column_name'] ), 'old_value' ) );
		$this->assertTrue( $run['truncated'] );
	}

	public function test_a_preview_records_only_the_samples_it_returns(): void {
		for ( $i = 1; $i <= 40; $i++ ) {
			$this->insert(
				'wp_posts',
				array(
					'ID'           => $i,
					'post_title'   => 'a',
					'post_content' => 'http://old.test/' . $i,
				)
			);
		}
		$preview = \Swapline\Api::preview(
			array(
				'search'  => 'old.test',
				'replace' => 'new.test',
				'tables'  => array( 'wp_posts' ),
				'samples' => 3,
			)
		);

		$this->assertCount( 3, $preview['samples'] );
		$this->assertSame( 40, $preview['totals']['cells_changed'] );
		$this->assertSame( array(), $this->rows( 'wp_swapline_changes' ), 'Nothing is left behind after a preview.' );
	}

	public function test_a_dry_run_records_no_more_cells_than_its_cap(): void {
		for ( $i = 1; $i <= 700; $i++ ) {
			$this->insert(
				'wp_posts',
				array(
					'ID'           => $i,
					'post_title'   => 'a',
					'post_content' => 'http://old.test/' . $i,
				)
			);
		}

		$job = $this->run_all( Runner::start( $this->config( array( 'dry_run' => true ) ), 'admin' ) );
		$run = Runner::finish( $job );

		$this->assertSame( 500, History::changes( $run['id'], 1, 1 )['total'] );
		$this->assertTrue( $run['truncated'] );
	}

	public function test_a_run_can_be_started_with_a_lower_recording_limit(): void {
		for ( $i = 1; $i <= 10; $i++ ) {
			$this->insert(
				'wp_posts',
				array(
					'ID'           => $i,
					'post_title'   => 'a',
					'post_content' => 'http://old.test/' . $i,
				)
			);
		}

		$job = $this->run_all( Runner::start( $this->config( array( 'dry_run' => true ) ), 'api', 2 ) );

		$this->assertSame( 2, History::changes( $job['id'], 1, 50 )['total'] );
	}
}
