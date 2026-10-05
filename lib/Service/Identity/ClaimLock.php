<?php

/**
 * Portaliq Claim Lock
 *
 * An exclusive lock around the read and the write of an invitation, so two
 * requests that hand in the same secret at the same moment cannot both join
 * the waiting account (security review M2), and two wrong secrets from one
 * account cannot both be counted as the first (security review L1).
 *
 * The lock is Nextcloud's locking provider: memcache when the instance has
 * one, the database otherwise. A lock that stays taken past a short wait is
 * reported as busy; the caller then changes nothing.
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
 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Takes and gives back the exclusive lock of one account row.
 *
 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
 */
class ClaimLock {
	/**
	 * How often a taken lock is tried again before the caller hears busy.
	 */
	public const RETRIES = 20;

	/**
	 * How long to wait between two tries, in microseconds.
	 */
	private const WAIT = 50000;

	/**
	 * Where these locks live among the instance's locks.
	 */
	private const PREFIX = 'portaliq/claim/';

	/**
	 * Constructor.
	 *
	 * @param ILockingProvider $locks The instance's locking provider.
	 * @param int $retries How often a taken lock is tried again.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ILockingProvider $locks,
		private readonly int $retries = self::RETRIES,
	) {
	}//end __construct()

	/**
	 * Take the exclusive lock of one account row.
	 *
	 * @param string $accountId The account row's identifier.
	 *
	 * @return bool True when the lock is now held; false when it stayed taken.
	 *
	 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
	 */
	public function acquire(string $accountId): bool {
		for ($try = 0; $try <= $this->retries; $try++) {
			try {
				$this->locks->acquireLock(self::PREFIX . $accountId, ILockingProvider::LOCK_EXCLUSIVE);
				return true;
			} catch (LockedException) {
				if ($try < $this->retries) {
					usleep(self::WAIT);
				}
			}
		}

		return false;
	}//end acquire()

	/**
	 * Give the lock of one account row back.
	 *
	 * @param string $accountId The account row's identifier.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
	 */
	public function release(string $accountId): void {
		$this->locks->releaseLock(self::PREFIX . $accountId, ILockingProvider::LOCK_EXCLUSIVE);
	}//end release()
}//end class
