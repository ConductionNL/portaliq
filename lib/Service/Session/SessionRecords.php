<?php

/**
 * Portaliq Session Records
 *
 * Looks up the `portalSession` row recorded for a token id and says whether
 * that session is still live. A missing row, an unreachable OpenRegister or
 * a revoked row all count as not live (fail closed).
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Session
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
 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#1.1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Session;

use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalSessionRevoker;
use OCA\Portaliq\Service\PortalSessionService;

/**
 * Finds a session's recorded row by `jti` and tells a live one from a revoked one.
 *
 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#2.2
 */
final class SessionRecords {
	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader   $reader  Looks up sessions by jti.
	 * @param PortalSessionRevoker $revoker Reads a row's revoked flag.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly PortalSessionRevoker $revoker,
	) {
	}//end __construct()

	/**
	 * Whether a `jti` has a corresponding, not-revoked `portalSession` row.
	 * A missing row, an unreachable OpenRegister, or an explicitly revoked row
	 * ALL resolve to `false` — fail closed, never "not revoked by default".
	 *
	 * @param string $jti The session's token id.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#2.2
	 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#2.3
	 */
	public function isJtiActive(string $jti): bool {
		if ($jti === '') {
			return false;
		}

		$row = $this->findSessionByJti(jti: $jti);
		if ($row === null) {
			return false;
		}

		return $this->revoker->isRevoked(row: $row) === false;
	}//end isJtiActive()

	/**
	 * Find the `portalSession` row for a `jti`, or null when absent/unreachable.
	 *
	 * @param string $jti The session's token id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#2.2
	 */
	public function findSessionByJti(string $jti): ?array {
		if ($jti === '') {
			return null;
		}

		$rows = $this->reader->readCollection(
			register: PortalSessionService::SESSION_REGISTER,
			schema: PortalSessionService::SESSION_SCHEMA,
			scopeField: 'jti',
			subjectRef: $jti,
			limit: 2
		);

		return ($rows[0] ?? null);
	}//end findSessionByJti()
}//end class
