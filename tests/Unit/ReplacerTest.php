<?php

namespace Swapline\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Swapline\Engine\Replacer;
use Swapline\Tests\NaiveReplacer;

final class ReplacerTest extends TestCase {

	private static function naive(): bool {
		return (bool) getenv( 'SWAPLINE_NAIVE_ENGINE' );
	}

	/**
	 * @return Replacer|NaiveReplacer
	 */
	private function engine( string $search, string $replace, bool $ci = false, bool $regex = false ) {
		if ( self::naive() ) {
			return new NaiveReplacer( $search, $replace );
		}
		return new Replacer( $search, $replace, $ci, $regex );
	}

	private static function regex_error( string $pattern ): ?string {
		return self::naive() ? NaiveReplacer::regex_error( $pattern ) : Replacer::regex_error( $pattern );
	}

	/**
	 * @return mixed
	 */
	private static function decode( string $value ) {
		$decoded = unserialize( $value, array( 'allowed_classes' => false ) );
		self::assertTrue( false !== $decoded || 'b:0;' === $value, 'Result must unserialize: ' . $value );
		return $decoded;
	}

	public function test_nested_serialized_string_inside_serialized_array(): void {
		$inner    = serialize( array( 'url' => 'http://old.example.com/page' ) );
		$original = serialize(
			array(
				'widget' => $inner,
				'title'  => 'Visit http://old.example.com',
			)
		);

		$result = $this->engine( 'http://old.example.com', 'https://new.example.org' )->replace( $original );

		$outer = self::decode( $result->value );
		$this->assertSame( 'Visit https://new.example.org', $outer['title'] );
		$this->assertSame( array( 'url' => 'https://new.example.org/page' ), self::decode( $outer['widget'] ) );
		$this->assertSame( 2, $result->replacements );
		$this->assertSame( 0, $result->failures );
	}

	public function test_three_levels_of_nesting(): void {
		$level3   = serialize( array( 'old' ) );
		$level2   = serialize(
			array(
				'x' => $level3,
				'y' => 'old',
			)
		);
		$original = serialize( array( $level2 ) );

		$result = $this->engine( 'old', 'brand-new' )->replace( $original );

		$l1 = self::decode( $result->value );
		$l2 = self::decode( $l1[0] );
		$this->assertSame( 'brand-new', $l2['y'] );
		$this->assertSame( array( 'brand-new' ), self::decode( $l2['x'] ) );
		$this->assertSame( 2, $result->replacements );
	}

	public function test_objects_of_unknown_classes_are_rebuilt_without_loading_them(): void {
		$original = 'O:20:"Swapline_Fake_Widget":3:{s:5:"title";s:16:"old.example.com!";'
			. "s:7:\"\0*\0link\";s:22:\"https://old.example.com\";"
			. "s:26:\"\0Swapline_Fake_Widget\0secret\";s:3:\"old\";}";
		$original = preg_replace_callback(
			'/s:\d+:"((?:[^"]|"(?!;))*)";/s',
			static fn( array $m ): string => 's:' . strlen( $m[1] ) . ':"' . $m[1] . '";',
			$original
		);

		$loaded = array();
		$spy    = static function ( string $class_name ) use ( &$loaded ): void {
			$loaded[] = $class_name;
		};
		spl_autoload_register( $spy );

		$result = $this->engine( 'old.example.com', 'new.example.org.uk' )->replace( $original );

		$object = self::decode( $result->value );
		spl_autoload_unregister( $spy );

		$this->assertSame( array(), $loaded, 'No class may be autoloaded.' );
		$this->assertInstanceOf( \__PHP_Incomplete_Class::class, $object );
		$props = (array) $object;
		$this->assertSame( 'Swapline_Fake_Widget', $props['__PHP_Incomplete_Class_Name'] );
		$this->assertSame( 'new.example.org.uk!', $props['title'] );
		$this->assertSame( 'https://new.example.org.uk', $props["\0*\0link"] );
		$this->assertSame( 'old', $props["\0Swapline_Fake_Widget\0secret"] );
		$this->assertStringContainsString( 'O:20:"Swapline_Fake_Widget":3:', $result->value );
	}

	public function test_multibyte_replacement_keeps_byte_lengths_correct(): void {
		$original = serialize(
			array(
				'name' => 'Café old',
				'city' => 'São Paulo old',
			)
		);

		$result = $this->engine( 'old', 'ñandú 🦜' )->replace( $original );

		$decoded = self::decode( $result->value );
		$this->assertSame( 'Café ñandú 🦜', $decoded['name'] );
		$this->assertSame( 'São Paulo ñandú 🦜', $decoded['city'] );
		$this->assertStringContainsString( 's:' . strlen( 'Café ñandú 🦜' ) . ':"Café ñandú 🦜"', $result->value );
	}

	public function test_multibyte_search_term(): void {
		$original = serialize( array( 'Ciudad de México', 'Méxicoland' ) );

		$result = $this->engine( 'México', 'Mexico' )->replace( $original );

		$this->assertSame( array( 'Ciudad de Mexico', 'Mexicoland' ), self::decode( $result->value ) );
	}

	public function test_broken_serialized_value_is_left_alone_and_counted(): void {
		$original = 'a:1:{s:3:"url";s:99:"http://old.example.com";}';

		$result = $this->engine( 'old.example.com', 'new.example.com' )->replace( $original );

		$this->assertSame( $original, $result->value );
		$this->assertFalse( $result->changed );
		$this->assertSame( 1, $result->failures );
		$this->assertSame( 0, $result->replacements );

		$unrelated = $this->engine( 'old.example.com', 'new.example.com' )->replace( 'a:1:{s:3:"url";s:99:"http://elsewhere.test";}' );
		$this->assertSame( 0, $unrelated->failures, 'Broken values without a match are not counted.' );
	}

	public function test_array_keys_are_not_replaced(): void {
		$original = serialize( array( 'old' => 'old value' ) );

		$result = $this->engine( 'old', 'new' )->replace( $original );

		$this->assertSame( array( 'old' => 'new value' ), self::decode( $result->value ) );
	}

	public function test_references_floats_and_booleans_survive(): void {
		$shared   = array( 'link' => 'old' );
		$data     = array(
			'a'    => &$shared,
			'b'    => &$shared,
			'pi'   => 3.14159,
			'flag' => false,
			'none' => null,
			'n'    => 42,
		);
		$original = serialize( $data );

		$result = $this->engine( 'old', 'replaced' )->replace( $original );

		$decoded = self::decode( $result->value );
		$this->assertSame( 'replaced', $decoded['a']['link'] );
		$this->assertSame( 'replaced', $decoded['b']['link'] );
		$decoded['a']['link'] = 'changed through reference';
		$this->assertSame( 'changed through reference', $decoded['b']['link'] );
		$this->assertSame( 3.14159, $decoded['pi'] );
		$this->assertFalse( $decoded['flag'] );
		$this->assertNull( $decoded['none'] );
	}

	public function test_serialized_value_with_surrounding_whitespace(): void {
		$original = "  \n" . serialize( 'old site' ) . "\n";

		$result = $this->engine( 'old', 'shiny new' )->replace( $original );

		$this->assertSame( "  \n" . serialize( 'shiny new site' ) . "\n", $result->value );
	}

	public function test_regex_with_backreferences_inside_serialized_data(): void {
		$original = serialize( array( 'http://old.example.com/p/12', 'https://old.example.com/p/345' ) );

		$result = $this->engine( 'https?://old\.example\.com/p/(\d+)', 'https://new.example.com/post-$1', false, true )->replace( $original );

		$this->assertSame( array( 'https://new.example.com/post-12', 'https://new.example.com/post-345' ), self::decode( $result->value ) );
		$this->assertSame( 2, $result->replacements );
	}

	public function test_regex_containing_every_delimiter_candidate(): void {
		$result = $this->engine( 'a/b#c~d%e@f!g;h`i+', 'ok', false, true )->replace( 'x a/b#c~d%e@f!g;h`iii y' );

		$this->assertSame( 'x ok y', $result->value );
	}

	public function test_invalid_regex_is_reported_before_running(): void {
		$this->assertNotNull( self::regex_error( '(unclosed' ) );
		$this->assertNotNull( self::regex_error( 'x*' ), 'Patterns that match empty strings are rejected.' );
		$this->assertNull( self::regex_error( 'old\.example\.com' ) );
	}

	public function test_case_insensitive_ascii_inside_serialized(): void {
		$original = serialize( array( 'HTTP://OLD.Example.com/a', 'http://old.example.com/b' ) );

		$result = $this->engine( 'http://old.example.com', 'https://new.example.com', true )->replace( $original );

		$this->assertSame( array( 'https://new.example.com/a', 'https://new.example.com/b' ), self::decode( $result->value ) );
		$this->assertSame( 2, $result->replacements );
	}

	public function test_case_insensitive_multibyte(): void {
		$result = $this->engine( 'école', 'school', true )->replace( serialize( 'ÉCOLE and École' ) );

		$this->assertSame( 'school and school', self::decode( $result->value ) );
	}

	public function test_case_sensitive_by_default(): void {
		$result = $this->engine( 'Old', 'Newer' )->replace( serialize( 'Old old OLD' ) );

		$this->assertSame( 'Newer old OLD', self::decode( $result->value ) );
		$this->assertSame( 1, $result->replacements );
		$this->assertSame( 0, $result->failures );
	}

	public function test_elementor_style_json_with_escaped_slashes(): void {
		$original = '[{"id":"a1b2","settings":{"link":{"url":"https:\/\/old.example.com\/shop"},"title":"Caf\u00e9"},"elements":[]}]';

		$result = $this->engine( 'https://old.example.com', 'https://new.example.com' )->replace( $original );

		$this->assertSame(
			'[{"id":"a1b2","settings":{"link":{"url":"https:\/\/new.example.com\/shop"},"title":"Caf\u00e9"},"elements":[]}]',
			$result->value
		);
		$this->assertNotNull( json_decode( $result->value ) );
		$this->assertSame( 1, $result->replacements );
	}

	public function test_json_unicode_escapes_are_matched(): void {
		$original = '{"title":"Caf\u00e9 old","empty":{},"n":1.0}';

		$result = $this->engine( 'Café old', 'Café new' )->replace( $original );

		$this->assertSame( '{"title":"Caf\u00e9 new","empty":{},"n":1.0}', $result->value );
	}

	public function test_json_that_does_not_round_trip_is_replaced_in_place(): void {
		$original = "{ \"url\" : \"https:\\/\\/old.example.com\\/x\",\n  \"plain\": \"https://old.example.com\" }";

		$result = $this->engine( 'https://old.example.com', 'https://new.example.com' )->replace( $original );

		$this->assertSame( "{ \"url\" : \"https:\\/\\/new.example.com\\/x\",\n  \"plain\": \"https://new.example.com\" }", $result->value );
		$this->assertSame( 2, $result->replacements );
	}

	public function test_json_replacement_with_quotes_is_escaped(): void {
		$original = '{ "a" : "old" }';

		$result = $this->engine( 'old', 'say "hi"', false, false )->replace( $original );

		$decoded = json_decode( $result->value, true );
		$this->assertSame( array( 'a' => 'say "hi"' ), $decoded, 'Quotes are JSON-escaped in the replacement.' );
	}

	public function test_regex_that_would_break_json_is_refused_and_counted(): void {
		$original = '{ "a" : "old" }';

		$result = $this->engine( 'o(l)d', 'x"$1', false, true )->replace( $original );

		$this->assertSame( $original, $result->value );
		$this->assertSame( 1, $result->failures );
	}

	public function test_serialized_json_inside_serialized_array(): void {
		$json     = '{"img":"https:\/\/old.example.com\/a.jpg"}';
		$original = serialize( array( 'data' => $json ) );

		$result = $this->engine( 'https://old.example.com', 'https://cdn.example.com' )->replace( $original );

		$decoded = self::decode( $result->value );
		$this->assertSame( '{"img":"https:\/\/cdn.example.com\/a.jpg"}', $decoded['data'] );
	}

	public function test_shortcode_that_looks_like_json_is_plain_text(): void {
		$result = $this->engine( 'OLD', 'new', true )->replace( '[gallery link="old"]' );

		$this->assertSame( '[gallery link="new"]', $result->value );
		$this->assertSame( 1, $result->replacements );
		$this->assertSame( 0, $result->failures );
	}

	public function test_serialized_string_inside_json_that_does_not_round_trip_is_left_alone_and_counted(): void {
		$original = '{ "data": "s:3:\"old\";" }';

		$result = $this->engine( 'old', 'longer' )->replace( $original );

		$this->assertSame( $original, $result->value );
		$this->assertSame( 1, $result->failures );
		$this->assertSame( 0, $result->replacements );
	}

	public function test_serialized_array_inside_pretty_json_is_left_alone_and_counted(): void {
		$original = "{\n  \"a\": \"old\",\n  \"meta\": [\"a:1:{s:3:\\\"url\\\";s:3:\\\"old\\\";}\"]\n}";

		$result = $this->engine( 'old', 'longer' )->replace( $original );

		$this->assertSame( $original, $result->value );
		$this->assertSame( 1, $result->failures );
	}

	public function test_json_text_replace_still_runs_when_serialized_leaves_survive(): void {
		$original = '{ "data": "s:3:\"abc\";", "url": "old" }';

		$result = $this->engine( 'old', 'longer' )->replace( $original );

		$this->assertSame( '{ "data": "s:3:\"abc\";", "url": "longer" }', $result->value );
		$this->assertSame( 0, $result->failures );
	}

	public function test_uppercase_s_string_is_left_alone_and_counted(): void {
		$original = 'S:3:"old";';
		$this->assertSame( 'old', @unserialize( $original ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- newer PHP deprecates the S format.

		$result = $this->engine( 'old', 'longer' )->replace( $original );

		$this->assertSame( $original, $result->value );
		$this->assertSame( 1, $result->failures );
	}

	public function test_uppercase_s_string_nested_in_array_is_left_alone_and_counted(): void {
		$original = 'a:2:{i:0;S:3:"old";i:1;s:3:"old";}';
		$this->assertSame( array( 'old', 'old' ), @unserialize( $original ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- newer PHP deprecates the S format.

		$result = $this->engine( 'old', 'longer' )->replace( $original );

		$this->assertSame( $original, $result->value );
		$this->assertSame( 1, $result->failures );
	}
}
