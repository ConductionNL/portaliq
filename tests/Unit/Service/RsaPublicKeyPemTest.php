<?php

/**
 * RsaPublicKeyPem: an RSA modulus and exponent, as a JWKS publishes them,
 * written as the PEM public key OpenSSL reads.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\RsaPublicKeyPem;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The PEM encoding of an RSA public key.
 */
class RsaPublicKeyPemTest extends TestCase {
	/**
	 * A fresh RSA key pair of the given size.
	 *
	 * @param int $bits The key size.
	 *
	 * @return OpenSSLAsymmetricKey
	 */
	private function keyPair(int $bits): OpenSSLAsymmetricKey {
		$key = openssl_pkey_new(options: ['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		$this->assertInstanceOf(expected: OpenSSLAsymmetricKey::class, actual: $key);
		return $key;
	}//end keyPair()

	/**
	 * Key sizes a JWKS may publish.
	 *
	 * @return array<string, array{0: int}>
	 */
	public static function keySizes(): array {
		return [
			'1024 bits' => [1024],
			'2048 bits' => [2048],
			'4096 bits' => [4096],
		];
	}//end keySizes()

	/**
	 * The PEM is byte for byte the one OpenSSL writes for the same key.
	 *
	 * @param int $bits The key size.
	 *
	 * @return void
	 */
	#[DataProvider('keySizes')]
	public function testThePemIsWhatOpenSslWrites(int $bits): void {
		$details = openssl_pkey_get_details(key: $this->keyPair(bits: $bits));
		$pem     = (new RsaPublicKeyPem())->encode(modulus: $details['rsa']['n'], exponent: $details['rsa']['e']);
		$this->assertSame(expected: $details['key'], actual: $pem);
	}//end testThePemIsWhatOpenSslWrites()

	/**
	 * A signature made with the private key verifies against the encoded public key.
	 *
	 * @return void
	 */
	public function testTheEncodedKeyVerifiesASignature(): void {
		$private = $this->keyPair(bits: 2048);
		$details = openssl_pkey_get_details(key: $private);
		$pem     = (new RsaPublicKeyPem())->encode(modulus: $details['rsa']['n'], exponent: $details['rsa']['e']);
		$this->assertTrue(condition: openssl_sign(data: 'header.payload', signature: $signature, private_key: $private, algorithm: OPENSSL_ALGO_SHA256));
		$algorithm = OPENSSL_ALGO_SHA256;
		$this->assertSame(expected: 1, actual: openssl_verify(data: 'header.payload', signature: $signature, public_key: $pem, algorithm: $algorithm));
		$this->assertSame(expected: 0, actual: openssl_verify(data: 'header.other', signature: $signature, public_key: $pem, algorithm: $algorithm));
	}//end testTheEncodedKeyVerifiesASignature()

	/**
	 * Leading zero bytes, as base64url-decoded JWKS values may carry, do not change the key.
	 *
	 * @return void
	 */
	public function testLeadingZeroBytesAreIgnored(): void {
		$details = openssl_pkey_get_details(key: $this->keyPair(bits: 2048));
		$encoder = new RsaPublicKeyPem();
		$this->assertSame(
			expected: $encoder->encode(modulus: $details['rsa']['n'], exponent: $details['rsa']['e']),
			actual: $encoder->encode(modulus: "\x00\x00".$details['rsa']['n'], exponent: "\x00".$details['rsa']['e'])
		);
	}//end testLeadingZeroBytesAreIgnored()

	/**
	 * The PEM is wrapped at 64 characters between the public key markers.
	 *
	 * @return void
	 */
	public function testThePemIsWrappedAtSixtyFour(): void {
		$details = openssl_pkey_get_details(key: $this->keyPair(bits: 2048));
		$lines   = explode("\n", rtrim((new RsaPublicKeyPem())->encode(modulus: $details['rsa']['n'], exponent: $details['rsa']['e'])));
		$this->assertSame(expected: '-----BEGIN PUBLIC KEY-----', actual: array_shift($lines));
		$this->assertSame(expected: '-----END PUBLIC KEY-----', actual: array_pop($lines));
		foreach (array_slice($lines, 0, -1) as $line) {
			$this->assertSame(expected: 64, actual: strlen($line));
		}

		$this->assertLessThanOrEqual(expected: 64, actual: strlen((string)end($lines)));
	}//end testThePemIsWrappedAtSixtyFour()

	/**
	 * A tiny key still encodes its integers as positive DER integers.
	 *
	 * @return void
	 */
	public function testSmallIntegersAreEncodedAsPositive(): void {
		$pem = (new RsaPublicKeyPem())->encode(modulus: "\xff", exponent: "\x01\x00\x01");
		$der = base64_decode(str_replace(["-----BEGIN PUBLIC KEY-----\n", "-----END PUBLIC KEY-----\n", "\n"], '', $pem));
		$this->assertSame(expected: '301d300d06092a864886f70d010101050003'.'0c00'.'3009'.'020200ff'.'0203010001', actual: bin2hex($der));
	}//end testSmallIntegersAreEncodedAsPositive()
}//end class
