/**
 * SureCart - Restrict Price by User Role
 * Frontend script — hides restricted price choices in real time.
 *
 * Uses a MutationObserver so that dynamically rendered SureCart
 * web-components (e.g. <sc-price-choice>) are caught as soon as
 * they appear in the DOM.
 *
 * @package SC_Restrict_Price_By_Role
 */
( function () {
	'use strict';

	if (
		typeof scrpbrFrontend === 'undefined' ||
		! scrpbrFrontend.restrictedPriceIds ||
		! scrpbrFrontend.restrictedPriceIds.length
	) {
		return;
	}

	var restrictedIds = scrpbrFrontend.restrictedPriceIds;

	/**
	 * Build an array of CSS selectors that target restricted price elements.
	 *
	 * @return {string[]}
	 */
	function buildSelectors() {
		var selectors = [];

		restrictedIds.forEach( function ( id ) {
			selectors.push( 'sc-price-choice[price-id="' + id + '"]' );
			selectors.push( 'sc-price-choice[value="' + id + '"]' );
			selectors.push( '[data-price-id="' + id + '"]' );
		} );

		return selectors;
	}

	var SELECTORS = buildSelectors();

	/**
	 * Hide every element that matches one of our restricted-price selectors.
	 */
	function hideRestrictedPrices() {
		SELECTORS.forEach( function ( selector ) {
			try {
				var elements = document.querySelectorAll( selector );
				for ( var i = 0; i < elements.length; i++ ) {
					elements[ i ].style.display = 'none';
					elements[ i ].setAttribute( 'aria-hidden', 'true' );
					elements[ i ].setAttribute(
						'data-scrpbr-restricted',
						'true'
					);
				}
			} catch ( e ) {
				// Silently skip invalid selectors.
			}
		} );
	}

	// ── Run immediately (in case elements already exist). ────────────────

	hideRestrictedPrices();

	// ── Run again on DOMContentLoaded if we loaded early. ────────────────

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', hideRestrictedPrices );
	}

	// ── MutationObserver for dynamically injected elements. ──────────────

	var target = document.body || document.documentElement;
	if ( ! target ) {
		return;
	}

	var debounceTimer;
	var observer = new MutationObserver( function ( mutations ) {
		var shouldRun = false;

		for ( var i = 0; i < mutations.length; i++ ) {
			if ( mutations[ i ].addedNodes.length > 0 ) {
				shouldRun = true;
				break;
			}
		}

		if ( shouldRun ) {
			// Debounce rapid mutations to avoid excessive DOM queries.
			clearTimeout( debounceTimer );
			debounceTimer = setTimeout( hideRestrictedPrices, 50 );
		}
	} );

	observer.observe( target, {
		childList: true,
		subtree: true,
	} );
} )();
