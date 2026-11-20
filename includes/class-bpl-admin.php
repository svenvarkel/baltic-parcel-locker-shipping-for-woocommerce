<?php
/**
 * Order edit screen metabox: create shipment, show barcode/door code, print label, release a stuck lock.
 *
 * State machine (`_bpl_state`): pending → created | failed | unknown.
 * A per-order lock row in wp_options (INSERT IGNORE) makes create atomic; it is released on
 * created/failed and kept on unknown (timeout / unparseable 2xx) until an admin releases it.
 */

defined( 'ABSPATH' ) || exit;

class BPL_Admin {

	const CAP        = 'edit_shop_orders';
	const STALE_SECS = 120;

	public static function init(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ) );
		add_action( 'admin_post_bpl_ship_create', array( __CLASS__, 'create' ) );
		add_action( 'admin_post_bpl_ship_label', array( __CLASS__, 'label' ) );
		add_action( 'admin_post_bpl_ship_release', array( __CLASS__, 'release' ) );
		add_action( 'admin_notices', array( __CLASS__, 'flash_show' ) );
	}

	public static function add_box(): void {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'baltic-parcel-locker-shipping-for-woocommerce', __( 'Parcel', 'baltic-parcel-locker-shipping-for-woocommerce' ), array( __CLASS__, 'render' ), $screen, 'side', 'high' );
	}

	private static function lock_name( int $id ): string {
		return "bpl_ship_lock_$id";
	}

	private static function url( string $action, int $id ): string {
		return wp_nonce_url( admin_url( "admin-post.php?action=$action&order_id=$id" ), "{$action}_$id" );
	}

	public static function render( $post_or_order ): void {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		$id      = $order->get_id();
		$barcode = (string) $order->get_meta( '_bpl_barcode' );
		$state   = (string) $order->get_meta( '_bpl_state' );
		$target  = BPL_Carriers::order_target( $order );

		if ( $barcode ) {
			printf( '<p>%s: <strong>%s</strong></p>', esc_html__( 'Barcode', 'baltic-parcel-locker-shipping-for-woocommerce' ), esc_html( $barcode ) );
			if ( $order->get_meta( '_bpl_doorcode' ) ) {
				printf( '<p>%s: <strong>%s</strong></p>', esc_html__( 'Door code', 'baltic-parcel-locker-shipping-for-woocommerce' ), esc_html( $order->get_meta( '_bpl_doorcode' ) ) );
			}
			if ( 'test' === $order->get_meta( '_bpl_env' ) ) {
				echo '<p><em>Omniva TEST</em></p>';
			}
			printf( '<p><a class="button button-primary" target="_blank" href="%s">%s</a></p>', esc_url( self::url( 'bpl_ship_label', $id ) ), esc_html__( 'Print label', 'baltic-parcel-locker-shipping-for-woocommerce' ) );
			return;
		}

		if ( ! $target ) {
			if ( $order->has_shipping_method( 'smartpost_estonia' ) || $order->has_shipping_method( 'smartpost_finland' ) ) {
				echo '<p>' . esc_html__( 'Legacy SmartPost order: terminal ID is not compatible, create the shipment in SmartPosti self-service.', 'baltic-parcel-locker-shipping-for-woocommerce' ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'Not a parcel machine order.', 'baltic-parcel-locker-shipping-for-woocommerce' ) . '</p>';
			}
			return;
		}

		$name = $order->get_meta( '_bpl_terminal_name' ) ?: $target[1];
		printf( '<p>%s<br>%s (%s)</p>', esc_html( 'bpl_omniva' === $target[0] ? 'Omniva' : 'SmartPost' ), esc_html( $name ), esc_html( $target[2] ) );

		$lock  = (int) get_option( self::lock_name( $id ), 0 );
		// Lock age alone decides staleness: a fatal before 'pending' was saved must not block the order forever.
		$stale = $lock && time() - $lock > self::STALE_SECS;
		if ( 'unknown' === $state || $stale ) {
			echo '<p style="color:#b32d2e">' . esc_html__( 'Shipment status is unknown (the carrier did not answer clearly). Check carrier self-service before creating it again.', 'baltic-parcel-locker-shipping-for-woocommerce' ) . '</p>';
			printf( '<p><a class="button" href="%s" onclick="return confirm(this.dataset.q)" data-q="%s">%s</a></p>',
				esc_url( self::url( 'bpl_ship_release', $id ) ),
				esc_attr__( 'Release only if the shipment does NOT exist at the carrier. Continue?', 'baltic-parcel-locker-shipping-for-woocommerce' ),
				esc_html__( 'Release lock', 'baltic-parcel-locker-shipping-for-woocommerce' )
			);
			return;
		}
		if ( $lock ) {
			echo '<p>' . esc_html__( 'Shipment is being created…', 'baltic-parcel-locker-shipping-for-woocommerce' ) . '</p>';
			return;
		}
		if ( 'failed' === $state ) {
			printf( '<p style="color:#b32d2e">%s</p>', esc_html( (string) $order->get_meta( '_bpl_error' ) ) );
		}
		printf( '<p><a class="button button-primary" href="%s" onclick="this.style.pointerEvents=\'none\'">%s</a></p>', esc_url( self::url( 'bpl_ship_create', $id ) ), esc_html__( 'Create shipment', 'baltic-parcel-locker-shipping-for-woocommerce' ) );
	}

	/** Checks cap + nonce, returns the order. */
	private static function guard( string $action ): WC_Order {
		$id = absint( $_GET['order_id'] ?? 0 );
		if ( ! current_user_can( self::CAP ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), "{$action}_$id" ) ) {
			wp_die( esc_html__( 'Not allowed.', 'baltic-parcel-locker-shipping-for-woocommerce' ), 403 );
		}
		$order = wc_get_order( $id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'baltic-parcel-locker-shipping-for-woocommerce' ), 404 );
		}
		return $order;
	}

	private static function back( WC_Order $order, string $msg, bool $error = false ): void {
		set_transient( 'bpl_ship_flash_' . get_current_user_id(), array( $msg, $error ), 60 );
		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}

	public static function create(): void {
		global $wpdb;
		$order  = self::guard( 'bpl_ship_create' );
		$id     = $order->get_id();
		$target = BPL_Carriers::order_target( $order );
		if ( ! $target || $order->get_meta( '_bpl_barcode' ) ) {
			self::back( $order, __( 'Nothing to create: shipment exists or order has no parcel machine.', 'baltic-parcel-locker-shipping-for-woocommerce' ), true );
		}
		$got = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
			self::lock_name( $id ),
			(string) time()
		) );
		if ( 1 !== $got ) {
			self::back( $order, __( 'Shipment creation is already in progress or its status is unknown.', 'baltic-parcel-locker-shipping-for-woocommerce' ), true );
		}
		wp_cache_delete( 'notoptions', 'options' );
		// Re-read after locking: a request that finished just before us may have saved a barcode and released the lock.
		clean_post_cache( $id );
		wp_cache_delete( $id, 'orders' );
		$order = wc_get_order( $id );
		$order->read_meta_data( true ); // bypass WC's object_meta cache
		if ( $order->get_meta( '_bpl_barcode' ) ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => self::lock_name( $id ) ) );
			self::back( $order, __( 'Nothing to create: shipment exists or order has no parcel machine.', 'baltic-parcel-locker-shipping-for-woocommerce' ), true );
		}
		$order->update_meta_data( '_bpl_state', 'pending' );
		$order->save();

		[ $state, $barcode, $doorcode, $message, $env ] = BPL_Carriers::create( $order, $target );
		$carrier = 'bpl_omniva' === $target[0] ? 'Omniva' : 'SmartPost';

		$order->update_meta_data( '_bpl_state', $state );
		if ( 'created' === $state ) {
			$order->update_meta_data( '_bpl_barcode', $barcode );
			$order->update_meta_data( '_bpl_doorcode', $doorcode );
			$order->update_meta_data( '_bpl_carrier', $target[0] );
			$order->update_meta_data( '_bpl_env', $env );
			$order->delete_meta_data( '_bpl_error' );
			$note = sprintf( __( '%1$s shipment created: %2$s', 'baltic-parcel-locker-shipping-for-woocommerce' ), $carrier, $barcode ) . ( $doorcode ? sprintf( __( ', door code %s', 'baltic-parcel-locker-shipping-for-woocommerce' ), $doorcode ) : '' );
		} else {
			$order->update_meta_data( '_bpl_error', $message );
			$note = sprintf( __( '%1$s shipment %2$s: %3$s', 'baltic-parcel-locker-shipping-for-woocommerce' ), $carrier, 'failed' === $state ? __( 'failed', 'baltic-parcel-locker-shipping-for-woocommerce' ) : __( 'status unknown', 'baltic-parcel-locker-shipping-for-woocommerce' ), $message );
		}
		$order->add_order_note( $note );
		$order->save();
		if ( 'unknown' !== $state ) {
			delete_option( self::lock_name( $id ) );
		}
		self::back( $order, $note, 'created' !== $state );
	}

	public static function release(): void {
		$order = self::guard( 'bpl_ship_release' );
		delete_option( self::lock_name( $order->get_id() ) );
		$order->update_meta_data( '_bpl_state', 'failed' );
		$order->update_meta_data( '_bpl_error', __( 'Lock released manually.', 'baltic-parcel-locker-shipping-for-woocommerce' ) );
		$order->add_order_note( __( 'Shipment lock released manually.', 'baltic-parcel-locker-shipping-for-woocommerce' ) );
		$order->save();
		self::back( $order, __( 'Lock released.', 'baltic-parcel-locker-shipping-for-woocommerce' ) );
	}

	/** Streams the label inline; labels contain personal data, so nothing is stored. */
	public static function label(): void {
		$order   = self::guard( 'bpl_ship_label' );
		$barcode = (string) $order->get_meta( '_bpl_barcode' );
		if ( ! $barcode ) {
			wp_die( esc_html__( 'No shipment for this order.', 'baltic-parcel-locker-shipping-for-woocommerce' ) );
		}
		$pdf = BPL_Carriers::label( (string) $order->get_meta( '_bpl_carrier' ), $barcode, (string) $order->get_meta( '_bpl_env' ) );
		if ( is_wp_error( $pdf ) ) {
			wp_die( esc_html( $pdf->get_error_message() ) );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="label-' . sanitize_file_name( $barcode ) . '.pdf"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput -- binary PDF.
		exit;
	}

	public static function flash_show(): void {
		$key   = 'bpl_ship_flash_' . get_current_user_id();
		$flash = get_transient( $key );
		if ( $flash ) {
			delete_transient( $key );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $flash[1] ? 'error' : 'success', esc_html( $flash[0] ) );
		}
	}
}
