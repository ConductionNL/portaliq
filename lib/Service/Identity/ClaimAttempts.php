<?php

/**
 * Portaliq Claim Attempts
 *
 * Counts the wrong invitation secrets a signed-in person offers, so the
 * redeem route cannot be used to guess one. The count that locks lives on
 * the person's own account row, where it survives a restart, a second
 * session and an instance without a shared cache. A second count per session
 * sits in the cache as a cheaper first line.
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

use DateTimeImmutable;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\ICacheFactory;
use Throwable;

/**
 * The attempt limits of the invitation redeem route.
 *
 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
 */
class ClaimAttempts {
	/**
	 * Wrong secrets one account may offer inside a window before it is locked.
	 */
	public const PER_ACCOUNT = 5;

	/**
	 * Wrong secrets one session may offer before it is locked for good.
	 */
	public const PER_SESSION = 5;

	/**
	 * The window of the account count, and how long a lock lasts, in seconds.
	 */
	public const WINDOW = 3600;

	/**
	 * The register the account lives in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The schema recording an account.
	 */
	private const SCHEMA = 'portalAccount';

	/**
	 * The cache namespace of the session count.
	 */
	private const PREFIX = 'portaliq_claim_attempts';

	/**
	 * Constructor.
	 *
	 * @param PortalObjectWriter $writer Writes the count on the account row.
	 * @param ICacheFactory $cacheFactory Holds the session count.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PortalObjectWriter $writer,
		private readonly ICacheFactory $cacheFactory,
	) {
	}//end __construct()

	/**
	 * Whether this account or this session has used up its attempts.
	 *
	 * @param array<string, mixed> $account The signed-in person's own account row.
	 * @param string $jti The session's token id.
	 * @param DateTimeImmutable $now The moment.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
	 */
	public function locked(array $account, string $jti, DateTimeImmutable $now): bool {
		if ($this->countInWindow(account: $account, now: $now) >= self::PER_ACCOUNT) {
			return true;
		}

		return $this->sessionCount(jti: $jti) >= self::PER_SESSION;
	}//end locked()

	/**
	 * Count one wrong secret, on the account and on the session.
	 *
	 * @param array<string, mixed> $account The signed-in person's own account row.
	 * @param string $accountId The account row's identifier.
	 * @param string $jti The session's token id.
	 * @param DateTimeImmutable $now The moment.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
	 */
	public function fail(array $account, string $accountId, string $jti, DateTimeImmutable $now): void {
		$count = $this->countInWindow(account: $account, now: $now);
		$data  = ['claimAttempts' => ($count + 1)];
		if ($count === 0) {
			// The window opens at the first wrong secret and does not move
			// with later ones, so a steady stream cannot keep it open.
			$data['claimAttemptsSince'] = $now->format(DATE_ATOM);
		}

		$this->write(accountId: $accountId, data: $data);

		if ($jti === '') {
			return;
		}

		try {
			$cache = $this->cacheFactory->createDistributed(self::PREFIX);
			$cache->set(hash('sha256', $jti), ($this->sessionCount(jti: $jti) + 1), 86400);
		} catch (Throwable) {
			// Without a cache the account count is the limit.
			return;
		}
	}//end fail()

	/**
	 * Forget the account's wrong secrets after a right one.
	 *
	 * @param array<string, mixed> $account The account row.
	 * @param string $accountId The account row's identifier.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
	 */
	public function clear(array $account, string $accountId): void {
		if ((int)($account['claimAttempts'] ?? 0) === 0) {
			return;
		}

		$this->write(accountId: $accountId, data: ['claimAttempts' => 0]);
	}//end clear()

	/**
	 * The wrong secrets counted on the account inside the running window.
	 *
	 * @param array<string, mixed> $account The account row.
	 * @param DateTimeImmutable $now The moment.
	 *
	 * @return int
	 */
	private function countInWindow(array $account, DateTimeImmutable $now): int {
		$count = (int)($account['claimAttempts'] ?? 0);
		$since = date_create_immutable((string)($account['claimAttemptsSince'] ?? ''));
		if ($count <= 0 || $since === false) {
			return 0;
		}

		if (($now->getTimestamp() - $since->getTimestamp()) >= self::WINDOW) {
			return 0;
		}

		return $count;
	}//end countInWindow()

	/**
	 * The wrong secrets counted on the session, 0 without a cache.
	 *
	 * @param string $jti The session's token id.
	 *
	 * @return int
	 */
	private function sessionCount(string $jti): int {
		if ($jti === '') {
			return 0;
		}

		try {
			$count = $this->cacheFactory->createDistributed(self::PREFIX)->get(hash('sha256', $jti));
		} catch (Throwable) {
			return 0;
		}

		if (is_int($count) === false) {
			return 0;
		}

		return $count;
	}//end sessionCount()

	/**
	 * One internal update of the account row the caller already located.
	 *
	 * @param string $accountId The row's identifier.
	 * @param array<string, mixed> $data The fields to write.
	 *
	 * @return void
	 */
	private function write(string $accountId, array $data): void {
		$this->writer->updateObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: '',
			id: $accountId,
			data: $data
		);
	}//end write()
}//end class
