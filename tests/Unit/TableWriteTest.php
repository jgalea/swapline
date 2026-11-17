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

	public function test_keyless_tables_are_read_in_a_repeatable_order_when_only_reading(): void {
		$this->db->exec_sql( 'CREATE TABLE wp_log (msg TEXT, n INTEGER)' );
		foreach ( array( 'c', 'a', 'b' ) as $i => $msg ) {
			$this->insert(
				'wp_log',
				array(
					'msg' => $msg,
					'n'   => $i,
				)
			);
		}
		$table = new Table( 'wp_log' );
		$this->assertSame( Table::KEY_NONE, $table->key_type );

		$this->assertSame( array( 'a', 'b' ), array_column( $table->fetch( array( 'msg' ), 0, 2, true ), 'msg' ) );
		$this->assertSame( array( 'c' ), array_column( $table->fetch( array( 'msg' ), 2, 2, true ), 'msg' ) );
		$this->assertSame( array( 'c', 'a' ), array_column( $table->fetch( array( 'msg' ), 0, 2 ), 'msg' ), 'Writes keep the table\'s own order.' );
	}

	public function test_float_columns_are_not_used_to_find_a_keyless_row(): void {
		$this->db->exec_sql( 'CREATE TABLE wp_ratings (label TEXT, score FLOAT, ratio DOUBLE, qty INTEGER)' );
		$this->insert(
			'wp_ratings',
			array(
				'label' => 'x',
				'score' => 0.1,
				'ratio' => 0.2,
				'qty'   => 3,
			)
		);
		$table = new Table( 'wp_ratings' );
		$row   = $table->fetch( array( 'label' ), 0, 10 )[0];

		$this->assertSame( array( 'label', 'qty' ), array_keys( $table->identify( $row ) ) );
		$this->assertTrue( $table->update( $row, array( 'label' => 'y' ) ) );
	}
}
