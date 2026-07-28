<?php
/**
 * Offer lifecycle orchestration: the single place status transitions
 * happen, shared by the admin actions, the REST controller, and the
 * Action Scheduler job handlers. Hook-free — Module.php wires the job
 * hooks to the process_* methods.
 *
 * Everything that talks to Stripe with money on the line (capture,
 * payment-method release) is enqueued and runs inside an Action
 * Scheduler job, mirroring the Shippo module's async-only rule. The
 * capture idempotency key (offer ID + amount) makes a re-run job safe.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Modules\MakeAnOffer;

use BLT\SCE\Support\Logger;
use BLT\SCE\Support\Scheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OfferManager
 */
final class OfferManager {

	const HOOK_CAPTURE      = 'blt_sce_offer_capture';
	const HOOK_RELEASE_PM   = 'blt_sce_offer_release_pm';
	const HOOK_EXPIRE_SWEEP = 'blt_sce_offer_expire_sweep';

	/**
	 * Repository.
	 *
	 * @var OfferRepository
	 */
	private $repository;

	/**
	 * Stripe flows.
	 *
	 * @var StripeService
	 */
	private $stripe;

	/**
	 * Emails.
	 *
	 * @var EmailNotifier
	 */
	private $emails;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param OfferRepository $repository Offer repository.
	 * @param StripeService   $stripe     Stripe flows.
	 * @param EmailNotifier   $emails     Email notifications.
	 * @param Logger          $logger     Shared logger.
	 */
	public function __construct( OfferRepository $repository, StripeService $stripe, EmailNotifier $emails, Logger $logger ) {
		$this->repository = $repository;
		$this->stripe     = $stripe;
		$this->emails     = $emails;
		$this->logger     = $logger;
	}

	/**
	 * Queue an acceptance: the actual charge happens in the capture job.
	 *
	 * @param int      $offer_id     Offer post ID.
	 * @param int|null $amount_cents Amount to charge; null = the offer amount.
	 * @return true|\WP_Error
	 */
	public function request_acceptance( $offer_id, $amount_cents = null ) {
		$offer = $this->repository->find( $offer_id );

		if ( ! $offer ) {
			return new \WP_Error( 'blt_sce_offer_not_found', __( 'Offer not found.', 'blt-surecart-extensions' ) );
		}

		if ( ! in_array( $offer->status, array( OfferPostType::STATUS_PENDING, OfferPostType::STATUS_COUNTERED ), true ) ) {
			return new \WP_Error( 'blt_sce_offer_not_open', __( 'This offer is no longer open.', 'blt-surecart-extensions' ) );
		}

		if ( ! $offer->pm_confirmed ) {
			return new \WP_Error( 'blt_sce_offer_card_pending', __( 'The customer has not finished saving a card for this offer yet.', 'blt-surecart-extensions' ) );
		}

		Scheduler::enqueue(
			self::HOOK_CAPTURE,
			array(
				'offer_id'     => (int) $offer_id,
				'amount_cents' => null === $amount_cents ? (int) $offer->amount : (int) $amount_cents,
			)
		);

		return true;
	}

	/**
	 * Capture job handler: charge the vaulted card, then mark accepted
	 * and notify the customer. On failure the offer stays open with the
	 * error recorded, so the merchant can see it and retry.
	 *
	 * @param int $offer_id     Offer post ID.
	 * @param int $amount_cents Amount to charge.
	 * @return void
	 */
	public function process_capture( $offer_id, $amount_cents ) {
		$offer = $this->repository->find( $offer_id );

		if ( ! $offer || ! in_array( $offer->status, array( OfferPostType::STATUS_PENDING, OfferPostType::STATUS_COUNTERED ), true ) ) {
			return;
		}

		$result = $this->stripe->capture( $offer, (int) $amount_cents );

		if ( is_wp_error( $result ) ) {
			$this->repository->set_meta( $offer_id, OfferRepository::META_CAPTURE_ERROR, $result->get_error_message() );
			$this->logger->error(
				'Offer capture failed.',
				array(
					'offer_id' => $offer_id,
					'error'    => $result->get_error_message(),
				)
			);

			return;
		}

		$this->repository->set_meta( $offer_id, OfferRepository::META_CAPTURE_ERROR, '' );
		$this->repository->set_status( $offer_id, OfferPostType::STATUS_ACCEPTED );

		$this->logger->info(
			'Offer accepted and captured.',
			array(
				'offer_id'     => $offer_id,
				'amount_cents' => (int) $amount_cents,
			)
		);

		$this->emails->customer_accepted( $offer, (int) $amount_cents );
	}

	/**
	 * Decline an offer: status flip now, PM release + email async-adjacent
	 * (release runs in a job; the email is a local send).
	 *
	 * @param int $offer_id Offer post ID.
	 * @return true|\WP_Error
	 */
	public function decline( $offer_id ) {
		$offer = $this->repository->find( $offer_id );

		if ( ! $offer ) {
			return new \WP_Error( 'blt_sce_offer_not_found', __( 'Offer not found.', 'blt-surecart-extensions' ) );
		}

		if ( ! in_array( $offer->status, array( OfferPostType::STATUS_PENDING, OfferPostType::STATUS_COUNTERED ), true ) ) {
			return new \WP_Error( 'blt_sce_offer_not_open', __( 'This offer is no longer open.', 'blt-surecart-extensions' ) );
		}

		$this->repository->set_status( $offer_id, OfferPostType::STATUS_DECLINED );
		Scheduler::enqueue( self::HOOK_RELEASE_PM, array( 'offer_id' => (int) $offer_id ) );

		if ( $offer->pm_confirmed ) {
			$this->emails->customer_declined( $offer );
		}

		return true;
	}

	/**
	 * Record a counter-offer and email the customer their signed
	 * accept/decline links.
	 *
	 * @param int $offer_id     Offer post ID.
	 * @param int $amount_cents Counter amount in cents.
	 * @return true|\WP_Error
	 */
	public function counter( $offer_id, $amount_cents ) {
		if ( ! Settings::get( 'allow_counter' ) ) {
			return new \WP_Error( 'blt_sce_offer_counter_disabled', __( 'Counter-offers are disabled in settings.', 'blt-surecart-extensions' ) );
		}

		$offer = $this->repository->find( $offer_id );

		if ( ! $offer ) {
			return new \WP_Error( 'blt_sce_offer_not_found', __( 'Offer not found.', 'blt-surecart-extensions' ) );
		}

		if ( OfferPostType::STATUS_PENDING !== $offer->status ) {
			return new \WP_Error( 'blt_sce_offer_not_open', __( 'Only a pending offer can be countered.', 'blt-surecart-extensions' ) );
		}

		if ( ! $offer->pm_confirmed ) {
			return new \WP_Error( 'blt_sce_offer_card_pending', __( 'The customer has not finished saving a card for this offer yet.', 'blt-surecart-extensions' ) );
		}

		if ( $amount_cents < 1 ) {
			return new \WP_Error( 'blt_sce_offer_bad_amount', __( 'Counter amount must be greater than zero.', 'blt-surecart-extensions' ) );
		}

		$this->repository->set_meta( $offer_id, OfferRepository::META_COUNTER_AMOUNT, (int) $amount_cents );
		$this->repository->set_status( $offer_id, OfferPostType::STATUS_COUNTERED );

		$this->emails->customer_countered( $this->repository->find( $offer_id ) );

		return true;
	}

	/**
	 * Customer response to a counter-offer, via signed email link.
	 *
	 * @param int    $offer_id Offer post ID.
	 * @param string $decision 'accept' or 'decline'.
	 * @param string $token    Presented HMAC token.
	 * @return true|\WP_Error
	 */
	public function respond_to_counter( $offer_id, $decision, $token ) {
		$offer = $this->repository->find( $offer_id );

		if ( ! $offer || OfferPostType::STATUS_COUNTERED !== $offer->status ) {
			return new \WP_Error( 'blt_sce_offer_not_countered', __( 'This counter-offer is no longer open.', 'blt-surecart-extensions' ) );
		}

		if ( ! CounterToken::verify( $token, $offer->id, $offer->counter_amount ) ) {
			return new \WP_Error( 'blt_sce_offer_bad_token', __( 'This link is not valid.', 'blt-surecart-extensions' ) );
		}

		if ( $offer->expires_at && $offer->expires_at < time() ) {
			return new \WP_Error( 'blt_sce_offer_expired', __( 'This counter-offer has expired.', 'blt-surecart-extensions' ) );
		}

		if ( 'accept' === $decision ) {
			return $this->request_acceptance( $offer_id, $offer->counter_amount );
		}

		$this->repository->set_status( $offer_id, OfferPostType::STATUS_CANCELLED );
		Scheduler::enqueue( self::HOOK_RELEASE_PM, array( 'offer_id' => (int) $offer_id ) );

		return true;
	}

	/**
	 * Release-PM job handler.
	 *
	 * @param int $offer_id Offer post ID.
	 * @return void
	 */
	public function process_release_pm( $offer_id ) {
		$offer = $this->repository->find( $offer_id );

		if ( $offer ) {
			$this->stripe->release_payment_method( $offer );
		}
	}

	/**
	 * Hourly expiration sweep (Action Scheduler recurring job): expire
	 * every open offer past its deadline, release its card, notify the
	 * customer. Offers that never completed card setup are cancelled
	 * silently — the customer abandoned the form, there's nothing to say.
	 *
	 * @return void
	 */
	public function process_expire_sweep() {
		foreach ( $this->repository->expired_offer_ids() as $offer_id ) {
			$offer = $this->repository->find( $offer_id );

			if ( ! $offer ) {
				continue;
			}

			$this->repository->set_status( $offer_id, $offer->pm_confirmed ? OfferPostType::STATUS_EXPIRED : OfferPostType::STATUS_CANCELLED );
			Scheduler::enqueue( self::HOOK_RELEASE_PM, array( 'offer_id' => (int) $offer_id ) );

			if ( $offer->pm_confirmed ) {
				$this->emails->customer_expired( $offer );
			}
		}
	}

	/**
	 * Auto-accept check, called right after a card is confirmed: if the
	 * setting is on and the offer clears the threshold percentage of
	 * list price, queue the capture immediately.
	 *
	 * @param object $offer Offer object (pm_confirmed already true).
	 * @return bool Whether auto-accept fired.
	 */
	public function maybe_auto_accept( $offer ) {
		$threshold_pct = (int) Settings::get( 'auto_accept_pct' );

		if ( $threshold_pct < 1 || $offer->list_price < 1 ) {
			return false;
		}

		// Integer math: amount/list >= pct/100  <=>  amount*100 >= list*pct.
		if ( $offer->amount * 100 < $offer->list_price * $threshold_pct ) {
			return false;
		}

		$result = $this->request_acceptance( $offer->id );

		if ( true === $result ) {
			$this->logger->info( 'Offer auto-accepted.', array( 'offer_id' => $offer->id ) );

			return true;
		}

		return false;
	}
}
