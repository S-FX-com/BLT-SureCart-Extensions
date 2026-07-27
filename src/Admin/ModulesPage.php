<?php
/**
 * "Modules" admin screen: enable/disable each registered module and see
 * why a module isn't running if its requirements aren't met.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Admin;

use BLT\SCE\Modules\ModuleRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ModulesPage
 */
final class ModulesPage {

	const CAPABILITY   = 'manage_options';
	const NONCE_ACTION = 'blt_sce_toggle_module';

	/**
	 * Module registry.
	 *
	 * @var ModuleRegistry
	 */
	private $registry;

	/**
	 * Constructor.
	 *
	 * @param ModuleRegistry $registry Module registry.
	 */
	public function __construct( ModuleRegistry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Register WP hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_blt_sce_toggle_module', array( $this, 'handle_toggle' ) );
	}

	/**
	 * Register the top-level admin menu and the Modules submenu page.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'BLT SureCart Extensions', 'blt-surecart-extensions' ),
			__( 'SC Extensions', 'blt-surecart-extensions' ),
			self::CAPABILITY,
			'blt-sce-modules',
			array( $this, 'render' ),
			'dashicons-networking'
		);

		add_submenu_page(
			'blt-sce-modules',
			__( 'Modules', 'blt-surecart-extensions' ),
			__( 'Modules', 'blt-surecart-extensions' ),
			self::CAPABILITY,
			'blt-sce-modules',
			array( $this, 'render' )
		);
	}

	/**
	 * Handle the enable/disable form submission.
	 *
	 * @return void
	 */
	public function handle_toggle() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'blt-surecart-extensions' ) );
		}

		$slug = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( $_POST['module'] ) ) : '';

		check_admin_referer( self::NONCE_ACTION . '_' . $slug );

		$enabled = ! empty( $_POST['enabled'] );

		if ( '' !== $slug && $this->registry->get( $slug ) ) {
			$this->registry->set_enabled( $slug, $enabled );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'blt-sce-modules' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render the Modules screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'BLT SureCart Extensions — Modules', 'blt-surecart-extensions' ) . '</h1>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Module', 'blt-surecart-extensions' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'blt-surecart-extensions' ) . '</th>';
		echo '<th>' . esc_html__( 'Action', 'blt-surecart-extensions' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $this->registry->all() as $module ) {
			$slug    = $module->slug();
			$enabled = $this->registry->is_enabled( $slug );
			$unmet   = $module->unmet_requirements();
			$booted  = in_array( $slug, $this->registry->booted(), true );

			echo '<tr>';
			echo '<td><strong>' . esc_html( $module->label() ) . '</strong><br /><span class="description">' . esc_html( $module->description() ) . '</span></td>';

			echo '<td>';
			if ( ! $enabled ) {
				echo '<span class="dashicons dashicons-marker" style="color:#999"></span> ' . esc_html__( 'Disabled', 'blt-surecart-extensions' );
			} elseif ( $booted ) {
				echo '<span class="dashicons dashicons-yes" style="color:#46b450"></span> ' . esc_html__( 'Active', 'blt-surecart-extensions' );
			} else {
				echo '<span class="dashicons dashicons-warning" style="color:#dc3232"></span> ' . esc_html__( 'Enabled, but not running', 'blt-surecart-extensions' );
			}

			if ( ! empty( $unmet ) ) {
				echo '<ul style="color:#dc3232;margin:.5em 0 0 1.2em;list-style:disc;">';
				foreach ( $unmet as $reason ) {
					echo '<li>' . esc_html( $reason ) . '</li>';
				}
				echo '</ul>';
			}
			echo '</td>';

			echo '<td>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( self::NONCE_ACTION . '_' . $slug );
			echo '<input type="hidden" name="action" value="blt_sce_toggle_module" />';
			echo '<input type="hidden" name="module" value="' . esc_attr( $slug ) . '" />';
			echo '<input type="hidden" name="enabled" value="' . ( $enabled ? '0' : '1' ) . '" />';
			submit_button( $enabled ? __( 'Disable', 'blt-surecart-extensions' ) : __( 'Enable', 'blt-surecart-extensions' ), $enabled ? 'delete small' : 'primary small', 'submit', false );
			echo '</form>';
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}
}
