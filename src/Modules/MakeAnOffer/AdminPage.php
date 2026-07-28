<?php
/**
 * Make an Offer admin: the Offers screen (list + detail + accept/
 * decline/counter actions), the module settings screen, and the
 * admin-bar pending-count badge. All server-rendered, no build step.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Modules\MakeAnOffer;

use BLT\SCE\Support\Money;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AdminPage
 */
final class AdminPage {

	const CAPABILITY     = 'manage_options';
	const PAGE_OFFERS    = 'blt-sce-offers';
	const PAGE_SETTINGS  = 'blt-sce-offer-settings';
	const ACTION_OFFER   = 'blt_sce_offer_action';
	const ACTION_DETECT  = 'blt_sce_offer_detect_stripe';
	const NONCE_SETTINGS = 'blt_sce_offer_save_settings';

	/**
	 * Repository.
	 *
	 * @var OfferRepository
	 */
	private $repository;

	/**
	 * Lifecycle orchestration.
	 *
	 * @var OfferManager
	 */
	private $manager;

	/**
	 * Constructor.
	 *
	 * @param OfferRepository $repository Offer repository.
	 * @param OfferManager    $manager    Lifecycle orchestration.
	 */
	public function __construct( OfferRepository $repository, OfferManager $manager ) {
		$this->repository = $repository;
		$this->manager    = $manager;
	}

	/**
	 * Register WP hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_post_' . self::ACTION_OFFER, array( $this, 'handle_offer_action' ) );
		add_action( 'admin_post_' . self::ACTION_DETECT, array( $this, 'handle_detect_stripe' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_badge' ), 90 );
	}

	/**
	 * Nonce-signed admin-post URL for one action verb on one offer. The
	 * verb is baked into the nonce action so a nonce for "accept" can't
	 * be replayed as "decline" (same pattern as the Shipments screen).
	 *
	 * @param int    $offer_id Offer post ID.
	 * @param string $verb     accept|decline|counter.
	 * @return string
	 */
	public static function action_url( $offer_id, $verb ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => self::ACTION_OFFER,
					'offer_id' => (int) $offer_id,
					'verb'     => $verb,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION_OFFER . '_' . $verb . '_' . (int) $offer_id
		);
	}

	/**
	 * Register the Offers and Offer Settings submenus.
	 *
	 * @return void
	 */
	public function register_menus() {
		$pending = $this->repository->pending_count();
		$label   = __( 'Offers', 'blt-surecart-extensions' );

		if ( $pending > 0 ) {
			$label .= sprintf( ' <span class="awaiting-mod count-%1$d"><span class="pending-count">%1$d</span></span>', $pending );
		}

		add_submenu_page(
			'blt-sce-modules',
			__( 'Offers', 'blt-surecart-extensions' ),
			$label,
			self::CAPABILITY,
			self::PAGE_OFFERS,
			array( $this, 'render_offers_page' )
		);

		add_submenu_page(
			'blt-sce-modules',
			__( 'Make an Offer Settings', 'blt-surecart-extensions' ),
			__( 'Offer Settings', 'blt-surecart-extensions' ),
			self::CAPABILITY,
			self::PAGE_SETTINGS,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Admin-bar badge with the pending offer count.
	 *
	 * @param \WP_Admin_Bar $bar Admin bar.
	 * @return void
	 */
	public function admin_bar_badge( $bar ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$pending = $this->repository->pending_count();

		if ( $pending < 1 ) {
			return;
		}

		$bar->add_node(
			array(
				'id'    => 'blt-sce-offers',
				'title' => sprintf(
					/* translators: %d: number of pending offers */
					_n( '%d offer', '%d offers', $pending, 'blt-surecart-extensions' ),
					$pending
				),
				'href'  => add_query_arg( array( 'page' => self::PAGE_OFFERS ), admin_url( 'admin.php' ) ),
			)
		);
	}

	// ─── Offers screen ───────────────────────────────────────────────────

	/**
	 * Render the Offers screen: detail view when an offer is selected,
	 * the list table otherwise.
	 *
	 * @return void
	 */
	public function render_offers_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Offers', 'blt-surecart-extensions' ) . '</h1>';

		if ( isset( $_GET['blt_sce_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->render_notice( sanitize_key( wp_unslash( $_GET['blt_sce_notice'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$offer_id = isset( $_GET['offer'] ) ? (int) $_GET['offer'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $offer_id > 0 ) {
			$this->render_detail( $offer_id );
			echo '</div>';

			return;
		}

		$table = new OffersListTable( $this->repository );
		$table->prepare_items();
		$table->views();
		$table->display();

		echo '</div>';
	}

	/**
	 * Map a notice key from the redirect back to a rendered notice.
	 *
	 * @param string $key Notice key.
	 * @return void
	 */
	private function render_notice( $key ) {
		$notices = array(
			'accept_queued'  => array( 'success', __( 'Acceptance queued — the card will be charged in the background and the offer will flip to Accepted once the payment succeeds.', 'blt-surecart-extensions' ) ),
			'declined'       => array( 'success', __( 'Offer declined and the customer notified.', 'blt-surecart-extensions' ) ),
			'countered'      => array( 'success', __( 'Counter-offer sent to the customer.', 'blt-surecart-extensions' ) ),
			'error'          => array( 'error', __( 'The action could not be completed.', 'blt-surecart-extensions' ) ),
			'stripe_found'   => array( 'success', __( 'Stripe details detected from SureCart and saved.', 'blt-surecart-extensions' ) ),
			'stripe_missing' => array( 'error', __( 'Could not detect Stripe details from SureCart — enter them manually below.', 'blt-surecart-extensions' ) ),
		);

		if ( ! isset( $notices[ $key ] ) ) {
			return;
		}

		// A specific error message may have been stashed by the redirect.
		$message = $notices[ $key ][1];

		if ( 'error' === $key ) {
			$detail = get_transient( 'blt_sce_offer_admin_error_' . get_current_user_id() );

			if ( $detail ) {
				$message = $detail;
				delete_transient( 'blt_sce_offer_admin_error_' . get_current_user_id() );
			}
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $notices[ $key ][0] ),
			esc_html( $message )
		);
	}

	/**
	 * Render one offer's full detail + action buttons.
	 *
	 * @param int $offer_id Offer post ID.
	 * @return void
	 */
	private function render_detail( $offer_id ) {
		$offer = $this->repository->find( $offer_id );

		if ( ! $offer ) {
			echo '<p>' . esc_html__( 'Offer not found.', 'blt-surecart-extensions' ) . '</p>';

			return;
		}

		$currency = strtoupper( $offer->currency ? $offer->currency : 'USD' );
		$money    = static function ( $cents ) use ( $currency ) {
			return $currency . ' ' . Money::cents_to_decimal_string( $cents );
		};

		printf(
			'<p><a href="%s">&larr; %s</a></p>',
			esc_url( add_query_arg( array( 'page' => self::PAGE_OFFERS ), admin_url( 'admin.php' ) ) ),
			esc_html__( 'Back to all offers', 'blt-surecart-extensions' )
		);

		/* translators: %d: offer ID */
		echo '<h2>' . esc_html( sprintf( __( 'Offer #%d', 'blt-surecart-extensions' ), $offer->id ) ) . '</h2>';

		if ( '' !== $offer->capture_error ) {
			echo '<div class="notice notice-error"><p>' . esc_html(
				sprintf(
					/* translators: %s: Stripe error message */
					__( 'The last charge attempt failed: %s', 'blt-surecart-extensions' ),
					$offer->capture_error
				)
			) . '</p></div>';
		}

		$rows = array(
			__( 'Status', 'blt-surecart-extensions' )         => OfferPostType::status_label( $offer->status ) . ( $offer->pm_confirmed ? '' : ' — ' . __( 'awaiting card', 'blt-surecart-extensions' ) ),
			__( 'Customer', 'blt-surecart-extensions' )       => trim( $offer->customer_name . ' <' . $offer->customer_email . '>' ),
			__( 'Product', 'blt-surecart-extensions' )        => $offer->product_name . ' (' . $offer->product_id . ')',
			__( 'Offer amount', 'blt-surecart-extensions' )   => $money( $offer->amount ),
			__( 'List price', 'blt-surecart-extensions' )     => $money( $offer->list_price ),
			__( '% of list', 'blt-surecart-extensions' )      => $offer->list_price > 0 ? round( $offer->amount * 100 / $offer->list_price ) . '%' : '—',
			__( 'Counter amount', 'blt-surecart-extensions' ) => $offer->counter_amount ? $money( $offer->counter_amount ) : '—',
			__( 'Message', 'blt-surecart-extensions' )        => $offer->message ? $offer->message : '—',
			__( 'Submitted', 'blt-surecart-extensions' )      => get_date_from_gmt( $offer->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
			__( 'Expires', 'blt-surecart-extensions' )        => $offer->expires_at ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $offer->expires_at ) : '—',
			__( 'Stripe PaymentIntent', 'blt-surecart-extensions' ) => $offer->stripe_pi_id ? $offer->stripe_pi_id : '—',
		);

		echo '<table class="widefat striped" style="max-width:720px;"><tbody>';

		foreach ( $rows as $label => $value ) {
			echo '<tr><th style="width:200px;">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}

		echo '</tbody></table>';

		$open = in_array( $offer->status, array( OfferPostType::STATUS_PENDING, OfferPostType::STATUS_COUNTERED ), true );

		if ( ! $open || ! $offer->pm_confirmed ) {
			return;
		}

		echo '<h3>' . esc_html__( 'Actions', 'blt-surecart-extensions' ) . '</h3>';

		printf(
			'<p><a href="%s" class="button button-primary">%s</a> <a href="%s" class="button" onclick="return confirm(%s);">%s</a></p>',
			esc_url( self::action_url( $offer->id, 'accept' ) ),
			'' !== $offer->capture_error ? esc_html__( 'Retry charge', 'blt-surecart-extensions' ) : esc_html__( 'Accept & charge card', 'blt-surecart-extensions' ),
			esc_url( self::action_url( $offer->id, 'decline' ) ),
			esc_attr( wp_json_encode( __( 'Decline this offer? The customer will be notified.', 'blt-surecart-extensions' ) ) ),
			esc_html__( 'Decline', 'blt-surecart-extensions' )
		);

		if ( Settings::get( 'allow_counter' ) && OfferPostType::STATUS_PENDING === $offer->status ) {
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
				<?php wp_nonce_field( self::ACTION_OFFER . '_counter_' . $offer->id ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_OFFER ); ?>" />
				<input type="hidden" name="offer_id" value="<?php echo esc_attr( $offer->id ); ?>" />
				<input type="hidden" name="verb" value="counter" />
				<label>
					<?php esc_html_e( 'Counter at', 'blt-surecart-extensions' ); ?>
					<input type="number" name="counter_amount" min="0.01" step="0.01" placeholder="0.00" required />
				</label>
				<?php submit_button( __( 'Send counter-offer', 'blt-surecart-extensions' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php
		}
	}

	/**
	 * Handle accept/decline/counter from the Offers screen.
	 *
	 * @return void
	 */
	public function handle_offer_action() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'blt-surecart-extensions' ) );
		}

		$offer_id = isset( $_REQUEST['offer_id'] ) ? (int) $_REQUEST['offer_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$verb     = isset( $_REQUEST['verb'] ) ? sanitize_key( wp_unslash( $_REQUEST['verb'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $verb, array( 'accept', 'decline', 'counter' ), true ) ) {
			wp_die( esc_html__( 'Unknown action.', 'blt-surecart-extensions' ) );
		}

		check_admin_referer( self::ACTION_OFFER . '_' . $verb . '_' . $offer_id );

		switch ( $verb ) {
			case 'accept':
				$result = $this->manager->request_acceptance( $offer_id );
				$notice = 'accept_queued';
				break;
			case 'decline':
				$result = $this->manager->decline( $offer_id );
				$notice = 'declined';
				break;
			default:
				$cents  = isset( $_POST['counter_amount'] ) ? Money::decimal_string_to_cents( sanitize_text_field( wp_unslash( $_POST['counter_amount'] ) ) ) : 0;
				$result = $this->manager->counter( $offer_id, $cents );
				$notice = 'countered';
				break;
		}

		if ( is_wp_error( $result ) ) {
			set_transient( 'blt_sce_offer_admin_error_' . get_current_user_id(), $result->get_error_message(), MINUTE_IN_SECONDS );
			$notice = 'error';
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => self::PAGE_OFFERS,
					'offer'          => $offer_id,
					'blt_sce_notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	// ─── Settings screen ─────────────────────────────────────────────────

	/**
	 * Render (and save) the module settings.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$notice = '';

		if ( isset( $_POST['blt_sce_offer_settings'] ) && check_admin_referer( self::NONCE_SETTINGS ) ) {
			$notice = $this->save_settings();
		}

		$settings   = Settings::all();
		$key_locked = Settings::secret_key_is_constant_defined();

		echo '<div class="wrap"><h1>' . esc_html__( 'Make an Offer Settings', 'blt-surecart-extensions' ) . '</h1>';

		if ( $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
		}

		if ( isset( $_GET['blt_sce_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->render_notice( sanitize_key( wp_unslash( $_GET['blt_sce_notice'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		?>
		<form method="post">
			<?php wp_nonce_field( self::NONCE_SETTINGS ); ?>
			<input type="hidden" name="blt_sce_offer_settings" value="1" />

			<h2><?php esc_html_e( 'Offer rules', 'blt-surecart-extensions' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="expiry_days"><?php esc_html_e( 'Offer expiry (days)', 'blt-surecart-extensions' ); ?></label></th>
					<td>
						<input type="number" min="1" step="1" name="expiry_days" id="expiry_days" value="<?php echo esc_attr( $settings['expiry_days'] ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'Days before a pending offer auto-expires.', 'blt-surecart-extensions' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="min_pct"><?php esc_html_e( 'Minimum offer (% of list price)', 'blt-surecart-extensions' ); ?></label></th>
					<td>
						<input type="number" min="0" max="100" step="1" name="min_pct" id="min_pct" value="<?php echo esc_attr( $settings['min_pct'] ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'Offers below this percentage are rejected at submission. 0 disables the check.', 'blt-surecart-extensions' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="auto_accept_pct"><?php esc_html_e( 'Auto-accept threshold (% of list price)', 'blt-surecart-extensions' ); ?></label></th>
					<td>
						<input type="number" min="0" max="100" step="1" name="auto_accept_pct" id="auto_accept_pct" value="<?php echo esc_attr( $settings['auto_accept_pct'] ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'Offers at or above this percentage are accepted and charged automatically, with no merchant review. 0 (recommended default) disables auto-accept.', 'blt-surecart-extensions' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Counter-offers', 'blt-surecart-extensions' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="allow_counter" value="1" <?php checked( ! empty( $settings['allow_counter'] ) ); ?> />
							<?php esc_html_e( 'Allow sending counter-offers.', 'blt-surecart-extensions' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Repeat offers', 'blt-surecart-extensions' ); ?></th>
					<td>
						<select name="resubmit_policy">
							<option value="reject" <?php selected( $settings['resubmit_policy'], 'reject' ); ?>><?php esc_html_e( 'Reject — one open offer per customer per product', 'blt-surecart-extensions' ); ?></option>
							<option value="supersede" <?php selected( $settings['resubmit_policy'], 'supersede' ); ?>><?php esc_html_e( 'Supersede — a new offer replaces the open one', 'blt-surecart-extensions' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="notify_email"><?php esc_html_e( 'Merchant notification email', 'blt-surecart-extensions' ); ?></label></th>
					<td>
						<input type="email" name="notify_email" id="notify_email" value="<?php echo esc_attr( $settings['notify_email'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_bloginfo( 'admin_email' ) ); ?>" />
						<p class="description"><?php esc_html_e( 'Leave blank to use the site admin email.', 'blt-surecart-extensions' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Stripe', 'blt-surecart-extensions' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Offers vault the customer\'s card with Stripe and charge it on acceptance. Charges are made directly through this Stripe account — an accepted offer does not create a SureCart order.', 'blt-surecart-extensions' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="stripe_secret_key"><?php esc_html_e( 'Secret key', 'blt-surecart-extensions' ); ?></label></th>
					<td>
						<?php if ( $key_locked ) : ?>
							<input type="text" class="regular-text" value="<?php echo esc_attr( str_repeat( '•', 8 ) ); ?>" disabled />
							<p class="description"><?php esc_html_e( 'Defined via the BLT_SCE_STRIPE_SECRET_KEY constant in wp-config.php — remove the constant to manage it here instead.', 'blt-surecart-extensions' ); ?></p>
						<?php else : ?>
							<input type="password" autocomplete="new-password" name="stripe_secret_key" id="stripe_secret_key" class="regular-text" value="" placeholder="<?php echo '' === $settings['stripe_secret_key'] ? esc_attr__( 'sk_test_… / sk_live_…', 'blt-surecart-extensions' ) : esc_attr__( 'Currently set — leave blank to keep it', 'blt-surecart-extensions' ); ?>" />
							<p class="description"><?php esc_html_e( 'Stored server-side only; never rendered back into this page. For production, prefer defining BLT_SCE_STRIPE_SECRET_KEY in wp-config.php instead.', 'blt-surecart-extensions' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="stripe_publishable_key"><?php esc_html_e( 'Publishable key', 'blt-surecart-extensions' ); ?></label></th>
					<td>
						<input type="text" name="stripe_publishable_key" id="stripe_publishable_key" class="regular-text" value="<?php echo esc_attr( $settings['stripe_publishable_key'] ); ?>" placeholder="pk_test_… / pk_live_…" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="stripe_account_id"><?php esc_html_e( 'Connected account ID (optional)', 'blt-surecart-extensions' ); ?></label></th>
					<td>
						<input type="text" name="stripe_account_id" id="stripe_account_id" class="regular-text" value="<?php echo esc_attr( $settings['stripe_account_id'] ); ?>" placeholder="acct_…" />
						<p class="description"><?php esc_html_e( 'Only needed when the keys above belong to a platform account and charges should run on a connected account (e.g. SureCart\'s connected Stripe account).', 'blt-surecart-extensions' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::ACTION_DETECT ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_DETECT ); ?>" />
			<p>
				<?php submit_button( __( 'Detect publishable key / account from SureCart', 'blt-surecart-extensions' ), 'secondary', 'submit', false ); ?>
			</p>
			<p class="description"><?php esc_html_e( 'Attempts to read the Stripe publishable key and connected account ID from the SureCart store configuration. The secret key can never be auto-detected — it comes from your own Stripe dashboard.', 'blt-surecart-extensions' ); ?></p>
		</form>
		</div>
		<?php
	}

	/**
	 * Save the settings form.
	 *
	 * @return string Success message.
	 */
	private function save_settings() {
		$values = array(
			'expiry_days'            => isset( $_POST['expiry_days'] ) ? max( 1, (int) $_POST['expiry_days'] ) : 3,
			'min_pct'                => isset( $_POST['min_pct'] ) ? min( 100, max( 0, (int) $_POST['min_pct'] ) ) : 0,
			'auto_accept_pct'        => isset( $_POST['auto_accept_pct'] ) ? min( 100, max( 0, (int) $_POST['auto_accept_pct'] ) ) : 0,
			'allow_counter'          => ! empty( $_POST['allow_counter'] ),
			'resubmit_policy'        => isset( $_POST['resubmit_policy'] ) && 'supersede' === $_POST['resubmit_policy'] ? 'supersede' : 'reject',
			'notify_email'           => isset( $_POST['notify_email'] ) ? sanitize_email( wp_unslash( $_POST['notify_email'] ) ) : '',
			'stripe_publishable_key' => isset( $_POST['stripe_publishable_key'] ) ? sanitize_text_field( wp_unslash( $_POST['stripe_publishable_key'] ) ) : '',
			'stripe_account_id'      => isset( $_POST['stripe_account_id'] ) ? sanitize_text_field( wp_unslash( $_POST['stripe_account_id'] ) ) : '',
		);

		// Like the Shippo token: blank means "keep the current secret."
		if ( ! Settings::secret_key_is_constant_defined() && ! empty( $_POST['stripe_secret_key'] ) ) {
			$values['stripe_secret_key'] = sanitize_text_field( wp_unslash( $_POST['stripe_secret_key'] ) );
		}

		Settings::save( $values );

		return __( 'Settings saved.', 'blt-surecart-extensions' );
	}

	/**
	 * Best-effort detection of Stripe publishable key / connected account
	 * from SureCart's store model. Runs only on an explicit button click
	 * (admin-post), never during a page render, because Store::get() may
	 * call the SureCart API synchronously. Feature-detected — SureCart's
	 * PHP models are not officially documented, so missing properties
	 * degrade to "not found" rather than fataling.
	 *
	 * @return void
	 */
	public function handle_detect_stripe() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'blt-surecart-extensions' ) );
		}

		check_admin_referer( self::ACTION_DETECT );

		$found = array();

		if ( class_exists( '\SureCart\Models\Store' ) ) {
			try {
				$store = \SureCart\Models\Store::get();

				if ( $store && ! is_wp_error( $store ) ) {
					if ( ! empty( $store->stripe_publishable_key ) && is_string( $store->stripe_publishable_key ) ) {
						$found['stripe_publishable_key'] = sanitize_text_field( $store->stripe_publishable_key );
					}

					if ( ! empty( $store->stripe_account_id ) && is_string( $store->stripe_account_id ) ) {
						$found['stripe_account_id'] = sanitize_text_field( $store->stripe_account_id );
					}
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Fall through to "not found".
			}
		}

		if ( $found ) {
			Settings::save( $found );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => self::PAGE_SETTINGS,
					'blt_sce_notice' => $found ? 'stripe_found' : 'stripe_missing',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
