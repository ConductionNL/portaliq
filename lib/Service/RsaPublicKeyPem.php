<?php

/**
 * Portaliq RSA Public Key PEM
 *
 * The PEM-encoded public key for an RSA JWK's modulus and exponent, as
 * `openssl_pkey_get_public()` reads it when an ID token's signature is
 * verified.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T04
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * Builds the PEM of an RSA public key, for OidcClientService only.
 *
 * @internal
 *
 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T04
 */
final class RsaPublicKeyPem {
	/**
	 * Build a PEM-encoded X.509 SubjectPublicKeyInfo for an RSA public key
	 * from its JWK `n` (modulus) and `e` (exponent), already base64url-decoded.
	 *
	 * Standard PKCS#1 `RSAPublicKey ::= SEQUENCE { n INTEGER, e INTEGER }`
	 * wrapped in the standard X.509 `SubjectPublicKeyInfo` (rsaEncryption
	 * OID + NULL params, BIT STRING payload) — the same encoding
	 * `openssl_pkey_get_public()` expects for a `-----BEGIN PUBLIC KEY-----`
	 * PEM. There is no `ext-jwk`/composer JOSE dependency in this app
	 * (mirrors `PortalJwtService`'s self-contained rationale), so this is a
	 * small, self-contained DER builder rather than a new dependency.
	 *
	 * @param string $modulus  The modulus, as raw big-endian bytes.
	 * @param string $exponent The exponent, as raw big-endian bytes.
	 *
	 * @return string PEM-encoded public key.
	 *
	 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T04
	 */
	public function encode(string $modulus, string $exponent): string {
		$rsaPublicKey = $this->derSequence(content: $this->derInteger(bytes: $modulus) . $this->derInteger(bytes: $exponent));
		$bitString = $this->derBitString(content: $rsaPublicKey);
		// AlgorithmIdentifier for rsaEncryption (1.2.840.113549.1.1.1) + NULL.
		$algorithmId = hex2bin('300d06092a864886f70d0101010500');
		$spki = $this->derSequence(content: $algorithmId . $bitString);

		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
	}//end encode()

	/**
	 * DER-encode a length (short or long form).
	 *
	 * @param int $length The content length in bytes.
	 *
	 * @return string
	 */
	private function derLength(int $length): string {
		if ($length < 0x80) {
			return chr($length);
		}

		$bytes = ltrim(pack('N', $length), "\x00");

		return chr(0x80 | strlen($bytes)) . $bytes;
	}//end derLength()

	/**
	 * DER-encode an unsigned big-endian integer, prefixing a `0x00` byte
	 * when the high bit is set (so it is never misread as negative).
	 *
	 * @param string $bytes Raw big-endian magnitude bytes.
	 *
	 * @return string
	 */
	private function derInteger(string $bytes): string {
		$bytes = ltrim($bytes, "\x00");
		if ($bytes === '') {
			$bytes = "\x00";
		}

		if (ord($bytes[0]) > 0x7f) {
			$bytes = "\x00" . $bytes;
		}

		return "\x02" . $this->derLength(length: strlen($bytes)) . $bytes;
	}//end derInteger()

	/**
	 * DER-encode a SEQUENCE.
	 *
	 * @param string $content The already-encoded inner content.
	 *
	 * @return string
	 */
	private function derSequence(string $content): string {
		return "\x30" . $this->derLength(length: strlen($content)) . $content;
	}//end derSequence()

	/**
	 * DER-encode a BIT STRING with zero unused bits.
	 *
	 * @param string $content The raw bit-string payload.
	 *
	 * @return string
	 */
	private function derBitString(string $content): string {
		return "\x03" . $this->derLength(length: (strlen($content) + 1)) . "\x00" . $content;
	}//end derBitString()
}//end class
