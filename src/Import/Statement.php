<?php

namespace Swapline\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Normalises one SQL statement and decides whether an import may run it.
 *
 * Only CREATE TABLE, DROP TABLE, INSERT ... VALUES with literal values,
 * LOCK/UNLOCK TABLES and session-scoped SET are accepted. mysqldump's
 * ALTER TABLE ... DISABLE/ENABLE KEYS is accepted and skipped.
 */
final class Statement {

	public const CREATE = 'create';
	public const DROP   = 'drop';
	public const INSERT = 'insert';
	public const SET    = 'set';
	public const SKIP   = 'skip';
	public const EMPTY  = 'empty';
	public const REJECT = 'reject';

	private const IDENT = '(`(?:[^`]|``)+`|[A-Za-z0-9_$]+)';

	private const ENGINES = array( 'innodb', 'myisam', 'aria', 'memory', 'heap' );

	/**
	 * Session variables an import may change. Other session variables are accepted but not run.
	 */
	private const SESSION_VARIABLES = array(
		'character_set_client',
		'character_set_connection',
		'character_set_results',
		'collation_connection',
		'foreign_key_checks',
		'sql_notes',
		'sql_quote_show_create',
		'time_zone',
		'unique_checks',
	);

	/**
	 * Character sets whose bytes can never hide a backslash or a quote inside a multibyte character.
	 */
	private const CHARSETS = array( 'utf8', 'utf8mb3', 'utf8mb4', 'latin1', 'ascii', 'binary' );

	/**
	 * Variables that change how the server reads the bytes of a statement.
	 */
	private const CHARSET_VARIABLES = array( 'character_set_client', 'character_set_connection', 'collation_connection' );

	/**
	 * SQL modes that leave quoting, escaping and function names as this validator reads them.
	 * Anything else, including ANSI_QUOTES, NO_BACKSLASH_ESCAPES, PIPES_AS_CONCAT and the
	 * ORACLE or MSSQL style combinations, is dropped.
	 */
	private const SAFE_MODES = array(
		'ALLOW_INVALID_DATES',
		'ERROR_FOR_DIVISION_BY_ZERO',
		'HIGH_NOT_PRECEDENCE',
		'NO_AUTO_CREATE_USER',
		'NO_AUTO_VALUE_ON_ZERO',
		'NO_DIR_IN_CREATE',
		'NO_ENGINE_SUBSTITUTION',
		'NO_FIELD_OPTIONS',
		'NO_KEY_OPTIONS',
		'NO_TABLE_OPTIONS',
		'NO_UNSIGNED_SUBTRACTION',
		'NO_ZERO_DATE',
		'NO_ZERO_IN_DATE',
		'ONLY_FULL_GROUP_BY',
		'PAD_CHAR_TO_FULL_LENGTH',
		'REAL_AS_FLOAT',
		'SIMULTANEOUS_ASSIGNMENT',
		'STRICT_ALL_TABLES',
		'STRICT_TRANS_TABLES',
		'TIME_ROUND_FRACTIONAL',
		'TIME_TRUNCATE_FRACTIONAL',
		'TRADITIONAL',
	);

	/**
	 * Words a CREATE TABLE may follow with a parenthesis: data types, key and constraint syntax,
	 * and deterministic functions used in defaults and generated columns.
	 */
	private const CALLS = array(
		'tinyint',
		'smallint',
		'mediumint',
		'int',
		'integer',
		'bigint',
		'decimal',
		'dec',
		'numeric',
		'fixed',
		'float',
		'double',
		'real',
		'bit',
		'char',
		'varchar',
		'binary',
		'varbinary',
		'enum',
		'set',
		'datetime',
		'timestamp',
		'time',
		'year',
		'vector',
		'key',
		'index',
		'unique',
		'check',
		'as',
		'default',
		'using',
		'references',
		'current_timestamp',
		'now',
		'localtime',
		'localtimestamp',
		'utc_timestamp',
		'curdate',
		'current_date',
		'curtime',
		'current_time',
		'json_valid',
		'json_extract',
		'json_unquote',
		'json_value',
		'json_length',
		'json_contains',
		'concat',
		'concat_ws',
		'lower',
		'upper',
		'lcase',
		'ucase',
		'substring',
		'substr',
		'left',
		'right',
		'trim',
		'ltrim',
		'rtrim',
		'length',
		'char_length',
		'replace',
		'reverse',
		'md5',
		'sha1',
		'sha2',
		'crc32',
		'hex',
		'unhex',
		'if',
		'ifnull',
		'coalesce',
		'nullif',
		'cast',
		'convert',
		'abs',
		'round',
		'floor',
		'ceil',
		'ceiling',
		'greatest',
		'least',
		'date',
		'month',
		'day',
		'dayofmonth',
		'hour',
		'minute',
		'second',
		'date_format',
		'from_unixtime',
		'unix_timestamp',
	);

	public string $type;
	public string $sql;
	public string $table      = '';
	public string $reason     = '';
	public bool $execute      = false;
	private int $table_at     = 0;
	private int $table_length = 0;

	private function __construct( string $type, string $sql ) {
		$this->type = $type;
		$this->sql  = $sql;
	}

	/**
	 * @param string[] $foreign Prefixes of other installs in the same database, whose tables are refused.
	 */
	public static function parse( string $raw, string $prefix, array $foreign = array() ): self {
		$sql = self::normalize( $raw );

		if ( '' === $sql ) {
			return new self( self::EMPTY, $sql );
		}
		if ( preg_match( '/^DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?' . self::IDENT . '\s*$/i', $sql, $m, PREG_OFFSET_CAPTURE ) ) {
			return self::with_table( self::DROP, $sql, $m[1], $prefix, $foreign );
		}
		if ( preg_match( '/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?' . self::IDENT . '\s*\(/i', $sql, $m, PREG_OFFSET_CAPTURE ) ) {
			$statement = self::with_table( self::CREATE, $sql, $m[1], $prefix, $foreign );
			if ( self::REJECT !== $statement->type ) {
				$reason = self::create_problem( $sql, strlen( $m[0][0] ) - 1 );
				if ( null !== $reason ) {
					return self::reject( $sql, $reason );
				}
			}
			return $statement;
		}
		if ( preg_match( '/^INSERT\s+(?:IGNORE\s+)?INTO\s+' . self::IDENT . '\s*(?:\(\s*' . self::IDENT . '(?:\s*,\s*' . self::IDENT . ')*\s*\))?\s*VALUES\s*(?=\()/i', $sql, $m, PREG_OFFSET_CAPTURE ) ) {
			if ( ! self::literal_values( (string) substr( $sql, strlen( $m[0][0] ) ) ) ) {
				return self::reject( $sql, 'INSERT values must be literals.' );
			}
			return self::with_table( self::INSERT, $sql, $m[1], $prefix, $foreign );
		}
		if ( preg_match( '/^(?:LOCK\s+TABLES?\s+\S|UNLOCK\s+TABLES?$)/i', $sql ) ) {
			return new self( self::SKIP, $sql );
		}
		if ( preg_match( '/^ALTER\s+TABLE\s+' . self::IDENT . '\s+(?:DISABLE|ENABLE)\s+KEYS$/i', $sql ) ) {
			return new self( self::SKIP, $sql );
		}
		if ( preg_match( '/^SET\s+(.+)$/is', $sql, $m ) ) {
			return self::session_set( $sql, $m[1] );
		}

		preg_match( '/^[A-Za-z]+/', $sql, $verb );
		$kind = strtoupper( $verb[0] ?? '' );
		if ( in_array( $kind, array( 'CREATE', 'DROP', 'ALTER' ), true ) && preg_match( '/\b(TABLE|TRIGGER|PROCEDURE|FUNCTION|EVENT|VIEW|USER|DATABASE|SCHEMA|INDEX|ROLE|SERVER|TABLESPACE)\b/i', $sql, $object ) ) {
			$kind .= ' ' . strtoupper( $object[1] );
		}
		return self::reject( $sql, sprintf( '%s statements are not allowed.', '' === $kind ? 'These' : $kind ) );
	}

	/**
	 * The statement with its table name swapped for another, quoted identifier.
	 */
	public function with_table_name( string $table ): string {
		return substr( $this->sql, 0, $this->table_at ) . '`' . str_replace( '`', '``', $table ) . '`' . substr( $this->sql, $this->table_at + $this->table_length );
	}

	/**
	 * Strips comments and unwraps MySQL's executable comments.
	 */
	public static function normalize( string $sql ): string {
		$out        = '';
		$length     = strlen( $sql );
		$i          = 0;
		$executable = false;

		while ( $i < $length ) {
			$run = strcspn( $sql, "'\"`#-/*", $i );
			if ( $run > 0 ) {
				$out .= substr( $sql, $i, $run );
				$i   += $run;
				continue;
			}

			$char = $sql[ $i ];
			$next = $sql[ $i + 1 ] ?? '';

			if ( "'" === $char || '"' === $char || '`' === $char ) {
				$end  = self::quote_end( $sql, $i );
				$out .= substr( $sql, $i, $end - $i + 1 );
				$i    = $end + 1;
				continue;
			}
			if ( '#' === $char || ( '-' === $char && '-' === $next && ( ! isset( $sql[ $i + 2 ] ) || ord( $sql[ $i + 2 ] ) <= 32 ) ) ) {
				$end  = strpos( $sql, "\n", $i );
				$i    = false === $end ? $length : $end + 1;
				$out .= ' ';
				continue;
			}
			if ( '/' === $char && '*' === $next ) {
				if ( '!' === ( $sql[ $i + 2 ] ?? '' ) ) {
					$i         += 3 + strspn( $sql, '0123456789', $i + 3 );
					$executable = true;
				} else {
					$end = strpos( $sql, '*/', $i + 2 );
					$i   = false === $end ? $length : $end + 2;
				}
				$out .= ' ';
				continue;
			}
			if ( '*' === $char && '/' === $next && $executable ) {
				$executable = false;
				$i         += 2;
				$out       .= ' ';
				continue;
			}

			$out .= $char;
			++$i;
		}

		return trim( $out );
	}

	/**
	 * Position of the quote that closes the string opening at $start.
	 */
	public static function quote_end( string $sql, int $start ): int {
		$quote  = $sql[ $start ];
		$length = strlen( $sql );
		$i      = $start + 1;
		$stops  = '`' === $quote ? '`' : '\\' . $quote;

		while ( true ) {
			$i += strcspn( $sql, $stops, $i );
			if ( $i >= $length ) {
				return $length - 1;
			}
			if ( '\\' === $sql[ $i ] ) {
				$i += 2;
				continue;
			}
			if ( ( $sql[ $i + 1 ] ?? '' ) === $quote ) {
				$i += 2;
				continue;
			}
			return $i;
		}
	}

	/**
	 * @param array{0: string, 1: int} $found
	 * @param string[]                 $foreign
	 */
	private static function with_table( string $type, string $sql, array $found, string $prefix, array $foreign ): self {
		$table = self::unquote( $found[0] );

		if ( ! preg_match( '/^[A-Za-z0-9_$-]{1,64}$/', $table ) ) {
			return self::reject( $sql, 'The table name is not valid.' );
		}
		if ( '' === $prefix || 0 !== strpos( $table, $prefix ) ) {
			return self::reject( $sql, sprintf( 'The table %s does not use this site\'s table prefix.', $table ) );
		}

		foreach ( $foreign as $other ) {
			if ( 0 === strpos( $table, $other ) ) {
				return self::reject( $sql, sprintf( 'The table %s belongs to another WordPress install in this database.', $table ) );
			}
		}

		$statement               = new self( $type, $sql );
		$statement->table        = $table;
		$statement->table_at     = $found[1];
		$statement->table_length = strlen( $found[0] );
		$statement->execute      = true;
		return $statement;
	}

	private static function reject( string $sql, string $reason ): self {
		$statement         = new self( self::REJECT, $sql );
		$statement->reason = $reason;
		return $statement;
	}

	private static function unquote( string $identifier ): string {
		if ( '`' === $identifier[0] ) {
			return str_replace( '``', '`', substr( $identifier, 1, -1 ) );
		}
		return $identifier;
	}

	/**
	 * Checks the column definitions and table options of a CREATE TABLE.
	 */
	private static function create_problem( string $sql, int $open ): ?string {
		$depth  = 0;
		$length = strlen( $sql );
		$close  = null;

		for ( $i = $open; $i < $length; $i++ ) {
			$char = $sql[ $i ];
			if ( "'" === $char || '"' === $char || '`' === $char ) {
				$i = self::quote_end( $sql, $i );
				continue;
			}
			if ( '(' === $char ) {
				++$depth;
			} elseif ( ')' === $char && 0 === --$depth ) {
				$close = $i;
				break;
			}
		}

		if ( null === $close ) {
			return 'The CREATE TABLE statement is incomplete.';
		}

		$options = self::without_strings( (string) substr( $sql, $close + 1 ) );
		if ( preg_match( '/\b(SELECT|LIKE|UNION|DIRECTORY|TABLESPACE|CONNECTION)\b/i', $options, $m ) ) {
			return sprintf( 'CREATE TABLE with %s is not allowed.', strtoupper( $m[1] ) );
		}
		// Read on the raw text, since the engine name may be quoted.
		$raw = (string) substr( $sql, $close + 1 );
		preg_match_all( '/\bENGINE\b/i', $raw, $engines, PREG_OFFSET_CAPTURE );
		foreach ( $engines[0] as $found ) {
			if ( ! preg_match( '/\G\s*=?\s*([\'"`]?)([A-Za-z0-9_]+)\1(?![A-Za-z0-9_\'"`])/i', $raw, $engine, 0, $found[1] + strlen( $found[0] ) ) ) {
				return 'The storage engine could not be read.';
			}
			if ( ! in_array( strtolower( $engine[2] ), self::ENGINES, true ) ) {
				return sprintf( 'The %s storage engine is not allowed.', $engine[2] );
			}
		}

		$body = self::without_strings( (string) substr( $sql, $open ) );
		if ( preg_match( '/\bSELECT\b/i', $body ) ) {
			return 'CREATE TABLE with SELECT is not allowed.';
		}
		preg_match_all( '/([A-Za-z_][A-Za-z0-9_$]*)\s*\(/', $body, $calls, PREG_OFFSET_CAPTURE );
		foreach ( $calls[1] as $call ) {
			if ( in_array( strtolower( $call[0] ), self::CALLS, true ) ) {
				continue;
			}
			if ( preg_match( '/\b(?:KEY|INDEX|REFERENCES|CONSTRAINT)\s+$/i', substr( $body, 0, $call[1] ) ) ) {
				continue;
			}
			return sprintf( 'CREATE TABLE with %s() is not allowed.', strtoupper( $call[0] ) );
		}
		// A quoted or qualified name before a parenthesis calls a stored or loadable function.
		if ( preg_match( '/\.\s*(?:\'\'|[A-Za-z_][A-Za-z0-9_$]*)\s*\(/', $body ) ) {
			return 'CREATE TABLE with a qualified function name is not allowed.';
		}
		preg_match_all( "/''\\s*\\((?!\\s*\\d+\\s*\\))/", $body, $quoted, PREG_OFFSET_CAPTURE );
		foreach ( $quoted[0] as $call ) {
			if ( ! preg_match( '/\b(?:KEY|INDEX|UNIQUE|REFERENCES|CONSTRAINT)\s+$/i', substr( $body, 0, $call[1] ) ) ) {
				return 'CREATE TABLE with a quoted function name is not allowed.';
			}
		}
		return null;
	}

	private static function without_strings( string $sql ): string {
		$out    = '';
		$length = strlen( $sql );
		$i      = 0;
		while ( $i < $length ) {
			$run  = strcspn( $sql, "'\"`", $i );
			$out .= substr( $sql, $i, $run );
			$i   += $run;
			if ( $i < $length ) {
				$i    = self::quote_end( $sql, $i ) + 1;
				$out .= "''";
			}
		}
		return $out;
	}

	/**
	 * True when the VALUES list holds only literals: strings, numbers, hex, NULL, booleans and DEFAULT.
	 */
	private static function literal_values( string $values ): bool {
		$length = strlen( $values );
		$i      = 0;

		while ( $i < $length ) {
			$run = strcspn( $values, "'\"", $i );
			if ( $run > 0 ) {
				$rest = preg_replace( '/[\s(),]+|NULL|TRUE|FALSE|DEFAULT|_(?i:binary|utf8mb4|utf8mb3|utf8|latin1|ascii)(?=\s*(?:0x|[xXbB]?$))|0x[0-9A-Fa-f]*|[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?|[xXbBnN]$/', '', substr( $values, $i, $run ) );
				if ( '' !== $rest ) {
					return false;
				}
				$i += $run;
				continue;
			}
			$i = self::quote_end( $values, $i ) + 1;
		}
		return true;
	}

	private static function session_set( string $sql, string $assignments ): self {
		$execute = true;

		foreach ( self::split_assignments( $assignments ) as $assignment ) {
			$assignment = trim( $assignment );
			$scalar     = '(?:\'(?:[^\'\\\\]|\\\\.|\'\')*\'|"(?:[^"\\\\]|\\\\.|"")*"|@@(?:SESSION\.|LOCAL\.)?[A-Za-z0-9_]+|@[A-Za-z0-9_.$]+|[A-Za-z0-9_+.-]+)';

			if ( preg_match( '/^(?:NAMES|CHARACTER\s+SET|CHARSET)\s+(\'?)([A-Za-z0-9_]+)\1(?:\s+COLLATE\s+(\'?)([A-Za-z0-9_]+)\3)?$/i', $assignment, $m ) ) {
				if ( ! self::safe_charset( $m[2] ) || ( isset( $m[4] ) && '' !== $m[4] && ! self::safe_charset( $m[4], true ) ) ) {
					return self::reject( $sql, sprintf( 'The %s character set is not allowed.', $m[2] ) );
				}
				continue;
			}
			if ( preg_match( '/^@[A-Za-z0-9_.$]+\s*:?=\s*' . $scalar . '$/s', $assignment ) ) {
				continue;
			}
			if ( preg_match( '/^(?:@@(?:SESSION\.|LOCAL\.)?|(?:SESSION|LOCAL)\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*:?=\s*(' . $scalar . ')$/is', $assignment, $m ) ) {
				$name = strtolower( $m[1] );
				if ( in_array( $name, array( 'global', 'persist', 'persist_only', 'password' ), true ) ) {
					return self::reject( $sql, 'Only session variables can be set.' );
				}
				$literal = self::literal( $m[2] );
				if ( in_array( $name, self::CHARSET_VARIABLES, true ) ) {
					if ( null !== $literal && ! self::safe_charset( $literal, 'collation_connection' === $name ) ) {
						return self::reject( $sql, sprintf( 'The %s character set is not allowed.', $literal ) );
					}
					$execute = $execute && null !== $literal;
					continue;
				}
				if ( 'sql_mode' === $name ) {
					$execute = $execute && null !== $literal && self::safe_sql_mode( $literal ) === $literal;
					continue;
				}
				$execute = $execute && in_array( $name, self::SESSION_VARIABLES, true );
				continue;
			}
			return self::reject( $sql, 'Only session variables can be set.' );
		}

		$statement          = new self( self::SET, $sql );
		$statement->execute = $execute;
		return $statement;
	}

	/**
	 * The given sql_mode reduced to the modes that leave statements read the way they were checked.
	 */
	public static function safe_sql_mode( string $modes ): string {
		$kept = array_filter(
			array_map( 'trim', explode( ',', $modes ) ),
			static fn( string $mode ): bool => in_array( strtoupper( $mode ), self::SAFE_MODES, true )
		);
		return implode( ',', $kept );
	}

	public static function safe_charset( string $name, bool $collation = false ): bool {
		$name = strtolower( $name );
		if ( 'default' === $name ) {
			return true;
		}
		if ( $collation ) {
			$name = 'binary' === $name ? $name : (string) strstr( $name, '_', true );
		}
		return in_array( $name, self::CHARSETS, true );
	}

	/**
	 * The value of a quoted string or bare word, or null for a variable reference.
	 */
	private static function literal( string $scalar ): ?string {
		if ( '@' === $scalar[0] ) {
			return null;
		}
		if ( "'" === $scalar[0] || '"' === $scalar[0] ) {
			$inner = substr( $scalar, 1, -1 );
			return false !== strpos( $inner, '\\' ) ? null : str_replace( $scalar[0] . $scalar[0], $scalar[0], $inner );
		}
		return $scalar;
	}

	/**
	 * @return string[]
	 */
	private static function split_assignments( string $sql ): array {
		$parts  = array();
		$start  = 0;
		$depth  = 0;
		$length = strlen( $sql );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $sql[ $i ];
			if ( "'" === $char || '"' === $char || '`' === $char ) {
				$i = self::quote_end( $sql, $i );
			} elseif ( '(' === $char ) {
				++$depth;
			} elseif ( ')' === $char ) {
				--$depth;
			} elseif ( ',' === $char && 0 === $depth ) {
				$parts[] = substr( $sql, $start, $i - $start );
				$start   = $i + 1;
			}
		}
		$parts[] = substr( $sql, $start );
		return $parts;
	}
}
