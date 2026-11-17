<?php

namespace Swapline\Tests\Unit;

use Swapline\Database\Tables;
use Swapline\Import\Importer;
use Swapline\Tests\Support\DbTestCase;

final class ImportScratchTablesTest extends DbTestCase {

	private function names(): array {
		return $this->db->get_col( 'SHOW TABLES' );
	}

	public function test_leftover_cleanup_only_drops_this_sites_scratch_tables(): void {
		$this->db->exec_sql( 'CREATE TABLE wp_swapline_tmp_0a1b2c3d_0 (a INT)' );
		$this->db->exec_sql( 'CREATE TABLE wp_swapline_old_0a1b2c3d_1 (a INT)' );
		$this->db->exec_sql( 'CREATE TABLE wp2_swapline_tmp_0a1b2c3d_0 (a INT)' );
		$this->db->exec_sql( 'CREATE TABLE wp2_posts (a INT)' );

		Importer::drop_leftovers();

		$names = $this->names();
		$this->assertContains( 'wp2_swapline_tmp_0a1b2c3d_0', $names, 'Another install\'s import table must survive.' );
		$this->assertContains( 'wp2_posts', $names );
		$this->assertNotContains( 'wp_swapline_tmp_0a1b2c3d_0', $names );
		$this->assertNotContains( 'wp_swapline_old_0a1b2c3d_1', $names );
	}

	public function test_scratch_tables_are_not_offered_as_tables_to_process(): void {
		$this->db->exec_sql( 'CREATE TABLE wp_swapline_tmp_0a1b2c3d_0 (a INT)' );

		$this->assertArrayNotHasKey( 'wp_swapline_tmp_0a1b2c3d_0', Tables::all() );
		$this->assertArrayHasKey( 'wp_posts', Tables::all() );
	}
}
