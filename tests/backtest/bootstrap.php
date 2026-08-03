<?php
/**
 * Backtest bootstrap: loads SureCart's REAL 4.6.2 model + request code and the
 * plugin's REAL report pipeline, stubbing only WordPress core functions and the
 * single lowest HTTP boundary (RequestService::remoteRequest). Everything in
 * between — query building, URL serialization, auth headers, JSON decoding,
 * model hydration, Collection wrapping — is SureCart's shipped code.
 */

error_reporting( E_ALL & ~E_DEPRECATED );
ini_set( 'error_log', __DIR__ . '/error.log' );

define( 'ABSPATH', __DIR__ . '/' );
define( 'SURECART_API_URL', 'https://api.surecart.com' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

/* ---------------------------------------------------------------- WP stubs */

$GLOBALS['bt_options']    = array();
$GLOBALS['bt_transients'] = array();

class WP_Error {
	public $errors = array();
	public $error_data = array();
	public function __construct( $code = '', $message = '', $data = '' ) {
		if ( $code ) { $this->errors[ $code ][] = $message; if ( $data ) { $this->error_data[ $code ] = $data; } }
	}
	public function get_error_code() { $codes = array_keys( $this->errors ); return $codes[0] ?? ''; }
	public function get_error_message() { $c = $this->get_error_code(); return $this->errors[ $c ][0] ?? ''; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }

function __( $t, $d = null ) { return $t; }
function _n( $s, $p, $n, $d = null ) { return 1 === (int) $n ? $s : $p; }
function esc_html__( $t, $d = null ) { return $t; }
function esc_url_raw( $u ) { return $u; }
function esc_sql( $s ) { return $s; }
function apply_filters( $tag, $value = null ) { return $value; }
function do_action() {}
function add_filter() {}
function add_action() {}
function has_filter() { return false; }
function remove_filter() { return true; }
function wp_list_pluck( $input, $field ) {
	$out = array();
	foreach ( $input as $v ) { $out[] = is_object( $v ) ? $v->$field : $v[ $field ]; }
	return $out;
}
function remove_all_actions() {}
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_parse_args( $args, $defaults = array() ) {
	if ( is_object( $args ) ) { $args = get_object_vars( $args ); }
	return array_merge( $defaults, (array) $args );
}
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function untrailingslashit( $s ) { return rtrim( $s, '/' ); }
function add_query_arg( $args, $url ) {
	// Faithful enough to WP for this purpose: arrays serialize as key[0]=v,
	// which SureCart's own %5B0%5D -> %5B%5D rewrite then converts to key[]=v.
	return $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . http_build_query( $args );
}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? ( $r['body'] ?? '' ) : ''; }
function wp_remote_retrieve_header( $r, $h ) { return is_array( $r ) ? ( $r['headers'][ $h ] ?? '' ) : ''; }

function get_option( $k, $default = false ) { return array_key_exists( $k, $GLOBALS['bt_options'] ) ? $GLOBALS['bt_options'][ $k ] : $default; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['bt_options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['bt_options'][ $k ] ); return true; }
function get_transient( $k ) { return $GLOBALS['bt_transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['bt_transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['bt_transients'][ $k ] ); return true; }

function wp_timezone() { return new DateTimeZone( 'America/New_York' ); }
function current_time( $type, $gmt = 0 ) { return 'mysql' === $type ? gmdate( 'Y-m-d H:i:s' ) : gmdate( $type ); }
function wp_upload_dir() { $d = __DIR__ . '/tmp-uploads'; return array( 'basedir' => $d, 'error' => false ); }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function wp_generate_password( $len = 12, $special = false, $extra = false ) { return substr( bin2hex( random_bytes( 32 ) ), 0, $len ); }
function wp_delete_file( $f ) { if ( file_exists( $f ) ) { unlink( $f ); } }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', (string) $s ) ); }
function sanitize_file_name( $s ) { return preg_replace( '/[^A-Za-z0-9._-]/', '-', (string) $s ); }

/* ------------------------------------------------------------- fake $wpdb */

class BT_Wpdb {
	public $prefix = 'wp_';
	public $insert_id = 0;
	public $tables = array();
	private $auto = array();

	public function insert( $table, $data, $format = null ) {
		$this->auto[ $table ] = ( $this->auto[ $table ] ?? 0 ) + 1;
		$data['id']           = $this->auto[ $table ];
		$this->tables[ $table ][ $data['id'] ] = $data;
		$this->insert_id = $data['id'];
		return 1;
	}
	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$sql = str_replace( array( '%d', '%s' ), array( '%%BTD%%', '%%BTS%%' ), $sql );
		foreach ( $args as $a ) {
			$needle = strpos( $sql, '%%BTD%%' );
			$s      = strpos( $sql, '%%BTS%%' );
			if ( false !== $needle && ( false === $s || $needle < $s ) ) {
				$sql = preg_replace( '/%%BTD%%/', (string) (int) $a, $sql, 1 );
			} else {
				$sql = preg_replace( '/%%BTS%%/', "'" . $a . "'", $sql, 1 );
			}
		}
		return $sql;
	}
	public function get_row( $sql, $output = ARRAY_A ) {
		if ( preg_match( '/FROM (\S+) WHERE id = (\d+)/', $sql, $m ) ) {
			return $this->tables[ $m[1] ][ (int) $m[2] ] ?? null;
		}
		return null;
	}
	public function get_results( $sql, $output = ARRAY_A ) {
		if ( preg_match( '/FROM (\S+) ORDER BY id DESC LIMIT (\d+)/', $sql, $m ) ) {
			$rows = array_values( $this->tables[ $m[1] ] ?? array() );
			usort( $rows, function ( $a, $b ) { return $b['id'] <=> $a['id']; } );
			return array_slice( $rows, 0, (int) $m[2] );
		}
		return array();
	}
	public function get_var( $sql ) { return null; } // "SHOW TABLES" -> Logger falls back to error_log.
	public function update( $table, $data, $where, $f1 = null, $f2 = null ) {
		$id = (int) ( $where['id'] ?? 0 );
		if ( isset( $this->tables[ $table ][ $id ] ) ) {
			$this->tables[ $table ][ $id ] = array_merge( $this->tables[ $table ][ $id ], $data );
			return 1;
		}
		return 0;
	}
	public function delete( $table, $where, $format = null ) {
		$id = (int) ( $where['id'] ?? 0 );
		unset( $this->tables[ $table ][ $id ] );
		return 1;
	}
	public function query( $sql ) { return 0; }
	public function get_charset_collate() { return ''; }
}
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
$GLOBALS['wpdb'] = new BT_Wpdb();

/* --------------------------------------------- real SureCart model classes */

$SC = __DIR__ . '/surecart-src/surecart/app/src';

// PSR-4 autoloader over SureCart's real source tree, so every interface,
// trait and class a model pulls in resolves to the shipped 4.6.2 code.
spl_autoload_register( function ( $class ) use ( $SC ) {
	if ( 0 !== strpos( $class, 'SureCart\\' ) ) {
		return;
	}
	$path = $SC . '/' . str_replace( '\\', '/', substr( $class, strlen( 'SureCart\\' ) ) ) . '.php';
	if ( file_exists( $path ) ) {
		require $path;
	}
} );

require $SC . '/Request/RequestService.php';

/* --------------------------------- capture layer: the one stubbed boundary */

/**
 * Real RequestService with only the wp_remote_request call replaced: records
 * every outbound URL + args, and serves fixture JSON per endpoint.
 */
class BT_CapturingRequestService extends \SureCart\Request\RequestService {
	/** @var array[] every request: [url, decoded_url, method, headers, endpoint, query] */
	public $requests = array();
	/** @var callable fn(string $endpoint, array $query): array|WP_Error envelope */
	public $responder;

	public function remoteRequest( $url, $args = array() ) {
		$decoded = urldecode( $url );
		$query   = array();
		$parts   = parse_url( $decoded );
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
		}
		$endpoint = preg_replace( '#^.*?/v1/#', '', $parts['path'] ?? '' );
		$endpoint = trim( $endpoint, '/' );

		$this->requests[] = array(
			'url'      => $url,
			'decoded'  => $decoded,
			'method'   => $args['method'] ?? 'GET',
			'headers'  => $args['headers'] ?? array(),
			'endpoint' => $endpoint,
			'query'    => $query,
		);

		$envelope = call_user_func( $this->responder, $endpoint, $query );

		if ( is_wp_error( $envelope ) ) {
			return $envelope;
		}

		return array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
			'body'     => json_encode( $envelope ),
		);
	}
}

/** Minimal Pimple-alike for the two services RequestService pulls. */
class BT_Container implements ArrayAccess {
	private $v = array();
	public function offsetExists( $o ): bool { return isset( $this->v[ $o ] ); }
	#[\ReturnTypeWillChange] public function offsetGet( $o ) { return $this->v[ $o ]; }
	public function offsetSet( $o, $val ): void { $this->v[ $o ] = $val; }
	public function offsetUnset( $o ): void { unset( $this->v[ $o ] ); }
}

class BT_FakeCache {
	public function getTransientCache() { return false; }
	public function setCache( $body, $type = '' ) {}
	public function getPreviousCacheUpdatingState() { return ''; }
	public function getPreviousCache() { return false; }
	public function setPreviousCache( $body ) {}
	public function setPreviousCacheUpdatingState( $state ) {}
}

/**
 * Facade double for the global \SureCart class (we deliberately do not load
 * SureCart's own facade/container plumbing). Model::makeRequest() calls
 * \SureCart::request(); RequestService calls \SureCart::plugin()->version().
 */
class SureCart {
	/** @var BT_CapturingRequestService */
	public static $service;

	public static function request( ...$args ) {
		return self::$service->makeRequest( ...$args );
	}
	public static function plugin() {
		return new class() {
			public function version() { return '4.6.2'; }
		};
	}
	public static function notices() {
		return new class() {
			public function showResponseNotice( $notice ) {}
		};
	}
	// Any other facade service a model touches during hydration (e.g.
	// \SureCart::sync()->product()->post()->primeByModelIds()) gets a FLUENT
	// null-object: every method returns the object itself so arbitrary chains
	// resolve, and property reads yield null.
	public static function __callStatic( $name, $args ) {
		return new class() {
			public function __call( $m, $a ) { return $this; }
			public function __get( $k ) { return null; }
		};
	}
}

function bt_new_service( callable $responder ) {
	$container                     = new BT_Container();
	$container['requests.cache']   = function () { return new BT_FakeCache(); };
	$container['requests.errors']  = new class() {
		public function translate( $body, $code ) { return new WP_Error( 'http_' . $code, 'API error ' . $code ); }
	};
	$service            = new BT_CapturingRequestService( $container, 'sc_test_token_bt', '/v1', true );
	$service->responder = $responder;
	SureCart::$service  = $service;
	return $service;
}

/* ----------------------------------------------------- plugin real classes */

$P = dirname( __DIR__, 2 ) . '/src';
require $P . '/Support/Obj.php';
require $P . '/Support/Logger.php';
require $P . '/Db/Schema.php';
require $P . '/Db/ReportRepository.php';
require $P . '/Api/SureCartGateway.php';
require $P . '/Modules/Reports/FulfillmentMatrix.php';
require $P . '/Modules/Reports/CsvWriter.php';
require $P . '/Modules/Reports/ReportStorage.php';
require $P . '/Modules/Reports/ProductIndex.php';
require $P . '/Modules/Reports/ReportRunner.php';
