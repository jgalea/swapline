<?php

namespace Swapline\Tests\Unit;

use Swapline\Import\Importer;
use Swapline\Tests\Support\DbTestCase;

final class InflateTest extends DbTestCase {

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir                           = sys_get_temp_dir() . '/swapline-inflate-' . bin2hex( random_bytes( 4 ) );
		$GLOBALS['swapline_test']['uploads'] = $this->dir;
		$GLOBALS['swapline_test']['options']['swapline_backup_dir'] = 'swapline-' . str_repeat( 'a', 20 );
		mkdir( $this->dir . '/swapline-' . str_repeat( 'a', 20 ), 0777, true );
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->dir . '/*/*' ) as $file ) {
			@unlink( $file );
		}
		@rmdir( $this->dir . '/swapline-' . str_repeat( 'a', 20 ) );
		@rmdir( $this->dir );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function job( string $contents ): array {
		$folder = $this->dir . '/swapline-' . str_repeat( 'a', 20 );
		$file   = $folder . '/upload-aaaaaaaaaaaa.sql.gz';
		file_put_contents( $file, gzencode( $contents ) );
		return array(
			'id'     => 'aaaaaaaa',
			'file'   => $file,
			'owned'  => false,
			'work'   => $folder . '/import-aaaaaaaa.sql',
			'phase'  => 'inflate',
			'offset' => 0,
			'size'   => (int) filesize( $file ),
			'tables' => array(),
			'done'   => false,
			'error'  => null,
		);
	}

	public function test_a_file_that_unpacks_far_beyond_its_size_is_refused(): void {
		$job = Importer::step( $this->job( str_repeat( '0', 4 * 1024 * 1024 ) ), microtime( true ) + 5 );

		$this->assertTrue( $job['done'] );
		$this->assertNotNull( $job['error'] );
		$this->assertFileDoesNotExist( $job['work'] );
	}

	public function test_an_ordinary_file_unpacks(): void {
		$sql = '';
		for ( $i = 0; $i < 2000; $i++ ) {
			$sql .= "INSERT INTO t VALUES ('" . bin2hex( random_bytes( 20 ) ) . "');\n";
		}
		$job = Importer::step( $this->job( $sql ), microtime( true ) + 5 );

		$this->assertNull( $job['error'] );
		$this->assertSame( 'scan', $job['phase'] );
		$this->assertSame( strlen( $sql ), filesize( $job['work'] ) );
	}

	public function test_the_limit_can_be_raised_with_a_filter(): void {
		$GLOBALS['swapline_test']['filters']['swapline_max_unpacked_size'] = static fn() => 64 * 1024 * 1024;
		$job = Importer::step( $this->job( str_repeat( '0', 4 * 1024 * 1024 ) ), microtime( true ) + 5 );

		$this->assertNull( $job['error'] );
	}
}
