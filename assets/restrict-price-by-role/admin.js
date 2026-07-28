/**
 * SureCart - Restrict Price by User Role
 * Admin settings page controller.
 *
 * Loads products + prices via AJAX, renders them using a wp.template,
 * and saves the role-restriction matrix back via AJAX.
 *
 * @package SC_Restrict_Price_By_Role
 */
( function ( $ ) {
	'use strict';

	var SCRPBR = {
		/**
		 * Bootstrap.
		 */
		init: function () {
			this.loadProducts();
			this.bindEvents();
		},

		/**
		 * Bind DOM events.
		 */
		bindEvents: function () {
			$( '#scrpbr-form' ).on( 'submit', this.saveRestrictions.bind( this ) );
		},

		// ── Data loading ─────────────────────────────────────────────────

		/**
		 * Fetch a page of products from the server and render them.
		 *
		 * @param {number} page 1-based page index.
		 */
		loadProducts: function ( page ) {
			page = page || 1;

			$.ajax( {
				url: scrpbrAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'blt_sce_rpbr_load_products',
					nonce: scrpbrAdmin.nonce,
					page: page,
				},
				success: function ( response ) {
					if (
						response.success &&
						response.data.products.length > 0
					) {
						$( '#scrpbr-loading' ).hide();
						SCRPBR.renderProducts( response.data.products );
						$( '#scrpbr-products-container' ).show();

						// If there are more pages, keep loading.
						var pagination = response.data.pagination;
						if (
							pagination &&
							pagination.count > page * 50
						) {
							SCRPBR.loadProducts( page + 1 );
						}
					} else if (
						response.success &&
						response.data.products.length === 0 &&
						page === 1
					) {
						$( '#scrpbr-loading' ).hide();
						$( '#scrpbr-products-container' )
							.show()
							.html(
								'<p>' +
									scrpbrAdmin.strings.noProducts +
									'</p>'
							);
					} else if ( ! response.success ) {
						$( '#scrpbr-loading' ).html(
							'<p class="scrpbr-error">' +
								( response.data ||
									scrpbrAdmin.strings.loadError ) +
								'</p>'
						);
					}
				},
				error: function () {
					$( '#scrpbr-loading' ).html(
						'<p class="scrpbr-error">' +
							scrpbrAdmin.strings.loadError +
							'</p>'
					);
				},
			} );
		},

		/**
		 * Render an array of product objects into the DOM.
		 *
		 * @param {Array} products Product data from the AJAX response.
		 */
		renderProducts: function ( products ) {
			var template = wp.template( 'scrpbr-product' );
			var $container = $( '#scrpbr-products-list' );

			products.forEach( function ( product ) {
				$container.append( template( product ) );
			} );
		},

		// ── Save ─────────────────────────────────────────────────────────

		/**
		 * Serialize the form and persist restrictions via AJAX.
		 *
		 * @param {Event} e Submit event.
		 */
		saveRestrictions: function ( e ) {
			e.preventDefault();

			var $btn = $( '#scrpbr-save-btn' );
			var originalText = $btn.text();

			$btn.prop( 'disabled', true ).text( scrpbrAdmin.strings.saving );

			$.ajax( {
				url: scrpbrAdmin.ajaxUrl,
				type: 'POST',
				data:
					$( '#scrpbr-form' ).serialize() +
					'&action=blt_sce_rpbr_save_restrictions&nonce=' +
					scrpbrAdmin.nonce,
				success: function ( response ) {
					$btn.prop( 'disabled', false ).text( originalText );

					if ( response.success ) {
						SCRPBR.showNotice(
							scrpbrAdmin.strings.saved,
							'success'
						);
					} else {
						SCRPBR.showNotice(
							response.data || scrpbrAdmin.strings.error,
							'error'
						);
					}
				},
				error: function () {
					$btn.prop( 'disabled', false ).text( originalText );
					SCRPBR.showNotice( scrpbrAdmin.strings.error, 'error' );
				},
			} );
		},

		// ── UI helpers ───────────────────────────────────────────────────

		/**
		 * Display a WordPress-style admin notice.
		 *
		 * @param {string} message Notice text.
		 * @param {string} type    "success" or "error".
		 */
		showNotice: function ( message, type ) {
			var cls =
				type === 'success' ? 'notice-success' : 'notice-error';
			var $notice = $(
				'<div class="notice ' +
					cls +
					' is-dismissible"><p>' +
					message +
					'</p></div>'
			);

			$( '#scrpbr-notices' ).html( $notice );

			setTimeout( function () {
				$notice.fadeOut( function () {
					$notice.remove();
				} );
			}, 5000 );
		},
	};

	$( document ).ready( function () {
		SCRPBR.init();
	} );
} )( jQuery );
