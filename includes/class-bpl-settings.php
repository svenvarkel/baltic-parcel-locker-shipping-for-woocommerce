<?php
/**
 * WooCommerce > Settings > Baltic Parcel Locker Shipping. All values live in one option array `bpl_settings`;
 * secrets are stored as `bpl1:` ciphertext and never rendered back.
 */

defined( 'ABSPATH' ) || exit;

class BPL_Settings {

	const OPTION  = 'bpl_settings';
	const SECRETS = array( 'omniva_password', 'smartpost_key' );

	public static function init(): void {
		add_filter( 'woocommerce_settings_tabs_array', array( __CLASS__, 'tab' ), 60 );
		add_action( 'woocommerce_settings_tabs_bpl_shipping', array( __CLASS__, 'output' ) );
		add_action( 'woocommerce_update_options_bpl_shipping', array( __CLASS__, 'save' ) );
		add_action( 'woocommerce_admin_field_bpl_secret', array( __CLASS__, 'secret_field' ) );
		add_filter( 'woocommerce_admin_settings_sanitize_option', array( __CLASS__, 'sanitize_secret' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'decrypt_notice' ) );
	}

	/** Settings with secrets decrypted ('' when missing or undecryptable). */
	public static function get(): array {
		$s = (array) get_option( self::OPTION, array() ) + array(
			'omniva_env'          => 'test',
			'omniva_user'         => '',
			'omniva_customer'     => '',
			'omniva_sms'          => 'no',
			'smartpost_doorcode'  => 'no',
			'smartpost_size'      => 'M',
			'sender_name'         => '',
			'sender_phone'        => '',
			'sender_email'        => '',
			'sender_street'       => '',
			'sender_city'         => '',
			'sender_postcode'     => '',
		);
		foreach ( self::SECRETS as $k ) {
			$s[ $k ] = BPL_Crypto::decrypt( (string) ( $s[ $k ] ?? '' ) ) ?? '';
		}
		return $s;
	}

	public static function tab( array $tabs ): array {
		$tabs['bpl_shipping'] = __( 'Baltic Parcel Locker Shipping', 'baltic-parcel-locker-shipping-for-woocommerce' );
		return $tabs;
	}

	private static function fields(): array {
		$f = fn( $key, $name, $type = 'text', $extra = array() ) => array(
			'id'   => self::OPTION . "[$key]",
			'name' => $name,
			'type' => $type,
		) + $extra;
		$yes_no = fn( $key, $name, $desc ) => $f( $key, $name, 'checkbox', array( 'desc' => $desc, 'default' => 'no' ) );

		return array(
			array( 'type' => 'title', 'id' => 'bpl_omniva_section', 'name' => 'Omniva (OMX)' ),
			$f( 'omniva_env', __( 'Environment', 'baltic-parcel-locker-shipping-for-woocommerce' ), 'select', array(
				'options' => array( 'test' => 'TEST (test-omx.omniva.eu)', 'live' => 'LIVE (omx.omniva.eu)' ),
				'default' => 'test',
			) ),
			$f( 'omniva_user', __( 'Username', 'baltic-parcel-locker-shipping-for-woocommerce' ) ),
			$f( 'omniva_password', __( 'Password', 'baltic-parcel-locker-shipping-for-woocommerce' ), 'bpl_secret' ),
			$f( 'omniva_customer', __( 'Customer code', 'baltic-parcel-locker-shipping-for-woocommerce' ), 'text', array( 'desc' => __( 'Usually the same as the API username.', 'baltic-parcel-locker-shipping-for-woocommerce' ) ) ),
			$yes_no( 'omniva_sms', __( 'Registration SMS', 'baltic-parcel-locker-shipping-for-woocommerce' ), __( 'Send a registration SMS to the sender (pakikood? — unverified)', 'baltic-parcel-locker-shipping-for-woocommerce' ) ),
			array( 'type' => 'sectionend', 'id' => 'bpl_omniva_section' ),

			array( 'type' => 'title', 'id' => 'bpl_smartpost_section', 'name' => 'SmartPosti' ),
			$f( 'smartpost_key', __( 'API key', 'baltic-parcel-locker-shipping-for-woocommerce' ), 'bpl_secret' ),
			$yes_no( 'smartpost_doorcode', __( 'Door code', 'baltic-parcel-locker-shipping-for-woocommerce' ), __( 'Request a sender door code (the service must be activated in SmartPosti self-service)', 'baltic-parcel-locker-shipping-for-woocommerce' ) ),
			$f( 'smartpost_size', __( 'Default parcel size', 'baltic-parcel-locker-shipping-for-woocommerce' ), 'select', array(
				'options' => array_combine( array( 'XS', 'S', 'M', 'L', 'XL' ), array( 'XS', 'S', 'M', 'L', 'XL' ) ),
				'default' => 'M',
			) ),
			array( 'type' => 'sectionend', 'id' => 'bpl_smartpost_section' ),

			array( 'type' => 'title', 'id' => 'bpl_sender', 'name' => __( 'Sender', 'baltic-parcel-locker-shipping-for-woocommerce' ) ),
			$f( 'sender_name', __( 'Name', 'baltic-parcel-locker-shipping-for-woocommerce' ) ),
			$f( 'sender_phone', __( 'Mobile phone', 'baltic-parcel-locker-shipping-for-woocommerce' ) ),
			$f( 'sender_email', __( 'E-mail', 'baltic-parcel-locker-shipping-for-woocommerce' ) ),
			$f( 'sender_street', __( 'Street', 'baltic-parcel-locker-shipping-for-woocommerce' ) ),
			$f( 'sender_city', __( 'City', 'baltic-parcel-locker-shipping-for-woocommerce' ) ),
			$f( 'sender_postcode', __( 'Postcode', 'baltic-parcel-locker-shipping-for-woocommerce' ) ),
			array( 'type' => 'sectionend', 'id' => 'bpl_sender' ),
		);
	}

	public static function output(): void {
		woocommerce_admin_fields( self::fields() );
	}

	public static function save(): void {
		woocommerce_update_options( self::fields() );
	}

	public static function secret_field( array $field ): void {
		$key    = str_replace( array( self::OPTION . '[', ']' ), '', $field['id'] );
		$stored = (string) ( get_option( self::OPTION, array() )[ $key ] ?? '' );
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><label for="<?php echo esc_attr( $field['id'] ); ?>"><?php echo esc_html( $field['name'] ); ?></label></th>
			<td class="forminp">
				<input type="password" autocomplete="new-password" name="<?php echo esc_attr( $field['id'] ); ?>" id="<?php echo esc_attr( $field['id'] ); ?>" value=""
					placeholder="<?php echo '' !== $stored ? esc_attr__( 'saved — leave blank to keep', 'baltic-parcel-locker-shipping-for-woocommerce' ) : ''; ?>" />
			</td>
		</tr>
		<?php
	}

	/** Blank submit keeps the stored value (null = WC skips it); anything else gets encrypted. */
	public static function sanitize_secret( $value, $option, $raw ) {
		if ( ( $option['type'] ?? '' ) !== 'bpl_secret' ) {
			return $value;
		}
		$raw = trim( (string) $raw );
		return '' === $raw ? null : BPL_Crypto::encrypt( $raw );
	}

	public static function decrypt_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$stored = (array) get_option( self::OPTION, array() );
		foreach ( self::SECRETS as $k ) {
			if ( ! empty( $stored[ $k ] ) && null === BPL_Crypto::decrypt( (string) $stored[ $k ] ) ) {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html__( 'Baltic Parcel Locker Shipping: stored carrier credentials cannot be decrypted (salts or key changed). Please re-enter them.', 'baltic-parcel-locker-shipping-for-woocommerce' )
				);
				return;
			}
		}
	}
}
