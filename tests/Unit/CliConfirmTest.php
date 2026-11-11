<?php

namespace Swapline\Tests\Unit;

use RuntimeException;
use Swapline\Cli\Command;
use Swapline\Tests\Support\DbTestCase;
use WP_CLI;

final class CliConfirmTest extends DbTestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/../Support/wp-cli-stubs.php';
		WP_CLI::$calls  = array();
		WP_CLI::$answer = false;
		$this->insert(
			'wp_posts',
			array(
				'ID'           => 1,
				'post_title'   => 'a',
				'post_content' => 'http://old.test',
			)
		);
	}

	public function test_a_live_run_asks_first_and_stops_when_declined(): void {
		try {
			( new Command() )->run( array( 'old.test', 'new.test', 'wp_posts' ), array( 'format' => 'table' ) );
			$this->fail( 'The run went ahead without confirmation.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'declined', $e->getMessage() );
		}

		$this->assertSame( 'http://old.test', $this->rows( 'wp_posts' )[0]['post_content'] );
	}

	public function test_yes_skips_the_question(): void {
		( new Command() )->run(
			array( 'old.test', 'new.test', 'wp_posts' ),
			array(
				'format' => 'table',
				'yes'    => true,
			)
		);

		$this->assertSame( 'http://new.test', $this->rows( 'wp_posts' )[0]['post_content'] );
	}

	public function test_a_dry_run_does_not_ask(): void {
		( new Command() )->run(
			array( 'old.test', 'new.test', 'wp_posts' ),
			array(
				'format'  => 'table',
				'dry-run' => true,
			)
		);

		$this->assertNotContains( 'confirm', WP_CLI::$calls );
		$this->assertSame( 'http://old.test', $this->rows( 'wp_posts' )[0]['post_content'] );
	}
}
