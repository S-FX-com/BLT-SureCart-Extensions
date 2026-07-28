<?php
/**
 * Frontend price restriction handling. Defence-in-depth:
 *   Layer 1 – Block render filter: strips restricted price-choice elements
 *             from server-rendered HTML before it reaches the browser.
 *   Layer 2 – Inline CSS: immediately hides restricted web-component
 *             elements to prevent a flash of restricted content.
 *   Layer 3 – JavaScript MutationObserver: catches dynamically injected
 *             price elements created after initial page render.
 * Layer 4 (server-side checkout validation) lives in CheckoutValidator
 * and acts as the final gate.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Modules\RestrictPriceByRole;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Frontend
 */
final class Frontend {

	/**
	 * Restriction map service.
	 *
	 * @var Restrictions
	 */
	private $restrictions;

	/**
	 * Constructor.
	 *
	 * @param Restrictions $restrictions Restriction map service.
	 */
	public function __construct( Restrictions $restrictions ) {
		$this->restrictions = $restrictions;
	}

	/**
	 * Register WP hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_filter( 'render_block', array( $this, 'filter_price_block' ), 10, 2 );
	}

	/**
	 * Enqueue the frontend JS and inject inline CSS for restricted prices.
	 *
	 * @return void
	 */
	public function enqueue_frontend_assets() {
		$restricted_ids = $this->restrictions->restricted_price_ids();

		if ( empty( $restricted_ids ) ) {
			return;
		}

		// Layer 3: JS-based MutationObserver hiding.
		wp_enqueue_script(
			'blt-sce-rpbr-frontend',
			BLT_SCE_URL . 'assets/restrict-price-by-role/frontend.js',
			array(),
			BLT_SCE_VERSION,
			true
		);

		wp_localize_script(
			'blt-sce-rpbr-frontend',
			'scrpbrFrontend',
			array(
				'restrictedPriceIds' => $restricted_ids,
			)
		);

		// Layer 2: Inline CSS that fires before JS is parsed.
		$css = '';
		foreach ( $restricted_ids as $price_id ) {
			$escaped = esc_attr( $price_id );
			$css    .= sprintf(
				'sc-price-choice[price-id="%1$s"],' .
				'sc-price-choice[value="%1$s"],' .
				'[data-price-id="%1$s"]' .
				'{display:none!important}' . "\n",
				$escaped
			);
		}

		wp_register_style( 'blt-sce-rpbr-frontend-inline', false, array(), BLT_SCE_VERSION );
		wp_enqueue_style( 'blt-sce-rpbr-frontend-inline' );
		wp_add_inline_style( 'blt-sce-rpbr-frontend-inline', $css );
	}

	/**
	 * Intercept SureCart price-related blocks and remove restricted prices.
	 *
	 * @param string $block_content Rendered HTML of the block.
	 * @param array  $block         Block data (blockName, attrs, innerBlocks…).
	 * @return string Possibly modified HTML.
	 */
	public function filter_price_block( $block_content, $block ) {
		$target_blocks = array(
			'surecart/price-choice',
			'surecart/price-choices',
			'surecart/product-price',
			'surecart/product-price-choices',
		);

		if ( empty( $block['blockName'] ) || ! in_array( $block['blockName'], $target_blocks, true ) ) {
			return $block_content;
		}

		$restricted_ids = $this->restrictions->restricted_price_ids();

		if ( empty( $restricted_ids ) ) {
			return $block_content;
		}

		if ( 'surecart/price-choice' === $block['blockName'] ) {
			return $this->maybe_remove_single_price_block( $block_content, $block, $restricted_ids );
		}

		if ( in_array( $block['blockName'], array( 'surecart/price-choices', 'surecart/product-price-choices' ), true ) ) {
			return $this->strip_restricted_elements( $block_content, $restricted_ids );
		}

		return $block_content;
	}

	/**
	 * Return empty string if this single price-choice block is restricted.
	 *
	 * @param string $html           Block HTML.
	 * @param array  $block          Block data.
	 * @param array  $restricted_ids Restricted price IDs.
	 * @return string
	 */
	private function maybe_remove_single_price_block( $html, $block, $restricted_ids ) {
		$price_id = '';

		// Try block attributes first.
		if ( ! empty( $block['attrs']['priceId'] ) ) {
			$price_id = $block['attrs']['priceId'];
		} elseif ( ! empty( $block['attrs']['price_id'] ) ) {
			$price_id = $block['attrs']['price_id'];
		} elseif ( ! empty( $block['attrs']['price'] ) ) {
			$price_id = $block['attrs']['price'];
		}

		// Fallback: parse price-id from rendered HTML.
		if ( empty( $price_id ) && preg_match( '/price-id=["\']([^"\']+)["\']/', $html, $m ) ) {
			$price_id = $m[1];
		}

		if ( $price_id && in_array( $price_id, $restricted_ids, true ) ) {
			return '';
		}

		return $html;
	}

	/**
	 * Parse HTML and remove any sc-price-choice elements whose price-id
	 * or value attribute matches a restricted price.
	 *
	 * @param string $html           Block HTML.
	 * @param array  $restricted_ids Restricted price IDs.
	 * @return string Cleaned HTML.
	 */
	private function strip_restricted_elements( $html, $restricted_ids ) {
		foreach ( $restricted_ids as $price_id ) {
			$escaped = preg_quote( $price_id, '/' );

			$patterns = array(
				// <sc-price-choice … price-id="xxx" …>…</sc-price-choice>
				'/<sc-price-choice\b[^>]*\b(?:price-id|value)\s*=\s*["\']' . $escaped . '["\'][^>]*>.*?<\/sc-price-choice>/is',
				// Self-closing variant.
				'/<sc-price-choice\b[^>]*\b(?:price-id|value)\s*=\s*["\']' . $escaped . '["\'][^>]*\/?\s*>/is',
			);

			foreach ( $patterns as $pattern ) {
				$html = preg_replace( $pattern, '', $html );
			}
		}

		return $html;
	}
}
