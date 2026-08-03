<?php
/**
 * The Reports admin screen: request a fulfillment report, watch it build,
 * download the CSV.
 *
 * Server-rendered, no build step, same as every other admin screen here.
 * Nothing on this page makes a SureCart call — the product picker reads
 * ProductIndex's cache and the report itself is built by a background job
 * (rule 1).
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Modules\Reports;

use BLT\SCE\Db\ReportRepository;
use BLT\SCE\Support\Scheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AdminPage
 */
final class AdminPage {

	const CAPABILITY   = 'manage_options';
	const PAGE_SLUG    = 'blt-sce-reports';
	const NONCE_CREATE = 'blt_sce_create_report';
	const NONCE_MANAGE = 'blt_sce_manage_report';

	/**
	 * Guard transient so a cold-cache page view enqueues one refresh job,
	 * not one per page load.
	 */
	const REFRESH_GUARD = 'blt_sce_reports_refresh_pending';

	/**
	 * Report index repository.
	 *
	 * @var ReportRepository
	 */
	private $repository;

	/**
	 * File storage.
	 *
	 * @var ReportStorage
	 */
	private $storage;

	/**
	 * Product picker cache.
	 *
	 * @var ProductIndex
	 */
	private $products;

	/**
	 * Constructor.
	 *
	 * @param ReportRepository $repository Report index repository.
	 * @param ReportStorage    $storage    File storage.
	 * @param ProductIndex     $products   Product picker cache.
	 */
	public function __construct( ReportRepository $repository, ReportStorage $storage, ProductIndex $products ) {
		$this->repository = $repository;
		$this->storage    = $storage;
		$this->products   = $products;
	}

	/**
	 * Register WP hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_blt_sce_create_report', array( $this, 'handle_create' ) );
		add_action( 'admin_post_blt_sce_download_report', array( $this, 'handle_download' ) );
		add_action( 'admin_post_blt_sce_delete_report', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_blt_sce_refresh_report_products', array( $this, 'handle_refresh_products' ) );
	}

	/**
	 * Register the Reports submenu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'blt-sce-modules',
			__( 'Reports', 'blt-surecart-extensions' ),
			__( 'Reports', 'blt-surecart-extensions' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Order statuses offered as report filters, with labels. Values are
	 * SureCart's own documented order status enum (discovery §H) — `draft`
	 * and `payment_failed` are deliberately omitted, since neither
	 * represents something to manufacture or ship.
	 *
	 * @return array<string,string>
	 */
	private function status_choices() {
		return array(
			'paid'       => __( 'Paid', 'blt-surecart-extensions' ),
			'processing' => __( 'Processing', 'blt-surecart-extensions' ),
		);
	}

	/**
	 * Fulfillment-status presets, keyed by the value stored in params.
	 *
	 * `remaining_only` decides which quantity the report counts. A preset
	 * about work still to do counts the unfulfilled remainder, so a
	 * partially shipped order doesn't ask for the shipped units a second
	 * time; a preset about what was bought counts the ordered quantity.
	 *
	 * @return array<string,array{label: string, statuses: string[], remaining_only: bool}>
	 */
	private function fulfillment_choices() {
		return array(
			'any'         => array(
				'label'          => __( 'Any — everything ordered in the period', 'blt-surecart-extensions' ),
				'statuses'       => array(),
				'remaining_only' => false,
			),
			'outstanding' => array(
				'label'          => __( 'Outstanding — unfulfilled and partially fulfilled (counts units still to ship)', 'blt-surecart-extensions' ),
				'statuses'       => array( 'unfulfilled', 'partially_fulfilled' ),
				'remaining_only' => true,
			),
			'unfulfilled' => array(
				'label'          => __( 'Unfulfilled only (counts units still to ship)', 'blt-surecart-extensions' ),
				'statuses'       => array( 'unfulfilled' ),
				'remaining_only' => true,
			),
			'fulfilled'   => array(
				'label'          => __( 'Already fulfilled only', 'blt-surecart-extensions' ),
				'statuses'       => array( 'fulfilled' ),
				'remaining_only' => false,
			),
		);
	}

	/**
	 * Handle the "generate report" submission.
	 *
	 * @return void
	 */
	public function handle_create() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'blt-surecart-extensions' ) );
		}

		check_admin_referer( self::NONCE_CREATE );

		$start_date = isset( $_POST['start_date'] ) ? $this->sanitize_date( wp_unslash( $_POST['start_date'] ) ) : '';
		$end_date   = isset( $_POST['end_date'] ) ? $this->sanitize_date( wp_unslash( $_POST['end_date'] ) ) : '';

		if ( '' === $start_date || '' === $end_date ) {
			$this->redirect_with( 'err_dates' );
		}

		if ( $start_date > $end_date ) {
			$this->redirect_with( 'err_range' );
		}

		$statuses = array();

		if ( isset( $_POST['statuses'] ) && is_array( $_POST['statuses'] ) ) {
			$allowed  = array_keys( $this->status_choices() );
			$statuses = array_values(
				array_intersect(
					$allowed,
					array_map( 'sanitize_key', $this->scalars( wp_unslash( $_POST['statuses'] ) ) )
				)
			);
		}

		if ( empty( $statuses ) ) {
			$statuses = array( 'paid' );
		}

		$product_ids = array();

		if ( isset( $_POST['product_ids'] ) && is_array( $_POST['product_ids'] ) ) {
			foreach ( $this->scalars( wp_unslash( $_POST['product_ids'] ) ) as $product_id ) {
				$product_id = sanitize_text_field( $product_id );

				if ( '' !== $product_id ) {
					$product_ids[] = $product_id;
				}
			}
		}

		$fulfillment_key     = isset( $_POST['fulfillment'] ) ? sanitize_key( wp_unslash( $_POST['fulfillment'] ) ) : 'any';
		$fulfillment_choices = $this->fulfillment_choices();

		if ( ! isset( $fulfillment_choices[ $fulfillment_key ] ) ) {
			$fulfillment_key = 'any';
		}

		$params = array(
			'start_date'           => $start_date,
			'end_date'             => $end_date,
			'statuses'             => $statuses,
			'product_ids'          => array_values( array_unique( $product_ids ) ),
			'fulfillment'          => $fulfillment_key,
			'fulfillment_statuses' => $fulfillment_choices[ $fulfillment_key ]['statuses'],
			'remaining_only'       => $fulfillment_choices[ $fulfillment_key ]['remaining_only'],
			'include_address'      => ! empty( $_POST['include_address'] ),
		);

		if ( ! Scheduler::is_available() ) {
			$this->redirect_with( 'err_scheduler' );
		}

		$report_id = $this->repository->create( ReportRepository::TYPE_FULFILLMENT, $params, get_current_user_id() );

		if ( ! $report_id ) {
			$this->redirect_with( 'err_create' );
		}

		Scheduler::enqueue( ReportRunner::HOOK_RUN, array( $report_id ) );

		$this->redirect_with( 'queued' );
	}

	/**
	 * Stream a generated CSV to the browser.
	 *
	 * @return void
	 */
	public function handle_download() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'blt-surecart-extensions' ) );
		}

		$report_id = isset( $_GET['report'] ) ? (int) $_GET['report'] : 0;

		check_admin_referer( self::NONCE_MANAGE . '_' . $report_id );

		$row = $this->repository->find( $report_id );

		if ( ! $row || empty( $row['filename'] ) ) {
			wp_die( esc_html__( 'That report could not be found.', 'blt-surecart-extensions' ) );
		}

		$path = $this->storage->path( $row['filename'] );

		if ( is_wp_error( $path ) || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'That report file is no longer on disk. Generate the report again.', 'blt-surecart-extensions' ) );
		}

		// Discard any buffer another plugin left open, so its contents can't
		// be prepended to the CSV and corrupt the file.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $this->download_filename( $row ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Delete a report row and its file.
	 *
	 * @return void
	 */
	public function handle_delete() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'blt-surecart-extensions' ) );
		}

		$report_id = isset( $_POST['report'] ) ? (int) $_POST['report'] : 0;

		check_admin_referer( self::NONCE_MANAGE . '_' . $report_id );

		$row = $this->repository->find( $report_id );

		if ( $row ) {
			if ( ! empty( $row['filename'] ) ) {
				$this->storage->delete( $row['filename'] );
			}

			$this->repository->delete( $report_id );
		}

		$this->redirect_with( 'deleted' );
	}

	/**
	 * Queue a product-list refresh.
	 *
	 * @return void
	 */
	public function handle_refresh_products() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'blt-surecart-extensions' ) );
		}

		check_admin_referer( self::NONCE_CREATE );

		if ( ! Scheduler::is_available() ) {
			$this->redirect_with( 'err_scheduler' );
		}

		Scheduler::enqueue( ReportRunner::HOOK_REFRESH_PRODUCTS );
		set_transient( self::REFRESH_GUARD, 1, 5 * MINUTE_IN_SECONDS );

		$this->redirect_with( 'refreshing' );
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// Cold cache: ask a job to populate the picker. Guarded so repeated
		// page loads don't pile up jobs.
		if ( $this->products->is_cold() && Scheduler::is_available() && ! get_transient( self::REFRESH_GUARD ) ) {
			Scheduler::enqueue( ReportRunner::HOOK_REFRESH_PRODUCTS );
			set_transient( self::REFRESH_GUARD, 1, 5 * MINUTE_IN_SECONDS );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'BLT SureCart Extensions — Reports', 'blt-surecart-extensions' ) . '</h1>';

		$this->render_notice();
		$this->render_form();
		$this->render_list();

		echo '</div>';
	}

	/**
	 * The complete set of notices this screen can show, keyed by the code
	 * that travels in the redirect URL.
	 *
	 * Codes rather than message text, deliberately: passing the message
	 * itself through the query string would let anyone hand an administrator
	 * a link that renders an arbitrary "success" notice in wp-admin.
	 *
	 * @return array<string,array{type: string, message: string}>
	 */
	private function notices() {
		return array(
			'queued'        => array(
				'type'    => 'success',
				'message' => __( 'Report queued. It will appear below once the background job finishes — reload this page to check.', 'blt-surecart-extensions' ),
			),
			'deleted'       => array(
				'type'    => 'success',
				'message' => __( 'Report deleted.', 'blt-surecart-extensions' ),
			),
			'refreshing'    => array(
				'type'    => 'success',
				'message' => __( 'Product list refresh queued. Reload in a moment to see it.', 'blt-surecart-extensions' ),
			),
			'err_dates'     => array(
				'type'    => 'error',
				'message' => __( 'A start date and an end date are both required.', 'blt-surecart-extensions' ),
			),
			'err_range'     => array(
				'type'    => 'error',
				'message' => __( 'The start date must be on or before the end date.', 'blt-surecart-extensions' ),
			),
			'err_scheduler' => array(
				'type'    => 'error',
				'message' => __( 'Action Scheduler is not available, so nothing can be queued.', 'blt-surecart-extensions' ),
			),
			'err_create'    => array(
				'type'    => 'error',
				'message' => __( 'The report could not be queued. Check that the plugin database tables were created.', 'blt-surecart-extensions' ),
			),
		);
	}

	/**
	 * Render whatever notice the redirect asked for.
	 *
	 * @return void
	 */
	private function render_notice() {
		$code = isset( $_GET['blt_sce_notice'] ) ? sanitize_key( wp_unslash( $_GET['blt_sce_notice'] ) ) : '';

		if ( '' === $code ) {
			return;
		}

		$notices = $this->notices();

		if ( ! isset( $notices[ $code ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			'error' === $notices[ $code ]['type'] ? 'error' : 'success',
			esc_html( $notices[ $code ]['message'] )
		);
	}

	/**
	 * Render the report-request form.
	 *
	 * @return void
	 */
	private function render_form() {
		$products     = $this->products->all();
		$refreshed_at = $this->products->refreshed_at();
		$today        = current_time( 'Y-m-d' );
		$month_start  = current_time( 'Y-m-01' );

		echo '<h2>' . esc_html__( 'Fulfillment Report', 'blt-surecart-extensions' ) . '</h2>';
		echo '<p class="description" style="max-width:60em">' . esc_html__( 'Aggregates every order in a date range into one row per customer, with a column for each product variant (for example each shirt size) holding the quantity that customer ordered. A TOTALS row at the bottom gives the per-variant quantities to order from the manufacturer.', 'blt-surecart-extensions' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_CREATE );
		echo '<input type="hidden" name="action" value="blt_sce_create_report" />';

		echo '<table class="form-table" role="presentation"><tbody>';

		// Date range.
		echo '<tr><th scope="row"><label for="blt-sce-start-date">' . esc_html__( 'Date range', 'blt-surecart-extensions' ) . '</label></th><td>';
		echo '<input type="date" id="blt-sce-start-date" name="start_date" value="' . esc_attr( $month_start ) . '" required /> ';
		echo '<span aria-hidden="true">&mdash;</span> ';
		echo '<input type="date" id="blt-sce-end-date" name="end_date" value="' . esc_attr( $today ) . '" required />';
		echo '<p class="description">' . esc_html__( 'Inclusive, in the site timezone, matched against each order\'s creation date.', 'blt-surecart-extensions' ) . '</p>';
		echo '</td></tr>';

		// Products.
		echo '<tr><th scope="row"><label for="blt-sce-products">' . esc_html__( 'Products', 'blt-surecart-extensions' ) . '</label></th><td>';

		if ( empty( $products ) && $this->products->is_cold() ) {
			echo '<p><em>' . esc_html__( 'The product list has not been cached yet. A background job has been queued to fetch it — reload this page in a moment. Until then, the report will cover all products.', 'blt-surecart-extensions' ) . '</em></p>';
		} elseif ( empty( $products ) ) {
			// Refreshed, but came back with nothing — a real fault worth saying
			// out loud rather than repeating "not cached yet" forever.
			echo '<p><em>' . esc_html__( 'The product list was refreshed but SureCart returned no products, so there is nothing to choose from and reports will cover all products. This usually means the store connection cannot read products — check SureCart is connected, then use "Refresh product list" to retry.', 'blt-surecart-extensions' ) . '</em></p>';
		} else {
			echo '<select id="blt-sce-products" name="product_ids[]" multiple size="10" style="min-width:26em;max-width:100%">';

			foreach ( $products as $product ) {
				if ( empty( $product['id'] ) ) {
					continue;
				}

				printf(
					'<option value="%s">%s</option>',
					esc_attr( $product['id'] ),
					esc_html( isset( $product['name'] ) ? $product['name'] : $product['id'] )
				);
			}

			echo '</select>';
			echo '<p class="description">' . esc_html__( 'Select nothing to include all products, or hold Ctrl/Cmd to select several.', 'blt-surecart-extensions' ) . '</p>';
		}

		echo '<p>';
		echo '<span class="description">';

		if ( $refreshed_at > 0 ) {
			printf(
				/* translators: %s: human-readable time difference, e.g. "2 hours" */
				esc_html__( 'Product list cached %s ago.', 'blt-surecart-extensions' ),
				esc_html( human_time_diff( $refreshed_at, time() ) )
			);
		} else {
			esc_html_e( 'Product list has never been cached.', 'blt-surecart-extensions' );
		}

		echo ' </span>';
		echo '</p>';
		echo '</td></tr>';

		// Order status.
		echo '<tr><th scope="row">' . esc_html__( 'Order status', 'blt-surecart-extensions' ) . '</th><td><fieldset>';

		foreach ( $this->status_choices() as $value => $label ) {
			printf(
				'<label style="margin-right:1.5em"><input type="checkbox" name="statuses[]" value="%s" %s /> %s</label>',
				esc_attr( $value ),
				checked( 'paid' === $value, true, false ),
				esc_html( $label )
			);
		}

		echo '<p class="description">' . esc_html__( 'Defaults to paid orders only. Draft and failed-payment orders are never included.', 'blt-surecart-extensions' ) . '</p>';
		echo '</fieldset></td></tr>';

		// Fulfillment status.
		echo '<tr><th scope="row"><label for="blt-sce-fulfillment">' . esc_html__( 'Fulfillment status', 'blt-surecart-extensions' ) . '</label></th><td>';
		echo '<select id="blt-sce-fulfillment" name="fulfillment">';

		foreach ( $this->fulfillment_choices() as $key => $choice ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $key ),
				selected( 'any' === $key, true, false ),
				esc_html( $choice['label'] )
			);
		}

		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Use "Outstanding" when the report is driving a bulk-fulfillment run: already-shipped orders drop out, and a partially shipped order counts only the units still owed rather than the whole original quantity.', 'blt-surecart-extensions' ) . '</p>';
		echo '</td></tr>';

		// Address columns.
		echo '<tr><th scope="row">' . esc_html__( 'Shipping addresses', 'blt-surecart-extensions' ) . '</th><td>';
		echo '<label><input type="checkbox" name="include_address" value="1" /> ' . esc_html__( 'Add shipping address columns', 'blt-surecart-extensions' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Off by default. Turn it on when the CSV is being handed to a fulfillment house rather than a manufacturer. Rows are then grouped per destination, so a customer who shipped to two addresses gets one row per address instead of a single merged row.', 'blt-surecart-extensions' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';

		submit_button( __( 'Generate Report', 'blt-surecart-extensions' ) );
		echo '</form>';

		// Product-list refresh, kept out of the main form so it can't be
		// submitted by accident.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:-1em">';
		wp_nonce_field( self::NONCE_CREATE );
		echo '<input type="hidden" name="action" value="blt_sce_refresh_report_products" />';
		submit_button( __( 'Refresh product list', 'blt-surecart-extensions' ), 'secondary small', 'submit', false );
		echo '</form>';
	}

	/**
	 * Render the generated-reports table.
	 *
	 * @return void
	 */
	private function render_list() {
		$rows = $this->repository->recent( 25 );

		echo '<h2 style="margin-top:2em">' . esc_html__( 'Generated Reports', 'blt-surecart-extensions' ) . '</h2>';

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No reports generated yet.', 'blt-surecart-extensions' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Requested', 'blt-surecart-extensions' ) . '</th>';
		echo '<th>' . esc_html__( 'Range &amp; filters', 'blt-surecart-extensions' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'blt-surecart-extensions' ) . '</th>';
		echo '<th>' . esc_html__( 'Contents', 'blt-surecart-extensions' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'blt-surecart-extensions' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$params    = $this->repository->params( $row );
			$report_id = (int) $row['id'];

			echo '<tr>';

			// Requested.
			echo '<td>' . esc_html( $this->local_datetime( $row['created_at'] ) );

			if ( ! empty( $row['created_by'] ) ) {
				$user = get_userdata( (int) $row['created_by'] );

				if ( $user ) {
					echo '<br /><span class="description">' . esc_html( $user->display_name ) . '</span>';
				}
			}

			echo '</td>';

			// Range and filters.
			echo '<td>';
			echo esc_html( $this->range_label( $params ) );
			echo '<br /><span class="description">' . esc_html( $this->filters_label( $params ) ) . '</span>';
			echo '</td>';

			// Status.
			echo '<td>' . $this->status_html( $row ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html'd parts below.

			// Contents.
			echo '<td>';

			if ( ReportRepository::STATUS_COMPLETE === $row['status'] ) {
				// In address mode a row is a customer-and-destination pair, so
				// calling every row a customer would overstate the count.
				printf(
					empty( $params['include_address'] )
						/* translators: 1: customer count, 2: variant column count, 3: total item count */
						? esc_html__( '%1$d customers · %2$d variant columns · %3$d items', 'blt-surecart-extensions' )
						/* translators: 1: destination row count, 2: variant column count, 3: total item count */
						: esc_html__( '%1$d destinations · %2$d variant columns · %3$d items', 'blt-surecart-extensions' ),
					(int) $row['row_count'],
					(int) $row['column_count'],
					(int) $row['item_count']
				);
				echo '<br /><span class="description">';
				printf(
					/* translators: 1: matched order count, 2: scanned order count */
					esc_html__( '%1$d of %2$d scanned orders matched', 'blt-surecart-extensions' ),
					(int) $row['orders_matched'],
					(int) $row['orders_scanned']
				);
				echo '</span>';

				if ( ! empty( $row['truncated'] ) ) {
					echo '<br /><span style="color:#b32d2e">' . esc_html__( 'Hit the page ceiling — may be incomplete.', 'blt-surecart-extensions' ) . '</span>';
				}

				// A completed-but-empty report carries a diagnostic explaining
				// which of the several possible reasons applied.
				if ( ! empty( $row['last_error'] ) ) {
					echo '<br /><span style="color:#b32d2e">' . esc_html( $row['last_error'] ) . '</span>';
				}
			} else {
				echo '&mdash;';
			}

			echo '</td>';

			// Actions.
			echo '<td>';

			if ( ReportRepository::STATUS_COMPLETE === $row['status'] && ! empty( $row['filename'] ) && $this->storage->exists( $row['filename'] ) ) {
				$download_url = wp_nonce_url(
					add_query_arg(
						array(
							'action' => 'blt_sce_download_report',
							'report' => $report_id,
						),
						admin_url( 'admin-post.php' )
					),
					self::NONCE_MANAGE . '_' . $report_id
				);

				echo '<a class="button button-primary button-small" href="' . esc_url( $download_url ) . '">' . esc_html__( 'Download CSV', 'blt-surecart-extensions' ) . '</a> ';
			}

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
			wp_nonce_field( self::NONCE_MANAGE . '_' . $report_id );
			echo '<input type="hidden" name="action" value="blt_sce_delete_report" />';
			echo '<input type="hidden" name="report" value="' . esc_attr( (string) $report_id ) . '" />';
			submit_button( __( 'Delete', 'blt-surecart-extensions' ), 'delete small', 'submit', false );
			echo '</form>';
			echo '</td>';

			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<p class="description" style="max-width:60em;margin-top:1em">';
		echo esc_html__( 'Reports are stored as files under uploads/blt-sce-reports/ with unguessable filenames, and are only served through this screen after a capability and nonce check. An .htaccess denying direct access is written alongside them, which Apache honors and nginx ignores — on nginx the unguessable filename is what keeps them private. Delete reports you no longer need; they contain customer data.', 'blt-surecart-extensions' );
		echo '</p>';
	}

	/**
	 * Status cell markup.
	 *
	 * @param array $row Report row.
	 * @return string Escaped HTML.
	 */
	private function status_html( array $row ) {
		switch ( $row['status'] ) {
			case ReportRepository::STATUS_COMPLETE:
				return '<span class="dashicons dashicons-yes" style="color:#46b450"></span> ' . esc_html__( 'Complete', 'blt-surecart-extensions' );

			case ReportRepository::STATUS_RUNNING:
				return '<span class="dashicons dashicons-update" style="color:#2271b1"></span> ' . esc_html__( 'Running', 'blt-surecart-extensions' );

			case ReportRepository::STATUS_FAILED:
				$html = '<span class="dashicons dashicons-warning" style="color:#dc3232"></span> ' . esc_html__( 'Failed', 'blt-surecart-extensions' );

				if ( ! empty( $row['last_error'] ) ) {
					$html .= '<br /><span class="description">' . esc_html( $row['last_error'] ) . '</span>';
				}

				return $html;

			default:
				return '<span class="dashicons dashicons-clock" style="color:#888"></span> ' . esc_html__( 'Queued', 'blt-surecart-extensions' );
		}
	}

	/**
	 * Human label for a report's date range.
	 *
	 * @param array $params Report params.
	 * @return string
	 */
	private function range_label( array $params ) {
		$start = isset( $params['start_date'] ) ? $params['start_date'] : '';
		$end   = isset( $params['end_date'] ) ? $params['end_date'] : '';

		if ( '' === $start && '' === $end ) {
			return __( 'All time', 'blt-surecart-extensions' );
		}

		return $start . ' → ' . $end;
	}

	/**
	 * Human summary of a report's filters.
	 *
	 * @param array $params Report params.
	 * @return string
	 */
	private function filters_label( array $params ) {
		$parts = array();

		$statuses = ! empty( $params['statuses'] ) && is_array( $params['statuses'] ) ? $params['statuses'] : array( 'paid' );
		$parts[]  = implode( ', ', $statuses );

		if ( ! empty( $params['product_ids'] ) && is_array( $params['product_ids'] ) ) {
			$names = array();

			foreach ( array_slice( $params['product_ids'], 0, 3 ) as $product_id ) {
				$names[] = $this->products->name( $product_id );
			}

			$remaining = count( $params['product_ids'] ) - count( $names );

			if ( $remaining > 0 ) {
				/* translators: %d: number of additional products */
				$names[] = sprintf( __( '+%d more', 'blt-surecart-extensions' ), $remaining );
			}

			$parts[] = implode( ', ', $names );
		} else {
			$parts[] = __( 'all products', 'blt-surecart-extensions' );
		}

		$fulfillment_choices = $this->fulfillment_choices();
		$fulfillment_key     = isset( $params['fulfillment'] ) ? $params['fulfillment'] : 'any';

		if ( isset( $fulfillment_choices[ $fulfillment_key ] ) && 'any' !== $fulfillment_key ) {
			$parts[] = $fulfillment_choices[ $fulfillment_key ]['label'];
		}

		// Which quantity was counted is the difference between "order 15
		// shirts" and "ship 15 shirts" — worth stating on a stored report.
		$parts[] = ! empty( $params['remaining_only'] )
			? __( 'counting units still to ship', 'blt-surecart-extensions' )
			: __( 'counting units ordered', 'blt-surecart-extensions' );

		if ( ! empty( $params['include_address'] ) ) {
			$parts[] = __( 'with addresses, grouped per destination', 'blt-surecart-extensions' );
		}

		return implode( ' · ', $parts );
	}

	/**
	 * Friendly filename for the downloaded copy — the on-disk name is a
	 * random token, which is useless to whoever receives the file.
	 *
	 * @param array $row Report row.
	 * @return string
	 */
	private function download_filename( array $row ) {
		$params = $this->repository->params( $row );
		$start  = isset( $params['start_date'] ) ? $params['start_date'] : 'all';
		$end    = isset( $params['end_date'] ) ? $params['end_date'] : 'all';

		return sanitize_file_name( sprintf( 'fulfillment-report-%s-to-%s.csv', $start, $end ) );
	}

	/**
	 * Render a stored UTC datetime in the site's timezone.
	 *
	 * @param string $mysql_datetime UTC datetime string.
	 * @return string
	 */
	private function local_datetime( $mysql_datetime ) {
		if ( empty( $mysql_datetime ) ) {
			return '';
		}

		$timestamp = strtotime( $mysql_datetime . ' UTC' );

		if ( ! $timestamp ) {
			return (string) $mysql_datetime;
		}

		return wp_date( 'Y-m-d H:i', $timestamp );
	}

	/**
	 * Keep only the scalar members of a submitted array, as strings.
	 *
	 * A crafted request can nest arrays inside `statuses[]`/`product_ids[]`,
	 * and handing an array to sanitize_key()/sanitize_text_field() is a
	 * TypeError on PHP 8 — a fatal, not a rejection.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string[]
	 */
	private function scalars( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$out = array();

		foreach ( $value as $item ) {
			if ( is_scalar( $item ) ) {
				$out[] = (string) $item;
			}
		}

		return $out;
	}

	/**
	 * Accept only a Y-m-d date that actually exists.
	 *
	 * @param string $value Raw input.
	 * @return string Validated date, or an empty string.
	 */
	private function sanitize_date( $value ) {
		$value = sanitize_text_field( $value );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return '';
		}

		list( $year, $month, $day ) = array_map( 'intval', explode( '-', $value ) );

		return checkdate( $month, $day, $year ) ? $value : '';
	}

	/**
	 * Redirect back to this screen carrying a notice code, and stop.
	 *
	 * @param string $code One of the keys from notices().
	 * @return void
	 */
	private function redirect_with( $code ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => self::PAGE_SLUG,
					'blt_sce_notice' => $code,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
