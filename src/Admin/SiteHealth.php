<?php
/**
 * Surfaces a broken fulfillment pipeline loudly: an admin notice plus a
 * WP Site Health test. A silently broken pipeline is worse than a loud
 * one (spec tasks/05-admin.md).
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Admin;

use BLT\SCE\Db\ShipmentRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SiteHealth
 */
final class SiteHealth {

	/**
	 * Shipment repository.
	 *
	 * @var ShipmentRepository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param ShipmentRepository $repository Shipment repository.
	 */
	public function __construct( ShipmentRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Register WP hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_filter( 'site_status_tests', array( $this, 'register_test' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * Register the async Site Health test.
	 *
	 * @param array $tests Existing tests.
	 * @return array
	 */
	public function register_test( $tests ) {
		$tests['direct']['blt_sce_failed_shipments'] = array(
			'label' => __( 'BLT SureCart Extensions: fulfillment pipeline', 'blt-surecart-extensions' ),
			'test'  => array( $this, 'run_test' ),
		);

		return $tests;
	}

	/**
	 * Counts of failed and held shipments, for the notice and the test.
	 *
	 * @return array{failed: int, review: int}
	 */
	private function counts() {
		$failed = $this->repository->paginated(
			array(
				'status'   => ShipmentRepository::STATUS_FAILED,
				'per_page' => 1,
			)
		);
		$review = $this->repository->paginated(
			array(
				'statuses' => array( ShipmentRepository::STATUS_REVIEW ),
				'per_page' => 1,
			)
		);

		return array(
			'failed' => $failed['total'],
			'review' => $review['total'],
		);
	}

	/**
	 * Run the Site Health test.
	 *
	 * @return array
	 */
	public function run_test() {
		$counts = $this->counts();

		$result = array(
			'label'       => __( 'Shipping labels are being purchased normally', 'blt-surecart-extensions' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'BLT SureCart Extensions', 'blt-surecart-extensions' ),
				'color' => 'blue',
			),
			'description' => sprintf(
				'<p>%s</p>',
				esc_html__( 'No failed shipments and nothing unusually long in the review queue.', 'blt-surecart-extensions' )
			),
			'actions'     => sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=blt-sce-shipments' ) ),
				esc_html__( 'View shipments', 'blt-surecart-extensions' )
			),
			'test'        => 'blt_sce_failed_shipments',
		);

		if ( $counts['failed'] > 0 ) {
			$result['status'] = 'critical';
			$result['label']  = sprintf(
				/* translators: %d: number of failed shipments */
				_n( '%d shipment failed to purchase a label', '%d shipments failed to purchase a label', $counts['failed'], 'blt-surecart-extensions' ),
				$counts['failed']
			);
			$result['description'] = sprintf(
				'<p>%s</p>',
				esc_html__( 'These have exhausted their retry attempts and need manual attention.', 'blt-surecart-extensions' )
			);
		} elseif ( $counts['review'] > 5 ) {
			$result['status'] = 'recommended';
			$result['label']  = sprintf(
				/* translators: %d: number of shipments in review */
				_n( '%d shipment is waiting in the review queue', '%d shipments are waiting in the review queue', $counts['review'], 'blt-surecart-extensions' ),
				$counts['review']
			);
			$result['description'] = sprintf(
				'<p>%s</p>',
				esc_html__( 'A growing review queue usually means a guardrail is blocking most orders — check the settings.', 'blt-surecart-extensions' )
			);
		}

		return $result;
	}

	/**
	 * Admin notice shown on this plugin's own screens (and the main
	 * dashboard) when something needs attention.
	 *
	 * @return void
	 */
	public function render_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = get_current_screen();
		$ours   = $screen && false !== strpos( (string) $screen->id, 'blt-sce' );

		if ( ! $ours && ( ! $screen || 'dashboard' !== $screen->id ) ) {
			return;
		}

		$counts = $this->counts();

		if ( 0 === $counts['failed'] ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s <a href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of failed shipments */
					_n( 'BLT SureCart Extensions: %d shipment failed to purchase a label.', 'BLT SureCart Extensions: %d shipments failed to purchase a label.', $counts['failed'], 'blt-surecart-extensions' ),
					$counts['failed']
				)
			),
			esc_url( admin_url( 'admin.php?page=blt-sce-shipments&status=failed' ) ),
			esc_html__( 'Review now', 'blt-surecart-extensions' )
		);
	}
}
