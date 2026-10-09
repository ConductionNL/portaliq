<?php

/**
 * Portaliq Portal Session Revoker
 *
 * Revokes every live `portalSession` of one organisation for an admin
 * (incident response), split out of PortalSessionService so that class stays
 * under the size the quality gate holds it to.
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
 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#3.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use Psr\Log\LoggerInterface;

/**
 * Reads the live session rows of a tenant and revokes them, with an audit entry each.
 *
 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#3.2
 */
class PortalSessionRevoker {
	/**
	 * Session rows read per page by revoke-all.
	 */
	private const REVOKE_PAGE = 500;

	/**
	 * The most pages revoke-all reads (one million rows) before it reports
	 * itself incomplete.
	 */
	private const REVOKE_MAX_PAGES = 2000;

	/**
	 * Build the revoker over the session service's collaborators.
	 *
	 * @param PortalObjectReader $reader The OpenRegister reader.
	 * @param PortalObjectWriter $writer The OpenRegister writer.
	 * @param AuditTrailService $auditor The audit recorder.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
		private readonly AuditTrailService $auditor,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Revoke every active `portalSession` for an organisation (admin incident
	 * response, e.g. a supplier reports device theft).
	 *
	 * Reads only the rows not yet revoked, page by page until OpenRegister
	 * runs out, BEFORE revoking any (revoking while paging would shift the
	 * pages under the offset), so a live session past the first page is
	 * never missed (security review B1). An unreachable OpenRegister or a
	 * failed write makes the result `complete: false`, so the admin never
	 * reads "0 revoked" for "did not run" (S5). Every revocation is audited
	 * as `admin-revoke` naming the admin, plus one entry for the call (S6).
	 *
	 * @param string $organisation The tenant to revoke every session for.
	 * @param string $admin The Nextcloud user id of the acting admin.
	 *
	 * @return array{revoked: int, failed: int, complete: bool}
	 *
	 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#3.2
	 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T09
	 */
	public function revokeAll(string $organisation, string $admin): array {
		if ($organisation === '') {
			return ['revoked' => 0, 'failed' => 0, 'complete' => true];
		}

		$rows = $this->liveSessionsOf(organisation: $organisation);
		if ($rows === null) {
			$this->recordAdminRevoke(admin: $admin, organisation: $organisation, revoked: 0, complete: false);
			return ['revoked' => 0, 'failed' => 0, 'complete' => false];
		}

		$revoked = 0;
		$failed  = 0;
		foreach ($rows as $row) {
			$uuid = $this->rowId(row: $row);
			$updated = null;
			if ($uuid !== null) {
				$updated = $this->writer->updateObject(
					register: PortalSessionService::SESSION_REGISTER,
					schema: PortalSessionService::SESSION_SCHEMA,
					scopeField: '',
					subjectRef: '',
					organisation: '',
					id: $uuid,
					data: ['revoked' => true]
				);
			}

			if ($updated === null) {
				$failed++;
				continue;
			}

			$revoked++;
			$this->auditor->record(
				verb: 'admin-revoke',
				subjectRef: (string)($row['subjectRef'] ?? ''),
				organisation: $organisation,
				register: PortalSessionService::SESSION_REGISTER,
				schema: PortalSessionService::SESSION_SCHEMA,
				id: (string)($row['jti'] ?? ''),
				jti: (string)($row['jti'] ?? ''),
				detail: ['admin' => $admin]
			);
		}//end foreach

		$this->recordAdminRevoke(admin: $admin, organisation: $organisation, revoked: $revoked, complete: $failed === 0);

		return ['revoked' => $revoked, 'failed' => $failed, 'complete' => $failed === 0];
	}//end revokeAll()

	/**
	 * Every not-yet-revoked session row of an organisation, read to the end,
	 * or null when OpenRegister could not be read.
	 *
	 * @param string $organisation The tenant.
	 *
	 * @return array<int, array<string, mixed>>|null
	 *
	 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#3.2
	 */
	private function liveSessionsOf(string $organisation): ?array {
		$live   = [];
		$offset = 0;
		for ($pages = 0; $pages < self::REVOKE_MAX_PAGES; $pages++) {
			$page = $this->reader->readScopedPage(
				register: PortalSessionService::SESSION_REGISTER,
				schema: PortalSessionService::SESSION_SCHEMA,
				scopeField: 'organisation',
				scopeValue: $organisation,
				organisation: $organisation,
				filter: ['revoked' => false],
				limit: self::REVOKE_PAGE,
				offset: $offset
			);
			if ($page === null) {
				return null;
			}

			foreach ($page['rows'] as $row) {
				// The filter narrows; the row's own flag decides (fail closed).
				if ($this->isRevoked(row: $row) === false) {
					$live[] = $row;
				}
			}

			$offset += $page['read'];
			if ($page['read'] < self::REVOKE_PAGE) {
				return $live;
			}
		}

		// More pages than the cap: report it as incomplete rather than done.
		$this->logger->warning('Portaliq: revoke-all stopped at the page cap', ['organisation' => $organisation]);
		return null;
	}//end liveSessionsOf()

	/**
	 * Record one `admin-revoke` entry for a revoke-all call.
	 *
	 * @param string $admin The acting admin's user id.
	 * @param string $organisation The tenant.
	 * @param int $revoked How many sessions were revoked.
	 * @param bool $complete Whether every live session was reached and revoked.
	 *
	 * @return void
	 */
	private function recordAdminRevoke(string $admin, string $organisation, int $revoked, bool $complete): void {
		$state = 'no';
		if ($complete === true) {
			$state = 'yes';
		}

		$this->auditor->record(
			verb: 'admin-revoke',
			subjectRef: $admin,
			organisation: $organisation,
			register: PortalSessionService::SESSION_REGISTER,
			schema: PortalSessionService::SESSION_SCHEMA,
			id: '',
			detail: ['admin' => $admin, 'revoked' => (string)$revoked, 'complete' => $state]
		);
	}//end recordAdminRevoke()

	/**
	 * Whether a session row is revoked. Any truthy flag (true, 1, "1",
	 * "true") counts, so a storage layer that hands the boolean back in
	 * another shape fails closed (security review S2).
	 *
	 * @param array<string, mixed> $row The session row.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#2.2
	 */
	public function isRevoked(array $row): bool {
		$flag = ($row['revoked'] ?? false);
		if (is_bool($flag) === true) {
			return $flag;
		}

		return in_array(strtolower(trim((string)$flag)), ['1', 'true', 'yes', 'on'], true);
	}//end isRevoked()

	/**
	 * Extract a row's identifier (`id`/`uuid`, flat or in `@self`), or null.
	 *
	 * @param array<string, mixed>|null $row The normalised row.
	 *
	 * @return string|null
	 */
	public function rowId(?array $row): ?string {
		if ($row === null) {
			return null;
		}

		$self = ($row['@self'] ?? null);
		$selfUuid = null;
		$selfId = null;
		if (is_array($self) === true) {
			$selfUuid = ($self['uuid'] ?? null);
			$selfId = ($self['id'] ?? null);
		}

		$candidates = [($row['uuid'] ?? null), ($row['id'] ?? null), $selfUuid, $selfId];
		foreach ($candidates as $candidate) {
			if ((is_string($candidate) === true || is_int($candidate) === true) && (string)$candidate !== '') {
				return (string)$candidate;
			}
		}

		return null;
	}//end rowId()
}//end class
