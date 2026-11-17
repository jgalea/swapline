<?php
// phpcs:disable WordPress.PHP.IniSet, WordPress.WP.AlternativeFunctions -- captures the PHP error log in a temp file.

namespace Swapline\Tests\Unit;

use ReflectionMethod;
use Swapline\Import\Importer;
use Swapline\Tests\Support\DbTestCase;

final class ImportErrorTest extends DbTestCase {

	public function test_database_error_details_stay_out_of_the_message_shown_in_the_browser(): void {
		$this->db->last_error = "Table 'secret_db.wp_private' doesn't exist";
		$method               = new ReflectionMethod( Importer::class, 'db_error' );
		$log                  = tempnam( sys_get_temp_dir(), 'swapline-log' );
		$previous             = ini_set( 'error_log', $log );

		$message = $method->invoke( null, 'A statement failed.' );

		ini_set( 'error_log', (string) $previous );
		$this->assertSame( 'A statement failed.', $message );
		$this->assertStringContainsString( 'secret_db.wp_private', (string) file_get_contents( $log ) );
		unlink( $log );
	}
}
