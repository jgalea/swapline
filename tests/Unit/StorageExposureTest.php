<?php
// phpcs:disable WordPress.WP.AlternativeFunctions -- scratch files in a temp folder.

namespace Swapline\Tests\Unit;

use Swapline\Backup\Storage;
use Swapline\Tests\Support\DbTestCase;

final class StorageExposureTest extends DbTestCase {

	private string $base;

	protected function setUp(): void {
		parent::setUp();
		$this->base                          = sys_get_temp_dir() . '/swapline-expose-' . bin2hex( random_bytes( 4 ) );
		$GLOBALS['swapline_test']['uploads'] = $this->base;
	}

	protected function tearDown(): void {
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->base, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $this->base );
	}

	public function test_a_folder_the_server_hands_out_is_reported(): void {
		$GLOBALS['swapline_test']['head'] = array( 'code' => 200 );

		$this->assertTrue( Storage::is_exposed() );
		$this->assertMatchesRegularExpression( '#/uploads/swapline-[a-f0-9]{20}/swapline-probe\.txt$#', $GLOBALS['swapline_test']['requests'][0] );
		$this->assertSame( array(), glob( $this->base . '/*/swapline-probe.txt' ), 'The probe file is removed.' );
	}

	public function test_a_protected_folder_is_not_reported_and_the_answer_is_remembered(): void {
		$GLOBALS['swapline_test']['head'] = array( 'code' => 403 );

		$this->assertFalse( Storage::is_exposed() );
		$this->assertFalse( Storage::is_exposed() );
		$this->assertCount( 1, $GLOBALS['swapline_test']['requests'] );
	}
}
