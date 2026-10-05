<?php

/**
 * Portaliq Invitation Code
 *
 * The short form of a waiting account's one-time secret, for a paper letter:
 * twelve characters a person can read and type. The alphabet leaves out the
 * characters people mix up (0 and O, 1 and I), and the code is shown in three
 * groups of four. What a person types is normalised before it is compared, so
 * lower case, spaces and dashes do not matter.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Identity
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
 * @spec openspec/changes/invitation-code-from-a-letter/specs/portal-identity-space/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use OCP\Security\ISecureRandom;

/**
 * Mints, shows and normalises an invitation code.
 *
 * @spec openspec/changes/invitation-code-from-a-letter/specs/portal-identity-space/spec.md
 */
class InvitationCode {
	/**
	 * The characters a code is made of: 32, none of them easy to mix up.
	 */
	public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

	/**
	 * How many characters a code has: 60 bits.
	 */
	public const LENGTH = 12;

	/**
	 * How many characters stand together when the code is shown.
	 */
	private const GROUP = 4;

	/**
	 * What the instance secret is mixed with to make the key of the code's
	 * hash, so the key serves this one purpose.
	 */
	private const KEY_LABEL = 'portaliq.invitation-code';

	/**
	 * Mint a code, in its plain form.
	 *
	 * @param ISecureRandom $random The secure random source.
	 *
	 * @return string Twelve characters of the alphabet, or '' when the source gave too few.
	 *
	 * @spec openspec/changes/invitation-code-from-a-letter/specs/portal-identity-space/spec.md
	 */
	public function mint(ISecureRandom $random): string {
		$code = $random->generate(self::LENGTH, self::ALPHABET);
		if ($this->isCode(value: $code) === false) {
			return '';
		}

		return $code;
	}//end mint()

	/**
	 * The code as a letter shows it: `ABCD-EFGH-JKLM`.
	 *
	 * @param string $code The plain code.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/invitation-code-from-a-letter/specs/portal-identity-space/spec.md
	 */
	public function shown(string $code): string {
		return implode('-', str_split($code, self::GROUP));
	}//end shown()

	/**
	 * What a person typed, as the plain code it stands for, or '' when it is
	 * not a code. Case, spaces and dashes are forgiven; nothing else is.
	 *
	 * @param string $typed What was typed.
	 *
	 * @return string The plain code, or ''.
	 *
	 * @spec openspec/changes/invitation-code-from-a-letter/specs/portal-identity-space/spec.md
	 */
	public function normalise(#[\SensitiveParameter] string $typed): string {
		$plain = strtoupper((string)preg_replace('/[\s\-]+/', '', $typed));
		if ($this->isCode(value: $plain) === false) {
			return '';
		}

		return $plain;
	}//end normalise()

	/**
	 * The keyed hash of a plain code, as it is stored.
	 *
	 * A code has 60 bits. A plain SHA-256 of it could be tested offline
	 * against every stored hash at once by anybody who can read them
	 * (security review M4). Keyed with a key derived from the instance's own
	 * secret, a stolen hash is worth nothing without that secret.
	 *
	 * @param string $code The plain code.
	 * @param string $instanceSecret The instance's `secret` from config.php.
	 *
	 * @return string The hash, or '' when the instance has no secret.
	 *
	 * @spec openspec/changes/invitation-code-from-a-letter/specs/portal-identity-space/spec.md
	 */
	public function keyedHash(#[\SensitiveParameter] string $code, #[\SensitiveParameter] string $instanceSecret): string {
		if ($code === '' || $instanceSecret === '') {
			return '';
		}

		return hash_hmac('sha256', $code, hash_hmac('sha256', self::KEY_LABEL, $instanceSecret));
	}//end keyedHash()

	/**
	 * Whether a value is a plain code: the right length, the alphabet only.
	 *
	 * @param string $value The value.
	 *
	 * @return bool
	 */
	private function isCode(string $value): bool {
		return strlen($value) === self::LENGTH && strspn($value, self::ALPHABET) === self::LENGTH;
	}//end isCode()
}//end class
