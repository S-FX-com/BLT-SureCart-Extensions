<?php
/**
 * "Price Restrictions" admin screen: assign WordPress roles to individual
 * SureCart prices. Products/prices are loaded via AJAX (paginated) so the
 * page render itself never blocks on a SureCart API call.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Modules\RestrictPriceByRole;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AdminPage
 */
final class AdminPage {

	const CAPABILITY = 'manage_options';
	const PAGE_SLUG  = 'blt-sce-price-restrictions';
	const NONCE      = 'blt_sce_rpbr_admin';

	/**
	 * Restriction map service.
	 *
	 * @var Restrictions
	 */
	private $restrictions;

	/**
	 * Hook suffix returned by add_submenu_page().
	 *
	 * @var string
	 */
	private $hook_suffix = '';

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
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_blt_sce_rpbr_save_restrictions', array( $this, 'ajax_save_restrictions' ) );
		add_action( 'wp_ajax_blt_sce_rpbr_load_products', array( $this, 'ajax_load_products' ) );
	}

	/**
	 * Register the submenu page under the extensions menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->hook_suffix = add_submenu_page(
			'blt-sce-modules',
			__( 'Price Restrictions', 'blt-surecart-extensions' ),
			__( 'Price Restrictions', 'blt-surecart-extensions' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueue CSS/JS only on this module's admin page.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'blt-sce-rpbr-admin',
			BLT_SCE_URL . 'assets/restrict-price-by-role/admin.css',
			array(),
			BLT_SCE_VERSION
		);

		wp_enqueue_script(
			'blt-sce-rpbr-admin',
			BLT_SCE_URL . 'assets/restrict-price-by-role/admin.js',
			array( 'jquery', 'wp-util' ),
			BLT_SCE_VERSION,
			true
		);

		wp_localize_script(
			'blt-sce-rpbr-admin',
			'scrpbrAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'strings' => array(
					'saving'     => __( 'Saving…', 'blt-surecart-extensions' ),
					'saved'      => __( 'Restrictions saved successfully!', 'blt-surecart-extensions' ),
					'error'      => __( 'Error saving restrictions. Please try again.', 'blt-surecart-extensions' ),
					'loading'    => __( 'Loading products…', 'blt-surecart-extensions' ),
					'noProducts' => __( 'No products found. Make sure you have products with prices in SureCart.', 'blt-surecart-extensions' ),
					'loadError'  => __( 'Error loading products. Please refresh the page.', 'blt-surecart-extensions' ),
				),
			)
		);
	}

	/**
	 * Return all WordPress roles as slug => translated name.
	 *
	 * @return array<string, string>
	 */
	private function get_wp_roles() {
		$result = array();

		foreach ( wp_roles()->get_names() as $slug => $name ) {
			$result[ $slug ] = translate_user_role( $name );
		}

		return $result;
	}

	/**
	 * Format a SureCart amount (integer in smallest currency unit) for display.
	 *
	 * @param int    $amount   Amount in smallest currency unit.
	 * @param string $currency ISO 4217 currency code.
	 * @return string
	 */
	private function format_amount( $amount, $currency = 'USD' ) {
		$zero_decimal = array(
			'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW',
			'MGA', 'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF',
			'XOF', 'XPF',
		);

		if ( in_array( strtoupper( $currency ), $zero_decimal, true ) ) {
			$formatted = number_format( $amount, 0 );
		} else {
			$formatted = number_format( $amount / 100, 2 );
		}

		return strtoupper( $currency ) . ' ' . $formatted;
	}

	/**
	 * Output the settings page markup (data is loaded via AJAX).
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$roles = $this->get_wp_roles();
		?>
		<div class="wrap scrpbr-wrap">
			<h1><?php esc_html_e( 'Restrict Price by User Role', 'blt-surecart-extensions' ); ?></h1>

			<p class="description">
				<?php esc_html_e( 'Select which user roles can access each product price. Prices with no roles selected are available to everyone (including guests).', 'blt-surecart-extensions' ); ?>
			</p>

			<div id="scrpbr-notices"></div>

			<div id="scrpbr-loading" class="scrpbr-loading">
				<span class="spinner is-active"></span>
				<span><?php esc_html_e( 'Loading products and prices…', 'blt-surecart-extensions' ); ?></span>
			</div>

			<div id="scrpbr-products-container" style="display:none;">
				<form id="scrpbr-form">
					<div id="scrpbr-products-list"></div>

					<p class="submit">
						<button type="submit" class="button button-primary" id="scrpbr-save-btn">
							<?php esc_html_e( 'Save Restrictions', 'blt-surecart-extensions' ); ?>
						</button>
					</p>
				</form>
			</div>
		</div>

		<script type="text/html" id="tmpl-scrpbr-product">
			<div class="scrpbr-product-card">
				<h3 class="scrpbr-product-name">{{ data.name }}</h3>

				<table class="widefat scrpbr-price-table">
					<thead>
						<tr>
							<th class="scrpbr-price-col"><?php esc_html_e( 'Price', 'blt-surecart-extensions' ); ?></th>
							<?php foreach ( $roles as $slug => $name ) : ?>
								<th class="scrpbr-role-col"><?php echo esc_html( $name ); ?></th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<# _.each( data.prices, function( price ) { #>
							<tr>
								<td class="scrpbr-price-col">
									<strong>{{ price.name || '<?php echo esc_js( __( 'Default Price', 'blt-surecart-extensions' ) ); ?>' }}</strong><br>
									<span class="scrpbr-price-amount">{{ price.display_amount }}</span>
									<# if ( price.recurring ) { #>
										<span class="scrpbr-recurring-badge"><?php esc_html_e( 'Recurring', 'blt-surecart-extensions' ); ?></span>
									<# } #>
								</td>

								<?php foreach ( $roles as $slug => $name ) : ?>
									<td class="scrpbr-role-col">
										<input
											type="checkbox"
											name="restrictions[{{ price.id }}][]"
											value="<?php echo esc_attr( $slug ); ?>"
											<# if ( price.allowed_roles && price.allowed_roles.indexOf( '<?php echo esc_js( $slug ); ?>' ) !== -1 ) { #>checked<# } #>
										/>
									</td>
								<?php endforeach; ?>
							</tr>
						<# }); #>
					</tbody>
				</table>
			</div>
		</script>
		<?php
	}

	/**
	 * Return paginated products (with nested prices) as JSON.
	 *
	 * @return void
	 */
	public function ajax_load_products() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'blt-surecart-extensions' ) );
		}

		$page = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
		$map  = $this->restrictions->get_map();

		try {
			$response = \SureCart\Models\Product::with( array( 'prices' ) )
				->where( array( 'archived' => false ) )
				->paginate(
					array(
						'per_page' => 50,
						'page'     => $page,
					)
				);

			if ( is_wp_error( $response ) ) {
				wp_send_json_error( $response->get_error_message() );
			}

			$products = array();

			foreach ( $response['data'] as $product ) {
				$prices = array();

				if ( ! empty( $product->prices->data ) ) {
					foreach ( $product->prices->data as $price ) {
						$amount   = isset( $price->amount ) ? $price->amount : 0;
						$currency = isset( $price->currency ) ? $price->currency : 'usd';

						$prices[] = array(
							'id'             => $price->id,
							'name'           => isset( $price->name ) ? $price->name : '',
							'amount'         => $amount,
							'display_amount' => $this->format_amount( $amount, $currency ),
							'currency'       => strtoupper( $currency ),
							'recurring'      => ! empty( $price->recurring_interval ),
							'allowed_roles'  => isset( $map[ $price->id ] ) ? $map[ $price->id ] : array(),
						);
					}
				}

				// Only include products that actually have prices.
				if ( ! empty( $prices ) ) {
					$products[] = array(
						'id'     => $product->id,
						'name'   => $product->name,
						'prices' => $prices,
					);
				}
			}

			wp_send_json_success(
				array(
					'products'   => $products,
					'pagination' => isset( $response['pagination'] ) ? $response['pagination'] : null,
				)
			);
		} catch ( \Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	/**
	 * Persist the restriction map sent from the admin form.
	 *
	 * @return void
	 */
	public function ajax_save_restrictions() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'blt-surecart-extensions' ) );
		}

		$map = array();

		if ( ! empty( $_POST['restrictions'] ) && is_array( $_POST['restrictions'] ) ) {
			$valid_roles = array_keys( wp_roles()->get_names() );

			foreach ( wp_unslash( $_POST['restrictions'] ) as $price_id => $roles ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field-by-field below.
				$price_id = sanitize_text_field( $price_id );

				$sanitized_roles = array();
				if ( is_array( $roles ) ) {
					foreach ( $roles as $role ) {
						$role = sanitize_text_field( $role );
						// Only accept known WordPress roles.
						if ( in_array( $role, $valid_roles, true ) ) {
							$sanitized_roles[] = $role;
						}
					}
				}

				if ( ! empty( $sanitized_roles ) ) {
					$map[ $price_id ] = $sanitized_roles;
				}
			}
		}

		$this->restrictions->save_map( $map );

		wp_send_json_success( __( 'Restrictions saved successfully.', 'blt-surecart-extensions' ) );
	}
}
