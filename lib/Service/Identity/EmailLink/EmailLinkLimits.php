<?php

/**
 * Portaliq Email Link Limits
 *
 * The counters an attribute cannot give (security review M5): three links per
 * mailbox per hour, counted on the hash of the normalised address for a known
 * and an unknown address alike, and a cap on outgoing link mails per portal
 * per hour that protects the sender's reputation.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Identity\EmailLink
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
 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#6
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity\EmailLink;

use OCP\Security\RateLimiting\ILimiter;
use OCP\Security\RateLimiting\IRateLimitExceededException;

/**
 * Counts link requests per mailbox and link mails per portal.
 *
 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#6
 */
class EmailLinkLimits {
	/**
	 * Links per address per hour.
	 */
	public const PER_ADDRESS = 3;

	/**
	 * Link mails per portal per hour.
	 */
	public const PER_PORTAL = 200;

	/**
	 * The window, in seconds.
	 */
	public const PERIOD = 3600;

	/**
	 * Constructor.
	 *
	 * @param ILimiter $limiter Nextcloud's rate limiter.
	 */
	public function __construct(
		private readonly ILimiter $limiter,
	) {
	}//end __construct()

	/**
	 * Count one request for an address; false when it is over the limit.
	 *
	 * @param string $addressHash SHA-256 of the normalised address.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/sign-in-with-an-email-link/specs/portal-ways-in/spec.md#requirement-the-e-mail-link-form-is-rate-limited-per-mailbox-per-client-and-per-portal-req-iwi-008
	 */
	public function countAddress(string $addressHash): bool {
		return $this->count(identifier: 'portaliq-email-link-address', limit: self::PER_ADDRESS, key: 'address:' . $addressHash);
	}//end countAddress()

	/**
	 * Count one outgoing link mail for a portal; false when it is over the cap.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/sign-in-with-an-email-link/specs/portal-ways-in/spec.md#requirement-the-e-mail-link-form-is-rate-limited-per-mailbox-per-client-and-per-portal-req-iwi-008
	 */
	public function countPortalMail(string $portal): bool {
		return $this->count(identifier: 'portaliq-email-link-portal', limit: self::PER_PORTAL, key: 'portal:' . hash('sha256', $portal));
	}//end countPortalMail()

	/**
	 * One count in one bucket.
	 *
	 * @param string $identifier The bucket family.
	 * @param int    $limit      The limit per period.
	 * @param string $key        The bucket within the family.
	 *
	 * @return bool
	 */
	private function count(string $identifier, int $limit, string $key): bool {
		try {
			$this->limiter->registerAnonRequest($identifier, $limit, self::PERIOD, $key);
		} catch (IRateLimitExceededException) {
			return false;
		}

		return true;
	}//end count()
}//end class
