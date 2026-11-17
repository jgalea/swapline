<?php

namespace Swapline\Tests\Unit;

use Swapline\Database\Tables;
use Swapline\Import\Importer;
use Swapline\Tests\Support\DbTestCase;
use Swapline\Tests\Support\SqliteWpdb;

final class SqliteImportTest extends DbTestCase {

	public function test_an_import_on_sqlite_says_why_it_cannot_run(): void {
		$GLOBALS['wpdb'] = new SqliteWpdb(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride

		$this->assertTrue( Tables::uses_sqlite() );
		$result = Importer::start( __FILE__, false, null, false, 'admin' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'swapline_sqlite', $result->get_error_code() );
		$this->assertStringNotContainsString( 'character set', $result->get_error_message() );
	}

	public function test_mysql_sites_are_not_flagged(): void {
		$this->assertFalse( Tables::uses_sqlite() );
	}
}
