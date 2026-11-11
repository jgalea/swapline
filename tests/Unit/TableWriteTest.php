<?php

namespace Swapline\Tests\Unit;

use Swapline\Database\Table;
use Swapline\Tests\Support\DbTestCase;

final class TableWriteTest extends DbTestCase {

	public function test_a_percent_sign_in_a_column_name_does_not_shift_the_placeholders(): void {
		$this->db->exec_sql( 'CREATE TABLE wp_odd (id INTEGER PRIMARY KEY, `x%s` TEXT, y TEXT)' );
		$this->insert(
			'wp_odd',
			array(
				'id'  => 7,
				'x%s' => 'old',
				'y'   => 'keep',
			)
		);

		$table = new Table( 'wp_odd' );
		$row   = $table->fetch( array( 'x%s', 'y' ), null, 10 )[0];

		$this->assertTrue( $table->update( $row, array( 'x%s' => 'new' ) ) );
		$this->assertEquals(
			array(
				'id'  => '7',
				'x%s' => 'new',
				'y'   => 'keep',
			),
			$this->rows( 'wp_odd' )[0] + array()
		);
		$this->assertSame( 'new', $table->read_cell( array( 'id' => '7' ), 'x%s' ) );
		$this->assertTrue( $table->write_cell( array( 'id' => '7' ), 'x%s', 'again' ) );
	}
}
