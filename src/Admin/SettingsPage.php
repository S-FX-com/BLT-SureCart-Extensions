<?php
/**
 * Shippo Fulfillment settings screen: mode/token, ship-from address,
 * guardrails, parcels + SKU mapping, service selection rules, and
 * JSON config export/import so a working setup replicates across
 * client sites without re-entry.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Admin;

use BLT\SCE\Modules\ShippoFulfillment\Guardrails;
use BLT\SCE\Modules\ShippoFulfillment\ParcelMapper;
use BLT\SCE\Modules\ShippoFulfillment\ServiceSelector;
use BLT\SCE\Rest\ShippoWebhookController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SettingsPage
 */
final class SettingsPage {

	const CAPABILITY              = 'manage_options';
	const NONCE_ACTION            = 'blt_sce_save_settings';
	const OPT_SHIPPO_TOKEN        = 'blt_sce_shippo_api_token';
	const OPT_SHIP_FROM           = 'blt_sce_shippo_ship_from';
	const OPT_RECONCILE_HOURS     = 'blt_sce_shippo_reconcile_after_hours';
	const OPT_DELETE_ON_UNINSTALL = 'blt_sce_delete_data_on_uninstall';

	/**
	 * Register WP hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	/**
	 * Register the submenu page.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'blt-sce-modules',
			__( 'Shippo Fulfillment Settings', 'blt-surecart-extensions' ),
			__( 'Settings', 'blt-surecart-extensions' ),
			self::CAPABILITY,
			'blt-sce-settings',
			array( $this, 'render' )
		);
	}

	/**
	 * Whether the Shippo API token is defined via wp-config.php constant,
	 * which takes precedence over the stored option and keeps the token
	 * out of the database entirely.
	 *
	 * @return bool
	 */
	public static function token_is_constant_defined() {
		return defined( 'BLT_SCE_SHIPPO_API_TOKEN' ) && '' !== BLT_SCE_SHIPPO_API_TOKEN;
	}

	/**
	 * The active Shippo API token — constant first, then the option.
	 * Never exposed to the browser; only ever read server-side.
	 *
	 * @return string
	 */
	public static function shippo_token() {
		if ( self::token_is_constant_defined() ) {
			return BLT_SCE_SHIPPO_API_TOKEN;
		}

		return (string) get_option( self::OPT_SHIPPO_TOKEN, '' );
	}

	/**
	 * The configured ship-from address.
	 *
	 * @return array
	 */
	public static function ship_from_address() {
		$default = array(
			'name'    => '',
			'company' => '',
			'street1' => '',
			'street2' => '',
			'city'    => '',
			'state'   => '',
			'zip'     => '',
			'country' => 'US',
			'phone'   => '',
			'email'   => '',
		);

		return wp_parse_args( get_option( self::OPT_SHIP_FROM, array() ), $default );
	}

	/**
	 * Hours a shipment may sit in a non-terminal status before the
	 * reconciliation sweep re-checks it with Shippo.
	 *
	 * @return int
	 */
	public static function reconcile_after_hours() {
		$hours = (int) get_option( self::OPT_RECONCILE_HOURS, 6 );

		return $hours > 0 ? $hours : 6;
	}

	/**
	 * Render the settings screen: handle any tab's POST, then the tabs.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$notice = '';

		if ( isset( $_POST['blt_sce_settings_tab'] ) && check_admin_referer( self::NONCE_ACTION ) ) {
			$notice = $this->handle_save( sanitize_key( wp_unslash( $_POST['blt_sce_settings_tab'] ) ) );
		}

		$tabs   = array(
			'general'    => __( 'General', 'blt-surecart-extensions' ),
			'parcels'    => __( 'Parcels & Mapping', 'blt-surecart-extensions' ),
			'rules'      => __( 'Service Rules', 'blt-surecart-extensions' ),
			'guardrails' => __( 'Guardrails', 'blt-surecart-extensions' ),
			'export'     => __( 'Export / Import', 'blt-surecart-extensions' ),
		);
		$active = isset( $_GET['tab'] ) && isset( $tabs[ sanitize_key( wp_unslash( $_GET['tab'] ) ) ] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_key( wp_unslash( $_GET['tab'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: 'general';

		echo '<div class="wrap"><h1>' . esc_html__( 'Shippo Fulfillment Settings', 'blt-surecart-extensions' ) . '</h1>';

		if ( $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
		}

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$class = $slug === $active ? 'nav-tab nav-tab-active' : 'nav-tab';
			printf(
				'<a class="%s" href="%s">%s</a>',
				esc_attr( $class ),
				esc_url(
					add_query_arg(
						array(
							'page' => 'blt-sce-settings',
							'tab'  => $slug,
						),
						admin_url( 'admin.php' )
					)
				),
				esc_html( $label )
			);
		}
		echo '</h2>';

		switch ( $active ) {
			case 'parcels':
				$this->render_parcels_tab();
				break;
			case 'rules':
				$this->render_rules_tab();
				break;
			case 'guardrails':
				$this->render_guardrails_tab();
				break;
			case 'export':
				$this->render_export_tab();
				break;
			default:
				$this->render_general_tab();
				break;
		}

		echo '</div>';
	}

	/**
	 * Dispatch a save by tab, return a human-readable success message.
	 *
	 * @param string $tab Tab slug being saved.
	 * @return string
	 */
	private function handle_save( $tab ) {
		switch ( $tab ) {
			case 'general':
				return $this->save_general();
			case 'parcels':
				return $this->save_parcels();
			case 'rules':
				return $this->save_rules();
			case 'guardrails':
				return $this->save_guardrails();
			case 'import':
				return $this->save_import();
			case 'uninstall':
				return $this->save_uninstall_preference();
			default:
				return '';
		}
	}

	/**
	 * General tab: mode, token, ship-from address, kill switch, sweep interval.
	 *
	 * @return void
	 */
	private function render_general_tab() {
		$mode          = get_option( Guardrails::OPT_MODE, Guardrails::MODE_TEST );
		$token_locked  = self::token_is_constant_defined();
		$token         = $token_locked ? str_repeat( '•', 8 ) : self::shippo_token();
		$ship_from     = self::ship_from_address();
		$kill_switch   = (bool) get_option( Guardrails::OPT_KILL_SWITCH, false );
		$auto_purchase = (bool) get_option( Guardrails::OPT_AUTO_PURCHASE, false );
		$reconcile_hrs = self::reconcile_after_hours();
		?>
		<form method="post">
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<input type="hidden" name="blt_sce_settings_tab" value="general" />

			<h2><?php esc_html_e( 'Kill switch', 'blt-surecart-extensions' ); ?></h2>
			<p>
				<label>
					<input type="checkbox" name="kill_switch" value="1" <?php checked( $kill_switch ); ?> />
					<?php esc_html_e( 'Halt all Shippo purchasing immediately (existing shipment records are untouched).', 'blt-surecart-extensions' ); ?>
				</label>
			</p>

			<h2><?php esc_html_e( 'Mode', 'blt-surecart-extensions' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="blt_sce_mode"><?php esc_html_e( 'Shippo mode', 'blt-surecart-extensions' ); ?></label></th>
					<td>
						<select name="mode" id="blt_sce_mode">
							<option value="test" <?php selected( $mode, Guardrails::MODE_TEST ); ?>><?php esc_html_e( 'Test', 'blt-surecart-extensions' ); ?></option>
							<option value="live" <?php selected( $mode, Guardrails::MODE_LIVE ); ?>><?php esc_html_e( 'Live', 'blt-surecart-extensions' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'The configured token must match this mode (shippo_test_… or shippo_live_…) or purchases are refused.', 'blt-surecart-extensions' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="blt_sce_token"><?php esc_html_e( 'Shippo API token', 'blt-surecart-extensions' ); ?></label></th>
					<td>
						<?php if ( $token_locked ) : ?>
							<input type="text" class="regular-text" value="<?php echo esc_attr( str_repeat( '•', 8 ) ); ?>" disabled />
							<p class="description"><?php esc_html_e( 'Defined via the BLT_SCE_SHIPPO_API_TOKEN constant in wp-config.php — remove the constant to manage it here instead.', 'blt-surecart-extensions' ); ?></p>
						<?php else : ?>
							<input type="password" autocomplete="new-password" name="shippo_token" id="blt_sce_token" class="regular-text" value="" placeholder="<?php echo '' === $token ? esc_attr__( 'shippo_test_… / shippo_live_…', 'blt-surecart-extensions' ) : esc_attr( sprintf( /* translators: %s: masked token */ __( 'Currently set (%s) — leave blank to keep it', 'blt-surecart-extensions' ), str_repeat( '•', 8 ) . substr( $token, -4 ) ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Stored server-side only; never rendered back into this page. Leave blank to keep the current token — the field is never pre-filled with the real value. For production, prefer defining BLT_SCE_SHIPPO_API_TOKEN in wp-config.php instead.', 'blt-surecart-extensions' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="blt_sce_auto_purchase"><?php esc_html_e( 'Auto-purchase', 'blt-surecart-extensions' ); ?></label></th>
					<td>
						<label>
							<input type="checkbox" name="auto_purchase" id="blt_sce_auto_purchase" value="1" <?php checked( $auto_purchase ); ?> />
							<?php esc_html_e( 'Automatically purchase labels once a rate clears all guardrails.', 'blt-surecart-extensions' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Off by default. While off, every order is quoted and placed in the Review Queue for a one-click purchase.', 'blt-surecart-extensions' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="blt_sce_reconcile_hours"><?php esc_html_e( 'Reconciliation threshold (hours)', 'blt-surecart-extensions' ); ?></label></th>
					<td>
						<input type="number" min="1" step="1" name="reconcile_hours" id="blt_sce_reconcile_hours" value="<?php echo esc_attr( $reconcile_hrs ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'Shipments stuck in a non-terminal status longer than this are re-checked against Shippo directly, in case a tracking webhook was missed.', 'blt-surecart-extensions' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Ship-from address', 'blt-surecart-extensions' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php
				foreach ( array(
					'name'    => __( 'Name', 'blt-surecart-extensions' ),
					'company' => __( 'Company', 'blt-surecart-extensions' ),
					'street1' => __( 'Address line 1', 'blt-surecart-extensions' ),
					'street2' => __( 'Address line 2', 'blt-surecart-extensions' ),
					'city'    => __( 'City', 'blt-surecart-extensions' ),
					'state'   => __( 'State', 'blt-surecart-extensions' ),
					'zip'     => __( 'ZIP / Postal code', 'blt-surecart-extensions' ),
					'country' => __( 'Country (ISO 2-letter)', 'blt-surecart-extensions' ),
					'phone'   => __( 'Phone', 'blt-surecart-extensions' ),
					'email'   => __( 'Email', 'blt-surecart-extensions' ),
				) as $field => $field_label ) :
					?>
					<tr>
						<th scope="row"><label for="ship_from_<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $field_label ); ?></label></th>
						<td><input type="text" class="regular-text" name="ship_from[<?php echo esc_attr( $field ); ?>]" id="ship_from_<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( $ship_from[ $field ] ); ?>" /></td>
					</tr>
				<?php endforeach; ?>
			</table>

			<h2><?php esc_html_e( 'Tracking webhook security', 'blt-surecart-extensions' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Shippo tracking updates are delivered to the URL below (registered automatically). The URL token is the default, self-service security check; the IP allowlist and HMAC secret are optional extra layers — HMAC requires requesting setup from Shippo support first.', 'blt-surecart-extensions' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Webhook URL', 'blt-surecart-extensions' ); ?></th>
					<td><code><?php echo esc_html( ShippoWebhookController::callback_url() ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'IP allowlist', 'blt-surecart-extensions' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="webhook_ip_allowlist" value="1" <?php checked( (bool) get_option( ShippoWebhookController::OPT_IP_ALLOWLIST_ENABLED, false ) ); ?> />
							<?php esc_html_e( 'Also require the request to come from a published Shippo IP.', 'blt-surecart-extensions' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Leave off if this site sits behind a proxy/CDN that obscures the real source IP.', 'blt-surecart-extensions' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="webhook_hmac_secret"><?php esc_html_e( 'HMAC shared secret', 'blt-surecart-extensions' ); ?></label></th>
					<td>
						<input type="password" autocomplete="new-password" name="webhook_hmac_secret" id="webhook_hmac_secret" class="regular-text" value="" placeholder="<?php echo '' === get_option( ShippoWebhookController::OPT_HMAC_SECRET, '' ) ? '' : esc_attr__( 'Currently set — leave blank to keep it', 'blt-surecart-extensions' ); ?>" />
						<p class="description"><?php esc_html_e( 'Never rendered back into this page. Only set this after Shippo support has confirmed HMAC signing is enabled for your account.', 'blt-surecart-extensions' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Save the general tab.
	 *
	 * @return string
	 */
	private function save_general() {
		check_admin_referer( self::NONCE_ACTION );

		update_option( Guardrails::OPT_KILL_SWITCH, ! empty( $_POST['kill_switch'] ) );
		update_option( Guardrails::OPT_AUTO_PURCHASE, ! empty( $_POST['auto_purchase'] ) );

		$mode = isset( $_POST['mode'] ) && Guardrails::MODE_LIVE === $_POST['mode'] ? Guardrails::MODE_LIVE : Guardrails::MODE_TEST;
		update_option( Guardrails::OPT_MODE, $mode );

		// The token field is never pre-filled with the real value (it's
		// never rendered back into the page at all), so a blank submit
		// means "leave it unchanged," not "clear it."
		if ( ! self::token_is_constant_defined() && ! empty( $_POST['shippo_token'] ) ) {
			update_option( self::OPT_SHIPPO_TOKEN, sanitize_text_field( wp_unslash( $_POST['shippo_token'] ) ), false );
		}

		$hours = isset( $_POST['reconcile_hours'] ) ? max( 1, (int) $_POST['reconcile_hours'] ) : 6;
		update_option( self::OPT_RECONCILE_HOURS, $hours );

		update_option( ShippoWebhookController::OPT_IP_ALLOWLIST_ENABLED, ! empty( $_POST['webhook_ip_allowlist'] ) );

		if ( ! empty( $_POST['webhook_hmac_secret'] ) ) {
			update_option( ShippoWebhookController::OPT_HMAC_SECRET, sanitize_text_field( wp_unslash( $_POST['webhook_hmac_secret'] ) ), false );
		}

		$ship_from = array();
		$posted    = isset( $_POST['ship_from'] ) && is_array( $_POST['ship_from'] ) ? wp_unslash( $_POST['ship_from'] ) : array();

		foreach ( array( 'name', 'company', 'street1', 'street2', 'city', 'state', 'zip', 'country', 'phone', 'email' ) as $field ) {
			$ship_from[ $field ] = isset( $posted[ $field ] ) ? sanitize_text_field( $posted[ $field ] ) : '';
		}

		update_option( self::OPT_SHIP_FROM, $ship_from );

		return __( 'General settings saved.', 'blt-surecart-extensions' );
	}

	/**
	 * Parcels & SKU mapping tab.
	 *
	 * @return void
	 */
	private function render_parcels_tab() {
		$mapper     = new ParcelMapper();
		$parcels    = $mapper->get_parcels();
		$sku_map    = $mapper->get_sku_map();
		$default    = $mapper->get_default_parcel_id();
		$blank_rows = 3;
		?>
		<form method="post">
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<input type="hidden" name="blt_sce_settings_tab" value="parcels" />

			<h2><?php esc_html_e( 'Parcel definitions', 'blt-surecart-extensions' ); ?></h2>
			<p class="description"><?php esc_html_e( 'One parcel per order (v1). Weight should reflect the fully packed parcel, not just the empty box.', 'blt-surecart-extensions' ); ?></p>
			<table class="widefat">
				<thead><tr>
					<th><?php esc_html_e( 'Remove', 'blt-surecart-extensions' ); ?></th>
					<th><?php esc_html_e( 'ID (slug)', 'blt-surecart-extensions' ); ?></th>
					<th><?php esc_html_e( 'Name', 'blt-surecart-extensions' ); ?></th>
					<th><?php esc_html_e( 'Length', 'blt-surecart-extensions' ); ?></th>
					<th><?php esc_html_e( 'Width', 'blt-surecart-extensions' ); ?></th>
					<th><?php esc_html_e( 'Height', 'blt-surecart-extensions' ); ?></th>
					<th><?php esc_html_e( 'Distance unit', 'blt-surecart-extensions' ); ?></th>
					<th><?php esc_html_e( 'Weight', 'blt-surecart-extensions' ); ?></th>
					<th><?php esc_html_e( 'Mass unit', 'blt-surecart-extensions' ); ?></th>
				</tr></thead>
				<tbody>
				<?php
				$rows = $parcels;
				for ( $i = 0; $i < $blank_rows; $i++ ) {
					$rows[ 'new-' . $i ] = array(
						'id'            => '',
						'name'          => '',
						'length'        => '',
						'width'         => '',
						'height'        => '',
						'distance_unit' => 'in',
						'weight'        => '',
						'mass_unit'     => 'lb',
					);
				}
				foreach ( $rows as $key => $parcel ) :
					$parcel = wp_parse_args(
						$parcel,
						array(
							'id'            => $key,
							'name'          => '',
							'length'        => '',
							'width'         => '',
							'height'        => '',
							'distance_unit' => 'in',
							'weight'        => '',
							'mass_unit'     => 'lb',
						)
					);
					?>
					<tr>
						<td><input type="checkbox" name="remove_parcel[<?php echo esc_attr( $key ); ?>]" value="1" /></td>
						<td><input type="text" name="parcels[<?php echo esc_attr( $key ); ?>][id]" value="<?php echo esc_attr( $parcel['id'] ); ?>" placeholder="board_set_box" /></td>
						<td><input type="text" name="parcels[<?php echo esc_attr( $key ); ?>][name]" value="<?php echo esc_attr( $parcel['name'] ); ?>" placeholder="Board Set Box" /></td>
						<td><input type="text" size="4" name="parcels[<?php echo esc_attr( $key ); ?>][length]" value="<?php echo esc_attr( $parcel['length'] ); ?>" /></td>
						<td><input type="text" size="4" name="parcels[<?php echo esc_attr( $key ); ?>][width]" value="<?php echo esc_attr( $parcel['width'] ); ?>" /></td>
						<td><input type="text" size="4" name="parcels[<?php echo esc_attr( $key ); ?>][height]" value="<?php echo esc_attr( $parcel['height'] ); ?>" /></td>
						<td>
							<select name="parcels[<?php echo esc_attr( $key ); ?>][distance_unit]">
								<?php foreach ( array( 'in', 'cm' ) as $unit ) : ?>
									<option value="<?php echo esc_attr( $unit ); ?>" <?php selected( $parcel['distance_unit'], $unit ); ?>><?php echo esc_html( $unit ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td><input type="text" size="4" name="parcels[<?php echo esc_attr( $key ); ?>][weight]" value="<?php echo esc_attr( $parcel['weight'] ); ?>" /></td>
						<td>
							<select name="parcels[<?php echo esc_attr( $key ); ?>][mass_unit]">
								<?php foreach ( array( 'lb', 'kg', 'oz', 'g' ) as $unit ) : ?>
									<option value="<?php echo esc_attr( $unit ); ?>" <?php selected( $parcel['mass_unit'], $unit ); ?>><?php echo esc_html( $unit ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Default parcel', 'blt-surecart-extensions' ); ?></h2>
			<p>
				<select name="default_parcel">
					<option value=""><?php esc_html_e( '— none (unmapped SKUs go to review) —', 'blt-surecart-extensions' ); ?></option>
					<?php foreach ( $parcels as $id => $parcel ) : ?>
						<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $default, $id ); ?>><?php echo esc_html( isset( $parcel['name'] ) ? $parcel['name'] : $id ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>

			<h2><?php esc_html_e( 'SKU → parcel mapping', 'blt-surecart-extensions' ); ?></h2>
			<table class="widefat">
				<thead><tr>
					<th><?php esc_html_e( 'Remove', 'blt-surecart-extensions' ); ?></th>
					<th><?php esc_html_e( 'SKU', 'blt-surecart-extensions' ); ?></th>
					<th><?php esc_html_e( 'Parcel', 'blt-surecart-extensions' ); ?></th>
				</tr></thead>
				<tbody>
				<?php
				$sku_rows = array();
				foreach ( $sku_map as $sku => $parcel_id ) {
					$sku_rows[] = array(
						'sku'    => $sku,
						'parcel' => $parcel_id,
					);
				}
				for ( $i = 0; $i < $blank_rows; $i++ ) {
					$sku_rows[] = array(
						'sku'    => '',
						'parcel' => '',
					);
				}
				foreach ( $sku_rows as $index => $sku_row ) :
					?>
					<tr>
						<td><input type="checkbox" name="remove_sku[<?php echo esc_attr( $index ); ?>]" value="1" /></td>
						<td><input type="text" name="sku_map[<?php echo esc_attr( $index ); ?>][sku]" value="<?php echo esc_attr( $sku_row['sku'] ); ?>" /></td>
						<td>
							<select name="sku_map[<?php echo esc_attr( $index ); ?>][parcel]">
								<option value=""><?php esc_html_e( '— choose —', 'blt-surecart-extensions' ); ?></option>
								<?php foreach ( $parcels as $id => $parcel ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $sku_row['parcel'], $id ); ?>><?php echo esc_html( isset( $parcel['name'] ) ? $parcel['name'] : $id ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Save the parcels tab.
	 *
	 * @return string
	 */
	private function save_parcels() {
		check_admin_referer( self::NONCE_ACTION );

		$posted_parcels = isset( $_POST['parcels'] ) && is_array( $_POST['parcels'] ) ? wp_unslash( $_POST['parcels'] ) : array();
		$removed        = isset( $_POST['remove_parcel'] ) && is_array( $_POST['remove_parcel'] ) ? wp_unslash( $_POST['remove_parcel'] ) : array();

		$parcels = array();

		foreach ( $posted_parcels as $key => $row ) {
			$id = sanitize_key( isset( $row['id'] ) ? $row['id'] : '' );

			if ( '' === $id || ! empty( $removed[ $key ] ) ) {
				continue;
			}

			$parcels[ $id ] = array(
				'id'            => $id,
				'name'          => sanitize_text_field( isset( $row['name'] ) ? $row['name'] : $id ),
				'length'        => (float) ( isset( $row['length'] ) ? $row['length'] : 0 ),
				'width'         => (float) ( isset( $row['width'] ) ? $row['width'] : 0 ),
				'height'        => (float) ( isset( $row['height'] ) ? $row['height'] : 0 ),
				'distance_unit' => in_array( $row['distance_unit'] ?? 'in', array( 'in', 'cm' ), true ) ? $row['distance_unit'] : 'in',
				'weight'        => (float) ( isset( $row['weight'] ) ? $row['weight'] : 0 ),
				'mass_unit'     => in_array( $row['mass_unit'] ?? 'lb', array( 'lb', 'kg', 'oz', 'g' ), true ) ? $row['mass_unit'] : 'lb',
			);
		}

		update_option( ParcelMapper::OPT_PARCELS, $parcels );

		$default = isset( $_POST['default_parcel'] ) ? sanitize_key( wp_unslash( $_POST['default_parcel'] ) ) : '';
		update_option( ParcelMapper::OPT_DEFAULT_PARCEL, isset( $parcels[ $default ] ) ? $default : '' );

		$posted_sku  = isset( $_POST['sku_map'] ) && is_array( $_POST['sku_map'] ) ? wp_unslash( $_POST['sku_map'] ) : array();
		$removed_sku = isset( $_POST['remove_sku'] ) && is_array( $_POST['remove_sku'] ) ? wp_unslash( $_POST['remove_sku'] ) : array();

		$sku_map = array();

		foreach ( $posted_sku as $index => $row ) {
			$sku = sanitize_text_field( isset( $row['sku'] ) ? $row['sku'] : '' );

			if ( '' === $sku || ! empty( $removed_sku[ $index ] ) ) {
				continue;
			}

			$parcel_id = sanitize_key( isset( $row['parcel'] ) ? $row['parcel'] : '' );

			if ( '' !== $parcel_id && isset( $parcels[ $parcel_id ] ) ) {
				$sku_map[ $sku ] = $parcel_id;
			}
		}

		update_option( ParcelMapper::OPT_SKU_MAP, $sku_map );

		return __( 'Parcels and SKU mapping saved.', 'blt-surecart-extensions' );
	}

	/**
	 * Service selection rules tab.
	 *
	 * @return void
	 */
	private function render_rules_tab() {
		$rules = ServiceSelector::get_rules();
		?>
		<form method="post">
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<input type="hidden" name="blt_sce_settings_tab" value="rules" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Selection strategy', 'blt-surecart-extensions' ); ?></th>
					<td>
						<select name="strategy">
							<?php foreach ( ServiceSelector::strategies() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $rules['strategy'], $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( '"Cheapest"/"Fastest" apply among the allowed service tokens below (if any are listed); "Priority order" always picks the first allowed token that a rate exists for, regardless of price/speed.', 'blt-surecart-extensions' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="allowed_tokens"><?php esc_html_e( 'Allowed service tokens (priority order, one per line)', 'blt-surecart-extensions' ); ?></label></th>
					<td>
						<textarea name="allowed_tokens" id="allowed_tokens" rows="6" class="large-text code"><?php echo esc_textarea( implode( "\n", $rules['allowed_tokens'] ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Shippo servicelevel tokens, e.g. usps_priority. Leave blank to consider every service Shippo returns a rate for.', 'blt-surecart-extensions' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Save the service rules tab.
	 *
	 * @return string
	 */
	private function save_rules() {
		check_admin_referer( self::NONCE_ACTION );

		$strategy = isset( $_POST['strategy'] ) ? sanitize_key( wp_unslash( $_POST['strategy'] ) ) : ServiceSelector::STRATEGY_CHEAPEST;
		$strategy = array_key_exists( $strategy, ServiceSelector::strategies() ) ? $strategy : ServiceSelector::STRATEGY_CHEAPEST;

		$tokens_raw = isset( $_POST['allowed_tokens'] ) ? wp_unslash( $_POST['allowed_tokens'] ) : '';
		$tokens     = array_values( array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( "\n", $tokens_raw ) ) ) ) );

		update_option(
			ServiceSelector::OPT_RULES,
			array(
				'strategy'       => $strategy,
				'allowed_tokens' => $tokens,
			)
		);

		return __( 'Service selection rules saved.', 'blt-surecart-extensions' );
	}

	/**
	 * Guardrails tab.
	 *
	 * @return void
	 */
	private function render_guardrails_tab() {
		$ceiling_cents   = (int) get_option( Guardrails::OPT_RATE_CEILING_CENTS, 0 );
		$ceiling_percent = (float) get_option( Guardrails::OPT_RATE_CEILING_PERCENT, 0 );
		$countries       = (array) get_option( Guardrails::OPT_ALLOWED_COUNTRIES, array( 'US' ) );
		$allow_military  = (bool) get_option( Guardrails::OPT_ALLOW_MILITARY, false );
		?>
		<form method="post">
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<input type="hidden" name="blt_sce_settings_tab" value="guardrails" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ceiling_cents"><?php esc_html_e( 'Absolute rate ceiling (USD)', 'blt-surecart-extensions' ); ?></label></th>
					<td>
						<input type="number" min="0" step="0.01" name="ceiling_dollars" id="ceiling_cents" value="<?php echo esc_attr( number_format( $ceiling_cents / 100, 2, '.', '' ) ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'A quoted rate above this is held for review. 0 disables this check.', 'blt-surecart-extensions' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ceiling_percent"><?php esc_html_e( 'Rate ceiling (% of order total)', 'blt-surecart-extensions' ); ?></label></th>
					<td>
						<input type="number" min="0" step="0.1" name="ceiling_percent" id="ceiling_percent" value="<?php echo esc_attr( $ceiling_percent ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'A quoted rate above this percentage of the order total is held for review. 0 disables this check.', 'blt-surecart-extensions' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="countries"><?php esc_html_e( 'Allowed destination countries', 'blt-surecart-extensions' ); ?></label></th>
					<td>
						<input type="text" name="countries" id="countries" value="<?php echo esc_attr( implode( ', ', $countries ) ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Comma-separated ISO 3166-1 alpha-2 codes, e.g. US, CA. Leave blank to allow all countries.', 'blt-surecart-extensions' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Military addresses', 'blt-surecart-extensions' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="allow_military" value="1" <?php checked( $allow_military ); ?> />
							<?php esc_html_e( 'Allow auto-purchase for APO/FPO/DPO destinations.', 'blt-surecart-extensions' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Save the guardrails tab.
	 *
	 * @return string
	 */
	private function save_guardrails() {
		check_admin_referer( self::NONCE_ACTION );

		$dollars = isset( $_POST['ceiling_dollars'] ) ? (float) $_POST['ceiling_dollars'] : 0;
		update_option( Guardrails::OPT_RATE_CEILING_CENTS, (int) round( $dollars * 100 ) );

		$percent = isset( $_POST['ceiling_percent'] ) ? (float) $_POST['ceiling_percent'] : 0;
		update_option( Guardrails::OPT_RATE_CEILING_PERCENT, $percent );

		$countries_raw = isset( $_POST['countries'] ) ? sanitize_text_field( wp_unslash( $_POST['countries'] ) ) : '';
		$countries     = array_values( array_filter( array_map( 'strtoupper', array_map( 'trim', explode( ',', $countries_raw ) ) ) ) );
		update_option( Guardrails::OPT_ALLOWED_COUNTRIES, $countries );

		update_option( Guardrails::OPT_ALLOW_MILITARY, ! empty( $_POST['allow_military'] ) );

		return __( 'Guardrails saved.', 'blt-surecart-extensions' );
	}

	/**
	 * Export/import tab: a single JSON blob of every module-config option
	 * (never includes the API token) that can be pasted into another
	 * site's import box to replicate a working setup.
	 *
	 * @return void
	 */
	private function render_export_tab() {
		$export = wp_json_encode( $this->exportable_config(), JSON_PRETTY_PRINT );
		?>
		<h2><?php esc_html_e( 'Export', 'blt-surecart-extensions' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Copy this and paste it into the Import box on another site. The Shippo API token is never included — enter it separately on each site.', 'blt-surecart-extensions' ); ?></p>
		<textarea readonly rows="16" class="large-text code" onclick="this.select();"><?php echo esc_textarea( $export ); ?></textarea>

		<h2><?php esc_html_e( 'Import', 'blt-surecart-extensions' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<input type="hidden" name="blt_sce_settings_tab" value="import" />
			<textarea name="import_json" rows="16" class="large-text code" placeholder="<?php esc_attr_e( 'Paste exported JSON here', 'blt-surecart-extensions' ); ?>"></textarea>
			<?php submit_button( __( 'Import', 'blt-surecart-extensions' ) ); ?>
		</form>

		<h2><?php esc_html_e( 'Danger zone', 'blt-surecart-extensions' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<input type="hidden" name="blt_sce_settings_tab" value="uninstall" />
			<p>
				<label>
					<input type="checkbox" name="delete_on_uninstall" value="1" <?php checked( (bool) get_option( self::OPT_DELETE_ON_UNINSTALL, false ) ); ?> />
					<?php esc_html_e( 'Delete all shipment history and settings when this plugin is uninstalled.', 'blt-surecart-extensions' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Off by default — uninstalling the plugin normally leaves your fulfillment history and settings in the database untouched, in case you reinstall it.', 'blt-surecart-extensions' ); ?></p>
			</p>
			<?php submit_button( __( 'Save', 'blt-surecart-extensions' ), 'delete' ); ?>
		</form>
		<?php
	}

	/**
	 * Save the uninstall data-deletion opt-in.
	 *
	 * @return string
	 */
	private function save_uninstall_preference() {
		check_admin_referer( self::NONCE_ACTION );

		update_option( self::OPT_DELETE_ON_UNINSTALL, ! empty( $_POST['delete_on_uninstall'] ) );

		return __( 'Uninstall preference saved.', 'blt-surecart-extensions' );
	}

	/**
	 * Import a previously exported JSON config blob.
	 *
	 * @return string
	 */
	private function save_import() {
		check_admin_referer( self::NONCE_ACTION );

		$json = isset( $_POST['import_json'] ) ? wp_unslash( $_POST['import_json'] ) : '';
		$data = json_decode( $json, true );

		if ( ! is_array( $data ) ) {
			return __( 'Import failed: could not parse JSON.', 'blt-surecart-extensions' );
		}

		$skipped = array();

		foreach ( $this->exportable_option_types() as $option => $expected_type ) {
			if ( ! array_key_exists( $option, $data ) ) {
				continue;
			}

			$value = $data[ $option ];

			if ( gettype( $value ) !== $expected_type ) {
				$skipped[] = $option;
				continue;
			}

			update_option( $option, $value );
		}

		if ( ! empty( $skipped ) ) {
			return sprintf(
				/* translators: %s: comma-separated list of option keys */
				__( 'Configuration imported, but these keys had an unexpected shape and were skipped: %s', 'blt-surecart-extensions' ),
				implode( ', ', $skipped )
			);
		}

		return __( 'Configuration imported.', 'blt-surecart-extensions' );
	}

	/**
	 * The option keys included in export/import (every module-config
	 * option except the API token), mapped to their expected PHP type —
	 * used to reject a malformed/hand-edited import blob per key rather
	 * than trusting arbitrary JSON straight into update_option().
	 *
	 * @return array<string, string>
	 */
	private function exportable_option_types() {
		return array(
			Guardrails::OPT_MODE                 => 'string',
			Guardrails::OPT_AUTO_PURCHASE        => 'boolean',
			Guardrails::OPT_RATE_CEILING_CENTS   => 'integer',
			Guardrails::OPT_RATE_CEILING_PERCENT => 'double',
			Guardrails::OPT_ALLOWED_COUNTRIES    => 'array',
			Guardrails::OPT_ALLOW_MILITARY       => 'boolean',
			self::OPT_SHIP_FROM                  => 'array',
			self::OPT_RECONCILE_HOURS            => 'integer',
			ParcelMapper::OPT_PARCELS            => 'array',
			ParcelMapper::OPT_SKU_MAP            => 'array',
			ParcelMapper::OPT_DEFAULT_PARCEL     => 'string',
			ServiceSelector::OPT_RULES           => 'array',
		);
	}

	/**
	 * Current values for every exportable option.
	 *
	 * @return array<string, mixed>
	 */
	private function exportable_config() {
		$defaults_by_type = array(
			'string'  => '',
			'boolean' => false,
			'integer' => 0,
			'double'  => 0.0,
			'array'   => array(),
		);

		$config = array();

		foreach ( $this->exportable_option_types() as $option => $expected_type ) {
			$config[ $option ] = get_option( $option, $defaults_by_type[ $expected_type ] );
		}

		return $config;
	}
}
