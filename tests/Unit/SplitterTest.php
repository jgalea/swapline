<?php
// phpcs:disable WordPress.WP.AlternativeFunctions -- tests work on temporary files.

namespace Swapline\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Swapline\Backup\SqlWriter;
use Swapline\Import\Splitter;
use Swapline\Import\Statement;
use Swapline\Tests\NaiveSplitter;

final class SplitterTest extends TestCase {

	/**
	 * @var string[]
	 */
	private array $files = array();

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
	}

	private function file( string $contents, bool $gzip = false ): string {
		$path          = tempnam( sys_get_temp_dir(), 'swapline' );
		$this->files[] = $path;
		file_put_contents( $path, $gzip ? gzencode( $contents ) : $contents );
		return $path;
	}

	/**
	 * @param resource $handle
	 * @return Splitter|NaiveSplitter
	 */
	private function splitter( $handle, int $chunk = 1048576 ) {
		if ( getenv( 'SWAPLINE_NAIVE_SPLITTER' ) ) {
			return new NaiveSplitter( $handle, $chunk );
		}
		return new Splitter( $handle, $chunk );
	}

	/**
	 * @return string[]
	 */
	private function split( string $sql, int $chunk = 1048576, string $wrapper = '' ): array {
		$handle     = fopen( $wrapper . $this->file( $sql, '' !== $wrapper ), 'rb' );
		$splitter   = $this->splitter( $handle, $chunk );
		$statements = array();
		for ( $statement = $splitter->next(); null !== $statement; $statement = $splitter->next() ) {
			$statements[] = trim( $statement );
		}
		fclose( $handle );
		return $statements;
	}

	public function test_semicolons_inside_strings_and_identifiers(): void {
		$sql = "INSERT INTO `wp_t` VALUES ('a;b', \"c;d\");\nINSERT INTO `wp_odd;name` VALUES (1);\nSELECT 1";

		$this->assertSame(
			array(
				"INSERT INTO `wp_t` VALUES ('a;b', \"c;d\")",
				'INSERT INTO `wp_odd;name` VALUES (1)',
				'SELECT 1',
			),
			$this->split( $sql )
		);
	}

	public function test_backslash_and_doubled_quotes(): void {
		$sql = "INSERT INTO wp_t VALUES ('it\\'s; one', 'it''s; two', 'ends in a backslash\\\\');\nINSERT INTO wp_t VALUES ('x')";

		$statements = $this->split( $sql );

		$this->assertCount( 2, $statements );
		$this->assertSame( "INSERT INTO wp_t VALUES ('it\\'s; one', 'it''s; two', 'ends in a backslash\\\\')", $statements[0] );
		$this->assertSame( "INSERT INTO wp_t VALUES ('x')", $statements[1] );
	}

	public function test_comments_hide_semicolons(): void {
		$sql = "-- a comment; with a semicolon\n# another; one\n/* block; comment\n spanning lines */\nCREATE TABLE wp_t (id int);\n/*!40101 SET NAMES utf8mb4 */;\nINSERT INTO wp_t VALUES (1)--1;\n;";

		$statements = $this->split( $sql );

		$this->assertCount( 3, $statements );
		$this->assertSame( 'CREATE TABLE wp_t (id int)', Statement::normalize( $statements[0] ) );
		$this->assertSame( 'SET NAMES utf8mb4', Statement::normalize( $statements[1] ) );
		$this->assertSame( 'INSERT INTO wp_t VALUES (1)--1', Statement::normalize( $statements[2] ) );
	}

	public function test_multi_line_statements(): void {
		$sql = "CREATE TABLE `wp_t` (\n  `id` bigint NOT NULL,\n  `v` text,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB;\n\nINSERT INTO `wp_t` VALUES\n(1,'line one\nline two;'),\n(2,NULL);\n";

		$statements = $this->split( $sql );

		$this->assertCount( 2, $statements );
		$this->assertStringContainsString( "'line one\nline two;'", $statements[1] );
	}

	public function test_every_chunk_boundary(): void {
		$sql = "-- c;\nINSERT INTO wp_t VALUES ('a\\'b;', 'c''d;', \"e\\\"f;\", '\\\\');/* x; */INSERT INTO wp_t VALUES (1);# y;\nINSERT INTO wp_t VALUES (2)";

		$expected = $this->split( $sql );
		$this->assertCount( 3, $expected );

		foreach ( array( 1, 2, 3, 5, 7 ) as $chunk ) {
			$this->assertSame( $expected, $this->split( $sql, $chunk ), "Chunk size {$chunk}" );
		}
	}

	public function test_huge_single_line_insert(): void {
		$tuples = array();
		for ( $i = 0; $i < 20000; $i++ ) {
			$tuples[] = array( (string) $i, SqlWriter::string( "row {$i}; it's \"quoted\" \\ and ; again\n" ), 'NULL' );
		}
		$insert = rtrim( str_replace( "\n", '', SqlWriter::insert( 'wp_t', array( 'id', 'v', 'n' ), $tuples ) ), ";\n" );
		$sql    = $insert . ";\nINSERT INTO wp_t VALUES (1);\n";

		$this->assertGreaterThan( 1000000, strlen( $insert ) );
		$this->assertStringNotContainsString( "\n", $insert );

		$statements = $this->split( $sql, 8192 );

		$this->assertCount( 2, $statements );
		$this->assertSame( $insert, $statements[0] );
		$this->assertSame( Statement::INSERT, Statement::parse( $statements[0], 'wp_' )->type );
	}

	public function test_reads_gzip_including_concatenated_members(): void {
		$first  = "CREATE TABLE wp_t (id int);\nINSERT INTO wp_t VALUES ('a;b');\n";
		$second = "INSERT INTO wp_t VALUES ('c;d');\n";
		$path   = $this->file( '' );
		file_put_contents( $path, gzencode( $first ) . gzencode( $second ) );

		$handle     = fopen( 'compress.zlib://' . $path, 'rb' );
		$splitter   = $this->splitter( $handle, 16 );
		$statements = array();
		for ( $statement = $splitter->next(); null !== $statement; $statement = $splitter->next() ) {
			$statements[] = trim( $statement );
		}
		fclose( $handle );

		$this->assertSame( array( 'CREATE TABLE wp_t (id int)', "INSERT INTO wp_t VALUES ('a;b')", "INSERT INTO wp_t VALUES ('c;d')" ), $statements );
	}

	public function test_resumes_from_offset(): void {
		$sql  = "INSERT INTO wp_t VALUES ('1;');\n-- note;\nINSERT INTO wp_t VALUES ('2;');\nINSERT INTO wp_t VALUES ('3;');";
		$path = $this->file( $sql );

		$handle   = fopen( $path, 'rb' );
		$splitter = $this->splitter( $handle, 4 );
		$splitter->next();
		$offset = $splitter->offset();
		fclose( $handle );

		$handle = fopen( $path, 'rb' );
		fseek( $handle, $offset );
		$splitter = $this->splitter( $handle, 4 );
		$rest     = array();
		for ( $statement = $splitter->next(); null !== $statement; $statement = $splitter->next() ) {
			$rest[] = Statement::normalize( $statement );
		}
		fclose( $handle );

		$this->assertSame( array( "INSERT INTO wp_t VALUES ('2;')", "INSERT INTO wp_t VALUES ('3;')" ), $rest );
	}

	public function test_blank_tail_ends_the_stream(): void {
		$this->assertSame( array( 'INSERT INTO wp_t VALUES (1)' ), $this->split( "INSERT INTO wp_t VALUES (1);\n\n  \n" ) );
	}
}
