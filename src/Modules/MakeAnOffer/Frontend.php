<?php
/**
 * The [sc_make_an_offer] shortcode: renders the offer button + modal
 * form and enqueues Stripe.js + the module's JS/CSS only on pages that
 * actually use the shortcode. No build step — vanilla JS.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Modules\MakeAnOffer;

use BLT\SCE\Rest\OfferController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Frontend
 */
final class Frontend {

	const SHORTCODE = 'sc_make_an_offer';

	/**
	 * Register WP hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Register (not enqueue) assets so the shortcode render can enqueue
	 * them only when it actually runs on the page.
	 *
	 * @return void
	 */
	public function register_assets() {
		// Stripe.js must load from Stripe's servers (PCI requirement — it
		// may not be bundled or self-hosted).
		wp_register_script( 'blt-sce-stripe-js', 'https://js.stripe.com/v3/', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Stripe.js is evergreen and must not be version-pinned.

		wp_register_script(
			'blt-sce-offer',
			BLT_SCE_URL . 'assets/make-an-offer/offer.js',
			array( 'blt-sce-stripe-js' ),
			BLT_SCE_VERSION,
			true
		);

		wp_register_style(
			'blt-sce-offer',
			BLT_SCE_URL . 'assets/make-an-offer/offer.css',
			array(),
			BLT_SCE_VERSION
		);
	}

	/**
	 * Render the shortcode.
	 *
	 * Attributes:
	 *  - product_id        (required) SureCart product ID.
	 *  - label             Button text.
	 *  - min_offer_percent Per-placement minimum % of list price (informational,
	 *                      shown to the customer; the global setting is enforced
	 *                      server-side).
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'product_id'        => '',
				'label'             => __( 'Make an Offer', 'blt-surecart-extensions' ),
				'min_offer_percent' => '',
			),
			$atts,
			self::SHORTCODE
		);

		$product_id = sanitize_text_field( $atts['product_id'] );

		if ( '' === $product_id || '' === Settings::stripe_publishable_key() ) {
			// Not configured — render nothing rather than a broken form.
			return '';
		}

		wp_enqueue_script( 'blt-sce-offer' );
		wp_enqueue_style( 'blt-sce-offer' );

		$user = wp_get_current_user();

		wp_localize_script(
			'blt-sce-offer',
			'bltSceOffer',
			array(
				'submitUrl'      => rest_url( OfferController::REST_NAMESPACE . '/submit' ),
				'confirmUrl'     => rest_url( OfferController::REST_NAMESPACE . '/confirm' ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'publishableKey' => Settings::stripe_publishable_key(),
				'stripeAccount'  => Settings::stripe_account_id(),
				'strings'        => array(
					'submitting'   => __( 'Submitting…', 'blt-surecart-extensions' ),
					'success'      => __( 'Your offer has been submitted! You\'ll be notified by email.', 'blt-surecart-extensions' ),
					'genericError' => __( 'Something went wrong. Please try again.', 'blt-surecart-extensions' ),
				),
			)
		);

		$modal_id = 'blt-sce-offer-modal-' . md5( $product_id );

		ob_start();
		?>
		<div class="blt-sce-offer" data-product-id="<?php echo esc_attr( $product_id ); ?>" data-modal="<?php echo esc_attr( $modal_id ); ?>">
			<button type="button" class="blt-sce-offer__open"><?php echo esc_html( $atts['label'] ); ?></button>

			<div class="blt-sce-offer__modal" id="<?php echo esc_attr( $modal_id ); ?>" hidden>
				<div class="blt-sce-offer__backdrop" data-close></div>
				<div class="blt-sce-offer__dialog" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $modal_id ); ?>-title">
					<button type="button" class="blt-sce-offer__close" data-close aria-label="<?php esc_attr_e( 'Close', 'blt-surecart-extensions' ); ?>">&times;</button>
					<h3 id="<?php echo esc_attr( $modal_id ); ?>-title"><?php echo esc_html( $atts['label'] ); ?></h3>

					<form class="blt-sce-offer__form">
						<p class="blt-sce-offer__field">
							<label><?php esc_html_e( 'Your offer', 'blt-surecart-extensions' ); ?>
								<input type="number" name="amount" min="0.01" step="0.01" required placeholder="0.00" />
							</label>
							<?php if ( '' !== $atts['min_offer_percent'] && (int) $atts['min_offer_percent'] > 0 ) : ?>
								<span class="blt-sce-offer__hint">
									<?php
									printf(
										/* translators: %d: minimum percentage of list price */
										esc_html__( 'Offers below %d%% of the list price are unlikely to be accepted.', 'blt-surecart-extensions' ),
										(int) $atts['min_offer_percent']
									);
									?>
								</span>
							<?php endif; ?>
						</p>
						<p class="blt-sce-offer__field">
							<label><?php esc_html_e( 'Name', 'blt-surecart-extensions' ); ?>
								<input type="text" name="name" value="<?php echo esc_attr( $user->exists() ? $user->display_name : '' ); ?>" required />
							</label>
						</p>
						<p class="blt-sce-offer__field">
							<label><?php esc_html_e( 'Email', 'blt-surecart-extensions' ); ?>
								<input type="email" name="email" value="<?php echo esc_attr( $user->exists() ? $user->user_email : '' ); ?>" required />
							</label>
						</p>
						<p class="blt-sce-offer__field">
							<label><?php esc_html_e( 'Message (optional)', 'blt-surecart-extensions' ); ?>
								<textarea name="message" rows="2"></textarea>
							</label>
						</p>
						<div class="blt-sce-offer__field">
							<label><?php esc_html_e( 'Card details', 'blt-surecart-extensions' ); ?></label>
							<div class="blt-sce-offer__card"></div>
							<span class="blt-sce-offer__hint"><?php esc_html_e( 'Your card is only charged if your offer is accepted.', 'blt-surecart-extensions' ); ?></span>
						</div>

						<div class="blt-sce-offer__error" role="alert" hidden></div>
						<div class="blt-sce-offer__success" hidden></div>

						<p class="blt-sce-offer__actions">
							<button type="submit" class="blt-sce-offer__submit"><?php esc_html_e( 'Submit Offer', 'blt-surecart-extensions' ); ?></button>
						</p>
					</form>
				</div>
			</div>
		</div>
		<?php

		return ob_get_clean();
	}
}
