<?php
/**
 * The Action Scheduler job that actually builds a report.
 *
 * Every SureCart call in this plugin happens inside a job (rule 1), and a
 * fulfillment report is the strongest case for it: `GET /v1/orders` has no
 * date filter and no sort parameter (discovery §H), so covering a date
 * range means walking every page of the server-side-filtered set. That is
 * minutes of blocking HTTP on a busy store — impossible in an admin
 * request, routine in a background job.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Modules\Reports;

use BLT\SCE\Api\SureCartGateway;
use BLT\SCE\Db\ReportRepository;
use BLT\SCE\Support\Logger;
use BLT\SCE\Support\Obj;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ReportRunner
 */
final class ReportRunner {

	const HOOK_RUN              = 'blt_sce_reports_run';
	const HOOK_REFRESH_PRODUCTS = 'blt_sce_reports_refresh_products';

	/**
	 * Hard ceiling on pages walked in a single run, at 100 orders per page.
	 * A backstop against an unbounded loop, not a business rule — when it's
	 * hit the report is still delivered, but it's flagged as truncated in
	 * the reports list and logged, never silently trimmed.
	 */
	const MAX_PAGES = 500;

	/**
	 * SureCart gateway.
	 *
	 * @var SureCartGateway
	 */
	private $gateway;

	/**
	 * Report index repository.
	 *
	 * @var ReportRepository
	 */
	private $repository;

	/**
	 * File storage.
	 *
	 * @var ReportStorage
	 */
	private $storage;

	/**
	 * Product picker cache.
	 *
	 * @var ProductIndex
	 */
	private $products;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param SureCartGateway  $gateway    SureCart gateway.
	 * @param ReportRepository $repository Report index repository.
	 * @param ReportStorage    $storage    File storage.
	 * @param ProductIndex     $products   Product picker cache.
	 * @param Logger           $logger     Shared logger.
	 */
	public function __construct( SureCartGateway $gateway, ReportRepository $repository, ReportStorage $storage, ProductIndex $products, Logger $logger ) {
		$this->gateway    = $gateway;
		$this->repository = $repository;
		$this->storage    = $storage;
		$this->products   = $products;
		$this->logger     = $logger;
	}

	/**
	 * Job handler: build the report identified by $report_id.
	 *
	 * @param int $report_id Report row ID.
	 * @return void
	 */
	public function run( $report_id ) {
		$report_id = (int) $report_id;
		$row       = $this->repository->find( $report_id );

		if ( ! $row ) {
			$this->logger->warning( 'Report job ran for a report row that no longer exists.', array( 'report_id' => $report_id ) );

			return;
		}

		// Idempotency: a retried or duplicated job must not rebuild a report
		// that already finished (and must not orphan the file it wrote).
		if ( ReportRepository::STATUS_COMPLETE === $row['status'] ) {
			$this->logger->debug( 'Report job skipped — already complete.', array( 'report_id' => $report_id ) );

			return;
		}

		$params = $this->repository->params( $row );

		$this->repository->mark_running( $report_id );

		$result = $this->build( $report_id, $params );

		if ( is_wp_error( $result ) ) {
			$this->repository->mark_failed( $report_id, $result->get_error_message() );
			$this->logger->error(
				'Fulfillment report failed.',
				array(
					'report_id' => $report_id,
					'error'     => $result->get_error_message(),
				)
			);

			return;
		}

		// A report can be deleted while its job is still running — a real
		// window, since a large date range takes minutes. mark_complete()
		// would then update nothing and leave the CSV on disk unreferenced,
		// invisible to the Reports screen and so undeletable through it. Since
		// that file holds customer PII, discard it instead.
		if ( ! $this->repository->find( $report_id ) ) {
			$this->storage->delete( $result['filename'] );

			$this->logger->info(
				'Report was deleted while it was building — generated file discarded.',
				array( 'report_id' => $report_id )
			);

			return;
		}

		$this->repository->mark_complete( $report_id, $result['filename'], $result['counts'], $result['note'] );

		$this->logger->info(
			'Fulfillment report generated.',
			array(
				'report_id' => $report_id,
				'counts'    => $result['counts'],
				'note'      => $result['note'],
			)
		);
	}

	/**
	 * Explain a run that scanned zero orders.
	 *
	 * "0 of 0 orders matched" has two very different causes — the store really
	 * returned no orders for these filters, or we failed to read the response
	 * we were given — and they need opposite fixes. So when a run comes up
	 * empty, ask for a single unfiltered order and report what came back. The
	 * probe never feeds the report; it only annotates it.
	 *
	 * @param string $list_shape Runtime shape of the filtered list response.
	 * @param array  $filters    Filters that were applied.
	 * @return string Diagnostic note, empty when nothing useful can be said.
	 */
	private function diagnose_empty( $list_shape, array $filters ) {
		$probe = $this->gateway->probe_orders();

		if ( is_wp_error( $probe ) ) {
			return sprintf(
				/* translators: 1: filtered response shape, 2: error message */
				__( 'No orders were returned. Filtered response was %1$s. An unfiltered probe query also failed: %2$s', 'blt-surecart-extensions' ),
				$list_shape,
				$probe->get_error_message()
			);
		}

		$probe_returned = count( $probe['data'] );

		if ( $probe_returned > 0 || $probe['count'] > 0 ) {
			// The store does return orders without filters, so the filters (or
			// the date window) are what excluded everything.
			return sprintf(
				/* translators: 1: reported total order count, 2: applied filters */
				__( 'No orders matched. The store does return orders (SureCart reports %1$d in total), so the filters excluded everything: %2$s. Check the order status and date range.', 'blt-surecart-extensions' ),
				$probe['count'],
				$this->describe_filters( $filters )
			);
		}

		return sprintf(
			/* translators: 1: filtered response shape, 2: unfiltered response shape */
			__( 'No orders were returned even with no filters applied, so this is not a filter problem. Response shapes — filtered: %1$s; unfiltered: %2$s. Send this line to your developer.', 'blt-surecart-extensions' ),
			$list_shape,
			$probe['shape']
		);
	}

	/**
	 * Compact, human-readable rendering of the applied query filters.
	 *
	 * @param array $filters Filters passed to the gateway.
	 * @return string
	 */
	private function describe_filters( array $filters ) {
		$parts = array();

		foreach ( $filters as $key => $value ) {
			$parts[] = $key . '=' . ( is_array( $value ) ? implode( '|', $value ) : (string) $value );
		}

		return empty( $parts ) ? __( 'none', 'blt-surecart-extensions' ) : implode( ', ', $parts );
	}

	/**
	 * Job handler: refresh the cached product list used by the picker.
	 *
	 * @return void
	 */
	public function refresh_products() {
		$products = array();
		$page     = 1;
		$shape    = '';

		do {
			$result = $this->gateway->list_products_page( $page );

			if ( is_wp_error( $result ) ) {
				$this->logger->error(
					'Could not refresh the report product list.',
					array(
						'page'  => $page,
						'error' => $result->get_error_message(),
					)
				);

				return;
			}

			if ( 1 === $page ) {
				$shape = isset( $result['shape'] ) ? $result['shape'] : '';

				$this->logger->debug(
					'Report product page 1 response.',
					array(
						'shape'    => $shape,
						'reported' => (int) $result['count'],
						'returned' => count( $result['data'] ),
					)
				);
			}

			foreach ( $result['data'] as $product ) {
				$id = Obj::str( $product, 'id' );

				if ( '' === $id ) {
					continue;
				}

				$products[] = array(
					'id'   => $id,
					'name' => Obj::str( $product, 'name', $id ),
				);
			}

			$fetched = count( $result['data'] );
			++$page;
		} while ( $fetched >= SureCartGateway::MAX_PER_PAGE && $page <= self::MAX_PAGES );

		// Never replace a working list with an empty one. An empty result is
		// far more likely to mean the query or the response parsing broke than
		// that the store genuinely lost all its products, and overwriting would
		// destroy a picker that was working a moment ago.
		if ( empty( $products ) && ! $this->products->is_cold() ) {
			$this->logger->warning(
				'Product refresh returned nothing; keeping the previously cached list.',
				array(
					'shape'   => $shape,
					'existing' => count( $this->products->all() ),
				)
			);

			return;
		}

		if ( empty( $products ) ) {
			$this->logger->warning(
				'Product refresh returned no products. The picker will stay empty and reports will cover all products.',
				array( 'shape' => $shape )
			);
		}

		$this->products->store( $products );

		$this->logger->info( 'Report product list refreshed.', array( 'products' => count( $products ) ) );
	}

	/**
	 * Walk the orders and produce the CSV.
	 *
	 * @param int   $report_id Report row ID.
	 * @param array $params    Report parameters.
	 * @return array|\WP_Error {
	 *     @type string $filename Basename of the written CSV.
	 *     @type array  $counts   Row/column/item/order counts.
	 * }
	 */
	private function build( $report_id, array $params ) {
		$window = $this->window( $params );

		if ( is_wp_error( $window ) ) {
			return $window;
		}

		$filters = array(
			'status' => ! empty( $params['statuses'] ) && is_array( $params['statuses'] ) ? $params['statuses'] : array( 'paid' ),
		);

		if ( ! empty( $params['product_ids'] ) && is_array( $params['product_ids'] ) ) {
			$filters['product_ids'] = $params['product_ids'];
		}

		if ( ! empty( $params['fulfillment_statuses'] ) && is_array( $params['fulfillment_statuses'] ) ) {
			$filters['fulfillment_status'] = $params['fulfillment_statuses'];
		}

		$matrix = new FulfillmentMatrix(
			array(
				'include_address' => ! empty( $params['include_address'] ),
				'remaining_only'  => ! empty( $params['remaining_only'] ),
				// The same selection that narrows the order query has to narrow
				// the line items too — see FulfillmentMatrix::product_selected().
				'product_ids'     => ! empty( $params['product_ids'] ) && is_array( $params['product_ids'] ) ? $params['product_ids'] : array(),
			)
		);

		$scanned    = 0;
		$matched    = 0;
		$page       = 1;
		$truncated  = false;
		$list_shape = '';
		$reported   = 0;

		while ( true ) {
			if ( $page > self::MAX_PAGES ) {
				$truncated = true;

				$this->logger->warning(
					'Report hit the page ceiling — results may be incomplete.',
					array(
						'report_id' => $report_id,
						'max_pages' => self::MAX_PAGES,
						'scanned'   => $scanned,
					)
				);

				break;
			}

			$result = $this->gateway->list_orders_page( $page, $filters );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$orders  = $result['data'];
			$fetched = count( $orders );

			if ( 1 === $page ) {
				$list_shape = isset( $result['shape'] ) ? $result['shape'] : '';
				$reported   = (int) $result['count'];

				$this->logger->debug(
					'Report order page 1 response.',
					array(
						'report_id' => $report_id,
						'shape'     => $list_shape,
						'reported'  => $reported,
						'returned'  => $fetched,
						'filters'   => $filters,
					)
				);
			}

			foreach ( $orders as $order ) {
				++$scanned;

				if ( ! $this->in_window( $order, $window ) ) {
					continue;
				}

				if ( $matrix->add_order( $order ) ) {
					++$matched;
				}
			}

			// No sort parameter exists on this endpoint, so page order is
			// undefined and we cannot stop early on a date boundary — the
			// only safe terminator is a short page.
			if ( $fetched < SureCartGateway::MAX_PER_PAGE ) {
				break;
			}

			++$page;
		}

		$table = $matrix->to_table();

		$note = '';

		if ( 0 === $scanned ) {
			$note = $this->diagnose_empty( $list_shape, $filters );

			$this->logger->warning(
				'Report scanned zero orders.',
				array(
					'report_id' => $report_id,
					'shape'     => $list_shape,
					'reported'  => $reported,
					'note'      => $note,
				)
			);
		} elseif ( 0 === $matched ) {
			$note = sprintf(
				/* translators: %d: number of orders scanned */
				__( '%d orders were returned but none fell inside the date range. Check the range against the orders\' creation dates.', 'blt-surecart-extensions' ),
				$scanned
			);
		}

		if ( $matrix->unidentified_skipped() > 0 ) {
			$this->logger->warning(
				'Report excluded line items whose product could not be identified while a product filter was active.',
				array(
					'report_id' => $report_id,
					'excluded'  => $matrix->unidentified_skipped(),
				)
			);
		}

		$filename = $this->storage->new_filename( $report_id, ReportRepository::TYPE_FULFILLMENT );
		$path     = $this->storage->path( $filename );

		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$written = ( new CsvWriter() )->write( $path, $table['header'], $table['rows'], $table['totals'] );

		if ( is_wp_error( $written ) ) {
			return $written;
		}

		return array(
			'filename' => $filename,
			'note'     => $note,
			'counts'   => array_merge(
				$table['counts'],
				array(
					'orders_matched' => $matched,
					'orders_scanned' => $scanned,
					'truncated'      => $truncated,
				)
			),
		);
	}

	/**
	 * Resolve the requested date range into inclusive UTC timestamp bounds.
	 *
	 * Dates are entered in the site's timezone (that's what an operator
	 * means by "June 1st"), while `order.created_at` is a UTC unix
	 * timestamp — so the end date is pushed to the last second of that day
	 * in site time before conversion, making the range inclusive at both
	 * ends.
	 *
	 * @param array $params Report parameters.
	 * @return array{start: int|null, end: int|null}|\WP_Error
	 */
	private function window( array $params ) {
		$timezone = wp_timezone();
		$start    = null;
		$end      = null;

		if ( ! empty( $params['start_date'] ) ) {
			$start_date = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $params['start_date'] . ' 00:00:00', $timezone );

			if ( ! $start_date ) {
				return new \WP_Error( 'blt_sce_bad_start_date', __( 'The start date could not be understood.', 'blt-surecart-extensions' ) );
			}

			$start = $start_date->getTimestamp();
		}

		if ( ! empty( $params['end_date'] ) ) {
			$end_date = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $params['end_date'] . ' 23:59:59', $timezone );

			if ( ! $end_date ) {
				return new \WP_Error( 'blt_sce_bad_end_date', __( 'The end date could not be understood.', 'blt-surecart-extensions' ) );
			}

			$end = $end_date->getTimestamp();
		}

		if ( null !== $start && null !== $end && $start > $end ) {
			return new \WP_Error( 'blt_sce_inverted_range', __( 'The start date is after the end date.', 'blt-surecart-extensions' ) );
		}

		return array(
			'start' => $start,
			'end'   => $end,
		);
	}

	/**
	 * Whether an order falls inside the requested window.
	 *
	 * An order with no readable `created_at` is excluded rather than
	 * guessed at — silently dating it to now would put it in whatever
	 * report happened to be running.
	 *
	 * @param object $order  Order object.
	 * @param array  $window Start/end bounds.
	 * @return bool
	 */
	private function in_window( $order, array $window ) {
		if ( null === $window['start'] && null === $window['end'] ) {
			return true;
		}

		// Obj::get(), not isset(): isset() reports false for every attribute on
		// a magic-accessor model, which would silently exclude every order.
		$created_at = Obj::get( $order, 'created_at' );

		if ( ! is_numeric( $created_at ) ) {
			return false;
		}

		$created_at = (int) $created_at;

		if ( null !== $window['start'] && $created_at < $window['start'] ) {
			return false;
		}

		if ( null !== $window['end'] && $created_at > $window['end'] ) {
			return false;
		}

		return true;
	}
}
