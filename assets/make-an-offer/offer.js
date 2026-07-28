/**
 * Make an Offer — frontend flow.
 *
 * 1. Open modal, mount a Stripe card element.
 * 2. POST /submit -> offer_id + SetupIntent client_secret.
 * 3. stripe.confirmCardSetup() vaults the card (Stripe.js keeps all
 *    card data out of our servers).
 * 4. POST /confirm -> server verifies the SetupIntent and notifies the
 *    merchant.
 *
 * No bundler, no dependencies beyond Stripe.js.
 */
( function () {
	'use strict';

	if ( typeof bltSceOffer === 'undefined' || typeof Stripe === 'undefined' ) {
		return;
	}

	var stripe = Stripe(
		bltSceOffer.publishableKey,
		bltSceOffer.stripeAccount ? { stripeAccount: bltSceOffer.stripeAccount } : {}
	);

	function post( url, data ) {
		return window
			.fetch( url, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': bltSceOffer.nonce,
				},
				body: JSON.stringify( data ),
			} )
			.then( function ( response ) {
				return response.json().then( function ( body ) {
					if ( ! response.ok ) {
						throw new Error(
							( body && body.message ) || bltSceOffer.strings.genericError
						);
					}
					return body;
				} );
			} );
	}

	function setupWidget( widget ) {
		var productId = widget.getAttribute( 'data-product-id' );
		var modal = document.getElementById( widget.getAttribute( 'data-modal' ) );
		var openBtn = widget.querySelector( '.blt-sce-offer__open' );

		if ( ! modal || ! openBtn ) {
			return;
		}

		var form = modal.querySelector( '.blt-sce-offer__form' );
		var submitBtn = modal.querySelector( '.blt-sce-offer__submit' );
		var errorBox = modal.querySelector( '.blt-sce-offer__error' );
		var successBox = modal.querySelector( '.blt-sce-offer__success' );
		var cardMount = modal.querySelector( '.blt-sce-offer__card' );
		var cardElement = null;

		function showError( message ) {
			errorBox.textContent = message;
			errorBox.hidden = false;
		}

		function openModal() {
			modal.hidden = false;
			document.body.classList.add( 'blt-sce-offer-open' );

			if ( ! cardElement ) {
				cardElement = stripe.elements().create( 'card' );
				cardElement.mount( cardMount );
			}
		}

		function closeModal() {
			modal.hidden = true;
			document.body.classList.remove( 'blt-sce-offer-open' );
		}

		openBtn.addEventListener( 'click', openModal );

		modal.querySelectorAll( '[data-close]' ).forEach( function ( el ) {
			el.addEventListener( 'click', closeModal );
		} );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();

			errorBox.hidden = true;

			var amount = parseFloat( form.elements.amount.value );

			if ( isNaN( amount ) || amount <= 0 ) {
				showError( bltSceOffer.strings.genericError );
				return;
			}

			var originalLabel = submitBtn.textContent;
			submitBtn.disabled = true;
			submitBtn.textContent = bltSceOffer.strings.submitting;

			var offerId = null;

			post( bltSceOffer.submitUrl, {
				product_id: productId,
				amount: form.elements.amount.value,
				name: form.elements.name.value,
				email: form.elements.email.value,
				message: form.elements.message.value,
			} )
				.then( function ( body ) {
					offerId = body.offer_id;

					return stripe.confirmCardSetup( body.client_secret, {
						payment_method: {
							card: cardElement,
							billing_details: {
								name: form.elements.name.value,
								email: form.elements.email.value,
							},
						},
					} );
				} )
				.then( function ( result ) {
					if ( result.error ) {
						throw new Error( result.error.message );
					}

					return post( bltSceOffer.confirmUrl, {
						offer_id: offerId,
						setup_intent_id: result.setupIntent.id,
					} );
				} )
				.then( function () {
					form.hidden = true;
					successBox.textContent = bltSceOffer.strings.success;
					successBox.hidden = false;
				} )
				.catch( function ( err ) {
					showError( err.message || bltSceOffer.strings.genericError );
				} )
				.then( function () {
					submitBtn.disabled = false;
					submitBtn.textContent = originalLabel;
				} );
		} );
	}

	function init() {
		document.querySelectorAll( '.blt-sce-offer' ).forEach( setupWidget );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
