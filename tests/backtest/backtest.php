<?php
/**
 * End-to-end backtest of the Fulfillment Report pipeline against SureCart's
 * real 4.6.2 model/request code. Only wp_remote_request is stubbed.
 *
 * Every scenario runs the REAL chain:
 *   ReportRepository::create -> ReportRunner::run -> SureCartGateway
 *     -> Order::where()->with()->paginate()   [real SureCart Model]
 *     -> RequestService::makeRequest           [real SureCart code: URL, auth]
 *     -> remoteRequest (stub serves fixture JSON)
 *     -> json_decode -> Model hydration -> Collection
 *     -> normalize_page -> FulfillmentMatrix -> CsvWriter -> disk
 *
 * Results are checked against an ORACLE: an independent re-aggregation of the
 * same fixtures written naively below, so the pipeline and the expectation
 * can't share a bug.
 */

require __DIR__ . '/bootstrap.php';

use BLT\SCE\Api\SureCartGateway;
use BLT\SCE\Db\ReportRepository;
use BLT\SCE\Modules\Reports\ProductIndex;
use BLT\SCE\Modules\Reports\ReportRunner;
use BLT\SCE\Modules\Reports\ReportStorage;
use BLT\SCE\Support\Logger;

/* ------------------------------------------------------------------ checks */

$FAIL = 0;
function check( $label, $got, $want ) {
	global $FAIL;
	$ok = ( $got === $want );
	if ( ! $ok ) { $FAIL++; }
	printf( "%s %-64s got=%s want=%s\n", $ok ? 'PASS' : 'FAIL', $label,
		is_scalar( $got ) ? var_export( $got, true ) : json_encode( $got ),
		is_scalar( $want ) ? var_export( $want, true ) : json_encode( $want ) );
}
function check_true( $label, $cond, $context = '' ) {
	global $FAIL;
	if ( ! $cond ) { $FAIL++; }
	printf( "%s %-64s %s\n", $cond ? 'PASS' : 'FAIL', $label, $cond ? '' : '[' . $context . ']' );
}

/* ---------------------------------------------------------------- fixtures */

$ET = new DateTimeZone( 'America/New_York' );
function et_ts( $s ) { global $ET; return ( new DateTimeImmutable( $s, $ET ) )->getTimestamp(); }

$PRODUCTS = array(
	array( 'id' => 'prod_ls24',  'name' => '2024 Ironman Competitor Long Sleeve Shirt' ),
	array( 'id' => 'prod_pdc24', 'name' => '2024 Pat Daley Classic T-Shirt' ),
	array( 'id' => 'prod_ls25',  'name' => '2025 Ironman Competitor Long Sleeve Shirt' ),
	array( 'id' => 'prod_pdc25', 'name' => '2025 Pat Daley Classic T-Shirt' ),
	array( 'id' => 'prod_ls26',  'name' => 'Ironman Tournament 2026 Long Sleeve Shirt' ),
	array( 'id' => 'prod_pdc26', 'name' => 'Pat Daley Classic 2026 T-Shirt' ),
	array( 'id' => 'prod_cap',   'name' => 'Team Cap' ),
);
usort( $PRODUCTS, function ( $a, $b ) { return strcasecmp( $a['name'], $b['name'] ); } ); // server sorts by name

$SHIRT = 'prod_pdc26';
$CAP   = 'prod_cap';
$SIZES = array( 'S' => 10, 'M' => 20, 'L' => 30, 'XL' => 40, '2XL' => 50 );

function fx_line( $product_id, $product_name, $size, $qty, $fulfilled = 0 ) {
	global $SIZES;
	$has_variant = null !== $size;
	return array(
		'id'                 => 'li_' . substr( md5( $product_id . $size . $qty . mt_rand() ), 0, 10 ),
		'object'             => 'line_item',
		'quantity'           => $qty,
		'fulfilled_quantity' => $fulfilled,
		'variant_options'    => $has_variant ? array( $size ) : array(),
		'variant'            => $has_variant ? array(
			'id'       => 'var_' . $product_id . '_' . strtolower( $size ),
			'object'   => 'variant',
			'option_1' => $size,
			'position' => $SIZES[ $size ],
			'sku'      => strtoupper( $product_id . '-' . $size ),
		) : null,
		'price'              => array(
			'id'      => 'price_' . $product_id,
			'object'  => 'price',
			'name'    => $product_name . ' price',
			'product' => array( 'id' => $product_id, 'object' => 'product', 'name' => $product_name ),
		),
	);
}

function fx_order( $n, $name, $email, $created_at, array $lines ) {
	$all_full = true; $any_full = false;
	foreach ( $lines as $l ) {
		if ( $l['fulfilled_quantity'] < $l['quantity'] ) { $all_full = false; }
		if ( $l['fulfilled_quantity'] > 0 ) { $any_full = true; }
	}
	$fstatus = $all_full && $any_full ? 'fulfilled' : ( $any_full ? 'partially_fulfilled' : 'unfulfilled' );
	return array(
		'id'                 => 'ord_' . $n,
		'object'             => 'order',
		'number'             => (string) ( 1000 + $n ),
		'status'             => 'paid',
		'order_type'         => 'checkout',
		'fulfillment_status' => $fstatus,
		'created_at'         => $created_at,
		'updated_at'         => $created_at,
		'live_mode'          => true,
		'checkout'           => array(
			'id'               => 'ch_' . $n,
			'object'           => 'checkout',
			'name'             => $name,
			'email'            => $email,
			'total_amount'     => 2500 * count( $lines ),
			'customer'         => array( 'id' => 'cust_' . strtolower( $email ), 'object' => 'customer', 'name' => $name, 'email' => $email ),
			'shipping_address' => array(
				'name' => $name, 'line_1' => ( 100 + $n ) . ' Main St', 'line_2' => '',
				'city' => 'Oyster Bay', 'state' => 'NY', 'postal_code' => '11771', 'country' => 'US',
			),
			'line_items'       => array(
				'object'     => 'list',
				'pagination' => array( 'count' => count( $lines ), 'limit' => 100, 'page' => 1 ),
				'data'       => $lines,
			),
		),
	);
}

// --- 103 orders. Page 1 = #1..100 (limit 100), page 2 = #101..103. ---------
$ORDERS      = array();
$size_names  = array_keys( $SIZES );
$product_pool = array( 'prod_pdc26' => 'Pat Daley Classic 2026 T-Shirt', 'prod_ls26' => 'Ironman Tournament 2026 Long Sleeve Shirt', 'prod_pdc25' => '2025 Pat Daley Classic T-Shirt' );
$pool_ids    = array_keys( $product_pool );

for ( $i = 1; $i <= 100; $i++ ) {
	$name  = "Customer {$i}";
	$email = "customer{$i}@example.com";
	if ( 1 === $i ) { $name = 'Alice Anderson'; $email = 'alice@example.com'; }

	$created = et_ts( '2026-07-05 12:00' ) + $i * 3600;
	if ( 99 === $i )  { $created = et_ts( '2026-07-01 00:10' ); } // inclusive lower boundary
	if ( 100 === $i ) { $created = et_ts( '2026-08-03 23:55' ); } // inclusive upper boundary

	$pid   = $pool_ids[ $i % 3 ];
	$size  = $size_names[ $i % 5 ];
	$qty   = ( $i % 3 ) + 1;
	$lines = array( fx_line( $pid, $product_pool[ $pid ], $size, $qty ) );

	if ( 7 === $i )  { $lines = array( fx_line( $SHIRT, $product_pool[ $SHIRT ], 'L', 3, 1 ) ); }  // partially fulfilled
	if ( 8 === $i )  { $lines = array( fx_line( $SHIRT, $product_pool[ $SHIRT ], 'M', 2, 2 ) ); }  // fully fulfilled
	if ( 0 === $i % 10 ) { $lines[] = fx_line( $CAP, 'Team Cap', null, 1 ); }                      // cap every 10th

	$ORDERS[] = fx_order( $i, $name, $email, $created, $lines );
}
// Page 2: repeat customer (case-differing email), out-of-window, zero-qty.
$ORDERS[] = fx_order( 101, 'Alice Anderson', 'ALICE@example.com', et_ts( '2026-07-20 09:00' ), array( fx_line( $SHIRT, $product_pool[ $SHIRT ], 'XL', 1 ) ) );
$ORDERS[] = fx_order( 102, 'June Buyer', 'june@example.com', et_ts( '2026-06-15 12:00' ), array( fx_line( $SHIRT, $product_pool[ $SHIRT ], 'L', 5 ) ) );
$ORDERS[] = fx_order( 103, 'Zero Qty', 'zero@example.com', et_ts( '2026-07-22 12:00' ), array( fx_line( $SHIRT, $product_pool[ $SHIRT ], 'S', 0 ) ) );

/* ------------------------------------------------- fixture "server" logic */

function bt_server_filter( array $orders, array $q ) {
	$out = array();
	foreach ( $orders as $o ) {
		if ( ! empty( $q['status'] ) && ! in_array( $o['status'], (array) $q['status'], true ) ) { continue; }
		if ( ! empty( $q['fulfillment_status'] ) && ! in_array( $o['fulfillment_status'], (array) $q['fulfillment_status'], true ) ) { continue; }
		if ( ! empty( $q['product_ids'] ) ) {
			$hit = false;
			foreach ( $o['checkout']['line_items']['data'] as $l ) {
				if ( in_array( $l['price']['product']['id'], (array) $q['product_ids'], true ) ) { $hit = true; break; }
			}
			if ( ! $hit ) { continue; }
		}
		$out[] = $o;
	}
	return $out;
}

function bt_orders_responder( array $orders ) {
	return function ( $endpoint, $q ) use ( $orders ) {
		global $PRODUCTS;
		if ( 'products' === $endpoint ) {
			$data = $PRODUCTS; // pre-sorted by name; archived filter is a no-op (none archived)
			$lim  = (int) ( $q['limit'] ?? 20 ); $page = (int) ( $q['page'] ?? 1 );
			return array(
				'object'     => 'list',
				'pagination' => array( 'count' => count( $data ), 'limit' => $lim, 'page' => $page ),
				'data'       => array_slice( $data, ( $page - 1 ) * $lim, $lim ),
			);
		}
		// orders: expansion contract — no expand[]=checkout means IDs only.
		$expanded = ! empty( $q['expand'] ) && in_array( 'checkout', (array) $q['expand'], true );
		$matched  = bt_server_filter( $orders, $q );
		$lim      = (int) ( $q['limit'] ?? 20 ); $page = (int) ( $q['page'] ?? 1 );
		$slice    = array_slice( $matched, ( $page - 1 ) * $lim, $lim );
		if ( ! $expanded ) {
			$slice = array_map( function ( $o ) { $o['checkout'] = $o['checkout']['id']; return $o; }, $slice );
		}
		return array(
			'object'     => 'list',
			'pagination' => array( 'count' => count( $matched ), 'limit' => $lim, 'page' => $page ),
			'data'       => $slice,
		);
	};
}

/* ---------------------------------------------------------------- oracle */

function bt_oracle( array $orders, array $opts ) {
	global $ET;
	$start = ( new DateTimeImmutable( $opts['start'] . ' 00:00:00', $ET ) )->getTimestamp();
	$end   = ( new DateTimeImmutable( $opts['end'] . ' 23:59:59', $ET ) )->getTimestamp();

	$server = bt_server_filter( $orders, array(
		'status'             => array( 'paid' ),
		'fulfillment_status' => $opts['fulfillment_status'] ?? array(),
		'product_ids'        => $opts['product_ids'] ?? array(),
	) );

	$cols = array(); $rows = array(); $matched = 0; $grand = 0;
	foreach ( $server as $o ) {
		if ( $o['created_at'] < $start || $o['created_at'] > $end ) { continue; }
		$key = strtolower( $o['checkout']['email'] );
		$contributed = false;
		foreach ( $o['checkout']['line_items']['data'] as $l ) {
			$pid = $l['price']['product']['id'];
			if ( ! empty( $opts['product_ids'] ) && ! in_array( $pid, $opts['product_ids'], true ) ) { continue; }
			$qty = ! empty( $opts['remaining_only'] ) ? max( 0, $l['quantity'] - $l['fulfilled_quantity'] ) : $l['quantity'];
			if ( $qty <= 0 ) { continue; }
			$label = $l['variant']
				? $l['price']['product']['name'] . ' — ' . $l['variant']['option_1']
				: $l['price']['product']['name'];
			$cols[ $label ]         = ( $cols[ $label ] ?? 0 ) + $qty;
			$rows[ $key ][ $label ] = ( $rows[ $key ][ $label ] ?? 0 ) + $qty;
			$grand      += $qty;
			$contributed = true;
		}
		if ( $contributed ) { $matched++; }
	}
	return array(
		'scanned'   => count( $server ),
		'matched'   => $matched,
		'customers' => count( $rows ),
		'columns'   => $cols,
		'grand'     => $grand,
		'rows'      => $rows,
	);
}

/* ------------------------------------------------------------- test rig */

function bt_read_csv( $path ) {
	$raw   = file_get_contents( $path );
	$raw   = preg_replace( '/^\xEF\xBB\xBF/', '', $raw );
	$lines = array_values( array_filter( explode( "\n", $raw ), 'strlen' ) );
	return array_map( 'str_getcsv', $lines );
}

function bt_run_report( array $params, $responder ) {
	$service = bt_new_service( $responder );

	$logger  = new Logger();
	$repo    = new ReportRepository();
	$storage = new ReportStorage();
	$index   = new ProductIndex();
	$runner  = new ReportRunner( new SureCartGateway(), $repo, $storage, $index, $logger );

	$id = $repo->create( ReportRepository::TYPE_FULFILLMENT, $params, 1 );
	$runner->run( $id );

	$row = $repo->find( $id );
	$csv = null;
	if ( $row && ! empty( $row['filename'] ) ) {
		$path = $storage->path( $row['filename'] );
		if ( ! is_wp_error( $path ) && is_readable( $path ) ) {
			$csv = bt_read_csv( $path );
		}
	}
	return array( $row, $csv, $service );
}

function bt_totals_from_csv( array $csv ) {
	$header = $csv[0];
	$totals = $csv[ count( $csv ) - 1 ];
	$map    = array();
	foreach ( $header as $i => $h ) { $map[ $h ] = isset( $totals[ $i ] ) ? $totals[ $i ] : ''; }
	return array( $header, $map, $totals );
}

$BASE_PARAMS = array(
	'start_date'           => '2026-07-01',
	'end_date'             => '2026-08-03',
	'statuses'             => array( 'paid' ),
	'product_ids'          => array(),
	'fulfillment'          => 'any',
	'fulfillment_statuses' => array(),
	'remaining_only'       => false,
	'include_address'      => false,
);

/* =================================================================== A === */
echo "=== A. Full run: paid, all products, ordered basis, 2 pages ===\n";

list( $row, $csv, $service ) = bt_run_report( $BASE_PARAMS, bt_orders_responder( $ORDERS ) );
$oracle = bt_oracle( $ORDERS, array( 'start' => '2026-07-01', 'end' => '2026-08-03' ) );

check( 'report status', $row['status'], ReportRepository::STATUS_COMPLETE );
check( 'orders scanned (all pages walked)', (int) $row['orders_scanned'], $oracle['scanned'] );
check( 'orders matched', (int) $row['orders_matched'], $oracle['matched'] );
check( 'customer rows', (int) $row['row_count'], $oracle['customers'] );
check( 'variant columns', (int) $row['column_count'], count( $oracle['columns'] ) );
check( 'total items', (int) $row['item_count'], $oracle['grand'] );
check( 'not truncated', (int) $row['truncated'], 0 );
check_true( 'no diagnostic note on a populated run', empty( $row['last_error'] ), (string) $row['last_error'] );

// The wire: what did we actually ask SureCart for?
$order_reqs = array_values( array_filter( $service->requests, function ( $r ) { return 'orders' === $r['endpoint']; } ) );
check( 'order list requests made', count( $order_reqs ), 2 );
check( 'page sequence', array_map( function ( $r ) { return (int) $r['query']['page']; }, $order_reqs ), array( 1, 2 ) );
check( 'per-page limit', (int) $order_reqs[0]['query']['limit'], 100 );
check( 'status filter on the wire', $order_reqs[0]['query']['status'], array( 'paid' ) );
sort( $order_reqs[0]['query']['expand'] );
$want_expand = \BLT\SCE\Api\SureCartGateway::REPORT_ORDER_EXPANDS;
sort( $want_expand );
check( 'all 7 expands requested', $order_reqs[0]['query']['expand'], $want_expand );
check_true( 'documented bracket form on the wire', strpos( $order_reqs[0]['url'], 'status%5B%5D=paid' ) !== false, $order_reqs[0]['url'] );
check_true( 'no invented date param on the wire', strpos( $order_reqs[0]['decoded'], 'created' ) === false, $order_reqs[0]['decoded'] );
check( 'bearer auth header sent', $order_reqs[0]['headers']['Authorization'], 'Bearer sc_test_token_bt' );

// The CSV against the oracle, column by column.
list( $header, $totals_map ) = bt_totals_from_csv( $csv );
$csv_variant_cols = array_slice( $header, 4, -1 );
check( 'CSV column count matches oracle', count( $csv_variant_cols ), count( $oracle['columns'] ) );
$col_mismatch = array();
foreach ( $oracle['columns'] as $label => $sum ) {
	if ( ! isset( $totals_map[ $label ] ) || (int) $totals_map[ $label ] !== $sum ) {
		$col_mismatch[ $label ] = array( 'want' => $sum, 'got' => $totals_map[ $label ] ?? '(column missing)' );
	}
}
check( 'every per-variant total matches oracle', $col_mismatch, array() );
check( 'grand total matches oracle', (int) $totals_map[ end( $header ) ], $oracle['grand'] );
check( 'data rows = customers', count( $csv ) - 2, $oracle['customers'] );

// Alice consolidated across two orders with case-differing emails.
$alice = null;
foreach ( $csv as $r ) { if ( isset( $r[1] ) && 'alice@example.com' === strtolower( $r[1] ) ) { $alice = $r; break; } }
check_true( 'Alice present once', null !== $alice );
check( 'Alice order numbers', $alice[2], '1001, 1101' );
check( 'Alice order count', $alice[3], '2' );

// Out-of-window order scanned but not counted anywhere.
$june_in_csv = false;
foreach ( $csv as $r ) { if ( in_array( 'june@example.com', $r, true ) ) { $june_in_csv = true; } }
check( 'out-of-window order excluded from CSV', $june_in_csv, false );

/* =================================================================== B === */
echo "\n=== B. Product-filtered run (Pat Daley Classic 2026 only) ===\n";

$paramsB = array_merge( $BASE_PARAMS, array( 'product_ids' => array( $SHIRT ) ) );
list( $rowB, $csvB, $serviceB ) = bt_run_report( $paramsB, bt_orders_responder( $ORDERS ) );
$oracleB = bt_oracle( $ORDERS, array( 'start' => '2026-07-01', 'end' => '2026-08-03', 'product_ids' => array( $SHIRT ) ) );

$reqB = array_values( array_filter( $serviceB->requests, function ( $r ) { return 'orders' === $r['endpoint']; } ) );
check( 'product_ids[] on the wire', $reqB[0]['query']['product_ids'], array( $SHIRT ) );
check( 'B: scanned = server-filtered set', (int) $rowB['orders_scanned'], $oracleB['scanned'] );
check( 'B: total items', (int) $rowB['item_count'], $oracleB['grand'] );
check( 'B: columns', (int) $rowB['column_count'], count( $oracleB['columns'] ) );

list( $headerB, $totalsB ) = bt_totals_from_csv( $csvB );
$cap_col = false;
foreach ( $headerB as $h ) { if ( false !== strpos( $h, 'Team Cap' ) ) { $cap_col = true; } }
check( 'cap bought in same orders NOT in filtered CSV', $cap_col, false );
$mismatchB = array();
foreach ( $oracleB['columns'] as $label => $sum ) {
	if ( (int) ( $totalsB[ $label ] ?? -1 ) !== $sum ) { $mismatchB[ $label ] = $totalsB[ $label ] ?? null; }
}
check( 'B: per-variant totals match oracle', $mismatchB, array() );

/* =================================================================== C === */
echo "\n=== C. Outstanding run (unfulfilled + partial, remaining units) ===\n";

$paramsC = array_merge( $BASE_PARAMS, array(
	'fulfillment'          => 'outstanding',
	'fulfillment_statuses' => array( 'unfulfilled', 'partially_fulfilled' ),
	'remaining_only'       => true,
) );
list( $rowC, $csvC, $serviceC ) = bt_run_report( $paramsC, bt_orders_responder( $ORDERS ) );
$oracleC = bt_oracle( $ORDERS, array(
	'start' => '2026-07-01', 'end' => '2026-08-03',
	'fulfillment_status' => array( 'unfulfilled', 'partially_fulfilled' ),
	'remaining_only'     => true,
) );

$reqC = array_values( array_filter( $serviceC->requests, function ( $r ) { return 'orders' === $r['endpoint']; } ) );
sort( $reqC[0]['query']['fulfillment_status'] );
check( 'fulfillment_status[] on the wire', $reqC[0]['query']['fulfillment_status'], array( 'partially_fulfilled', 'unfulfilled' ) );
check( 'C: fully-fulfilled order not scanned (server filter)', (int) $rowC['orders_scanned'], $oracleC['scanned'] );
check( 'C: total outstanding units', (int) $rowC['item_count'], $oracleC['grand'] );

list( $headerC, $totalsC ) = bt_totals_from_csv( $csvC );
check( 'C: basis labeled in header', end( $headerC ), 'Total Items Outstanding' );
// Order #7: 3 ordered, 1 shipped -> its L column contributes 2, not 3.
$pdcL = 'Pat Daley Classic 2026 T-Shirt — L';
check( 'C: partial order contributes remainder to its column', (int) $totalsC[ $pdcL ], $oracleC['columns'][ $pdcL ] );

/* =================================================================== D === */
echo "\n=== D. Product picker refresh against real Product model ===\n";

delete_option( ProductIndex::OPTION_PRODUCTS );
delete_option( ProductIndex::OPTION_REFRESHED );
$serviceD = bt_new_service( bt_orders_responder( $ORDERS ) );
$runnerD  = new ReportRunner( new SureCartGateway(), new ReportRepository(), new ReportStorage(), new ProductIndex(), new Logger() );
$runnerD->refresh_products();

$reqD = array_values( array_filter( $serviceD->requests, function ( $r ) { return 'products' === $r['endpoint']; } ) );
check( 'one products page requested', count( $reqD ), 1 );
check( 'archived=0 on the wire (bool -> int)', $reqD[0]['query']['archived'], '0' );
check( 'sort=name on the wire', $reqD[0]['query']['sort'], 'name' );
check( 'products per-page limit', (int) $reqD[0]['query']['limit'], 100 );

$indexD = new ProductIndex();
check( 'picker cached all products', count( $indexD->all() ), count( $PRODUCTS ) );
check( 'picker names in server order', array_column( $indexD->all(), 'name' ), array_column( $PRODUCTS, 'name' ) );
check( 'picker no longer cold', $indexD->is_cold(), false );

/* =================================================================== E === */
echo "\n=== E. Empty store: run completes, explains itself ===\n";

$empty_responder = function ( $endpoint, $q ) {
	return array( 'object' => 'list', 'pagination' => array( 'count' => 0, 'limit' => (int) ( $q['limit'] ?? 20 ), 'page' => (int) ( $q['page'] ?? 1 ) ), 'data' => array() );
};
list( $rowE, $csvE ) = bt_run_report( $BASE_PARAMS, $empty_responder );
check( 'E: still completes', $rowE['status'], ReportRepository::STATUS_COMPLETE );
check( 'E: zero items', (int) $rowE['item_count'], 0 );
check_true( 'E: note says even-unfiltered probe was empty', false !== strpos( (string) $rowE['last_error'], 'even with no filters' ), (string) $rowE['last_error'] );
check_true( 'E: note names the runtime shape', false !== strpos( (string) $rowE['last_error'], 'Collection' ), (string) $rowE['last_error'] );

/* =================================================================== F === */
echo "\n=== F. Filters exclude everything: probe distinguishes it ===\n";

$filters_exclude_responder = function ( $endpoint, $q ) use ( $ORDERS ) {
	if ( 'orders' === $endpoint && empty( $q['status'] ) ) {
		// unfiltered probe: the store clearly has orders
		return array( 'object' => 'list', 'pagination' => array( 'count' => count( $ORDERS ), 'limit' => 1, 'page' => 1 ), 'data' => array_slice( array_map( function ( $o ) { $o['checkout'] = $o['checkout']['id']; return $o; }, $ORDERS ), 0, 1 ) );
	}
	return array( 'object' => 'list', 'pagination' => array( 'count' => 0, 'limit' => (int) ( $q['limit'] ?? 20 ), 'page' => 1 ), 'data' => array() );
};
list( $rowF ) = bt_run_report( $BASE_PARAMS, $filters_exclude_responder );
check_true( 'F: note blames the filters', false !== strpos( (string) $rowF['last_error'], 'filters excluded everything' ), (string) $rowF['last_error'] );
check_true( 'F: note names the filters applied', false !== strpos( (string) $rowF['last_error'], 'status=paid' ), (string) $rowF['last_error'] );

/* =================================================================== G === */
echo "\n=== G. Regression guard: the 0.3.0 isset() bug against real Collection ===\n";

$service_g = bt_new_service( bt_orders_responder( $ORDERS ) );
$collection = \SureCart\Models\Order::where( array( 'status' => array( 'paid' ) ) )
	->with( \BLT\SCE\Api\SureCartGateway::REPORT_ORDER_EXPANDS )
	->paginate( array( 'per_page' => 100, 'page' => 1 ) );
check( 'paginate returns the real Collection class', get_class( $collection ), 'SureCart\Models\Collection' );
check( 'isset() on Collection->data lies (the shipped bug)', isset( $collection->data ), false );
check( 'the data is really there', count( $collection->data ), 100 );
check( 'first record is a hydrated Order model', get_class( $collection->data[0] ), 'SureCart\Models\Order' );
check( 'checkout hydrated as Checkout model', get_class( $collection->data[0]->checkout ), 'SureCart\Models\Checkout' );
// The nested envelope is NOT a Collection: setCollection() hydrates data[]
// into LineItem models but keeps the raw stdClass wrapper. So the shipped
// 0.3.0 bug was exactly one class deep — the top-level Collection.
check( 'line_items stays a hydrated stdClass envelope', get_class( $collection->data[0]->checkout->line_items ), 'stdClass' );
check( 'line items hydrated as LineItem models', get_class( $collection->data[0]->checkout->line_items->data[0] ), 'SureCart\Models\LineItem' );
check( 'variant hydrated as Variant model', get_class( $collection->data[0]->checkout->line_items->data[0]->variant ), 'SureCart\Models\Variant' );
check( 'variant_options survives hydration as a plain array', $collection->data[0]->checkout->line_items->data[0]->variant_options, array( $size_names[ 1 % 5 ] ) );

/* =================================================================== H === */
echo "\n=== H. Shippo path: extract_shipping_context on a real hydrated Order ===\n";

// A zero-quantity line must survive as zero — this quantity feeds SureCart's
// fulfillment payload via LabelPurchaser, and inflating it to 1 would try to
// fulfill a unit that was never bought. Default to 1 only when absent.
$h_fixture = fx_order( 900, 'Zero Case', 'zerocase@example.com', et_ts( '2026-07-10 10:00' ), array(
	fx_line( $SHIRT, $product_pool[ $SHIRT ], 'L', 0 ),
	fx_line( $SHIRT, $product_pool[ $SHIRT ], 'M', 2 ),
) );
unset( $h_fixture['checkout']['line_items']['data'][1]['quantity'] ); // absent entirely -> default 1

$h_order   = new \SureCart\Models\Order( json_decode( json_encode( $h_fixture ) ) );
$h_context = ( new SureCartGateway() )->extract_shipping_context( $h_order );

check_true( 'H: context extracted from real model', is_array( $h_context ), is_wp_error( $h_context ) ? $h_context->get_error_message() : '' );
check( 'H: real zero quantity preserved', $h_context['line_items'][0]['quantity'], 0 );
check( 'H: absent quantity defaults to 1', $h_context['line_items'][1]['quantity'], 1 );
check( 'H: shipping address mapped to Shippo field names', $h_context['shipping_address']['zip'], '11771' );
check( 'H: order total read in cents', $h_context['order_total_cents'], 5000 );

/* ==================================================================== ∎ === */
echo "\n" . ( 0 === $FAIL ? 'BACKTEST PASSED — every scenario, wire assertion and oracle comparison.' : "BACKTEST FAILED — {$FAIL} check(s)." ) . "\n";
exit( 0 === $FAIL ? 0 : 1 );
