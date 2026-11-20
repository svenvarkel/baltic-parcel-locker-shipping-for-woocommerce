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

/** Minimal valid one-page PDF with a line of text (xref offsets computed). */
function bpl_mock_pdf( string $text ): string {
	$text    = preg_replace( '/[^A-Za-z0-9 :._-]/', '', $text );
	$stream  = "BT /F1 14 Tf 20 250 Td (MOCK LABEL $text) Tj ET";
	$objects = array(
		'<< /Type /Catalog /Pages 2 0 R >>',
		'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
		'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 298 420] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
		'<< /Length ' . strlen( $stream ) . " >>\nstream\n$stream\nendstream",
		'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
	);
	$pdf  = "%PDF-1.4\n";
	$offs = array();
	foreach ( $objects as $i => $obj ) {
		$offs[] = strlen( $pdf );
		$pdf   .= ( $i + 1 ) . " 0 obj\n$obj\nendobj\n";
	}
	$xref = strlen( $pdf );
	$pdf .= 'xref' . "\n0 " . ( count( $objects ) + 1 ) . "\n0000000000 65535 f \n";
	foreach ( $offs as $o ) {
		$pdf .= sprintf( "%010d 00000 n \n", $o );
	}
	return $pdf . 'trailer << /Size ' . ( count( $objects ) + 1 ) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
}

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
			'successAddressCards' => array( array( 'barcode' => $b, 'fileData' => base64_encode( bpl_mock_pdf( "Omniva $b" ) ) ) ),
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
		return bpl_mock_response( 200, bpl_mock_pdf( 'SmartPost ' . ( $q['barcode'] ?? '' ) ), 'application/pdf' );
	}
	if ( str_ends_with( $path, '/mock-label.pdf' ) ) {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		return bpl_mock_response( 200, bpl_mock_pdf( 'SmartPost URL ' . ( $q['barcode'] ?? '' ) ), 'application/pdf' );
	}
	return bpl_mock_response( 404, '{"details":"Mock: unknown path"}' );
}, 10, 3 );
