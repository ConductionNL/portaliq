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
	 * @param string $subjectRef One account only (sign-in-with-an-email-link
	 *                           REQ-IWI-013), or '' for the whole organisation.
	 *                           A per-account run records no organisation entry.
	 *
	 * @return array{revoked: int, failed: int, complete: bool}
	 *
	 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#3.2
	 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T09
	 * @spec openspec/changes/sign-in-with-an-email-link/specs/portal-ways-in/spec.md#requirement-staff-can-revoke-an-accounts-e-mail-links-and-sessions-req-iwi-013
	 */
	public function revokeAll(string $organisation, string $admin, string $subjectRef = ''): array {
		if ($organisation === '') {
			return ['revoked' => 0, 'failed' => 0, 'complete' => true];
		}

		$run = new SessionRevokeRun(organisation: $organisation, admin: $admin);
		$run->subjectRef = $subjectRef;
		$rows = $this->liveSessionsOf(run: $run);
		if ($rows === null) {
			$this->recordAdminRevoke(run: $run, complete: false);
			return ['revoked' => 0, 'failed' => 0, 'complete' => false];
		}

		foreach ($rows as $row) {
			$this->revokeRow(run: $run, row: $row);
		}

		$this->recordAdminRevoke(run: $run, complete: $run->failed === 0);

		return ['revoked' => $run->revoked, 'failed' => $run->failed, 'complete' => $run->failed === 0];
	}//end revokeAll()

	/**
	 * Revoke one live session and audit it as `admin-revoke` naming the
	 * admin; count it as failed when it cannot be written.
	 *
	 * @param SessionRevokeRun     $run The revoke-all.
	 * @param array<string, mixed> $row The live session row.
	 *
	 * @return void
	 */
	private function revokeRow(SessionRevokeRun $run, array $row): void {
		if ($this->revokedRow(row: $row) === null) {
			$run->failed++;
			return;
		}

		$run->revoked++;
		$this->recordSessionRevoke(run: $run, row: $row);
	}//end revokeRow()

	/**
	 * Audit one revoked session as `admin-revoke` naming the admin.
	 *
	 * @param SessionRevokeRun     $run The revoke-all.
	 * @param array<string, mixed> $row The revoked session row.
	 *
	 * @return void
	 */
	private function recordSessionRevoke(SessionRevokeRun $run, array $row): void {
		$this->auditor->record(
			verb: 'admin-revoke',
			subjectRef: (string)($row['subjectRef'] ?? ''),
			organisation: $run->organisation,
			register: PortalSessionService::SESSION_REGISTER,
			schema: PortalSessionService::SESSION_SCHEMA,
			id: (string)($row['jti'] ?? ''),
			jti: (string)($row['jti'] ?? ''),
			detail: ['admin' => $run->admin]
		);
	}//end recordSessionRevoke()

	/**
	 * Write the revoked flag on a session row; null when the row names no id
	 * or the write fails.
	 *
	 * @param array<string, mixed> $row The live session row.
	 *
	 * @return array<string, mixed>|null
	 */
	private function revokedRow(array $row): ?array {
		$uuid = $this->rowId(row: $row);
		if ($uuid === null) {
			return null;
		}

		return $this->writer->updateObject(
			register: PortalSessionService::SESSION_REGISTER,
			schema: PortalSessionService::SESSION_SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: '',
			id: $uuid,
			data: ['revoked' => true]
		);
	}//end revokedRow()

	/**
	 * Every not-yet-revoked session row of an organisation, read to the end,
	 * or null when OpenRegister could not be read.
	 *
	 * @param SessionRevokeRun $run The revoke-all.
	 *
	 * @return array<int, array<string, mixed>>|null
	 *
	 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#3.2
	 */
	private function liveSessionsOf(SessionRevokeRun $run): ?array {
		$live = [];
		for ($pages = 0; $pages < self::REVOKE_MAX_PAGES; $pages++) {
			$page = $this->sessionPage(run: $run);
			if ($page === null) {
				return null;
			}

			$live = array_merge($live, $this->liveRowsOf(run: $run, rows: $page['rows']));
			$run->offset += $page['read'];
			if ($page['read'] < self::REVOKE_PAGE) {
				return $live;
			}
		}

		// More pages than the cap: report it as incomplete rather than done.
		$this->logger->warning('Portaliq: revoke-all stopped at the page cap', ['organisation' => $run->organisation]);
		return null;
	}//end liveSessionsOf()

	/**
	 * The next page of not-yet-revoked session rows in the run's scope: the
	 * one account of a per-account run, else the organisation.
	 *
	 * @param SessionRevokeRun $run The revoke-all, at its read offset.
	 *
	 * @return array{rows: array<int, array<string, mixed>>, read: int}|null
	 */
	private function sessionPage(SessionRevokeRun $run): ?array {
		$scope = $this->revokeScope(run: $run);
		return $this->reader->readScopedPage(
			register: PortalSessionService::SESSION_REGISTER,
			schema: PortalSessionService::SESSION_SCHEMA,
			scopeField: $scope[0],
			scopeValue: $scope[1],
			organisation: $run->organisation,
			filter: ['revoked' => false],
			limit: self::REVOKE_PAGE,
			offset: $run->offset
		);
	}//end sessionPage()

	/**
	 * The scope field and value a run reads: the one account of a
	 * per-account run, else the organisation.
	 *
	 * @param SessionRevokeRun $run The revoke-all.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function revokeScope(SessionRevokeRun $run): array {
		if ($run->subjectRef !== '') {
			return ['subjectRef', $run->subjectRef];
		}

		return ['organisation', $run->organisation];
	}//end revokeScope()

	/**
	 * The rows of a page that are live and in the run's scope. The filter
	 * narrows; the row's own flag decides (fail closed), and a per-account
	 * run never reaches another account's row.
	 *
	 * @param SessionRevokeRun                 $run  The revoke-all.
	 * @param array<int, array<string, mixed>> $rows The page's rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function liveRowsOf(SessionRevokeRun $run, array $rows): array {
		$live = [];
		foreach ($rows as $row) {
			if ($this->isRevoked(row: $row) === false && ($run->subjectRef === '' || ($row['subjectRef'] ?? null) === $run->subjectRef)) {
				$live[] = $row;
			}
		}

		return $live;
	}//end liveRowsOf()

	/**
	 * Record one `admin-revoke` entry for a revoke-all call.
	 *
	 * @param SessionRevokeRun $run      The revoke-all, with how many sessions it revoked.
	 * @param bool             $complete Whether every live session was reached and revoked.
	 *
	 * @return void
	 */
	private function recordAdminRevoke(SessionRevokeRun $run, bool $complete): void {
		// A per-account revoke is audited per session only.
		if ($run->subjectRef !== '') {
			return;
		}

		$state = 'no';
		if ($complete === true) {
			$state = 'yes';
		}

		$this->auditor->record(
			verb: 'admin-revoke',
			subjectRef: $run->admin,
			organisation: $run->organisation,
			register: PortalSessionService::SESSION_REGISTER,
			schema: PortalSessionService::SESSION_SCHEMA,
			id: '',
			detail: ['admin' => $run->admin, 'revoked' => (string)$run->revoked, 'complete' => $state]
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
	 *
	 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#3.2
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
