<?php
/**
 * Orchestrates the module's Stripe flows over Api\StripeClient:
 * vault-card setup at submission, server-side confirmation check,
 * off-session capture on acceptance, and payment-method release on
 * decline/expiry. Hook-free.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Modules\MakeAnOffer;

use BLT\SCE\Api\StripeClient;
use BLT\SCE\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class StripeService
 */
final class StripeService {

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Repository.
	 *
	 * @var OfferRepository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param OfferRepository $repository Offer repository.
	 * @param Logger          $logger     Shared logger.
	 */
	public function __construct( OfferRepository $repository, Logger $logger ) {
		$this->repository = $repository;
		$this->logger     = $logger;
	}

	/**
	 * A client configured from the module settings.
	 *
	 * @return StripeClient
	 */
	private function client() {
		return new StripeClient( $this->logger, Settings::stripe_secret_key(), Settings::stripe_account_id() );
	}

	/**
	 * Create the Stripe customer + SetupIntent for a freshly created
	 * offer and store their IDs. Returns the client_secret the browser
	 * needs for stripe.confirmCardSetup().
	 *
	 * This is the one Stripe call that must run synchronously in a
	 * customer-facing request: the SetupIntent's client_secret has to go
	 * back in the /submit response for Stripe.js to collect the card.
	 * It is customer-initiated, short-timeout, and creates nothing that
	 * costs money.
	 *
	 * @param object $offer Offer object from OfferRepository::find().
	 * @return array|\WP_Error array{client_secret: string, setup_intent_id: string}
	 */
	public function begin_card_setup( $offer ) {
		$client = $this->client();

		$customer = $client->create_customer( $offer->customer_email, $offer->customer_name );

		if ( is_wp_error( $customer ) ) {
			return $customer;
		}

		$this->repository->set_meta( $offer->id, OfferRepository::META_STRIPE_CUST_ID, $customer['id'] );

		$intent = $client->create_setup_intent( $customer['id'], array( 'blt_sce_offer_id' => (string) $offer->id ) );

		if ( is_wp_error( $intent ) ) {
			return $intent;
		}

		$this->repository->set_meta( $offer->id, OfferRepository::META_STRIPE_SI_ID, $intent['id'] );

		return array(
			'client_secret'   => $intent['client_secret'],
			'setup_intent_id' => $intent['id'],
		);
	}

	/**
	 * Verify a browser-reported card confirmation server-side: the
	 * SetupIntent must exist, belong to this offer, and have succeeded.
	 * Never trust the payment_method_id the browser sends — read it off
	 * the verified SetupIntent instead.
	 *
	 * @param object $offer Offer object.
	 * @return true|\WP_Error
	 */
	public function confirm_card_setup( $offer ) {
		if ( '' === $offer->stripe_si_id ) {
			return new \WP_Error( 'blt_sce_offer_no_si', __( 'This offer has no card setup in progress.', 'blt-surecart-extensions' ) );
		}

		$intent = $this->client()->retrieve_setup_intent( $offer->stripe_si_id );

		if ( is_wp_error( $intent ) ) {
			return $intent;
		}

		if ( ! isset( $intent['status'] ) || 'succeeded' !== $intent['status'] || empty( $intent['payment_method'] ) ) {
			return new \WP_Error( 'blt_sce_offer_si_incomplete', __( 'Your card could not be verified. Please try again.', 'blt-surecart-extensions' ) );
		}

		$payment_method = is_array( $intent['payment_method'] ) ? $intent['payment_method']['id'] : $intent['payment_method'];

		$this->repository->set_meta( $offer->id, OfferRepository::META_STRIPE_PM_ID, $payment_method );
		$this->repository->set_meta( $offer->id, OfferRepository::META_PM_CONFIRMED, 1 );

		return true;
	}

	/**
	 * Charge the vaulted card off-session. Runs ONLY inside an Action
	 * Scheduler job. The idempotency key is derived from the offer and
	 * amount, so a retried/duplicated job cannot double-charge.
	 *
	 * @param object $offer        Offer object.
	 * @param int    $amount_cents Amount to capture (offer or counter amount).
	 * @return array|\WP_Error The PaymentIntent, or WP_Error.
	 */
	public function capture( $offer, $amount_cents ) {
		if ( '' === $offer->stripe_pm_id || '' === $offer->stripe_customer_id ) {
			return new \WP_Error( 'blt_sce_offer_no_pm', __( 'No vaulted payment method on this offer.', 'blt-surecart-extensions' ) );
		}

		$currency = $offer->currency ? strtolower( $offer->currency ) : 'usd';

		$intent = $this->client()->create_payment_intent(
			$amount_cents,
			$currency,
			$offer->stripe_customer_id,
			$offer->stripe_pm_id,
			array(
				'blt_sce_offer_id' => (string) $offer->id,
				'product_id'       => $offer->product_id,
			),
			'blt-sce-offer-' . $offer->id . '-' . $amount_cents
		);

		if ( is_wp_error( $intent ) ) {
			return $intent;
		}

		if ( ! isset( $intent['status'] ) || 'succeeded' !== $intent['status'] ) {
			return new \WP_Error(
				'blt_sce_offer_pi_not_succeeded',
				sprintf(
					/* translators: %s: Stripe PaymentIntent status */
					__( 'Payment did not complete (status: %s).', 'blt-surecart-extensions' ),
					isset( $intent['status'] ) ? $intent['status'] : 'unknown'
				)
			);
		}

		$this->repository->set_meta( $offer->id, OfferRepository::META_STRIPE_PI_ID, $intent['id'] );

		return $intent;
	}

	/**
	 * Detach the vaulted payment method. Runs ONLY inside an Action
	 * Scheduler job. A missing PM is a no-op, not an error.
	 *
	 * @param object $offer Offer object.
	 * @return void
	 */
	public function release_payment_method( $offer ) {
		if ( '' === $offer->stripe_pm_id ) {
			return;
		}

		$result = $this->client()->detach_payment_method( $offer->stripe_pm_id );

		if ( is_wp_error( $result ) ) {
			// Log and move on — an already-detached PM returns an error we
			// don't need to surface, and nothing downstream depends on this.
			$this->logger->warning(
				'Offer PM detach failed.',
				array(
					'offer_id' => $offer->id,
					'error'    => $result->get_error_message(),
				)
			);
		}
	}
}
