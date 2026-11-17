<?php

namespace Swapline\Tests\Unit;

use Swapline\Api;
use Swapline\Cli\Command;
use Swapline\Run\History;
use Swapline\Tests\Support\DbTestCase;

final class ConcurrentRunTest extends DbTestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/../Support/wp-cli-stubs.php';
		for ( $i = 1; $i <= 30; $i++ ) {
			$this->insert(
				'wp_posts',
				array(
					'ID'           => $i,
					'post_title'   => 't',
					'post_content' => 'http://old.test/' . $i,
				)
			);
		}
	}

	private function preview_during_the_second_batch(): void {
		$calls = 0;
		$GLOBALS['swapline_test']['filters']['swapline_sensitive_columns'] = function ( $columns ) use ( &$calls ) {
			++$calls;
			if ( 11 === $calls ) {
				Api::preview(
					array(
						'search'  => 'old.test',
						'replace' => 'other',
						'tables'  => array( 'wp_posts' ),
					)
				);
			}
			return $columns;
		};
	}

	public function test_a_preview_during_a_cli_run_leaves_the_diff_and_undo_intact(): void {
		$this->preview_during_the_second_batch();

		( new Command() )->run(
			array( 'old.test', 'new.test', 'wp_posts' ),
			array(
				'format'    => 'table',
				'yes'       => true,
				'page-size' => '10',
			)
		);

		$run = History::runs()[0];
		$this->assertSame( 30, History::changes( $run['id'], 1, 100 )['total'] );
		$changes = Api::changes( $run['id'] );
		$this->assertTrue( $changes['complete'] );
		$this->assertCount( 30, $changes['cells'] );
		$this->assertIsArray( Api::undo( $run['id'] ) );
		$this->assertSame( 'http://old.test/1', $this->rows( 'wp_posts' )[0]['post_content'] );
	}

	public function test_a_preview_during_an_api_replace_leaves_the_diff_and_undo_intact(): void {
		$this->preview_during_the_second_batch();

		$result = Api::replace(
			array(
				'search'    => 'old.test',
				'replace'   => 'new.test',
				'tables'    => array( 'wp_posts' ),
				'page_size' => 10,
			),
			false
		);

		$run = $result['run'];
		$this->assertSame( 30, History::changes( $run['id'], 1, 100 )['total'] );
		$this->assertTrue( Api::changes( $run['id'] )['complete'] );
		$this->assertIsArray( Api::undo( $run['id'] ) );
		$this->assertSame( 'http://old.test/1', $this->rows( 'wp_posts' )[0]['post_content'] );
	}
}
