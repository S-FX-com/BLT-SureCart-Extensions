<?php
/**
 * The Shippo Fulfillment module: wires the order-paid trigger, the
 * Action Scheduler jobs, the REST webhook route, and the module's admin
 * screens together. This is the only file that touches WordPress hooks
 * for this module — everything else is plain, hook-free service classes.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Modules\ShippoFulfillment;

use BLT\SCE\Admin\ReviewQueuePage;
use BLT\SCE\Admin\SettingsPage;
use BLT\SCE\Admin\ShipmentsListTable;
use BLT\SCE\Admin\SiteHealth;
use BLT\SCE\Api\ShippoClient;
use BLT\SCE\Api\SureCartGateway;
use BLT\SCE\Db\ShipmentRepository;
use BLT\SCE\Modules\ModuleInterface;
use BLT\SCE\Rest\ShippoWebhookController;
use BLT\SCE\Support\Logger;
use BLT\SCE\Support\Scheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Module
 */
final class Module implements ModuleInterface {

	const SLUG                    = 'shippo-fulfillment';
	const ENSURE_WEBHOOK_HOOK     = 'blt_sce_shippo_ensure_webhook';
	const RECONCILE_INTERVAL      = 15 * MINUTE_IN_SECONDS;
	const ENSURE_WEBHOOK_INTERVAL = DAY_IN_SECONDS;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Shipment repository.
	 *
	 * @var ShipmentRepository
	 */
	private $repository;

	/**
	 * Label purchaser.
	 *
	 * @var LabelPurchaser
	 */
	private $purchaser;

	/**
	 * Status sync.
	 *
	 * @var StatusSync
	 */
	private $status_sync;

	/**
	 * Review queue.
	 *
	 * @var ReviewQueue
	 */
	private $review_queue;

	/**
	 * Webhook REST controller.
	 *
	 * @var ShippoWebhookController
	 */
	private $webhook_controller;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Shared logger.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;

		$this->repository = new ShipmentRepository();

		$shippo   = new ShippoClient( $logger );
		$surecart = new SureCartGateway();

		$this->purchaser = new LabelPurchaser(
			$this->repository,
			$shippo,
			$surecart,
			new ParcelMapper(),
			new ServiceSelector(),
			new Guardrails(),
			$logger
		);

		$this->status_sync        = new StatusSync( $this->repository, $shippo, $surecart, $logger );
		$this->review_queue       = new ReviewQueue( $this->repository );
		$this->webhook_controller = new ShippoWebhookController( $this->status_sync );
	}

	/**
	 * {@inheritDoc}
	 */
	public function slug() {
		return self::SLUG;
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Shippo Fulfillment', 'blt-surecart-extensions' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Purchases a Shippo shipping label after a SureCart order is paid, writes tracking back to SureCart, and keeps shipment status in sync.', 'blt-surecart-extensions' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function dependencies() {
		return array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function unmet_requirements() {
		$unmet = array();

		if ( ! Scheduler::is_available() ) {
			$unmet[] = __( 'Action Scheduler is not available — label purchasing cannot run.', 'blt-surecart-extensions' );
		}

		if ( '' === SettingsPage::shippo_token() ) {
			$unmet[] = __( 'No Shippo API token is configured yet (Settings → General).', 'blt-surecart-extensions' );
		}

		return $unmet;
	}

	/**
	 * {@inheritDoc}
	 */
	public function boot() {
		add_action( 'surecart/order_updated', array( $this, 'on_order_updated' ), 10, 2 );
		add_action( LabelPurchaser::HOOK_PURCHASE, array( $this->purchaser, 'process' ), 10, 2 );
		add_action( StatusSync::RECONCILE_HOOK, array( $this->status_sync, 'reconcile' ) );
		add_action( self::ENSURE_WEBHOOK_HOOK, array( $this, 'ensure_webhook_registered' ) );

		add_action(
			'init',
			static function () {
				Scheduler::ensure_recurring( StatusSync::RECONCILE_HOOK, self::RECONCILE_INTERVAL );
				Scheduler::ensure_recurring( self::ENSURE_WEBHOOK_HOOK, self::ENSURE_WEBHOOK_INTERVAL );
			}
		);

		add_action( 'rest_api_init', array( $this->webhook_controller, 'register_routes' ) );

		if ( is_admin() ) {
			( new SettingsPage() )->hooks();
			( new ReviewQueuePage( $this->review_queue ) )->hooks();
			( new SiteHealth( $this->repository ) )->hooks();

			add_action( 'admin_menu', array( $this, 'register_shipments_menu' ) );
			add_action( 'admin_init', array( $this, 'handle_shipment_row_action' ) );
		}
	}

	/**
	 * `surecart/order_updated` handler. SureCart has no dedicated
	 * "paid"/"fulfillment needed" hook (tasks/00-discovery.md §A) — this
	 * generic hook fires on every order update, so we check status
	 * ourselves, exactly as SureCart's own documented example does.
	 *
	 * The DB insert (idempotency anchor) happens here, inline — it's a
	 * fast local query, not the HTTP work the "async only" rule is about.
	 * Only a shipment still in its freshly created 'pending' state ever
	 * triggers a new job; anything already quoted/held/purchased/failed
	 * is left alone so repeated order_updated firings (refunds, other
	 * edits) can't re-trigger guardrail holds or re-attempt a failed
	 * purchase.
	 *
	 * @param object $order SureCart order model.
	 * @param object $data  Raw event data (unused).
	 * @return void
	 */
	public function on_order_updated( $order, $data ) {
		unset( $data );

		if ( ! is_object( $order ) || ! isset( $order->status ) || 'paid' !== $order->status ) {
			return;
		}

		$result = $this->repository->find_or_create_for_order( $order->id );

		if ( ! $result['row'] || ShipmentRepository::STATUS_PENDING !== $result['row']->status ) {
			return;
		}

		Scheduler::enqueue(
			LabelPurchaser::HOOK_PURCHASE,
			array(
				'shipment_id' => (int) $result['row']->id,
				'manual'      => false,
			)
		);
	}

	/**
	 * Ensure the account-wide Shippo tracking webhook is registered.
	 * Runs from a low-frequency recurring Action Scheduler job, never
	 * inline in a request.
	 *
	 * @return void
	 */
	public function ensure_webhook_registered() {
		$this->status_sync->ensure_webhook_registered( ShippoWebhookController::callback_url() );
	}

	/**
	 * Register the "Shipments" admin submenu.
	 *
	 * @return void
	 */
	public function register_shipments_menu() {
		add_submenu_page(
			'blt-sce-modules',
			__( 'Shipments', 'blt-surecart-extensions' ),
			__( 'Shipments', 'blt-surecart-extensions' ),
			'manage_options',
			'blt-sce-shipments',
			array( $this, 'render_shipments_page' )
		);
	}

	/**
	 * Render the Shipments screen: the list table, plus a log viewer when
	 * a specific shipment's "View log" action is clicked.
	 *
	 * @return void
	 */
	public function render_shipments_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Shipments', 'blt-surecart-extensions' ) . '</h1>';

		if ( isset( $_GET['blt_sce_view'], $_GET['shipment_id'] ) && 'logs' === $_GET['blt_sce_view'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->render_log_viewer( (int) $_GET['shipment_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$table = new ShipmentsListTable( $this->repository );
		$table->prepare_items();

		echo '<form method="get">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'blt-sce-shipments' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$table->search_box( __( 'Search', 'blt-surecart-extensions' ), 'blt-sce-shipments-search' );
		$table->views();
		$table->display();
		echo '</form></div>';
	}

	/**
	 * Render a read-only log viewer scoped to one shipment.
	 *
	 * @param int $shipment_id Shipment row id.
	 * @return void
	 */
	private function render_log_viewer( $shipment_id ) {
		$row = $this->repository->find_by_id( $shipment_id );

		if ( ! $row ) {
			return;
		}

		$logs = $this->logger->for_shipment( $shipment_id );

		/* translators: %s: SureCart order id */
		echo '<h2>' . esc_html( sprintf( __( 'Log — order %s', 'blt-surecart-extensions' ), $row->surecart_order_id ) ) . '</h2>';

		if ( empty( $logs ) ) {
			echo '<p>' . esc_html__( 'No log entries.', 'blt-surecart-extensions' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time', 'blt-surecart-extensions' ) . '</th><th>' . esc_html__( 'Level', 'blt-surecart-extensions' ) . '</th><th>' . esc_html__( 'Message', 'blt-surecart-extensions' ) . '</th></tr></thead><tbody>';

		foreach ( $logs as $entry ) {
			echo '<tr>';
			echo '<td>' . esc_html( $entry->created_at ) . '</td>';
			echo '<td>' . esc_html( strtoupper( $entry->level ) ) . '</td>';
			echo '<td>' . esc_html( $entry->message );

			if ( ! empty( $entry->context ) ) {
				echo '<br /><code style="white-space:pre-wrap;">' . esc_html( $entry->context ) . '</code>';
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Handle the row actions from the Shipments list table: purchase,
	 * retry, void. All nonce- and capability-checked.
	 *
	 * @return void
	 */
	public function handle_shipment_row_action() {
		if ( ! isset( $_GET['page'], $_GET['blt_sce_action'], $_GET['shipment_id'] ) || 'blt-sce-shipments' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'blt-surecart-extensions' ) );
		}

		$shipment_id = (int) $_GET['shipment_id']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action      = sanitize_key( wp_unslash( $_GET['blt_sce_action'] ) );

		if ( ! in_array( $action, array( 'purchase', 'retry', 'void' ), true ) ) {
			return;
		}

		// The action verb is baked into the nonce action string so a
		// nonce issued for one action (e.g. "retry") can't be replayed
		// against a different action (e.g. "void") on the same shipment.
		check_admin_referer( 'blt_sce_shipment_action_' . $action . '_' . $shipment_id );

		switch ( $action ) {
			case 'purchase':
				$this->review_queue->purchase_now( $shipment_id );
				break;
			case 'retry':
				$this->purchaser->retry( $shipment_id );
				break;
			case 'void':
				$this->purchaser->void( $shipment_id );
				break;
		}

		wp_safe_redirect( remove_query_arg( array( 'blt_sce_action', 'shipment_id', '_wpnonce' ) ) );
		exit;
	}
}
