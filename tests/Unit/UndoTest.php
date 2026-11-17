<?php

namespace Swapline\Tests\Unit;

use Swapline\Admin\Ajax;
use Swapline\Api;
use Swapline\Run\Config;
use Swapline\Run\History;
use Swapline\Run\Runner;
use Swapline\Tests\Support\DbTestCase;
use Swapline_Test_Json_Exit;

final class UndoTest extends DbTestCase {

	protected function setUp(): void {
		parent::setUp();
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->insert(
				'wp_posts',
				array(
					'ID'           => $i,
					'post_title'   => 'title ' . $i,
					'post_content' => 'see http://old.test/' . $i,
				)
			);
		}
	}

	/**
	 * @param array<string, mixed> $extra
	 */
	private function run_replace( array $extra = array(), string $table = 'wp_posts' ): string {
		$config = Config::from_input(
			array_merge(
				array(
					'search'  => 'old.test',
					'replace' => 'new.test',
					'tables'  => array( $table ),
					'dry_run' => false,
				),
				$extra
			)
		);
		$this->assertIsArray( $config );
		$job = Runner::start( $config, 'cli' );
		while ( ! $job['done'] ) {
			$job = Runner::step( $job );
		}
		return Runner::finish( $job )['id'];
	}

	public function test_undo_puts_back_every_changed_cell(): void {
		$id = $this->run_replace();
		$this->assertSame( 'see http://new.test/3', $this->rows( 'wp_posts' )[2]['post_content'] );

		$result = Api::undo( $id );

		$this->assertSame(
			array(
				'restored'      => 5,
				'changed_since' => 0,
				'missing'       => 0,
			),
			$result
		);
		$this->assertSame( 'see http://old.test/3', $this->rows( 'wp_posts' )[2]['post_content'] );
		$this->assertNotEmpty( History::find( $id )['undone'] );
	}

	public function test_undo_leaves_cells_edited_since_alone(): void {
		$id = $this->run_replace();
		$this->db->exec_sql( "UPDATE wp_posts SET post_content = 'edited by hand' WHERE ID = 2" );
		$this->db->exec_sql( 'DELETE FROM wp_posts WHERE ID = 4' );

		$result = Api::undo( $id );

		$this->assertSame( 3, $result['restored'] );
		$this->assertSame( 1, $result['changed_since'] );
		$this->assertSame( 1, $result['missing'] );
		$this->assertSame( 'edited by hand', $this->rows( 'wp_posts' )[1]['post_content'] );
	}

	public function test_a_run_cannot_be_undone_twice(): void {
		$id = $this->run_replace();
		Api::undo( $id );

		$again = Api::undo( $id );

		$this->assertInstanceOf( \WP_Error::class, $again );
		$this->assertSame( 'swapline_undo_unavailable', $again->get_error_code() );
	}

	public function test_a_dry_run_has_nothing_to_undo(): void {
		$id = $this->run_replace( array( 'dry_run' => true ) );

		$this->assertSame( 'swapline_undo_unavailable', Api::undo( $id )->get_error_code() );
	}

	public function test_a_run_the_history_could_not_record_in_full_is_refused_and_nothing_is_written(): void {
		$GLOBALS['swapline_test']['filters']['swapline_max_recorded_changes'] = static fn() => 2;
		$id = $this->run_replace();

		$result = Api::undo( $id );

		$this->assertSame( 'swapline_undo_incomplete', $result->get_error_code() );
		$this->assertSame( 'see http://new.test/3', $this->rows( 'wp_posts' )[2]['post_content'] );
	}

	public function test_undo_works_on_a_table_without_a_key(): void {
		$this->db->exec_sql( 'CREATE TABLE wp_log (msg TEXT, n INTEGER)' );
		$this->insert(
			'wp_log',
			array(
				'msg' => 'http://old.test/a',
				'n'   => 1,
			)
		);
		$this->insert(
			'wp_log',
			array(
				'msg' => 'untouched',
				'n'   => 2,
			)
		);
		$id = $this->run_replace( array(), 'wp_log' );
		$this->assertSame( 'http://new.test/a', $this->rows( 'wp_log' )[0]['msg'] );

		$this->assertSame( 1, Api::undo( $id )['restored'] );
		$this->assertSame( 'http://old.test/a', $this->rows( 'wp_log' )[0]['msg'] );
	}

	public function test_the_admin_action_is_refused_for_a_delegated_role_when_the_run_touched_options(): void {
		$GLOBALS['swapline_test']['caps']                           = array( 'edit_others_posts' );
		$GLOBALS['swapline_test']['filters']['swapline_capability'] = static fn() => 'edit_others_posts';
		$id = $this->run_replace( array(), 'wp_options' );
		History::add(
			array_merge(
				History::find( $id ),
				array(
					'tables' => array( 'wp_options' => array( 'cells_changed' => 1 ) ),
				)
			)
		);
		$_POST['run'] = $id;

		try {
			Ajax::handle_undo();
			$this->fail( 'Undo went ahead.' );
		} catch ( Swapline_Test_Json_Exit $e ) {
			$this->assertSame( 403, $e->status );
		}
	}
}
