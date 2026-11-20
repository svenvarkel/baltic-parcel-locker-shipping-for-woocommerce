<?php
/**
 * Omniva OMX + SmartPosti: terminal lists, pure payload builders / response parsers, API calls.
 *
 * Pure functions (normalize_phone, *_payload, parse_*) take plain arrays so tests/selfcheck.php
 * can run them without WordPress.
 */

defined( 'ABSPATH' ) || exit;

class BPL_Carriers {

	const COUNTRIES = array(
		'bpl_omniva'    => array( 'EE', 'LV', 'LT' ),
		'bpl_smartpost' => array( 'EE', 'FI' ),
	);
	const OMNIVA_URL        = array( 'test' => 'https://test-omx.omniva.eu/api/v01/omx/', 'live' => 'https://omx.omniva.eu/api/v01/omx/' );
	const SMARTPOST_URL     = 'https://gateway.posti.fi/smartpost/api/ext/v1/';
	const SMARTPOST_PLACES  = 'https://my.smartposti.ee/api/ext/v1/places?country=';
	const OMNIVA_LOCATIONS  = 'https://www.omniva.ee/locations.json';
	const SMARTPOST_FORMAT  = 'A6';
	const DIAL              = array( 'EE' => '372', 'LV' => '371', 'LT' => '370', 'FI' => '358' );

	/* ---------------------------------------------------------------- pure */

	/** Local or international number → +<cc><digits>. Returns '' for empty input. */
	public static function normalize_phone( string $phone, string $country ): string {
		$digits = preg_replace( '/\D+/', '', $phone );
		if ( '' === $digits ) {
			return '';
		}
		$cc = self::DIAL[ $country ] ?? '';
		if ( str_starts_with( trim( $phone ), '+' ) ) {
			return '+' . $digits;
		}
		if ( str_starts_with( $digits, '00' ) ) {
			return '+' . substr( $digits, 2 );
		}
		if ( '' !== $cc && str_starts_with( $digits, $cc ) && strlen( $digits ) > strlen( $cc ) + 6 ) {
			return '+' . $digits;
		}
		// Trunk prefixes: FI 0xx, LT 8xx (old) / 0xx (new). EE and LV have none.
		if ( 'FI' === $country || 'LT' === $country ) {
			$digits = preg_replace( '/^(0|8)/', '', $digits );
		}
		return '+' . $cc . $digits;
	}

	/** Recursively drop null / '' / empty arrays (Omniva: absent values must not be sent). */
	public static function strip_empty( array $a ): array {
		foreach ( $a as $k => $v ) {
			if ( is_array( $v ) ) {
				$v = self::strip_empty( $v );
			}
			if ( null === $v || '' === $v || array() === $v ) {
				unset( $a[ $k ] );
			} else {
				$a[ $k ] = $v;
			}
		}
		return $a;
	}

	/**
	 * @param array $o order data: ref, name, phone, email, country, terminal, weight (kg|null)
	 * @param array $s settings (BPL_Settings::get())
	 */
	public static function omniva_payload( array $o, array $s ): array {
		$shipment = array(
			'partnerShipmentId' => (string) $o['ref'],
			'mainService'       => 'PARCEL',
			'deliveryChannel'   => 'PARCEL_MACHINE',
			'receiverAddressee' => array(
				'personName'    => mb_substr( $o['name'], 0, 50 ),
				'contactMobile' => self::normalize_phone( $o['phone'], $o['country'] ),
				'contactEmail'  => $o['email'],
				'address'       => array( 'country' => $o['country'], 'offloadPostcode' => (string) $o['terminal'] ),
			),
			'senderAddressee'   => array(
				'personName'    => mb_substr( $s['sender_name'], 0, 50 ),
				'contactMobile' => self::normalize_phone( $s['sender_phone'], 'EE' ),
				'contactEmail'  => $s['sender_email'],
				'address'       => array(
					'street'        => $s['sender_street'],
					'deliverypoint' => $s['sender_city'],
					'postcode'      => $s['sender_postcode'],
					'country'       => 'EE',
				),
			),
			'measurement'       => array( 'weight' => $o['weight'] ),
		);
		if ( 'yes' === $s['omniva_sms'] ) {
			$shipment['notifications'] = array( array( 'type' => 'REGISTERED', 'channel' => 'sms' ) );
		}
		return self::strip_empty( array( 'customerCode' => $s['omniva_customer'], 'shipments' => array( $shipment ) ) );
	}

	public static function smartpost_payload( array $o, array $s ): array {
		$item = array(
			'reference'   => (string) $o['ref'],
			'weight'      => $o['weight'],
			'source'      => array( 'country' => 'EE' ),
			'recipient'   => array(
				'name'  => $o['name'],
				'phone' => self::normalize_phone( $o['phone'], $o['country'] ),
				'email' => $o['email'],
			),
			// place_id > 1 forbids every other destination field, country included.
			'destination' => array( 'place_id' => (string) $o['terminal'] ),
		);
		if ( 'yes' === $s['smartpost_doorcode'] ) {
			// The sender block is what requests the door code; size is then mandatory.
			$item['size']   = $s['smartpost_size'];
			$item['sender'] = array(
				'name'  => $s['sender_name'],
				'phone' => self::normalize_phone( $s['sender_phone'], 'EE' ),
				'email' => $s['sender_email'],
			);
		}
		// ponytail: additionalservices.labelprinted omitted; flip it if self-service shows orders as "awaiting label".
		return array( 'orders' => array( 'item' => self::strip_empty( $item ) ) );
	}

	/**
	 * Map an HTTP outcome to the shipment state machine.
	 * $code: HTTP status, or 0 = transport error where the request may have been sent (timeout), -1 = not sent.
	 * Returns [state created|failed|unknown, barcode, doorcode, message].
	 */
	public static function parse_omniva_create( int $code, string $body ): array {
		$j = json_decode( $body, true );
		if ( $code <= 0 || $code >= 300 ) {
			return self::transport_state( $code, $j, $body );
		}
		if ( ! is_array( $j ) ) {
			return array( 'unknown', '', '', 'Unparseable response: ' . mb_substr( $body, 0, 200 ) );
		}
		$barcode = $j['savedShipments'][0]['barcode'] ?? '';
		if ( 'OK' === ( $j['resultCode'] ?? '' ) && '' !== $barcode ) {
			return array( 'created', $barcode, '', '' );
		}
		if ( ! empty( $j['failedShipments'] ) ) {
			$f = $j['failedShipments'][0];
			return array( 'failed', '', '', trim( ( $f['messageCode'] ?? '' ) . ': ' . ( $f['message'] ?? '' ), ': ' ) );
		}
		return array( 'unknown', '', '', 'Unexpected response: ' . mb_substr( $body, 0, 200 ) );
	}

	public static function parse_smartpost_create( int $code, string $body ): array {
		$j = json_decode( $body, true );
		if ( $code <= 0 || $code >= 300 ) {
			return self::transport_state( $code, $j, $body );
		}
		$item    = $j['orders']['item'][0] ?? ( $j['orders']['item'] ?? null );
		$barcode = is_array( $item ) ? (string) ( $item['barcode'] ?? '' ) : '';
		if ( '' === $barcode ) {
			return array( 'unknown', '', '', 'Unexpected response: ' . mb_substr( $body, 0, 200 ) );
		}
		return array( 'created', $barcode, (string) ( $item['sender']['doorcode'] ?? '' ), '' );
	}

	/**
	 * Non-2xx outcome. Only a definite rejection (4xx, or -1 = never sent) is 'failed';
	 * timeouts (0), 3xx and 5xx may have created the shipment anyway → 'unknown' blocks a duplicate.
	 */
	private static function transport_state( int $code, $j, string $body ): array {
		if ( $code <= 0 ) {
			return array( $code < 0 ? 'failed' : 'unknown', '', '', $body );
		}
		return array( $code >= 400 && $code < 500 ? 'failed' : 'unknown', '', '', self::error_text( $code, $j, $body ) );
	}

	private static function error_text( int $code, $j, string $body ): string {
		if ( is_array( $j ) ) {
			$msgs = array();
			array_walk_recursive( $j, function ( $v, $k ) use ( &$msgs ) {
				if ( in_array( $k, array( 'details', 'message', 'error', 'code' ), true ) && is_scalar( $v ) ) {
					$msgs[] = (string) $v;
				}
			} );
			if ( $msgs ) {
				return "HTTP $code: " . implode( '; ', array_unique( $msgs ) );
			}
		}
		return "HTTP $code: " . mb_substr( self::plain( $body ), 0, 300 );
	}

	/** strip_tags without depending on WordPress (selfcheck). */
	private static function plain( string $s ): string {
		return trim( preg_replace( '/\s+/', ' ', strip_tags( $s ) ) );
	}

	/* ---------------------------------------------------------- WordPress */

	private static function log( string $msg ): void {
		wc_get_logger()->error( $msg, array( 'source' => 'baltic-parcel-locker-shipping-for-woocommerce' ) );
	}

	/** wp_remote_request wrapper → [code, body]. code 0 = timeout (maybe sent), -1 = other transport error. */
	private static function http( string $method, string $url, array $headers = array(), ?string $body = null ): array {
		// No redirects on POST: a redirected create could fail after the carrier accepted it.
		$res = wp_remote_request( $url, array( 'method' => $method, 'timeout' => 20, 'headers' => $headers, 'body' => $body, 'redirection' => 'POST' === $method ? 0 : 5 ) );
		if ( is_wp_error( $res ) ) {
			$msg = $res->get_error_message();
			self::log( "$method $url: $msg" );
			// Only DNS / connect failures (cURL 6, 7) prove the request was never sent; anything else may have reached the carrier → unknown.
			return array( preg_match( '/cURL error (6|7):/', $msg ) ? -1 : 0, $msg );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$out  = (string) wp_remote_retrieve_body( $res );
		if ( $code >= 300 ) {
			self::log( "$method $url: HTTP $code " . mb_substr( $out, 0, 500 ) );
		}
		return array( $code, $out );
	}

	/** Terminals for a carrier + country: [id => ['name' =>, 'city' =>]] sorted by city, name. */
	public static function terminals( string $carrier, string $country ): array {
		$key = "bpl_terminals_{$carrier}_{$country}";
		$hit = get_transient( $key );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$list = 'bpl_omniva' === $carrier ? self::fetch_omniva( $country ) : self::fetch_smartpost( $country );
		if ( $list ) {
			uasort( $list, fn( $a, $b ) => array( $a['city'], $a['name'] ) <=> array( $b['city'], $b['name'] ) );
			update_option( "{$key}_backup", $list, false );
			set_transient( $key, $list, DAY_IN_SECONDS );
			return $list;
		}
		// Fetch failed: serve the last good copy, retry in an hour.
		$list = (array) get_option( "{$key}_backup", array() );
		set_transient( $key, $list, HOUR_IN_SECONDS );
		return $list;
	}

	// ponytail: locations.json holds all countries; fetched once per country per day instead of sharing a cache.
	private static function fetch_omniva( string $country ): array {
		[ $code, $body ] = self::http( 'GET', self::OMNIVA_LOCATIONS );
		$out = array();
		foreach ( 200 === $code ? (array) json_decode( $body, true ) : array() as $l ) {
			// ponytail: skip Omniva's own junk rows (no county = placeholder like "1. eelistus", company-internal machines); name match breaks if they rename it.
			if ( '' === ( $l['A1_NAME'] ?? '' ) || false !== mb_stripos( $l['NAME'] ?? '', 'ettevõttesisene' ) ) {
				continue;
			}
			if ( ( $l['A0_NAME'] ?? '' ) === $country && '0' === (string) ( $l['TYPE'] ?? '' ) ) {
				$city = 'EE' === $country ? $l['A2_NAME'] : $l['A1_NAME'];
				$out[ (string) $l['ZIP'] ] = array( 'name' => $l['NAME'], 'city' => $city ?: '—' );
			}
		}
		return $out;
	}

	private static function fetch_smartpost( string $country ): array {
		[ $code, $body ] = self::http( 'GET', self::SMARTPOST_PLACES . $country, array( 'Content-Type' => 'application/json' ) );
		$out = array();
		foreach ( 200 === $code ? (array) ( json_decode( $body, true )['places']['item'] ?? array() ) : array() as $p ) {
			// All place types (apt, ipb, po, pudo) — old plugin showed "all"; FI has no ipb.
			if ( ! empty( $p['place_id'] ) ) {
				$out[ (string) $p['place_id'] ] = array( 'name' => $p['name'], 'city' => $p['city'] ?: '—' );
			}
		}
		return $out;
	}

	/**
	 * What to ship to: [carrier, terminal id, country] or null. Handles legacy
	 * estonian-shipping-methods Omniva orders (meta value = machine ZIP = offloadPostcode).
	 */
	public static function order_target( WC_Order $order ): ?array {
		foreach ( array_keys( self::COUNTRIES ) as $carrier ) {
			if ( $order->has_shipping_method( $carrier ) && $order->get_meta( '_bpl_terminal_id' ) ) {
				return array( $carrier, (string) $order->get_meta( '_bpl_terminal_id' ), (string) $order->get_meta( '_bpl_terminal_country' ) );
			}
		}
		foreach ( array( 'ee', 'lv', 'lt' ) as $cc ) {
			$zip = $order->get_meta( "wc_shipping_omniva_parcel_machines_{$cc}_terminal" );
			if ( $zip && $order->has_shipping_method( "omniva_parcel_machines_$cc" ) ) {
				return array( 'bpl_omniva', (string) $zip, strtoupper( $cc ) );
			}
		}
		return null;
	}

	private static function order_data( WC_Order $order, array $target ): array {
		$weight = 0.0;
		foreach ( $order->get_items() as $item ) {
			$p = $item->get_product();
			if ( $p && is_numeric( $p->get_weight() ) ) {
				$weight += (float) $p->get_weight() * $item->get_quantity();
			}
		}
		$name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() )
			?: trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		return array(
			'ref'      => $order->get_order_number(),
			'name'     => $name,
			'phone'    => $order->get_shipping_phone() ?: $order->get_billing_phone(),
			'email'    => $order->get_billing_email(),
			'country'  => $target[2],
			'terminal' => $target[1],
			'weight'   => $weight > 0 ? round( wc_get_weight( $weight, 'kg' ), 3 ) : null,
		);
	}

	private static function omniva_headers( array $s ): array {
		return array(
			'Authorization' => 'Basic ' . base64_encode( $s['omniva_user'] . ':' . $s['omniva_password'] ),
			'Content-Type'  => 'application/json',
		);
	}

	/** Register the shipment. Returns [state, barcode, doorcode, message, env]. */
	public static function create( WC_Order $order, array $target ): array {
		$s = BPL_Settings::get();
		$o = self::order_data( $order, $target );
		if ( 'bpl_omniva' === $target[0] ) {
			$env = 'live' === $s['omniva_env'] ? 'live' : 'test';
			[ $code, $body ] = self::http( 'POST', self::OMNIVA_URL[ $env ] . 'shipments/business-to-client', self::omniva_headers( $s ), wp_json_encode( self::omniva_payload( $o, $s ) ) );
			return array_merge( self::parse_omniva_create( $code, $body ), array( $env ) );
		}
		[ $code, $body ] = self::http( 'POST', self::SMARTPOST_URL . 'orders', array(
			'Authorization' => $s['smartpost_key'],
			'Content-Type'  => 'application/json',
		), wp_json_encode( self::smartpost_payload( $o, $s ) ) );
		return array_merge( self::parse_smartpost_create( $code, $body ), array( 'live' ) );
	}

	/** Label PDF bytes for a created shipment, or WP_Error. */
	public static function label( string $carrier, string $barcode, string $env ) {
		$s = BPL_Settings::get();
		if ( 'bpl_omniva' === $carrier ) {
			$url = self::OMNIVA_URL[ 'live' === $env ? 'live' : 'test' ] . 'shipments/package-labels';
			// Manual says barcodes are strings, OpenAPI says {barcode} objects: try strings, then objects once.
			foreach ( array( array( $barcode ), array( array( 'barcode' => $barcode ) ) ) as $barcodes ) {
				$req = array( 'customerCode' => $s['omniva_customer'], 'barcodes' => $barcodes, 'sendAddressCardTo' => 'RESPONSE' );
				[ $code, $body ] = self::http( 'POST', $url, self::omniva_headers( $s ), wp_json_encode( $req ) );
				if ( $code < 400 || $code >= 500 ) {
					break;
				}
			}
			$card = json_decode( $body, true )['successAddressCards'][0] ?? array();
			$pdf  = base64_decode( (string) ( $card['fileData'] ?? $card['filedata'] ?? '' ), true );
		} else {
			[ $code, $body ] = self::http( 'GET', self::SMARTPOST_URL . 'labels?' . http_build_query( array( 'barcode' => $barcode, 'format' => self::SMARTPOST_FORMAT ) ), array(
				'Authorization' => $s['smartpost_key'],
				'Content-Type'  => 'application/json',
			) );
			$pdf = $body;
			// Doc says "PDF file URL": if we got a URL (plain or inside JSON) instead of bytes, fetch it.
			if ( 200 === $code && ! str_starts_with( $body, '%PDF' ) && preg_match( '#https?://[^\s"\'<>]+#', stripslashes( $body ), $m ) ) {
				[ $code, $pdf ] = self::http( 'GET', $m[0] );
			}
		}
		if ( ! is_string( $pdf ) || ! str_starts_with( $pdf, '%PDF' ) ) {
			self::log( "Label for $barcode failed: HTTP $code " . mb_substr( (string) $body, 0, 300 ) );
			return new WP_Error( 'bpl_label', sprintf( __( 'Label request failed (HTTP %1$d): %2$s', 'baltic-parcel-locker-shipping-for-woocommerce' ), $code, mb_substr( self::plain( (string) $body ), 0, 300 ) ) );
		}
		return $pdf;
	}
}
