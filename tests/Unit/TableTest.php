<?php

namespace Swapline\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Swapline\Database\Table;

final class TableTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function extras(): array {
		return array(
			'mysql virtual'          => array( 'VIRTUAL GENERATED', true ),
			'mysql stored'           => array( 'STORED GENERATED', true ),
			'mariadb lowercase'      => array( 'virtual generated', true ),
			'mariadb persistent'     => array( 'PERSISTENT GENERATED', true ),
			'mysql 8 default'        => array( 'DEFAULT_GENERATED', false ),
			'default with on update' => array( 'DEFAULT_GENERATED on update CURRENT_TIMESTAMP', false ),
			'auto increment'         => array( 'auto_increment', false ),
			'empty'                  => array( '', false ),
		);
	}

	/**
	 * @dataProvider extras
	 */
	public function test_is_generated( string $extra, bool $expected ): void {
		$this->assertSame( $expected, Table::is_generated( $extra ) );
	}
}
