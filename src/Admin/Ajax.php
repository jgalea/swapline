<?php

namespace Swapline\Admin;

use Swapline\Plugin;
use Swapline\Run\Config;
use Swapline\Run\History;
use Swapline\Run\Profiles;
use Swapline\Run\Runner;

defined( 'ABSPATH' ) || exit;

final class Ajax {

	public const ACTIONS = array( 'start', 'step', 'cancel', 'changes', 'profile_save', 'profile_delete' );

	private const CHANGES_PER_PAGE = 20;

	public static function register(): void {
		foreach ( self::ACTIONS as $action ) {
			add_action( 'wp_ajax_swapline_' . $action, array( self::class, 'handle_' . $action ) );
		}
	}

	private static function guard( string $action ): void {
		if ( ! check_ajax_referer( 'swapline_' . $action, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session has expired. Reload the page and try again.', 'swapline' ) ), 403 );
		}
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'swapline' ) ), 403 );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function config_input(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$raw = isset( $_POST['config'] ) ? (string) wp_unslash( $_POST['config'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decoded and validated field by field in Config::from_input().
		// phpcs:enable
		$input = json_decode( $raw, true );
		return is_array( $input ) ? $input : array();
	}

	private static function post_string( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	public static function handle_start(): void {
		self::guard( 'start' );

		$config = Config::from_input( self::config_input() );
		if ( is_wp_error( $config ) ) {
			wp_send_json_error( array( 'message' => $config->get_error_message() ), 400 );
		}

		if ( array() !== Plugin::protected_tables( $config['tables'] ) && ! current_user_can( Plugin::database_capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'Only an administrator can change the users tables.', 'swapline' ) ), 403 );
		}

		$job = Runner::start( $config, 'admin' );
		Runner::save( $job );

		wp_send_json_success( Runner::progress( $job ) );
	}

	public static function handle_step(): void {
		self::guard( 'step' );

		$job = Runner::current();
		if ( null === $job || self::post_string( 'job' ) !== $job['id'] ) {
			wp_send_json_error( array( 'message' => __( 'This run is no longer active. Another run may have replaced it.', 'swapline' ) ), 409 );
		}

		$job = Runner::step( $job );

		if ( $job['done'] ) {
			Runner::clear();
			wp_send_json_success(
				array(
					'progress' => Runner::progress( $job ),
					'run'      => Runner::finish( $job ),
				)
			);
		}

		Runner::save( $job );
		wp_send_json_success( array( 'progress' => Runner::progress( $job ) ) );
	}

	public static function handle_cancel(): void {
		self::guard( 'cancel' );

		$job = Runner::current();
		if ( null === $job || self::post_string( 'job' ) !== $job['id'] ) {
			wp_send_json_success( array( 'run' => null ) );
		}

		Runner::clear();
		wp_send_json_success( array( 'run' => Runner::finish( $job, true ) ) );
	}

	public static function handle_changes(): void {
		self::guard( 'changes' );

		$run_id = self::post_string( 'run' );
		$run    = History::find( $run_id );
		if ( null === $run ) {
			wp_send_json_error( array( 'message' => __( 'That run is no longer in the history.', 'swapline' ) ), 404 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$page  = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
		$table = self::post_string( 'table' );
		if ( '' !== $table && ! isset( $run['tables'][ $table ] ) ) {
			$table = '';
		}

		$changes = History::changes( $run_id, $page, self::CHANGES_PER_PAGE, $table );
		$items   = array();
		foreach ( $changes['items'] as $item ) {
			$items[] = array(
				'table'        => $item['table_name'],
				'row'          => self::row_label( (string) $item['row_key'] ),
				'column'       => $item['column_name'],
				'replacements' => (int) $item['replacements'],
				'truncated'    => (bool) $item['truncated'],
				'diff'         => Diff::render( (string) $item['old_value'], (string) $item['new_value'] ),
			);
		}

		wp_send_json_success(
			array(
				'run'   => $run,
				'page'  => $page,
				'pages' => (int) max( 1, ceil( $changes['total'] / self::CHANGES_PER_PAGE ) ),
				'total' => $changes['total'],
				'table' => $table,
				'items' => $items,
			)
		);
	}

	public static function handle_profile_save(): void {
		self::guard( 'profile_save' );

		$name = self::post_string( 'name' );
		if ( '' === $name || mb_strlen( $name ) > 100 ) {
			wp_send_json_error( array( 'message' => __( 'Give the profile a name of up to 100 characters.', 'swapline' ) ), 400 );
		}

		$config = Config::from_input( self::config_input() );
		if ( is_wp_error( $config ) ) {
			wp_send_json_error( array( 'message' => $config->get_error_message() ), 400 );
		}

		Profiles::save( $name, $config );
		wp_send_json_success( array( 'profiles' => (object) Profiles::all() ) );
	}

	public static function handle_profile_delete(): void {
		self::guard( 'profile_delete' );

		Profiles::delete( self::post_string( 'name' ) );
		wp_send_json_success( array( 'profiles' => (object) Profiles::all() ) );
	}

	private static function row_label( string $row_key ): string {
		$key = json_decode( $row_key, true );
		if ( ! is_array( $key ) ) {
			return '';
		}
		$parts = array();
		foreach ( $key as $column => $value ) {
			$value   = null === $value ? 'NULL' : (string) $value;
			$parts[] = $column . ' = ' . ( mb_strlen( $value ) > 40 ? mb_substr( $value, 0, 37 ) . '…' : $value );
		}
		return implode( ', ', $parts );
	}
}
