<?php

namespace Swapline\Tests\Unit;

use Swapline\Database\Tables;
use Swapline\Import\Statement;
use Swapline\Tests\Support\DbTestCase;

final class TablesPrefixTest extends DbTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->db->exec_sql( 'CREATE TABLE wp_postmeta (a INT)' );
		foreach ( array( 'options', 'posts', 'postmeta', 'users' ) as $suffix ) {
			$this->db->exec_sql( "CREATE TABLE wp_dev_{$suffix} (a INT)" );
		}
		$this->db->exec_sql( 'CREATE TABLE wp_2023_options (a INT)' );
		$this->db->exec_sql( 'CREATE TABLE wp_2023_posts (a INT)' );
		$this->db->exec_sql( 'CREATE TABLE wp_2023_postmeta (a INT)' );
		$this->db->exec_sql( 'CREATE TABLE wp_foo_options (a INT)' );
	}

	public function test_tables_of_another_install_sharing_the_database_are_left_out(): void {
		$names = array_keys( Tables::all() );

		$this->assertContains( 'wp_posts', $names );
		$this->assertContains( 'wp_postmeta', $names );
		$this->assertNotContains( 'wp_dev_options', $names );
		$this->assertNotContains( 'wp_dev_users', $names );
		$this->assertNotContains( 'wp_2023_posts', $names );
	}

	public function test_a_plugin_table_that_merely_ends_in_options_stays(): void {
		$this->assertContains( 'wp_foo_options', array_keys( Tables::all() ) );
	}

	public function test_another_installs_tables_cannot_be_selected(): void {
		$this->assertNull( Tables::validate( array( 'wp_posts', 'wp_dev_posts' ) ) );
		$this->assertSame( array( 'wp_posts' ), Tables::validate( array( 'wp_posts' ) ) );
	}

	public function test_subsite_tables_on_multisite_are_not_another_install(): void {
		$GLOBALS['swapline_test']['multi'] = true;
		foreach ( array( 'options', 'posts', 'postmeta' ) as $suffix ) {
			$this->db->exec_sql( "CREATE TABLE wp_2_{$suffix} (a INT)" );
		}

		$this->assertContains( 'wp_2_posts', array_keys( Tables::all() ) );
		$this->assertNotContains( 'wp_dev_posts', array_keys( Tables::all() ) );
	}

	public function test_an_import_file_cannot_bring_tables_of_another_install(): void {
		$foreign = Tables::foreign_prefixes();
		$this->assertEqualsCanonicalizing( array( 'wp_dev_', 'wp_2023_' ), $foreign );

		$own     = Statement::parse( 'CREATE TABLE `wp_posts` (a int)', 'wp_', $foreign );
		$other   = Statement::parse( 'CREATE TABLE `wp_dev_posts` (a int)', 'wp_', $foreign );
		$without = Statement::parse( 'CREATE TABLE `wp_dev_posts` (a int)', 'wp_' );

		$this->assertSame( Statement::CREATE, $own->type );
		$this->assertSame( Statement::REJECT, $other->type );
		$this->assertSame( Statement::CREATE, $without->type );
	}
}
