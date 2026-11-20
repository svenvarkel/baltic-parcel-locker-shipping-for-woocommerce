<?php
/**
 * Parcel machine shipping methods + classic (shortcode) checkout terminal picker.
 */

defined( 'ABSPATH' ) || exit;

abstract class BPL_Shipping_Method extends WC_Shipping_Method {

	public function __construct( $instance_id = 0 ) {
		parent::__construct( $instance_id );
		$this->supports             = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );
		$this->instance_form_fields = array(
			'title'      => array(
				'title'   => __( 'Title', 'baltic-parcel-locker-shipping-for-woocommerce' ),
				'type'    => 'text',
				'default' => $this->method_title,
			),
			'cost'       => array(
				'title'   => __( 'Cost', 'baltic-parcel-locker-shipping-for-woocommerce' ),
				'type'    => 'price',
				'default' => '0',
			),
			'free_from'  => array(
				'title'       => __( 'Free from', 'baltic-parcel-locker-shipping-for-woocommerce' ),
				'type'        => 'price',
				'description' => __( 'Free shipping when the cart total (after discounts) is at least this amount. Empty or 0 disables.', 'baltic-parcel-locker-shipping-for-woocommerce' ),
				'default'     => '',
			),
			'tax_status' => array(
				'title'   => __( 'Tax status', 'baltic-parcel-locker-shipping-for-woocommerce' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'default' => 'taxable',
				'options' => array( 'taxable' => __( 'Taxable', 'woocommerce' ), 'none' => _x( 'None', 'Tax status', 'woocommerce' ) ),
			),
		);
		$this->title      = $this->get_option( 'title', $this->method_title );
		$this->tax_status = $this->get_option( 'tax_status', 'taxable' );
	}

	public function is_available( $package ) {
		$country = $package['destination']['country'] ?? '';
		return parent::is_available( $package ) && in_array( $country, BPL_Carriers::COUNTRIES[ $this->id ], true );
	}

	public function calculate_shipping( $package = array() ) {
		$free_from = (float) wc_format_decimal( $this->get_option( 'free_from' ) );
		$cart      = WC()->cart;
		$total     = $cart ? $cart->get_displayed_subtotal() - $cart->get_discount_total() - ( $cart->display_prices_including_tax() ? $cart->get_discount_tax() : 0 ) : 0;
		$this->add_rate( array(
			'id'      => $this->get_rate_id(),
			'label'   => $this->title,
			'cost'    => $free_from > 0 && round( $total, wc_get_price_decimals() ) >= $free_from ? 0 : $this->get_option( 'cost' ),
			'package' => $package,
		) );
	}

	/* ------------------------------------------------------------ checkout */

	public static function init(): void {
		add_filter( 'woocommerce_shipping_methods', function ( $methods ) {
			$methods['bpl_omniva']    = 'BPL_Method_Omniva';
			$methods['bpl_smartpost'] = 'BPL_Method_SmartPost';
			return $methods;
		} );
		add_action( 'woocommerce_after_shipping_rate', array( __CLASS__, 'render_picker' ), 10, 2 );
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'remember' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'woocommerce_order_shipping_to_display', array( __CLASS__, 'display' ), 10, 2 );
	}

	public static function render_picker( $rate, $index ): void {
		$method = $rate->get_method_id();
		$chosen = WC()->session ? ( WC()->session->get( 'chosen_shipping_methods' )[ $index ] ?? '' ) : '';
		if ( ! is_checkout() || ! isset( BPL_Carriers::COUNTRIES[ $method ] ) || $chosen !== $rate->get_id() ) {
			return;
		}
		$country  = WC()->shipping()->get_packages()[ $index ]['destination']['country'] ?? 'EE';
		$selected = WC()->session->get( 'bpl_terminal' )[ $method ] ?? '';
		$groups   = array();
		foreach ( BPL_Carriers::terminals( $method, $country ) as $id => $t ) {
			$groups[ $t['city'] ][ $id ] = $t['name'];
		}
		echo '<div class="bpl-terminal"><select name="bpl_terminal[' . esc_attr( $method ) . ']" required style="max-width:100%;margin-top:.5em">';
		echo '<option value="">' . esc_html__( '— Choose a parcel machine —', 'baltic-parcel-locker-shipping-for-woocommerce' ) . '</option>';
		foreach ( $groups as $city => $items ) {
			echo '<optgroup label="' . esc_attr( $city ) . '">';
			foreach ( $items as $id => $name ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $id ), selected( (string) $id, (string) $selected, false ), esc_html( $name ) );
			}
			echo '</optgroup>';
		}
		echo '</select></div>';
	}

	/** Keep the choice across checkout fragment refreshes. */
	public static function remember( $post_data ): void {
		parse_str( (string) $post_data, $posted );
		if ( isset( $posted['bpl_terminal'] ) && is_array( $posted['bpl_terminal'] ) && WC()->session ) {
			$keep = array_map( 'wc_clean', $posted['bpl_terminal'] ) + (array) WC()->session->get( 'bpl_terminal' );
			WC()->session->set( 'bpl_terminal', $keep );
		}
	}

	/** [method, terminal id, country, terminal] for the chosen bpl method, or null. */
	private static function chosen( array $data ): ?array {
		foreach ( (array) ( $data['shipping_method'] ?? array() ) as $rate_id ) {
			$method = strtok( (string) $rate_id, ':' );
			if ( isset( BPL_Carriers::COUNTRIES[ $method ] ) ) {
				$id      = wc_clean( wp_unslash( $_POST['bpl_terminal'][ $method ] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification -- WC checkout nonce already verified.
				$country = (string) ( ( $data['shipping_country'] ?? '' ) ?: WC()->customer->get_shipping_country() );
				return array( $method, $id, $country, BPL_Carriers::terminals( $method, $country )[ $id ] ?? null );
			}
		}
		return null;
	}

	public static function validate( $data, $errors ): void {
		$c = self::chosen( $data );
		if ( $c && ! $c[3] ) {
			$errors->add( 'shipping', __( 'Please choose a parcel machine.', 'baltic-parcel-locker-shipping-for-woocommerce' ) );
		}
	}

	public static function save( $order, $data ): void {
		$c = self::chosen( $data );
		if ( $c && $c[3] ) {
			$order->update_meta_data( '_bpl_terminal_id', $c[1] );
			$order->update_meta_data( '_bpl_terminal_name', $c[3]['name'] );
			$order->update_meta_data( '_bpl_terminal_country', $c[2] );
		}
	}

	public static function display( $shipping, $order ) {
		$name = $order->get_meta( '_bpl_terminal_name' );
		return $name ? $shipping . ' — ' . $name : $shipping;
	}
}

class BPL_Method_Omniva extends BPL_Shipping_Method {
	public function __construct( $instance_id = 0 ) {
		$this->id           = 'bpl_omniva';
		$this->method_title = __( 'Omniva parcel machine', 'baltic-parcel-locker-shipping-for-woocommerce' );
		parent::__construct( $instance_id );
	}
}

class BPL_Method_SmartPost extends BPL_Shipping_Method {
	public function __construct( $instance_id = 0 ) {
		$this->id           = 'bpl_smartpost';
		$this->method_title = __( 'SmartPost parcel machine', 'baltic-parcel-locker-shipping-for-woocommerce' );
		parent::__construct( $instance_id );
	}
}
