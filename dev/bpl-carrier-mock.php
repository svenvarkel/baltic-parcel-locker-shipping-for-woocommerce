<?php
/**
 * Plugin Name: Baltic Parcel Locker Shipping carrier mock (DEV ONLY)
 * Description: Intercepts Omniva OMX + SmartPosti API calls with canned responses. Mode: option bpl_mock_mode
 *              = ok | http500 | timeout | omniva_failed | garbage | smartpost_label_url. Never deploy.
 */

defined( 'ABSPATH' ) || exit;

if ( 'local' !== wp_get_environment_type() && ! str_ends_with( (string) wp_parse_url( home_url(), PHP_URL_HOST ), '.local' ) ) {
	return;
}

// Tiny valid one-page PDF ("MOCK LABEL").
const BPL_MOCK_PDF_B64 = 'JVBERi0xLjQKMSAwIG9iago8PCAvVHlwZSAvQ2F0YWxvZyAvUGFnZXMgMiAwIFIgPj4KZW5kb2JqCjIgMCBvYmoKPDwgL1R5cGUgL1BhZ2VzIC9LaWRzIFszIDAgUl0gL0NvdW50IDEgPj4KZW5kb2JqCjMgMCBvYmoKPDwgL1R5cGUgL1BhZ2UgL1BhcmVudCAyIDAgUiAvTWVkaWFCb3ggWzAgMCAyOTggNDIwXSAvQ29udGVudHMgNCAwIFIgL1Jlc291cmNlcyA8PCAvRm9udCA8PCAvRjEgNSAwIFIgPj4gPj4gPj4KZW5kb2JqCjQgMCBvYmoKPDwgL0xlbmd0aCA0NyA+PgpzdHJlYW0KQlQgL0YxIDE0IFRmIDIwIDI1MCBUZCAoTU9DSyBMQUJFTCBsYWJlbCkgVGogRVQKZW5kc3RyZWFtCmVuZG9iago1IDAgb2JqCjw8IC9UeXBlIC9Gb250IC9TdWJ0eXBlIC9UeXBlMSAvQmFzZUZvbnQgL0hlbHZldGljYSA+PgplbmRvYmoKeHJlZgowIDYKMDAwMDAwMDAwMCA2NTUzNSBmIAowMDAwMDAwMDA5IDAwMDAwIG4gCjAwMDAwMDAwNTggMDAwMDAgbiAKMDAwMDAwMDExNSAwMDAwMCBuIAowMDAwMDAwMjQxIDAwMDAwIG4gCjAwMDAwMDAzMzggMDAwMDAgbiAKdHJhaWxlciA8PCAvU2l6ZSA2IC9Sb290IDEgMCBSID4+CnN0YXJ0eHJlZgo0MDgKJSVFT0YK';

function bpl_mock_response( int $code, string $body, string $type = 'application/json' ): array {
	return array(
		'headers'  => array( 'content-type' => $type ),
		'body'     => $body,
		'response' => array( 'code' => $code, 'message' => get_status_header_desc( $code ) ),
		'cookies'  => array(),
		'filename' => null,
	);
}

add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	$host = wp_parse_url( $url, PHP_URL_HOST );
	if ( ! in_array( $host, array( 'omx.omniva.eu', 'test-omx.omniva.eu', 'gateway.posti.fi' ), true ) ) {
		return $pre;
	}
	$mode = get_option( 'bpl_mock_mode', 'ok' );
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	$body = is_string( $args['body'] ?? null ) ? $args['body'] : '';
	$req  = json_decode( $body, true );
	wc_get_logger()->info( "MOCK[$mode] {$args['method']} $url\n$body", array( 'source' => 'bpl-carrier-mock' ) );

	$is_create = str_ends_with( $path, '/business-to-client' ) || str_ends_with( $path, '/orders' );
	if ( 'timeout' === $mode && $is_create ) {
		return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 20001 milliseconds with 0 bytes received' );
	}
	if ( 'http500' === $mode ) {
		return bpl_mock_response( 500, '{"title":"Internal Server Error","details":"Mock 500","code":"INTERNAL_ERROR"}' );
	}
	if ( 'garbage' === $mode && $is_create ) {
		return bpl_mock_response( 200, '<html><body>Bad gateway (mock)</body></html>', 'text/html' );
	}
	$barcode = 'CE' . wp_rand( 100000000, 999999999 ) . 'EE';

	if ( str_ends_with( $path, '/business-to-client' ) ) {
		$ref = $req['shipments'][0]['partnerShipmentId'] ?? '';
		if ( 'omniva_failed' === $mode ) {
			return bpl_mock_response( 200, wp_json_encode( array(
				'resultCode'      => 'ERROR',
				'provider'        => 'EEPOST',
				'failedShipments' => array( array( 'clientItemId' => $ref, 'messageCode' => 'invalid.offloadpostcode', 'message' => 'Invalid offload postcode (mock)' ) ),
			) ) );
		}
		return bpl_mock_response( 200, wp_json_encode( array(
			'resultCode'     => 'OK',
			'provider'       => 'EEPOST',
			'savedShipments' => array( array( 'barcode' => $barcode, 'clientItemId' => $ref ) ),
		) ) );
	}
	if ( str_ends_with( $path, '/package-labels' ) ) {
		$b = $req['barcodes'][0] ?? '';
		$b = is_array( $b ) ? $b['barcode'] : $b;
		return bpl_mock_response( 200, wp_json_encode( array(
			'successAddressCards' => array( array( 'barcode' => $b, 'fileData' => BPL_MOCK_PDF_B64 ) ),
			'failedAddressCards'  => array(),
		) ) );
	}
	if ( str_ends_with( $path, '/orders' ) ) {
		$item = $req['orders']['item'] ?? array();
		if ( 'omniva_failed' === $mode ) {
			return bpl_mock_response( 400, '{"error":"006 = unknown destination (place_id) (mock)"}' );
		}
		$out = array( 'barcode' => 'TESTPP' . wp_rand( 100000000, 999999999 ), 'reference' => $item['reference'] ?? '' );
		if ( isset( $item['sender'] ) ) {
			$out['sender'] = array( 'doorcode' => (string) wp_rand( 100000, 999999 ) );
		}
		return bpl_mock_response( 200, wp_json_encode( array( 'orders' => array( 'item' => array( $out ) ) ) ) );
	}
	if ( str_ends_with( $path, '/labels' ) ) {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		if ( 'smartpost_label_url' === $mode ) {
			return bpl_mock_response( 200, wp_json_encode( array( 'url' => 'https://gateway.posti.fi/smartpost/mock-label.pdf?barcode=' . rawurlencode( $q['barcode'] ?? '' ) ) ) );
		}
		return bpl_mock_response( 200, base64_decode( BPL_MOCK_PDF_B64 ), 'application/pdf' );
	}
	if ( str_ends_with( $path, '/mock-label.pdf' ) ) {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		return bpl_mock_response( 200, base64_decode( BPL_MOCK_PDF_B64 ), 'application/pdf' );
	}
	return bpl_mock_response( 404, '{"details":"Mock: unknown path"}' );
}, 10, 3 );
