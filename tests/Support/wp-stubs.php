<?php
// phpcs:disable -- minimal stand-ins for the WordPress functions the plugin calls under test.

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '' ) {}
		public function get_error_code(): string {
			return $this->code;
		}
		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! class_exists( 'Swapline_Test_Json_Exit' ) ) {
	class Swapline_Test_Json_Exit extends Exception {
		public function __construct( public bool $success, public $payload, public int $status ) {
			parent::__construct( $success ? 'success' : 'error' );
		}
	}
}

function swapline_test_reset(): void {
	$GLOBALS['swapline_test'] = array(
		'options' => array(),
		'filters' => array(),
		'actions' => array(),
		'user'    => 1,
		'caps'    => array(),
		'multi'   => false,
	);
}

swapline_test_reset();

function __( $text ) {
	return $text;
}
function esc_html__( $text ) {
	return $text;
}
function _n( $single, $plural, $number ) {
	return 1 === (int) $number ? $single : $plural;
}
function number_format_i18n( $number ) {
	return number_format( (float) $number );
}
function apply_filters( $tag, $value, ...$args ) {
	$filter = $GLOBALS['swapline_test']['filters'][ $tag ] ?? null;
	return null === $filter ? $value : $filter( $value, ...$args );
}
function do_action( $tag, ...$args ) {
	$GLOBALS['swapline_test']['actions'][] = array( $tag, $args );
}
function is_multisite() {
	return $GLOBALS['swapline_test']['multi'];
}
function get_option( $key, $fallback = false ) {
	return $GLOBALS['swapline_test']['options'][ $key ] ?? $fallback;
}
function update_option( $key, $value ) {
	$GLOBALS['swapline_test']['options'][ $key ] = $value;
	return true;
}
function delete_option( $key ) {
	unset( $GLOBALS['swapline_test']['options'][ $key ] );
	return true;
}
function get_site_option( $key, $fallback = false ) {
	return get_option( $key, $fallback );
}
function update_site_option( $key, $value ) {
	return update_option( $key, $value );
}
function delete_site_option( $key ) {
	return delete_option( $key );
}
function wp_generate_uuid4() {
	return sprintf( '%08x-%04x-4%03x-a%03x-%012x', random_int( 0, 0xffffffff ), random_int( 0, 0xffff ), random_int( 0, 0xfff ), random_int( 0, 0xfff ), random_int( 0, 0xffffffffffff ) );
}
function get_current_user_id() {
	return $GLOBALS['swapline_test']['user'];
}
function get_userdata( $id ) {
	return (object) array( 'user_login' => 'admin' . $id );
}
function current_user_can( $cap ) {
	return in_array( $cap, $GLOBALS['swapline_test']['caps'], true );
}
function check_ajax_referer() {
	return true;
}
function wp_send_json_error( $data = null, $status = 200 ) {
	throw new Swapline_Test_Json_Exit( false, $data, (int) $status );
}
function wp_send_json_success( $data = null, $status = 200 ) {
	throw new Swapline_Test_Json_Exit( true, $data, (int) $status );
}
function wp_unslash( $value ) {
	return $value;
}
function sanitize_text_field( $value ) {
	return trim( (string) $value );
}
function rest_sanitize_boolean( $value ) {
	return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
}
function absint( $value ) {
	return abs( (int) $value );
}
function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}
function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}
function wp_cache_flush() {
	return true;
}
function untrailingslashit( $value ) {
	return rtrim( $value, '/\\' );
}
function wp_die( $message = '', $title = '', $args = array() ) {
	throw new Swapline_Test_Json_Exit( false, $message, (int) ( $args['response'] ?? 500 ) );
}
function esc_html( $text ) {
	return $text;
}
function wp_upload_dir( $time = null, $create = true ) {
	$base = $GLOBALS['swapline_test']['uploads'] ?? sys_get_temp_dir() . '/swapline-test-uploads';
	return array(
		'basedir' => $base,
		'error'   => false,
	);
}
function wp_mkdir_p( $dir ) {
	return is_dir( $dir ) || mkdir( $dir, 0777, true );
}
function wp_delete_file_from_directory( $file, $dir ) {
	return unlink( $file );
}
function wp_delete_file( $file ) {
	if ( is_file( $file ) ) {
		unlink( $file );
	}
}
function wp_max_upload_size() {
	return 1048576;
}
function add_query_arg( $args, $url ) {
	return $url . '?' . http_build_query( $args );
}
function admin_url( $path = '' ) {
	return 'http://example.test/wp-admin/' . $path;
}
function wp_create_nonce( $action ) {
	return 'nonce-' . $action;
}
