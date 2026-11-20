<?php
/**
 * Secret settings encryption (sodium secretbox).
 *
 * ponytail: the key lives on the same server as the DB. This protects DB dumps/backups,
 * not a full server compromise.
 */

defined( 'ABSPATH' ) || exit;

class BPL_Crypto {

	const PREFIX = 'bpl1:';

	private static function key(): string {
		$material = defined( 'BPL_KEY' ) ? BPL_KEY : wp_salt( 'secure_auth' );
		return sodium_crypto_generichash( 'baltic-parcel-locker-shipping-for-woocommerce' . $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	public static function encrypt( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) );
	}

	/** Returns plaintext, '' for empty, null when the value cannot be decrypted (salt changed, tampered). */
	public static function decrypt( string $stored ): ?string {
		if ( '' === $stored ) {
			return '';
		}
		if ( ! str_starts_with( $stored, self::PREFIX ) ) {
			return null;
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			self::key()
		);
		return false === $plain ? null : $plain;
	}
}
