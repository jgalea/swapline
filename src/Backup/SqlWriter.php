<?php

namespace Swapline\Backup;

defined( 'ABSPATH' ) || exit;

final class SqlWriter {

	private const ESCAPES = array(
		'\\'   => '\\\\',
		"'"    => "\\'",
		"\0"   => '\\0',
		"\n"   => '\\n',
		"\r"   => '\\r',
		"\x1a" => '\\Z',
	);

	public static function identifier( string $name ): string {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	public static function string( string $value ): string {
		return "'" . strtr( $value, self::ESCAPES ) . "'";
	}

	/**
	 * @param string $kind One of 'text', 'binary' or 'bit', from kind().
	 */
	public static function value( ?string $value, string $kind ): string {
		if ( null === $value ) {
			return 'NULL';
		}
		if ( 'bit' === $kind && ctype_digit( $value ) ) {
			return $value;
		}
		if ( 'text' !== $kind ) {
			return '' === $value ? "''" : '0x' . bin2hex( $value );
		}
		return self::string( $value );
	}

	/**
	 * Binary types are written as hex so no byte is reinterpreted by a character set.
	 * BIT values arrive from mysqlnd as decimal strings and are written as numbers.
	 */
	public static function kind( string $type ): string {
		if ( preg_match( '/^bit\b/i', $type ) ) {
			return 'bit';
		}
		return preg_match( '/binary|blob|geometry|point|linestring|polygon/i', $type ) ? 'binary' : 'text';
	}

	/**
	 * @param string[]                       $columns
	 * @param array<int, array<int, string>> $tuples  Rows of already encoded values.
	 */
	public static function insert( string $table, array $columns, array $tuples ): string {
		$rows = array();
		foreach ( $tuples as $tuple ) {
			$rows[] = '(' . implode( ',', $tuple ) . ')';
		}
		return 'INSERT INTO ' . self::identifier( $table ) . ' (' . implode( ', ', array_map( array( self::class, 'identifier' ), $columns ) ) . ") VALUES\n" . implode( ",\n", $rows ) . ";\n";
	}
}
