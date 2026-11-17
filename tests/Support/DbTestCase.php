<?php

namespace Swapline\Tests\Support;

use PHPUnit\Framework\TestCase;

abstract class DbTestCase extends TestCase {

	protected FakeWpdb $db;

	protected function setUp(): void {
		parent::setUp();
		swapline_test_reset();
		$this->db        = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		$this->db->exec_sql( 'CREATE TABLE wp_swapline_changes (id INTEGER PRIMARY KEY AUTOINCREMENT, run_id TEXT, table_name TEXT, row_key TEXT, column_name TEXT, old_value TEXT, new_value TEXT, replacements INTEGER, truncated INTEGER)' );
		update_option( 'swapline_db_version', '1' );
		$this->db->exec_sql( 'CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_title TEXT, post_content TEXT)' );
		$this->db->exec_sql( 'CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY, option_name TEXT, option_value TEXT)' );
	}

	/**
	 * @param array<string, string> $row
	 */
	protected function insert( string $table, array $row ): void {
		$this->db->insert( $table, $row );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	protected function rows( string $table ): array {
		return $this->db->pdo->query( "SELECT * FROM `{$table}` ORDER BY rowid" )->fetchAll( \PDO::FETCH_ASSOC ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO -- SQLite test database.
	}
}
