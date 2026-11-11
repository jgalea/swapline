<?php

namespace Swapline\Tests\Unit;

use Swapline\Run\Config;
use Swapline\Run\Runner;
use Swapline\Tests\Support\DbTestCase;

final class ColumnScopeTest extends DbTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->insert(
			'wp_posts',
			array(
				'ID'           => 1,
				'post_title'   => 'old.test title',
				'post_content' => 'old.test content',
			)
		);
	}

	/**
	 * @param array<string, mixed> $extra
	 * @return array<string, string|null>
	 */
	private function run_with( array $extra ): array {
		$config = Config::from_input(
			array_merge(
				array(
					'search'  => 'old.test',
					'replace' => 'new.test',
					'tables'  => array( 'wp_posts' ),
					'dry_run' => false,
				),
				$extra
			)
		);
		$this->assertIsArray( $config, is_wp_error( $config ) ? $config->get_error_message() : '' );
		$job = Runner::start( $config, 'cli' );
		while ( ! $job['done'] ) {
			$job = Runner::step( $job );
		}
		return $this->rows( 'wp_posts' )[0];
	}

	public function test_only_the_chosen_columns_are_changed(): void {
		$row = $this->run_with( array( 'columns' => 'post_content' ) );

		$this->assertSame( 'new.test content', $row['post_content'] );
		$this->assertSame( 'old.test title', $row['post_title'] );
	}

	public function test_skipped_columns_are_left_alone(): void {
		$row = $this->run_with( array( 'skip_columns' => array( 'Post_Content' ) ) );

		$this->assertSame( 'new.test title', $row['post_title'] );
		$this->assertSame( 'old.test content', $row['post_content'] );
	}

	public function test_without_a_scope_every_text_column_is_searched(): void {
		$row = $this->run_with( array() );

		$this->assertSame( 'new.test title', $row['post_title'] );
		$this->assertSame( 'new.test content', $row['post_content'] );
	}

	public function test_column_names_are_cleaned_and_bad_ones_refused(): void {
		$config = Config::from_input(
			array(
				'search'  => 'a',
				'tables'  => array( 'wp_posts' ),
				'columns' => ' post_content , meta_value,post_content, ',
			)
		);
		$this->assertSame( array( 'post_content', 'meta_value' ), $config['columns'] );

		$bad = Config::from_input(
			array(
				'search'  => 'a',
				'tables'  => array( 'wp_posts' ),
				'columns' => 'post_content; DROP TABLE x',
			)
		);
		$this->assertInstanceOf( \WP_Error::class, $bad );
		$this->assertSame( 'swapline_bad_column', $bad->get_error_code() );
	}
}
