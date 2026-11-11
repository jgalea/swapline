<?php

namespace Swapline\Tests\Unit;

use Swapline\Admin\Ajax;
use Swapline\Admin\BackupAjax;
use Swapline\Plugin;
use Swapline\Run\History;
use Swapline\Tests\Support\DbTestCase;
use Swapline_Test_Json_Exit;

final class CapabilityTest extends DbTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->db->exec_sql( 'CREATE TABLE wp_users (ID INTEGER PRIMARY KEY, user_login TEXT, user_pass TEXT)' );
		$this->db->exec_sql( 'CREATE TABLE wp_usermeta (umeta_id INTEGER PRIMARY KEY, user_id INTEGER, meta_key TEXT, meta_value TEXT)' );
		$GLOBALS['swapline_test']['filters']['swapline_capability'] = static fn() => 'edit_others_posts';
		$GLOBALS['swapline_test']['caps']                           = array( 'edit_others_posts' );
	}

	private function refused( callable $call ): Swapline_Test_Json_Exit {
		try {
			$call();
		} catch ( Swapline_Test_Json_Exit $e ) {
			return $e;
		}
		$this->fail( 'The call did not end in a JSON response.' );
	}

	public function test_the_filter_still_sets_the_search_and_replace_capability(): void {
		$this->assertSame( 'edit_others_posts', Plugin::capability() );
	}

	public function test_the_database_capability_ignores_the_filter(): void {
		$this->assertSame( 'manage_options', Plugin::database_capability() );
		$GLOBALS['swapline_test']['multi'] = true;
		$this->assertSame( 'manage_network', Plugin::database_capability() );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function backup_actions(): array {
		return array(
			'start backup'  => array( 'handle_backup_start' ),
			'step backup'   => array( 'handle_backup_step' ),
			'delete backup' => array( 'handle_backup_delete' ),
			'upload import' => array( 'handle_import_upload' ),
			'start import'  => array( 'handle_import_start' ),
			'step import'   => array( 'handle_import_step' ),
			'settings'      => array( 'handle_backup_settings' ),
		);
	}

	/**
	 * @dataProvider backup_actions
	 */
	public function test_a_delegated_role_cannot_use_backups_or_imports( string $handler ): void {
		$e = $this->refused( array( BackupAjax::class, $handler ) );
		$this->assertFalse( $e->success );
		$this->assertSame( 403, $e->status );
	}

	public function test_a_delegated_role_cannot_download_a_backup(): void {
		$e = $this->refused( array( BackupAjax::class, 'download' ) );
		$this->assertSame( 403, $e->status );
	}

	public function test_a_delegated_role_cannot_run_on_the_users_tables(): void {
		$_POST['config'] = (string) wp_json_encode(
			array(
				'search'  => 'editor',
				'replace' => 'administrator',
				'tables'  => array( 'wp_usermeta' ),
				'dry_run' => false,
			)
		);

		$e = $this->refused( array( Ajax::class, 'handle_start' ) );

		$this->assertSame( 403, $e->status );
		$this->assertArrayNotHasKey( 'swapline_job', $GLOBALS['swapline_test']['options'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function protected_run_tables(): array {
		return array(
			'users'    => array( 'wp_users' ),
			'usermeta' => array( 'wp_usermeta' ),
			'options'  => array( 'wp_options' ),
		);
	}

	/**
	 * @dataProvider protected_run_tables
	 */
	public function test_a_delegated_role_cannot_dry_run_or_rewrite_the_roles_option( string $table ): void {
		foreach ( array( true, false ) as $dry_run ) {
			$_POST['config'] = (string) wp_json_encode(
				array(
					'search'  => 'a:1:{s:13:"administrator"',
					'replace' => 'x',
					'tables'  => array( $table ),
					'dry_run' => $dry_run,
				)
			);

			$this->assertSame( 403, $this->refused( array( Ajax::class, 'handle_start' ) )->status );
		}
	}

	public function test_a_delegated_role_cannot_read_changes_to_protected_tables(): void {
		History::add(
			array(
				'id'     => 'run-1',
				'tables' => array( 'wp_options' => array( 'cells_changed' => 1 ) ),
			)
		);
		$_POST['run'] = 'run-1';

		$this->assertSame( 403, $this->refused( array( Ajax::class, 'handle_changes' ) )->status );
	}

	public function test_a_delegated_role_can_still_run_on_other_tables(): void {
		$_POST['config'] = (string) wp_json_encode(
			array(
				'search'  => 'a',
				'replace' => 'b',
				'tables'  => array( 'wp_posts' ),
				'dry_run' => true,
			)
		);

		$this->assertTrue( $this->refused( array( Ajax::class, 'handle_start' ) )->success );
	}

	public function test_an_administrator_can_run_on_the_users_tables(): void {
		$GLOBALS['swapline_test']['caps'][] = 'manage_options';
		$_POST['config']                    = (string) wp_json_encode(
			array(
				'search'  => 'editor',
				'replace' => 'administrator',
				'tables'  => array( 'wp_usermeta' ),
				'dry_run' => true,
			)
		);

		$this->assertTrue( $this->refused( array( Ajax::class, 'handle_start' ) )->success );
	}

	public function test_protected_tables_are_the_accounts_tables(): void {
		$this->assertSame( array( 'wp_users', 'wp_options', 'wp_usermeta' ), Plugin::protected_tables( array( 'wp_posts', 'wp_users', 'wp_options', 'wp_usermeta' ) ) );
		$this->assertSame( array(), Plugin::protected_tables( array( 'wp_posts', 'wp_postmeta' ) ) );
		$GLOBALS['swapline_test']['multi'] = true;
		$this->assertSame( array( 'wp_3_options', 'wp_sitemeta' ), Plugin::protected_tables( array( 'wp_3_options', 'wp_3_posts', 'wp_sitemeta' ) ) );
	}
}
