<?php
/**
 * Maps Shippo tracking statuses to our local vocabulary and to SureCart's
 * fulfillment shipment_status vocabulary, in one documented place (spec
 * tasks/04 requirement #5). Handles both the webhook-driven path and the
 * reconciliation-sweep path identically.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Modules\ShippoFulfillment;

use BLT\SCE\Admin\SettingsPage;
use BLT\SCE\Api\ShippoClient;
use BLT\SCE\Api\SureCartGateway;
use BLT\SCE\Db\ShipmentRepository;
use BLT\SCE\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class StatusSync
 */
final class StatusSync {

	const RECONCILE_HOOK = 'blt_sce_shippo_reconcile';

	/**
	 * Shippo tracking_status.status -> our local ShipmentRepository
	 * status. UNKNOWN is intentionally absent — an unknown reading never
	 * downgrades an already-known status. Confirmed exhaustive set of
	 * Shippo values per tasks/00-discovery.md §D: PRE_TRANSIT, TRANSIT,
	 * DELIVERED, RETURNED, FAILURE, UNKNOWN (no literal "EXCEPTION").
	 *
	 * @var array<string, string>
	 */
	const SHIPPO_TO_LOCAL_STATUS = array(
		'PRE_TRANSIT' => ShipmentRepository::STATUS_SHIPPED,
		'TRANSIT'     => ShipmentRepository::STATUS_IN_TRANSIT,
		'DELIVERED'   => ShipmentRepository::STATUS_DELIVERED,
		'RETURNED'    => ShipmentRepository::STATUS_EXCEPTION,
		'FAILURE'     => ShipmentRepository::STATUS_EXCEPTION,
	);

	/**
	 * Our local status -> SureCart fulfillment shipment_status, sent
	 * best-effort on every write-back (see tasks/00-discovery.md §B item 2
	 * — SureCart's acceptance of this field is unverified, so a failure to
	 * apply it is never treated as an error here).
	 *
	 * @var array<string, string>
	 */
	const LOCAL_TO_SURECART_SHIPMENT_STATUS = array(
		ShipmentRepository::STATUS_PURCHASED  => 'label_purchased',
		ShipmentRepository::STATUS_SHIPPED    => 'shipped',
		ShipmentRepository::STATUS_IN_TRANSIT => 'in_transit',
		ShipmentRepository::STATUS_DELIVERED  => 'delivered',
	);

	/**
	 * Shipment repository.
	 *
	 * @var ShipmentRepository
	 */
	private $repository;

	/**
	 * Shippo API client.
	 *
	 * @var ShippoClient
	 */
	private $shippo;

	/**
	 * SureCart gateway.
	 *
	 * @var SureCartGateway
	 */
	private $surecart;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param ShipmentRepository $repository Shipment repository.
	 * @param ShippoClient       $shippo     Shippo API client.
	 * @param SureCartGateway    $surecart   SureCart gateway.
	 * @param Logger             $logger     Shared logger.
	 */
	public function __construct( ShipmentRepository $repository, ShippoClient $shippo, SureCartGateway $surecart, Logger $logger ) {
		$this->repository = $repository;
		$this->shippo     = $shippo;
		$this->surecart   = $surecart;
		$this->logger     = $logger;
	}

	/**
	 * Ensure the account-wide Shippo tracking webhook is registered.
	 * Idempotent — ShippoClient checks the existing list first, since
	 * Shippo's own webhook registration is explicitly NOT idempotent on
	 * url and duplicate registrations cause duplicate notifications.
	 *
	 * @param string $callback_url Our REST callback URL (with security token).
	 * @return void
	 */
	public function ensure_webhook_registered( $callback_url ) {
		$result = $this->shippo->ensure_webhook_registered( $callback_url, 'track_updated' );

		if ( is_wp_error( $result ) ) {
			$this->logger->error( 'Failed to register Shippo tracking webhook: ' . $result->get_error_message() );
		}
	}

	/**
	 * Handle one Shippo tracking update, from either the webhook or the
	 * reconciliation sweep — same normalized shape either way:
	 * {tracking_number, tracking_status: {status, ...}}.
	 *
	 * @param array $track Shippo Track object (associative array).
	 * @return void
	 */
	public function handle_tracking_update( array $track ) {
		$tracking_number = isset( $track['tracking_number'] ) ? $track['tracking_number'] : null;
		$shippo_status   = isset( $track['tracking_status']['status'] ) ? $track['tracking_status']['status'] : null;

		if ( ! $tracking_number || ! $shippo_status ) {
			$this->logger->warning( 'Tracking update missing tracking_number or status.', array( 'payload' => $track ) );
			return;
		}

		$row = $this->repository->find_by_tracking_number( $tracking_number );

		if ( ! $row ) {
			$this->logger->warning( 'Tracking update for an unknown tracking number.', array( 'tracking_number' => $tracking_number ) );
			return;
		}

		if ( ! isset( self::SHIPPO_TO_LOCAL_STATUS[ $shippo_status ] ) ) {
			$this->logger->debug( 'Tracking status has no local mapping (e.g. UNKNOWN) — leaving status unchanged.', array( 'shippo_status' => $shippo_status ), $row->id );
			return;
		}

		$local_status = self::SHIPPO_TO_LOCAL_STATUS[ $shippo_status ];

		if ( $local_status === $row->status ) {
			return;
		}

		$this->repository->update(
			$row->id,
			array(
				'status'  => $local_status,
				'payload' => wp_json_encode( array( 'tracking' => $track ) ),
			)
		);

		$this->logger->info(
			sprintf( 'Status advanced: %s -> %s (Shippo: %s)', $row->status, $local_status, $shippo_status ),
			array(),
			$row->id
		);

		$this->push_status_to_surecart( $row, $local_status, $shippo_status );
	}

	/**
	 * Scheduled sweep: re-check any shipment stuck in a non-terminal
	 * status for longer than the configured threshold, in case its
	 * webhook was missed. This is what makes the data trustworthy.
	 *
	 * @return void
	 */
	public function reconcile() {
		$hours = SettingsPage::reconcile_after_hours();
		$stuck = $this->repository->find_stuck( $hours );

		foreach ( $stuck as $row ) {
			if ( empty( $row->tracking_number ) || empty( $row->service_token ) ) {
				continue;
			}

			$carrier_slug = strtolower( strtok( $row->service_token, '_' ) );
			$track        = $this->shippo->get_tracking_status( $carrier_slug, $row->tracking_number );

			if ( is_wp_error( $track ) ) {
				$this->logger->warning( 'Reconciliation lookup failed: ' . $track->get_error_message(), array(), $row->id );
				continue;
			}

			$this->handle_tracking_update( $track );
		}
	}

	/**
	 * Best-effort push of the advanced status to SureCart's fulfillment.
	 *
	 * @param object $row           Shipment row.
	 * @param string $local_status  Our local status.
	 * @param string $shippo_status Raw Shippo tracking_status.status.
	 * @return void
	 */
	private function push_status_to_surecart( $row, $local_status, $shippo_status ) {
		if ( ! $row->surecart_fulfillment_id ) {
			return;
		}

		$surecart_status = isset( self::LOCAL_TO_SURECART_SHIPMENT_STATUS[ $local_status ] ) ? self::LOCAL_TO_SURECART_SHIPMENT_STATUS[ $local_status ] : null;

		if ( ShipmentRepository::STATUS_EXCEPTION === $local_status ) {
			$surecart_status = 'RETURNED' === $shippo_status ? 'returned' : 'failed';
		}

		if ( ! $surecart_status ) {
			return;
		}

		$result = $this->surecart->update_fulfillment( $row->surecart_fulfillment_id, null, null, $surecart_status );

		if ( is_wp_error( $result ) ) {
			$this->logger->warning( 'Local status advanced, but pushing to SureCart failed: ' . $result->get_error_message(), array(), $row->id );
		}
	}
}
