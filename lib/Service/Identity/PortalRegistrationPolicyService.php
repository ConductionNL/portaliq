<?php

/**
 * Portaliq Portal Registration Policy Service
 *
 * Whether a stranger may make an account on this portal, and from which
 * addresses. Absent or unreadable configuration means `off`: a portal that
 * never said anything about self-registration has not agreed to it.
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
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

/**
 * Decides what happens to a self-registration.
 *
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
 */
class PortalRegistrationPolicyService {
	/**
	 * No account is created by a stranger.
	 */
	public const POLICY_OFF = 'off';

	/**
	 * The account waits for a decision.
	 */
	public const POLICY_APPROVAL = 'approval';

	/**
	 * The account waits for a mail the registrant confirms.
	 */
	public const POLICY_ACTIVATION = 'activation';

	/**
	 * Whether this portal offers registration at all.
	 *
	 * @param array<string, mixed> $site The portal's own configuration row.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function isOffered(array $site): bool {
		return $this->policy(site: $site) !== self::POLICY_OFF;
	}//end isOffered()

	/**
	 * The policy this portal declares, or `off`.
	 *
	 * @param array<string, mixed> $site The portal's own configuration row.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function policy(array $site): string {
		$registration = ((array)($site['authentication'] ?? []))['registration'] ?? [];
		if (is_array($registration) === false) {
			return self::POLICY_OFF;
		}

		$policy = (string)($registration['policy'] ?? '');
		if (in_array($policy, [self::POLICY_APPROVAL, self::POLICY_ACTIVATION], true) === false) {
			return self::POLICY_OFF;
		}

		return $policy;
	}//end policy()

	/**
	 * What becomes of a registration from this address.
	 *
	 * @param array<string, mixed> $site The portal's own configuration row.
	 * @param string $email The address registering.
	 *
	 * @return array{accepted: bool, reason: string, status: string} The
	 *         decision: `accepted` false carries the refusal in `reason`;
	 *         `accepted` true carries the account status to create in
	 *         `status`, which is `pending` under either policy, because
	 *         neither admits anybody yet.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function decide(array $site, string $email): array {
		$policy = $this->policy(site: $site);
		if ($policy === self::POLICY_OFF) {
			return ['accepted' => false, 'reason' => 'registration_off', 'status' => ''];
		}

		if ($this->isWellFormed(email: $email) === false) {
			return ['accepted' => false, 'reason' => 'invalid_email', 'status' => ''];
		}

		if ($this->domainIsAllowed(site: $site, email: $email) === false) {
			return ['accepted' => false, 'reason' => 'domain_not_allowed', 'status' => ''];
		}

		return ['accepted' => true, 'reason' => $policy, 'status' => 'pending'];
	}//end decide()

	/**
	 * Whether the address's domain is on the list, when there is one.
	 *
	 * An empty list allows every domain; a list that names one allows that
	 * one. The comparison is on the whole domain, so `notgemeente-x.nl` does
	 * not pass a list holding `gemeente-x.nl`.
	 *
	 * @param array<string, mixed> $site The portal's own configuration row.
	 * @param string $email The address registering.
	 *
	 * @return bool
	 */
	private function domainIsAllowed(array $site, string $email): bool {
		$registration = (array)(((array)($site['authentication'] ?? []))['registration'] ?? []);
		$allowed = ($registration['allowedDomains'] ?? []);
		if (is_array($allowed) === false || $allowed === []) {
			return true;
		}

		$atSign = strrpos($email, '@');
		if ($atSign === false) {
			return false;
		}

		$domain = strtolower(substr($email, ($atSign + 1)));
		foreach ($allowed as $entry) {
			if (is_string($entry) === true && strtolower(trim($entry)) === $domain) {
				return true;
			}
		}

		return false;
	}//end domainIsAllowed()

	/**
	 * Whether the address is shaped like an address at all.
	 *
	 * @param string $email The address.
	 *
	 * @return bool
	 */
	private function isWellFormed(string $email): bool {
		return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
	}//end isWellFormed()
}//end class
