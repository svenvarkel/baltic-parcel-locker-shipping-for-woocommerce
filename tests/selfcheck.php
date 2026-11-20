<?php
/**
 * Plain asserts, no WordPress: php tests/selfcheck.php
 */

define( 'ABSPATH', __DIR__ );
define( 'BPL_KEY', 'selfcheck-key' );
require __DIR__ . '/../includes/class-bpl-crypto.php';
require __DIR__ . '/../includes/class-bpl-carriers.php';

$fails = 0;
function check( string $name, bool $ok ): void {
	global $fails;
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . "\n";
	$fails += $ok ? 0 : 1;
}

$s = array(
	'omniva_customer' => '123456', 'omniva_sms' => 'no', 'smartpost_doorcode' => 'no', 'smartpost_size' => 'M',
	'sender_name' => 'Example OÜ', 'sender_phone' => '5551234', 'sender_email' => 'shop@example.com',
	'sender_street' => 'Pikk 1', 'sender_city' => 'Tallinn', 'sender_postcode' => '10123',
);
$o = array( 'ref' => '1042', 'name' => 'Mari Maasikas', 'phone' => '5123 4567', 'email' => 'mari@example.ee', 'country' => 'EE', 'terminal' => '96331', 'weight' => null );

// Omniva B2C
$p  = BPL_Carriers::omniva_payload( $o, $s );
$sh = $p['shipments'][0];
check( 'omniva customerCode', '123456' === $p['customerCode'] );
check( 'omniva PARCEL / PARCEL_MACHINE', 'PARCEL' === $sh['mainService'] && 'PARCEL_MACHINE' === $sh['deliveryChannel'] );
check( 'omniva offloadPostcode = terminal zip', array( 'country' => 'EE', 'offloadPostcode' => '96331' ) === $sh['receiverAddressee']['address'] );
check( 'omniva receiver mobile normalised', '+37251234567' === $sh['receiverAddressee']['contactMobile'] );
check( 'omniva sender address', array( 'street' => 'Pikk 1', 'deliverypoint' => 'Tallinn', 'postcode' => '10123', 'country' => 'EE' ) === $sh['senderAddressee']['address'] );
check( 'omniva no measurement without weight', ! isset( $sh['measurement'] ) );
check( 'omniva no notifications when off', ! isset( $sh['notifications'] ) );
$no_empty = true;
array_walk_recursive( $p, function ( $v ) use ( &$no_empty ) { $no_empty = $no_empty && null !== $v && '' !== $v; } );
check( 'omniva has no empty fields', $no_empty );
$p2 = BPL_Carriers::omniva_payload( array( 'weight' => 0.35, 'email' => '' ) + $o, array( 'omniva_sms' => 'yes' ) + $s );
check( 'omniva notifications REGISTERED/sms when on', array( array( 'type' => 'REGISTERED', 'channel' => 'sms' ) ) === $p2['shipments'][0]['notifications'] );
check( 'omniva weight kept', 0.35 === $p2['shipments'][0]['measurement']['weight'] );
check( 'omniva empty email dropped', ! array_key_exists( 'contactEmail', $p2['shipments'][0]['receiverAddressee'] ) );

// SmartPost
$q = BPL_Carriers::smartpost_payload( array( 'terminal' => '01007641' ) + $o, $s )['orders']['item'];
check( 'smartpost destination is only place_id', array( 'place_id' => '01007641' ) === $q['destination'] );
check( 'smartpost no sender / size without door code', ! isset( $q['sender'] ) && ! isset( $q['size'] ) );
check( 'smartpost recipient phone', '+37251234567' === $q['recipient']['phone'] );
check( 'smartpost source EE', array( 'country' => 'EE' ) === $q['source'] );
$q2 = BPL_Carriers::smartpost_payload( array( 'country' => 'FI', 'phone' => '040 123 4567' ) + $o, array( 'smartpost_doorcode' => 'yes' ) + $s )['orders']['item'];
check( 'smartpost door code: sender + size', 'M' === $q2['size'] && '+3725551234' === $q2['sender']['phone'] );
check( 'smartpost FI destination still only place_id', array( 'place_id' => '96331' ) === $q2['destination'] );

// Phone normalisation
foreach ( array(
	array( '5123 4567', 'EE', '+37251234567' ),
	array( '+372 5123 4567', 'EE', '+37251234567' ),
	array( '0037251234567', 'EE', '+37251234567' ),
	array( '37251234567', 'EE', '+37251234567' ),
	array( '2123 4567', 'LV', '+37121234567' ),
	array( '8 612 34567', 'LT', '+37061234567' ),
	array( '061234567', 'LT', '+37061234567' ),
	array( '040 123 4567', 'FI', '+358401234567' ),
	array( '+358 40 1234567', 'FI', '+358401234567' ),
	array( '', 'EE', '' ),
) as [ $in, $cc, $want ] ) {
	check( "phone '$in' $cc → $want", $want === BPL_Carriers::normalize_phone( $in, $cc ) );
}

// Response parsing / state machine
check( 'omniva OK → created', array( 'created', 'CE123EE', '', '' ) === BPL_Carriers::parse_omniva_create( 200, '{"resultCode":"OK","savedShipments":[{"barcode":"CE123EE","clientItemId":"1042"}]}' ) );
check( 'omniva failedShipments → failed', 'failed' === BPL_Carriers::parse_omniva_create( 200, '{"resultCode":"ERROR","failedShipments":[{"messageCode":"x","message":"bad"}]}' )[0] );
check( 'omniva 500 → unknown', 'unknown' === BPL_Carriers::parse_omniva_create( 500, '{"details":"boom"}' )[0] );
check( 'omniva garbage 200 → unknown', 'unknown' === BPL_Carriers::parse_omniva_create( 200, '<html>' )[0] );
check( 'timeout → unknown', 'unknown' === BPL_Carriers::parse_smartpost_create( 0, 'cURL error 28' )[0] );
check( 'connect error → failed', 'failed' === BPL_Carriers::parse_smartpost_create( -1, 'cURL error 7' )[0] );
check( 'smartpost 200 → created + doorcode', array( 'created', 'B1', '1234', '' ) === BPL_Carriers::parse_smartpost_create( 200, '{"orders":{"item":[{"barcode":"B1","reference":"1","sender":{"doorcode":"1234"}}]}}' ) );
check( 'smartpost 400 → failed', 'failed' === BPL_Carriers::parse_smartpost_create( 400, '<error>006</error>' )[0] );

// Crypto
$c = BPL_Crypto::encrypt( 'sala-sõna' );
check( 'crypto prefix', str_starts_with( $c, 'bpl1:' ) && ! str_contains( $c, 'sala' ) );
check( 'crypto roundtrip', 'sala-sõna' === BPL_Crypto::decrypt( $c ) );
check( 'crypto fresh nonce', BPL_Crypto::encrypt( 'x' ) !== BPL_Crypto::encrypt( 'x' ) );
$raw    = base64_decode( substr( $c, 4 ) );
$raw[30] = chr( ord( $raw[30] ) ^ 1 );
check( 'crypto tamper → null', null === BPL_Crypto::decrypt( 'bpl1:' . base64_encode( $raw ) ) );
check( 'crypto plaintext → null', null === BPL_Crypto::decrypt( 'plain' ) );
check( 'crypto empty → empty', '' === BPL_Crypto::decrypt( '' ) );

check( 'omniva 302 → unknown', 'unknown' === BPL_Carriers::parse_omniva_create( 302, '' )[0] );
check( 'smartpost 302 → unknown', 'unknown' === BPL_Carriers::parse_smartpost_create( 302, '' )[0] );

echo $fails ? "\n$fails FAILED\n" : "\nALL OK\n";
exit( $fails ? 1 : 0 );
