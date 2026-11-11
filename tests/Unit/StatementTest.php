<?php
// phpcs:disable WordPress.WP.AlternativeFunctions -- tests work on temporary files.

namespace Swapline\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Swapline\Backup\SqlWriter;
use Swapline\Import\Splitter;
use Swapline\Import\Statement;

final class StatementTest extends TestCase {

	private static function parse( string $sql ): Statement {
		return Statement::parse( $sql, 'wp_' );
	}

	public function test_accepts_mysqldump_output(): void {
		$dump = <<<'SQL'
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
SET @MYSQLDUMP_TEMP_LOG_BIN = @@SESSION.SQL_LOG_BIN;
SET @@SESSION.SQL_LOG_BIN= 0;
DROP TABLE IF EXISTS `wp_options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
CREATE TABLE `wp_options` (
  `option_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `option_name` varchar(191) COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT '',
  `option_value` longtext COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `autoload` varchar(20) COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT 'yes',
  PRIMARY KEY (`option_id`),
  UNIQUE KEY `option_name` (`option_name`)
) ENGINE=InnoDB AUTO_INCREMENT=137 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci COMMENT='select; like';
LOCK TABLES `wp_options` WRITE;
/*!40000 ALTER TABLE `wp_options` DISABLE KEYS */;
INSERT INTO `wp_options` VALUES (1,'siteurl','http://example.com','yes'),(2,'blob',_binary 0x00FF,'no'),(3,'n',NULL,'on'),(4,'e',X'61','x'),(5,'f',-1.5e3,DEFAULT);
/*!40000 ALTER TABLE `wp_options` ENABLE KEYS */;
UNLOCK TABLES;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
SET @@SESSION.SQL_LOG_BIN = @MYSQLDUMP_TEMP_LOG_BIN;
SQL;

		$handle = fopen( 'php://memory', 'w+b' );
		fwrite( $handle, $dump );
		rewind( $handle );
		$splitter = new Splitter( $handle );
		$types    = array();
		for ( $raw = $splitter->next(); null !== $raw; $raw = $splitter->next() ) {
			$statement = self::parse( $raw );
			$this->assertNotSame( Statement::REJECT, $statement->type, $statement->reason . ': ' . $statement->sql );
			$types[] = $statement->type . ( Statement::SET === $statement->type && ! $statement->execute ? '-skipped' : '' );
		}

		$this->assertSame(
			array( 'set', 'set', 'set', 'set', 'set', 'set', 'set', 'set-skipped', 'drop', 'set', 'create', 'skip', 'skip', 'insert', 'skip', 'skip', 'set-skipped', 'set-skipped' ),
			$types
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function rejected(): array {
		return array(
			'grant'                     => array( "GRANT ALL PRIVILEGES ON *.* TO 'x'@'%'" ),
			'create trigger'            => array( 'CREATE TRIGGER wp_t BEFORE INSERT ON wp_posts FOR EACH ROW SET NEW.post_title = 1' ),
			'mysqldump trigger'         => array( '/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `wp_x` BEFORE INSERT ON `wp_posts` FOR EACH ROW SET NEW.post_title = 1 */' ),
			'create procedure'          => array( 'CREATE PROCEDURE wp_p() SELECT 1' ),
			'create user'               => array( "CREATE USER 'x'@'%' IDENTIFIED BY 'y'" ),
			'drop database'             => array( 'DROP DATABASE wordpress' ),
			'update'                    => array( "UPDATE wp_users SET user_pass = ''" ),
			'delete'                    => array( 'DELETE FROM wp_users' ),
			'alter add column'          => array( 'ALTER TABLE wp_posts ADD x int' ),
			'rename'                    => array( 'RENAME TABLE wp_users TO wp_gone' ),
			'select into outfile'       => array( "SELECT * FROM wp_users INTO OUTFILE '/tmp/x'" ),
			'set global'                => array( 'SET GLOBAL general_log = 1' ),
			'set @@global'              => array( "SET @@GLOBAL.GTID_PURGED='x'" ),
			'set persist'               => array( 'SET PERSIST max_connections = 1' ),
			'set password'              => array( "SET PASSWORD = 'x'" ),
			'set with subquery'         => array( 'SET @x = (SELECT user_pass FROM wp_users LIMIT 1)' ),
			'insert select'             => array( 'INSERT INTO wp_t SELECT * FROM wp_users' ),
			'insert subquery'           => array( 'INSERT INTO wp_t VALUES ((SELECT user_pass FROM wp_users LIMIT 1))' ),
			'insert function'           => array( "INSERT INTO wp_t VALUES (LOAD_FILE('/etc/passwd'))" ),
			'insert fake introducer'    => array( "INSERT INTO wp_t VALUES (_load_file('/etc/passwd'))" ),
			'insert on duplicate'       => array( 'INSERT INTO wp_t VALUES (1) ON DUPLICATE KEY UPDATE id = (SELECT 1)' ),
			'create table select'       => array( 'CREATE TABLE wp_t (id int) SELECT ID FROM wp_users' ),
			'create table like'         => array( 'CREATE TABLE wp_t LIKE wp_users' ),
			'create data directory'     => array( "CREATE TABLE wp_t (id int) ENGINE=MyISAM DATA DIRECTORY='/var/www'" ),
			'create federated'          => array( "CREATE TABLE wp_t (id int) ENGINE=FEDERATED CONNECTION='mysql://x'" ),
			'other prefix'              => array( 'CREATE TABLE other_t (id int)' ),
			'other prefix insert'       => array( 'INSERT INTO `mysql`.`user` VALUES (1)' ),
			'delimiter'                 => array( 'DELIMITER ' ),
			'multiple dropped tables'   => array( 'DROP TABLE wp_a, wp_b' ),
			'hidden in version comment' => array( '/*!50000 DROP DATABASE wordpress */' ),
			'names multibyte'           => array( 'SET NAMES gbk' ),
			'names quoted multibyte'    => array( "SET NAMES 'big5'" ),
			'character set multibyte'   => array( 'SET CHARACTER SET sjis' ),
			'names unsafe collation'    => array( 'SET NAMES utf8mb4 COLLATE gbk_chinese_ci' ),
			'client charset'            => array( "SET character_set_client = 'gbk'" ),
			'connection charset'        => array( 'SET @@SESSION.character_set_connection = big5' ),
			'connection collation'      => array( "SET collation_connection = 'sjis_japanese_ci'" ),
			'default expression'        => array( 'CREATE TABLE wp_t (id int, r double DEFAULT (rand()))' ),
			'default function'          => array( 'CREATE TABLE wp_t (id int, u varchar(36) DEFAULT uuid())' ),
			'subquery in body'          => array( 'CREATE TABLE wp_t (id int CHECK (id IN (SELECT 1)))' ),
			'second engine'             => array( 'CREATE TABLE wp_t (id int) ENGINE=InnoDB, ENGINE=FEDERATED' ),
			'unsafe introducer'         => array( 'INSERT INTO wp_t VALUES (_gbk 0x41)' ),
			'quoted function default'   => array( 'CREATE TABLE wp_t (id int, u varchar(36) DEFAULT (`uuid`()))' ),
			'quoted function check'     => array( 'CREATE TABLE `wp_t` (`id` int CHECK (`f` (`id`)))' ),
			'qualified function'        => array( 'CREATE TABLE wp_t (id int, d datetime DEFAULT (mydb.now()))' ),
			'quoted qualified function' => array( 'CREATE TABLE wp_t (id int, d datetime DEFAULT (`mydb`.`now`()))' ),
			'single-quoted engine'      => array( "CREATE TABLE wp_t (id int) ENGINE='BLACKHOLE'" ),
			'double-quoted engine'      => array( 'CREATE TABLE wp_t (id int) ENGINE = "FEDERATED"' ),
			'backticked engine'         => array( 'CREATE TABLE wp_t (id int) ENGINE=`CSV`' ),
			'quoted second engine'      => array( "CREATE TABLE wp_t (id int) ENGINE=InnoDB, ENGINE='BLACKHOLE'" ),
			'unreadable engine'         => array( "CREATE TABLE wp_t (id int) ENGINE='Inno''DB'" ),
		);
	}

	/**
	 * @dataProvider rejected
	 */
	public function test_rejects( string $sql ): void {
		$statement = self::parse( $sql );
		$this->assertSame( Statement::REJECT, $statement->type, $statement->sql );
		$this->assertNotSame( '', $statement->reason );
	}

	public function test_rejects_grant_and_trigger_inside_a_dump(): void {
		$dump   = "CREATE TABLE wp_t (id int);\nINSERT INTO wp_t VALUES (1);\nGRANT ALL ON *.* TO 'x'@'%';\nDELIMITER ;;\n/*!50003 CREATE*/ /*!50003 TRIGGER t BEFORE INSERT ON wp_t FOR EACH ROW BEGIN SET NEW.id = 2; END */;;\nDELIMITER ;\n";
		$handle = fopen( 'php://memory', 'w+b' );
		fwrite( $handle, $dump );
		rewind( $handle );

		$splitter = new Splitter( $handle );
		$rejected = array();
		for ( $raw = $splitter->next(); null !== $raw; $raw = $splitter->next() ) {
			$statement = self::parse( $raw );
			if ( Statement::REJECT === $statement->type ) {
				$rejected[] = strtok( $statement->sql, ' ' );
			}
		}

		$this->assertContains( 'GRANT', $rejected );
		$this->assertContains( 'DELIMITER', $rejected );
		$this->assertContains( 'CREATE', $rejected );
	}

	public function test_rewrites_only_the_table_name(): void {
		$insert = self::parse( "INSERT INTO `wp_posts` (`ID`, `post_content`) VALUES (1, 'INSERT INTO `wp_posts` wp_posts')" );
		$this->assertSame( 'wp_posts', $insert->table );
		$this->assertSame( "INSERT INTO `swapline_tmp_1` (`ID`, `post_content`) VALUES (1, 'INSERT INTO `wp_posts` wp_posts')", $insert->with_table_name( 'swapline_tmp_1' ) );

		$create = self::parse( 'CREATE TABLE IF NOT EXISTS wp_posts (ID int)' );
		$this->assertSame( 'CREATE TABLE IF NOT EXISTS `swapline_tmp_1` (ID int)', $create->with_table_name( 'swapline_tmp_1' ) );
	}

	public function test_normalize_keeps_strings_and_strips_comments(): void {
		$this->assertSame(
			"INSERT INTO wp_t   VALUES ('-- not a comment', '/* nor this */', '# nor this')",
			Statement::normalize( "-- lead\nINSERT INTO wp_t /* x */ VALUES ('-- not a comment', '/* nor this */', '# nor this') # tail" )
		);
	}

	public function test_written_values_are_literals(): void {
		$values = array( "a'b", 'back\\slash', "nul\0byte", "line\nbreak\r", "sub\x1a", 'ünïcødé 😀', '; DROP TABLE wp_users; --' );
		$tuple  = array( SqlWriter::value( null, 'text' ), SqlWriter::value( "\x00\xff\x27", 'binary' ), SqlWriter::value( '', 'binary' ), SqlWriter::value( '165', 'bit' ) );
		foreach ( $values as $value ) {
			$tuple[] = SqlWriter::value( $value, 'text' );
		}

		$sql       = SqlWriter::insert( 'wp_t', array_map( 'strval', array_keys( $tuple ) ), array( $tuple ) );
		$statement = self::parse( rtrim( $sql, ";\n" ) );

		$this->assertSame( Statement::INSERT, $statement->type, $statement->reason );
		$this->assertStringContainsString( "'a\\'b'", $sql );
		$this->assertStringContainsString( "'nul\\0byte'", $sql );
		$this->assertStringContainsString( "'line\\nbreak\\r'", $sql );
		$this->assertStringContainsString( "'sub\\Z'", $sql );
		$this->assertStringContainsString( '0x' . bin2hex( "\x00\xff\x27" ), $sql );
		$this->assertStringContainsString( 'NULL', $sql );
		$this->assertStringContainsString( ',165,', $sql );
		$this->assertSame( 'bit', SqlWriter::kind( 'bit(8)' ) );
		$this->assertSame( 'binary', SqlWriter::kind( 'varbinary(255)' ) );
		$this->assertSame( 'binary', SqlWriter::kind( 'longblob' ) );
		$this->assertSame( 'text', SqlWriter::kind( 'varchar(191)' ) );
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function session_sets(): array {
		return array(
			'safe sql_mode'          => array( "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'", true ),
			'empty sql_mode'         => array( "SET sql_mode = ''", true ),
			'ansi quotes'            => array( "SET sql_mode = 'ANSI_QUOTES'", false ),
			'no backslash escapes'   => array( "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_BACKSLASH_ESCAPES'", false ),
			'ansi'                   => array( 'SET @@sql_mode = ansi', false ),
			'pipes as concat'        => array( "SET sql_mode = 'PIPES_AS_CONCAT'", false ),
			'oracle combination'     => array( "SET sql_mode = 'ORACLE'", false ),
			'mssql combination'      => array( "SET sql_mode = 'STRICT_TRANS_TABLES,MSSQL'", false ),
			'traditional'            => array( "SET sql_mode = 'TRADITIONAL'", true ),
			'sql_mode from variable' => array( 'SET sql_mode = @x', false ),
			'mixed with safe vars'   => array( "SET UNIQUE_CHECKS=0, sql_mode='ANSI_QUOTES'", false ),
			'safe names'             => array( 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_520_ci', true ),
			'latin1 names'           => array( "SET NAMES 'latin1'", true ),
			'safe client charset'    => array( "SET character_set_client = 'utf8mb4'", true ),
			'client from variable'   => array( 'SET character_set_client = @saved_cs_client', false ),
			'safe collation'         => array( "SET collation_connection = 'utf8mb4_unicode_ci'", true ),
		);
	}

	/**
	 * @dataProvider session_sets
	 */
	public function test_session_sets_only_run_when_they_keep_the_session_readable( string $sql, bool $execute ): void {
		$statement = self::parse( $sql );
		$this->assertSame( Statement::SET, $statement->type, $statement->reason );
		$this->assertSame( $execute, $statement->execute, $sql );
	}

	public function test_safe_sql_mode_strips_modes_that_change_quoting(): void {
		$this->assertSame( 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION', Statement::safe_sql_mode( 'STRICT_TRANS_TABLES,ANSI_QUOTES,NO_BACKSLASH_ESCAPES,NO_ENGINE_SUBSTITUTION' ) );
		$this->assertSame( '', Statement::safe_sql_mode( 'ansi,ignore_space' ) );
		$this->assertSame( 'NO_AUTO_VALUE_ON_ZERO', Statement::safe_sql_mode( 'NO_AUTO_VALUE_ON_ZERO' ) );
		$this->assertSame( 'STRICT_TRANS_TABLES', Statement::safe_sql_mode( 'MSSQL,STRICT_TRANS_TABLES,PIPES_AS_CONCAT,EMPTY_STRING_IS_NULL' ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function accepted_creates(): array {
		return array(
			'mariadb json check'  => array( 'CREATE TABLE `wp_t` (`id` bigint(20) unsigned NOT NULL, `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data`)), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' ),
			'timestamps'          => array( 'CREATE TABLE `wp_t` (`id` int NOT NULL, `at` datetime(6) NOT NULL DEFAULT current_timestamp(6) ON UPDATE current_timestamp(6), `d` decimal(10,2) DEFAULT 0.00, `e` enum(\'a\',\'b\') DEFAULT \'a\')' ),
			'generated column'    => array( "CREATE TABLE `wp_t` (`id` int, `doc` json, `name` varchar(100) GENERATED ALWAYS AS (json_unquote(json_extract(`doc`, '$.name'))) VIRTUAL, `twice` int AS (`id` * 2) STORED)" ),
			'index prefix length' => array( 'CREATE TABLE `wp_postmeta` (`meta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `meta_key` varchar(255) DEFAULT NULL, PRIMARY KEY (`meta_id`), KEY `meta_key` (`meta_key`(191)), KEY `pair` (`meta_id`,`meta_key`( 32 ))) ENGINE=InnoDB' ),
			'quoted key names'    => array( 'CREATE TABLE `wp_t` (`id` int, `parent` int, KEY `idx_parent` (`parent`), UNIQUE `u` (`id`), FULLTEXT KEY `ft` (`id`), CONSTRAINT `fk` FOREIGN KEY `fk_parent` (`parent`) REFERENCES `wp_p` (`id`))' ),
			'unquoted key names'  => array( 'CREATE TABLE wp_t (id int, parent int, KEY idx_parent (parent), UNIQUE KEY u (id), CONSTRAINT fk FOREIGN KEY fk_parent (parent) REFERENCES wp_p (id))' ),
			'quoted engine'       => array( "CREATE TABLE wp_t (id int) ENGINE='InnoDB' DEFAULT CHARSET=utf8mb4" ),
			'backticked engine'   => array( 'CREATE TABLE wp_t (id int) ENGINE = `MyISAM`' ),
		);
	}

	/**
	 * @dataProvider accepted_creates
	 */
	public function test_accepts_create_tables_with_safe_column_definitions( string $sql ): void {
		$statement = self::parse( $sql );
		$this->assertSame( Statement::CREATE, $statement->type, $statement->reason );
	}

	public function test_rejection_reasons_go_through_translation(): void {
		$GLOBALS['swapline_test']['translations'] = array( 'CREATE TABLE with %s is not allowed.' => 'CREATE TABLE con %s no permitido.' );

		$statement = Statement::parse( 'CREATE TABLE `wp_x` (a int) SELECT * FROM wp_users', 'wp_' );

		$this->assertSame( Statement::REJECT, $statement->type );
		$this->assertSame( 'CREATE TABLE con SELECT no permitido.', $statement->reason );
		unset( $GLOBALS['swapline_test']['translations'] );
	}
}
