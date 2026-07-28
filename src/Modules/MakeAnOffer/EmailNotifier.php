<?php
/**
 * All Make an Offer email notifications, both directions, via wp_mail().
 * One shared HTML wrapper; every message is a plain paragraph stack so
 * it renders everywhere. Hook-free (the temporary content-type filter is
 * added and removed within a single send).
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Modules\MakeAnOffer;

use BLT\SCE\Support\Money;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EmailNotifier
 */
final class EmailNotifier {

	/**
	 * Merchant notification: a new offer arrived (sent once the card is
	 * vaulted, so every offer the merchant sees is actionable).
	 *
	 * @param object $offer Offer object.
	 * @return void
	 */
	public function merchant_new_offer( $offer ) {
		$detail_url = add_query_arg(
			array(
				'page'  => 'blt-sce-offers',
				'offer' => $offer->id,
			),
			admin_url( 'admin.php' )
		);

		$this->send(
			Settings::notify_email(),
			sprintf(
				/* translators: 1: customer email, 2: product name */
				__( 'New offer from %1$s — %2$s', 'blt-surecart-extensions' ),
				$offer->customer_email,
				$offer->product_name
			),
			array(
				sprintf(
					/* translators: 1: customer name/email, 2: offer amount, 3: list price, 4: product name */
					__( '%1$s offered %2$s (list price %3$s) for "%4$s".', 'blt-surecart-extensions' ),
					$offer->customer_name ? $offer->customer_name : $offer->customer_email,
					$this->money( $offer->amount, $offer->currency ),
					$this->money( $offer->list_price, $offer->currency ),
					$offer->product_name
				),
				$offer->message ? sprintf(
					/* translators: %s: customer message */
					__( 'Message: "%s"', 'blt-surecart-extensions' ),
					$offer->message
				) : '',
				sprintf( '<a href="%s">%s</a>', esc_url( $detail_url ), esc_html__( 'Review this offer (accept / decline / counter)', 'blt-surecart-extensions' ) ),
			)
		);
	}

	/**
	 * Customer notification: offer accepted and card charged.
	 *
	 * @param object $offer        Offer object.
	 * @param int    $amount_cents Amount actually charged.
	 * @return void
	 */
	public function customer_accepted( $offer, $amount_cents ) {
		$this->send(
			$offer->customer_email,
			sprintf(
				/* translators: %s: product name */
				__( 'Your offer for %s was accepted!', 'blt-surecart-extensions' ),
				$offer->product_name
			),
			array(
				sprintf(
					/* translators: 1: product name, 2: charged amount */
					__( 'Good news — your offer for "%1$s" was accepted, and your card has been charged %2$s.', 'blt-surecart-extensions' ),
					$offer->product_name,
					$this->money( $amount_cents, $offer->currency )
				),
				__( 'No further action is needed. This email is your receipt.', 'blt-surecart-extensions' ),
			)
		);
	}

	/**
	 * Customer notification: offer declined.
	 *
	 * @param object $offer Offer object.
	 * @return void
	 */
	public function customer_declined( $offer ) {
		$this->send(
			$offer->customer_email,
			sprintf(
				/* translators: %s: product name */
				__( 'Your offer for %s was declined', 'blt-surecart-extensions' ),
				$offer->product_name
			),
			array(
				sprintf(
					/* translators: 1: product name, 2: offer amount */
					__( 'Unfortunately your offer of %2$s for "%1$s" was declined. Your card has not been charged.', 'blt-surecart-extensions' ),
					$offer->product_name,
					$this->money( $offer->amount, $offer->currency )
				),
				__( 'You are welcome to submit a new offer at any time.', 'blt-surecart-extensions' ),
			)
		);
	}

	/**
	 * Customer notification: offer expired without a decision.
	 *
	 * @param object $offer Offer object.
	 * @return void
	 */
	public function customer_expired( $offer ) {
		$this->send(
			$offer->customer_email,
			sprintf(
				/* translators: %s: product name */
				__( 'Your offer for %s has expired', 'blt-surecart-extensions' ),
				$offer->product_name
			),
			array(
				sprintf(
					/* translators: 1: product name, 2: offer amount */
					__( 'Your offer of %2$s for "%1$s" expired before a decision was made. Your card has not been charged.', 'blt-surecart-extensions' ),
					$offer->product_name,
					$this->money( $offer->amount, $offer->currency )
				),
				__( 'You are welcome to submit a new offer at any time.', 'blt-surecart-extensions' ),
			)
		);
	}

	/**
	 * Customer notification: merchant sent a counter-offer, with signed
	 * accept/decline links (the customer has no account to log in to).
	 *
	 * @param object $offer Offer object (counter_amount already stored).
	 * @return void
	 */
	public function customer_countered( $offer ) {
		$token = CounterToken::make( $offer->id, $offer->counter_amount );
		$base  = rest_url( 'sc-offer/v1/counter/' . $offer->id . '/respond' );

		$accept_url  = add_query_arg(
			array(
				'decision' => 'accept',
				'token'    => $token,
			),
			$base
		);
		$decline_url = add_query_arg(
			array(
				'decision' => 'decline',
				'token'    => $token,
			),
			$base
		);

		$this->send(
			$offer->customer_email,
			sprintf(
				/* translators: %s: product name */
				__( 'Counter-offer for %s', 'blt-surecart-extensions' ),
				$offer->product_name
			),
			array(
				sprintf(
					/* translators: 1: product name, 2: original offer amount, 3: counter amount */
					__( 'Thanks for your offer of %2$s for "%1$s". The seller has countered at %3$s.', 'blt-surecart-extensions' ),
					$offer->product_name,
					$this->money( $offer->amount, $offer->currency ),
					$this->money( $offer->counter_amount, $offer->currency )
				),
				sprintf(
					/* translators: %s: counter amount */
					__( 'If you accept, your saved card will be charged %s immediately.', 'blt-surecart-extensions' ),
					$this->money( $offer->counter_amount, $offer->currency )
				),
				sprintf(
					'<a href="%s" style="display:inline-block;padding:10px 18px;background:#2271b1;color:#fff;text-decoration:none;border-radius:3px;">%s</a> &nbsp; <a href="%s">%s</a>',
					esc_url( $accept_url ),
					esc_html__( 'Accept counter-offer', 'blt-surecart-extensions' ),
					esc_url( $decline_url ),
					esc_html__( 'Decline', 'blt-surecart-extensions' )
				),
				sprintf(
					/* translators: %s: expiry date */
					__( 'This counter-offer expires on %s.', 'blt-surecart-extensions' ),
					wp_date( get_option( 'date_format' ), $offer->expires_at )
				),
			)
		);
	}

	/**
	 * Format cents for email display, reusing the shared Money helper.
	 *
	 * @param int    $cents    Amount in cents.
	 * @param string $currency ISO currency code.
	 * @return string
	 */
	private function money( $cents, $currency ) {
		return strtoupper( $currency ? $currency : 'USD' ) . ' ' . Money::cents_to_decimal_string( $cents );
	}

	/**
	 * Wrap paragraphs in the shared HTML shell and send.
	 *
	 * @param string   $to         Recipient.
	 * @param string   $subject    Subject line.
	 * @param string[] $paragraphs Body paragraphs (may contain safe HTML; empty ones are skipped).
	 * @return void
	 */
	private function send( $to, $subject, array $paragraphs ) {
		$body = '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;max-width:560px;margin:0 auto;padding:24px;color:#1e1e1e;">';
		$body .= '<h2 style="font-size:18px;">' . esc_html( $subject ) . '</h2>';

		foreach ( $paragraphs as $paragraph ) {
			if ( '' === $paragraph ) {
				continue;
			}

			$body .= '<p style="line-height:1.5;">' . wp_kses_post( $paragraph ) . '</p>';
		}

		$body .= '<hr style="border:none;border-top:1px solid #ddd;margin:24px 0;" />';
		$body .= '<p style="font-size:12px;color:#757575;">' . esc_html( get_bloginfo( 'name' ) ) . '</p>';
		$body .= '</div>';

		wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}
}
