<?php
/**
 * Orchestrates the label-purchase flow (spec §2 / §4 tasks/03). Runs
 * exclusively inside Action Scheduler jobs — no HTTP happens in a
 * customer-facing request. Idempotent: the local shipment row (unique on
 * surecart_order_id) is created before this ever runs, and every entry
 * point re-checks shippo_transaction_id before spending money.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Modules\ShippoFulfillment;

use BLT\SCE\Admin\SettingsPage;
use BLT\SCE\Api\ShippoClient;
use BLT\SCE\Api\SureCartGateway;
use BLT\SCE\Db\ShipmentRepository;
use BLT\SCE\Support\Logger;
use BLT\SCE\Support\Money;
use BLT\SCE\Support\Scheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LabelPurchaser
 */
final class LabelPurchaser {

	const HOOK_PURCHASE = 'blt_sce_shippo_purchase_label';

	/**
	 * Bounded retry ceiling — after this many attempts a shipment is
	 * marked failed and stops retrying against a paid API.
	 *
	 * @var int
	 */
	const MAX_ATTEMPTS = 5;

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
	 * Parcel mapper.
	 *
	 * @var ParcelMapper
	 */
	private $parcels;

	/**
	 * Service selector.
	 *
	 * @var ServiceSelector
	 */
	private $service_selector;

	/**
	 * Guardrails.
	 *
	 * @var Guardrails
	 */
	private $guardrails;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param ShipmentRepository $repository       Shipment repository.
	 * @param ShippoClient       $shippo           Shippo API client.
	 * @param SureCartGateway    $surecart         SureCart gateway.
	 * @param ParcelMapper       $parcels          Parcel mapper.
	 * @param ServiceSelector    $service_selector Service selector.
	 * @param Guardrails         $guardrails       Guardrails.
	 * @param Logger             $logger           Shared logger.
	 */
	public function __construct(
		ShipmentRepository $repository,
		ShippoClient $shippo,
		SureCartGateway $surecart,
		ParcelMapper $parcels,
		ServiceSelector $service_selector,
		Guardrails $guardrails,
		Logger $logger
	) {
		$this->repository       = $repository;
		$this->shippo           = $shippo;
		$this->surecart         = $surecart;
		$this->parcels          = $parcels;
		$this->service_selector = $service_selector;
		$this->guardrails       = $guardrails;
		$this->logger           = $logger;
	}

	/**
	 * Action Scheduler job entry point.
	 *
	 * @param int  $shipment_id Local shipment row id.
	 * @param bool $manual      Whether this run was triggered by an explicit admin "Purchase now" click.
	 * @return void
	 */
	public function process( $shipment_id, $manual = false ) {
		$row = $this->repository->find_by_id( $shipment_id );

		if ( ! $row ) {
			$this->logger->error( 'Purchase job ran for a shipment id that no longer exists.', array( 'shipment_id' => $shipment_id ) );
			return;
		}

		if ( ! empty( $row->shippo_transaction_id ) ) {
			$this->logger->info( 'Label already purchased — skipping.', array(), $row->id );
			return;
		}

		if ( $this->guardrails->is_halted() ) {
			$this->logger->warning( 'Kill switch is on — purchase halted.', array(), $row->id );
			return;
		}

		$token_check = $this->guardrails->assert_token_matches_mode( $this->shippo->token() );

		if ( is_wp_error( $token_check ) ) {
			$this->fail( $row, $token_check->get_error_message() );
			return;
		}

		// Attempts count transient failures of the external calls below
		// (SureCart order fetch, Shippo quote/purchase) — not local-only
		// guardrail holds (parcel resolution, destination/rate checks) or
		// the auto-purchase-off "quoted and waiting" stop, none of which
		// are failures and shouldn't burn down the retry budget.
		if ( $row->attempts >= self::MAX_ATTEMPTS ) {
			$this->fail( $row, __( 'Exceeded maximum purchase attempts.', 'blt-surecart-extensions' ) );
			return;
		}

		$this->repository->increment_attempts( $row->id );
		$row = $this->repository->find_by_id( $row->id );

		$order = $this->surecart->get_order( $row->surecart_order_id );

		if ( is_wp_error( $order ) ) {
			$this->retry_or_fail( $row, $order->get_error_message() );
			return;
		}

		$context = $this->surecart->extract_shipping_context( $order );

		if ( is_wp_error( $context ) ) {
			// Not shippable — not a transient condition, retrying won't help.
			$this->fail( $row, $context->get_error_message() );
			return;
		}

		$skus = array_filter( wp_list_pluck( $context['line_items'], 'sku' ) );

		if ( count( $skus ) < count( $context['line_items'] ) ) {
			$this->logger->warning( 'One or more line items had no resolvable SKU.', array(), $row->id );
		}

		$resolved = $this->parcels->resolve( $skus );

		if ( null === $resolved['parcel'] ) {
			$reason = ParcelMapper::REASON_MULTIPLE_PARCELS === $resolved['reason']
				? __( 'Order resolves to more than one distinct parcel — multi-parcel orders are out of scope for v1.', 'blt-surecart-extensions' )
				: __( 'No parcel could be resolved for this order\'s SKUs, and no default parcel is configured.', 'blt-surecart-extensions' );

			$this->hold_for_review( $row, $reason );
			return;
		}

		$address_validation = $this->shippo->validate_address( $context['shipping_address'], $row->id );
		$destination_check  = $this->guardrails->evaluate_destination(
			$context['shipping_address']['country'],
			$context['shipping_address']['state'],
			$address_validation['valid']
		);

		if ( ! $destination_check['allowed'] && ! $manual ) {
			$this->hold_for_review( $row, implode( ' ', $destination_check['reasons'] ) );
			return;
		}

		$address_from = SettingsPage::ship_from_address();
		$parcel       = $resolved['parcel'];
		unset( $parcel['id'], $parcel['name'] );

		$shipment = $this->shippo->create_shipment( $address_from, $context['shipping_address'], $parcel, $row->id );

		if ( is_wp_error( $shipment ) ) {
			$this->retry_or_fail( $row, $shipment->get_error_message() );
			return;
		}

		$rates = isset( $shipment['rates'] ) && is_array( $shipment['rates'] ) ? $shipment['rates'] : array();

		$this->repository->update(
			$row->id,
			array(
				'shippo_shipment_id' => isset( $shipment['object_id'] ) ? $shipment['object_id'] : null,
				'payload'            => wp_json_encode( array( 'shipment' => $shipment ) ),
			)
		);

		$rate = $this->service_selector->select( $rates );

		if ( null === $rate ) {
			$this->hold_for_review( $row, __( 'No Shippo rate matched the configured service selection rules.', 'blt-surecart-extensions' ) );
			return;
		}

		$rate_cents = Money::decimal_string_to_cents( isset( $rate['amount'] ) ? $rate['amount'] : '0' );
		$rate_check = $this->guardrails->evaluate_rate( $rate_cents, $context['order_total_cents'] );

		$this->repository->update(
			$row->id,
			array(
				'shippo_rate_id' => isset( $rate['object_id'] ) ? $rate['object_id'] : null,
				'carrier'        => isset( $rate['provider'] ) ? $rate['provider'] : null,
				'service_token'  => isset( $rate['servicelevel']['token'] ) ? $rate['servicelevel']['token'] : null,
				'amount_cents'   => $rate_cents,
				'status'         => ShipmentRepository::STATUS_QUOTED,
			)
		);

		if ( ! $rate_check['allowed'] && ! $manual ) {
			$this->hold_for_review( $row, implode( ' ', $rate_check['reasons'] ) );
			return;
		}

		if ( ! $this->guardrails->auto_purchase_enabled() && ! $manual ) {
			$this->logger->info( 'Auto-purchase is off — quoted and queued for manual purchase.', array(), $row->id );
			return;
		}

		$transaction = $this->shippo->purchase_transaction(
			$rate['object_id'],
			'PDF',
			$row->surecart_order_id,
			$row->id
		);

		if ( is_wp_error( $transaction ) ) {
			$this->retry_or_fail( $row, $transaction->get_error_message() );
			return;
		}

		if ( ! isset( $transaction['status'] ) || 'SUCCESS' !== $transaction['status'] ) {
			$messages = isset( $transaction['messages'] ) ? wp_json_encode( $transaction['messages'] ) : '';
			$this->retry_or_fail( $row, sprintf( 'Shippo transaction status: %s. %s', isset( $transaction['status'] ) ? $transaction['status'] : 'unknown', $messages ) );
			return;
		}

		$this->repository->update(
			$row->id,
			array(
				'shippo_transaction_id' => $transaction['object_id'],
				'tracking_number'       => isset( $transaction['tracking_number'] ) ? $transaction['tracking_number'] : null,
				'tracking_url'          => isset( $transaction['tracking_url_provider'] ) ? $transaction['tracking_url_provider'] : null,
				'label_url'             => isset( $transaction['label_url'] ) ? $transaction['label_url'] : null,
				'status'                => ShipmentRepository::STATUS_PURCHASED,
				'payload'               => wp_json_encode( array( 'transaction' => $transaction ) ),
			)
		);

		$this->logger->info( 'Label purchased.', array( 'transaction_id' => $transaction['object_id'] ), $row->id );

		$fulfillment_items = array();

		foreach ( $context['line_items'] as $line_item ) {
			$fulfillment_items[] = array(
				'line_item' => $line_item['line_item_id'],
				'quantity'  => $line_item['quantity'],
			);
		}

		$fulfillment = $this->surecart->create_fulfillment(
			$row->surecart_order_id,
			$fulfillment_items,
			isset( $transaction['tracking_number'] ) ? $transaction['tracking_number'] : '',
			isset( $transaction['tracking_url_provider'] ) ? $transaction['tracking_url_provider'] : null,
			'label_purchased'
		);

		if ( is_wp_error( $fulfillment ) ) {
			$this->logger->error(
				'Label purchased, but creating the SureCart fulfillment failed. The label and tracking are safe locally; this needs manual follow-up in SureCart.',
				array( 'error' => $fulfillment->get_error_message() ),
				$row->id
			);
		} else {
			$this->repository->update( $row->id, array( 'surecart_fulfillment_id' => $fulfillment->id ) );
		}

		$this->notify_fulfillment_team( $row );
	}

	/**
	 * Void a purchased label: request a Shippo refund and mark the local
	 * record voided. Never automatic — always an explicit admin action.
	 *
	 * @param int $shipment_id Local shipment row id.
	 * @return true|\WP_Error
	 */
	public function void( $shipment_id ) {
		$row = $this->repository->find_by_id( $shipment_id );

		if ( ! $row || empty( $row->shippo_transaction_id ) ) {
			return new \WP_Error( 'blt_sce_nothing_to_void', __( 'No purchased label to void for this shipment.', 'blt-surecart-extensions' ) );
		}

		$refund = $this->shippo->refund_transaction( $row->shippo_transaction_id, $row->id );

		if ( is_wp_error( $refund ) ) {
			return $refund;
		}

		if ( isset( $refund['status'] ) && 'ERROR' === $refund['status'] ) {
			$message = isset( $refund['messages'] ) ? wp_json_encode( $refund['messages'] ) : '';

			return new \WP_Error( 'blt_sce_refund_rejected', sprintf( 'Shippo rejected the refund request. %s', $message ) );
		}

		// QUEUED/PENDING/SUCCESS all mean the refund was accepted for
		// processing — Shippo refunds are not always immediate, but the
		// request itself succeeded, so the label is treated as voided now.
		$this->repository->update( $row->id, array( 'status' => ShipmentRepository::STATUS_VOIDED ) );

		if ( $row->surecart_fulfillment_id ) {
			// Best-effort — SureCart's shipment_status enum has no exact
			// "voided" value; 'failed' is the closest analogue available.
			$updated = $this->surecart->update_fulfillment( $row->surecart_fulfillment_id, null, null, 'failed' );

			if ( is_wp_error( $updated ) ) {
				$this->logger->warning( 'Voided locally, but updating the SureCart fulfillment failed.', array( 'error' => $updated->get_error_message() ), $row->id );
			}
		}

		$this->logger->info( 'Label voided.', array( 'refund' => $refund ), $row->id );

		return true;
	}

	/**
	 * Reset a failed shipment for a fresh attempt (explicit admin action).
	 *
	 * @param int $shipment_id Local shipment row id.
	 * @return void
	 */
	public function retry( $shipment_id ) {
		$row = $this->repository->find_by_id( $shipment_id );

		if ( ! $row ) {
			return;
		}

		$this->repository->reset_for_retry( $shipment_id );

		Scheduler::enqueue(
			self::HOOK_PURCHASE,
			array(
				'shipment_id' => $shipment_id,
				'manual'      => false,
			)
		);
	}

	/**
	 * Hold a shipment in the review queue for a human decision.
	 *
	 * @param object $row    Shipment row.
	 * @param string $reason Human-readable hold reason.
	 * @return void
	 */
	private function hold_for_review( $row, $reason ) {
		$this->repository->update(
			$row->id,
			array(
				'status'     => ShipmentRepository::STATUS_REVIEW,
				'last_error' => $reason,
			)
		);

		$this->logger->warning( 'Held for review: ' . $reason, array(), $row->id );
	}

	/**
	 * Retry with exponential backoff, or give up after MAX_ATTEMPTS.
	 *
	 * @param object $row     Shipment row.
	 * @param string $message Error message.
	 * @return void
	 */
	private function retry_or_fail( $row, $message ) {
		if ( $row->attempts >= self::MAX_ATTEMPTS ) {
			$this->fail( $row, $message );
			return;
		}

		$this->repository->update( $row->id, array( 'last_error' => $message ) );

		$delay = min( HOUR_IN_SECONDS, 60 * ( 2 ** $row->attempts ) );

		Scheduler::schedule_single(
			time() + $delay,
			self::HOOK_PURCHASE,
			array(
				'shipment_id' => $row->id,
				'manual'      => false,
			)
		);

		$this->logger->warning( sprintf( 'Attempt %d failed, retrying in %ds: %s', $row->attempts, $delay, $message ), array(), $row->id );
	}

	/**
	 * Give up: mark failed, stop retrying, surface in admin.
	 *
	 * @param object $row     Shipment row.
	 * @param string $message Error message.
	 * @return void
	 */
	private function fail( $row, $message ) {
		$this->repository->update(
			$row->id,
			array(
				'status'     => ShipmentRepository::STATUS_FAILED,
				'last_error' => $message,
			)
		);

		$this->logger->error( 'Marked failed: ' . $message, array(), $row->id );
	}

	/**
	 * Best-effort email notification to the fulfillment team. Failure to
	 * send never affects the shipment record — the label is already
	 * purchased and safely persisted regardless.
	 *
	 * @param object $row Shipment row.
	 * @return void
	 */
	private function notify_fulfillment_team( $row ) {
		/**
		 * Filters the notification recipient email address.
		 *
		 * @param string $email Defaults to the site admin email.
		 */
		$to = apply_filters( 'blt_sce_notification_email', get_option( 'admin_email' ) );

		if ( ! $to ) {
			return;
		}

		wp_mail(
			$to,
			sprintf(
				/* translators: %s: SureCart order id */
				__( '[BLT SCE] Label purchased for order %s', 'blt-surecart-extensions' ),
				$row->surecart_order_id
			),
			sprintf(
				/* translators: 1: SureCart order id, 2: carrier name, 3: tracking number */
				__( "A shipping label was purchased.\n\nOrder: %1\$s\nCarrier: %2\$s\nTracking: %3\$s\n", 'blt-surecart-extensions' ),
				$row->surecart_order_id,
				isset( $row->carrier ) ? $row->carrier : '',
				isset( $row->tracking_number ) ? $row->tracking_number : ''
			)
		);
	}
}
