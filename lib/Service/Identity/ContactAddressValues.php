<?php

/**
 * Portaliq Contact Address Values
 *
 * The values around a person's own addresses (identity-profile-page): a phone
 * number in the form it is stored in, an address shown without giving it
 * away, the fields that park an e-mail address until its link is followed,
 * and whether the portal asks for an address after sign-in.
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
 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/specs/portal-profile/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use DateInterval;
use DateTimeImmutable;

/**
 * The values around a person's own addresses.
 *
 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/specs/portal-profile/spec.md
 */
class ContactAddressValues {
	/**
	 * The ways the organisation may contact the person (REQ-IPP-004).
	 */
	public const CHANNELS = ['portal', 'email', 'phone', 'post'];

	/**
	 * How long a confirmation link works.
	 */
	private const TTL = 'P1D';

	/**
	 * An address in the shape it is stored in, or null when it is not one.
	 *
	 * A phone number is kept as given but written in E.164 where it parses: a
	 * leading 00 becomes +, and a Dutch number starting with 0 gets +31.
	 *
	 * @param string $kind `email` or `phone`.
	 * @param string $value What the person typed.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T03
	 */
	public function normalise(string $kind, string $value): ?string {
		$value = trim($value);
		if ($kind === 'email') {
			if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
				return null;
			}

			return $value;
		}

		if ($kind !== 'phone' || preg_match('/^[0-9+()\s.\-]+$/', $value) !== 1) {
			return null;
		}

		$digits = (string)preg_replace('/[^0-9+]/', '', $value);
		if (str_starts_with($digits, '00') === true) {
			$digits = '+' . substr($digits, 2);
		}

		if (preg_match('/^0[1-9][0-9]{8}$/', $digits) === 1) {
			$digits = '+31' . substr($digits, 1);
		}

		if (preg_match('/^\+?[0-9]{6,15}$/', $digits) !== 1) {
			return null;
		}

		return $digits;
	}//end normalise()

	/**
	 * The fields that park an e-mail address until its link is followed.
	 *
	 * @param string $email The address waiting for confirmation.
	 * @param string $token The plain secret for the mail.
	 * @param string $mode `replace` (change my address) or `add` (one more).
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T03
	 */
	public function pendingFields(string $email, string $token, string $mode): array {
		$kept = 'replace';
		if ($mode === 'add') {
			$kept = 'add';
		}

		return [
			'pendingEmail' => $email,
			'pendingEmailTokenHash' => hash('sha256', $token),
			'pendingEmailExpiresAt' => (new DateTimeImmutable())->add(new DateInterval(self::TTL))->format(DATE_ATOM),
			'pendingEmailMode' => $kept,
		];
	}//end pendingFields()

	/**
	 * Whether the portal asks for an e-mail address after sign-in
	 * (REQ-IPP-005): the account has none in use, or dispatch flagged that it
	 * needs another way to reach the person.
	 *
	 * @param array<string, mixed>|null $account The account row, or null.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/specs/portal-profile/spec.md#requirement-you-are-asked-for-an-e-mail-address-when-there-is-none-req-ipp-005
	 */
	public function needsContactPrompt(?array $account): bool {
		if ($account === null) {
			return false;
		}

		return (string)($account['email'] ?? '') === '' || ($account['needsAlternativeContact'] ?? false) === true;
	}//end needsContactPrompt()

	/**
	 * An address shown without giving it away: the first letter and the
	 * domain.
	 *
	 * @param string $email The address.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T05
	 */
	public function mask(string $email): string {
		$atSign = strrpos($email, '@');
		if ($email === '' || $atSign === false || $atSign < 1) {
			return '';
		}

		return substr($email, 0, 1) . '***' . substr($email, $atSign);
	}//end mask()
}//end class
