<?php
// phpcs:disable WordPress.WP.AlternativeFunctions -- scratch files in a temp folder.

namespace Swapline\Tests\Unit;

use Swapline\Admin\Ajax;
use Swapline\Api;
use Swapline\Backup\Exporter;
use Swapline\Import\Importer;
use Swapline\Run\Config;
use Swapline\Run\History;
use Swapline\Run\Runner;
use Swapline\Tests\Support\DbTestCase;
use Swapline_Test_Json_Exit;

final class RunLifecycleTest extends DbTestCase {

	private string $base;

	protected function setUp(): void {
		parent::setUp();
		$this->base                          = sys_get_temp_dir() . '/swapline-life-' . bin2hex( random_bytes( 4 ) );
		$GLOBALS['swapline_test']['uploads'] = $this->base;
		$GLOBALS['swapline_test']['caps']    = array( 'manage_options' );
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

	protected function tearDown(): void {
		if ( is_dir( $this->base ) ) {
			$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->base, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $items as $item ) {
				$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
			}
			rmdir( $this->base );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function config( bool $dry_run = false ): array {
		$config = Config::from_input(
			array(
				'search'    => 'old.test',
				'replace'   => 'new.test',
				'tables'    => array( 'wp_posts' ),
				'dry_run'   => $dry_run,
				'page_size' => 10,
			)
		);
		$this->assertIsArray( $config );
		return $config;
	}

	private function json( callable $call ): Swapline_Test_Json_Exit {
		try {
			$call();
		} catch ( Swapline_Test_Json_Exit $e ) {
			return $e;
		}
		$this->fail( 'No JSON response.' );
	}

	private function start_admin_run(): string {
		$_POST['config'] = (string) wp_json_encode( $this->config() );
		$response        = $this->json( array( Ajax::class, 'handle_start' ) );
		$this->assertTrue( $response->success, wp_json_encode( $response->payload ) );
		return $response->payload['id'];
	}

	public function test_a_started_admin_run_is_listed_as_unfinished_and_keeps_its_cells_when_it_stops_part_way(): void {
		$id           = $this->start_admin_run();
		$_POST['job'] = $id;
		$this->json( array( Ajax::class, 'handle_step' ) );

		$run = History::find( $id );
		$this->assertSame( 'running', $run['status'] );
		$this->assertSame( 10, $run['totals']['cells_changed'] );
		$this->assertSame( 10, History::changes( $id, 1, 100 )['total'] );
		$this->assertNotNull( Runner::current(), 'The job stays saved so it can be resumed.' );
	}

	public function test_resuming_finishes_the_same_run_record(): void {
		$id           = $this->start_admin_run();
		$_POST['job'] = $id;
		do {
			$response = $this->json( array( Ajax::class, 'handle_step' ) );
		} while ( empty( $response->payload['run'] ) );

		$runs = History::runs();
		$this->assertCount( 1, $runs );
		$this->assertSame( 'complete', $runs[0]['status'] );
		$this->assertSame( 30, $runs[0]['totals']['cells_changed'] );
		$this->assertSame( 30, History::changes( $id, 1, 100 )['total'] );
	}

	public function test_stopping_an_unfinished_run_keeps_its_record(): void {
		$id           = $this->start_admin_run();
		$_POST['job'] = $id;
		$this->json( array( Ajax::class, 'handle_step' ) );
		$this->json( array( Ajax::class, 'handle_cancel' ) );

		$this->assertSame( 'cancelled', History::find( $id )['status'] );
		$this->assertSame( 10, History::changes( $id, 1, 100 )['total'] );
		$this->assertNull( Runner::current() );
	}

	public function test_an_api_preview_does_not_delete_the_diff_of_an_admin_run_in_progress(): void {
		$id           = $this->start_admin_run();
		$_POST['job'] = $id;
		$this->json( array( Ajax::class, 'handle_step' ) );

		$preview = Api::preview(
			array(
				'search'  => 'old.test',
				'replace' => 'other',
				'tables'  => array( 'wp_posts' ),
			)
		);

		$this->assertIsArray( $preview );
		$this->assertSame( 10, History::changes( $id, 1, 100 )['total'], 'The admin run\'s recorded cells survive.' );
	}

	public function test_running_runs_do_not_count_against_the_history_size(): void {
		$id = $this->start_admin_run();
		for ( $i = 0; $i < 7; $i++ ) {
			$job = Runner::start( $this->config( true ), 'cli' );
			while ( ! $job['done'] ) {
				$job = Runner::step( $job );
			}
			Runner::finish( $job );
		}

		$this->assertNotNull( History::find( $id ) );
		$this->assertCount( 6, History::runs() );
	}

	public function test_a_second_user_cannot_start_a_run_while_one_is_active(): void {
		$this->start_admin_run();
		$GLOBALS['swapline_test']['user'] = 2;
		$_POST['config']                  = (string) wp_json_encode( $this->config() );

		$this->assertSame( 409, $this->json( array( Ajax::class, 'handle_start' ) )->status );
	}

	public function test_the_same_user_starting_again_closes_the_old_run_as_cancelled(): void {
		$first  = $this->start_admin_run();
		$second = $this->start_admin_run();

		$this->assertNotSame( $first, $second );
		$this->assertSame( 'cancelled', History::find( $first )['status'] );
		$this->assertSame( 'running', History::find( $second )['status'] );
	}

	public function test_an_abandoned_run_does_not_block_another_user_forever(): void {
		$this->start_admin_run();
		$job            = Runner::current();
		$job['touched'] = time() - 3600;
		update_option( 'swapline_job', $job );
		$GLOBALS['swapline_test']['user'] = 2;
		$_POST['config']                  = (string) wp_json_encode( $this->config() );

		$this->assertTrue( $this->json( array( Ajax::class, 'handle_start' ) )->success );
	}

	public function test_an_api_or_cli_import_is_refused_while_an_admin_import_is_active(): void {
		$file = $this->base . '.sql';
		file_put_contents( $file, "CREATE TABLE `wp_x` (a int);\n" );
		$active = Importer::start( $file, false, null, false, 'admin' );
		$this->assertIsArray( $active );
		Importer::save( $active );

		$busy = Importer::start( $file, false, null, false, 'api' );

		$this->assertInstanceOf( \WP_Error::class, $busy );
		$this->assertSame( 'swapline_busy', $busy->get_error_code() );
		$this->assertNotNull( Importer::current(), 'The active import is untouched.' );
		unlink( $file );
	}

	public function test_an_api_backup_is_refused_while_an_admin_backup_is_active(): void {
		$active = Exporter::start( array( 'wp_posts' ), null, 'admin' );
		$this->assertIsArray( $active );
		Exporter::save( $active );

		$busy = Exporter::start( array( 'wp_posts' ), null, 'api' );

		$this->assertInstanceOf( \WP_Error::class, $busy );
		$this->assertSame( 'swapline_busy', $busy->get_error_code() );
		$this->assertNotNull( Exporter::current() );
	}
}
