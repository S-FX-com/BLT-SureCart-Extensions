<?php
/**
 * Held orders awaiting a human decision: guardrail holds (status=review)
 * and, when auto-purchase is off, every newly quoted order (status=quoted).
 * Purchasing from here still runs through Action Scheduler — an admin
 * click enqueues the job rather than calling Shippo inline.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Modules\ShippoFulfillment;

use BLT\SCE\Db\ShipmentRepository;
use BLT\SCE\Support\Scheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ReviewQueue
 */
final class ReviewQueue {

	/**
	 * Statuses that show up in the review queue.
	 *
	 * @var string[]
	 */
	const QUEUE_STATUSES = array( ShipmentRepository::STATUS_REVIEW, ShipmentRepository::STATUS_QUOTED );

	/**
	 * Shipment repository.
	 *
	 * @var ShipmentRepository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param ShipmentRepository $repository Shipment repository.
	 */
	public function __construct( ShipmentRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Paginated listing of held shipments.
	 *
	 * @param array $args Passed through to ShipmentRepository::paginated(), 'statuses' is forced.
	 * @return array{rows: object[], total: int}
	 */
	public function list_held( array $args = array() ) {
		$args['statuses'] = self::QUEUE_STATUSES;

		return $this->repository->paginated( $args );
	}

	/**
	 * Enqueue a manual purchase for a held shipment. Guardrail holds are
	 * intentionally overridable this way — a human has now looked at it —
	 * but the kill switch and test/live token integrity checks are not:
	 * those are absolute stops, not judgment calls, and LabelPurchaser
	 * re-checks them inside the job regardless of who triggered it.
	 *
	 * @param int $shipment_id Shipment row ID.
	 * @return true|\WP_Error
	 */
	public function purchase_now( $shipment_id ) {
		$row = $this->repository->find_by_id( $shipment_id );

		if ( ! $row ) {
			return new \WP_Error( 'blt_sce_not_found', __( 'Shipment not found.', 'blt-surecart-extensions' ) );
		}

		if ( ! in_array( $row->status, self::QUEUE_STATUSES, true ) ) {
			return new \WP_Error( 'blt_sce_not_queued', __( 'This shipment is not awaiting manual purchase.', 'blt-surecart-extensions' ) );
		}

		Scheduler::enqueue(
			LabelPurchaser::HOOK_PURCHASE,
			array(
				'shipment_id' => (int) $row->id,
				'manual'      => true,
			)
		);

		return true;
	}
}
