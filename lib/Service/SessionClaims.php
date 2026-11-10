<?php

/**
 * Portaliq Session Claims
 *
 * The subject and the case reference a validated session token's claims
 * name, shaped the way PortalSessionService answers them.
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
 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Service\Branch\BranchNumber;

/**
 * Shapes a validated token's claims, for PortalSessionService only.
 *
 * @internal
 *
 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T1
 */
final class SessionClaims {
	/**
	 * The server-derived subject of a live session's claims.
	 *
	 * @param array<string, mixed> $claims The live session's claims.
	 * @param string               $trust  The claims' trust, as PortalSessionService::normaliseTrust() reads it.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T1
	 */
	public function subject(array $claims, string $trust): array {
		return [
			'subjectRef' => (string)($claims['sub'] ?? ''),
			'audience' => (string)($claims['audience'] ?? ''),
			'organisation' => (string)($claims['organisation'] ?? ''),
			'trust' => $trust,
			'roles' => (array)($claims['roles'] ?? []),
			'jti' => (string)($claims['jti'] ?? ''),
			// The ORIGIN login's timestamp (portal-session-hardening-v2) — 0
			// for a token minted before this claim existed, which refreshSession()
			// treats as "cannot establish an origin" and refuses (fail-closed).
			'authTime' => (int)($claims['authTime'] ?? 0),
		] + $this->branch(claims: $claims) + [
			// Change signin-session-idle-warning-and-sso: the OIDC provider
			// ('' for none) and when this bearer expires.
			'provider' => (string)($claims['provider'] ?? ''),
			'expiresAt' => (int)($claims['exp'] ?? 0),
		];
	}//end subject()

	/**
	 * The reference fields of a reference session's claims.
	 *
	 * @param array<string, mixed> $claims The reference session's claims.
	 *
	 * @return array{subjectRef: string, organisation: string, caseReference: string, register: string, schema: string, jti: string}
	 *
	 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T1
	 */
	public function reference(array $claims): array {
		return [
			'subjectRef' => (string)($claims['sub'] ?? ''),
			'organisation' => (string)($claims['organisation'] ?? ''),
			'caseReference' => (string)($claims['caseReference'] ?? ''),
			'register' => (string)($claims['register'] ?? ''),
			'schema' => (string)($claims['schema'] ?? ''),
			'jti' => (string)($claims['jti'] ?? ''),
		];
	}//end reference()

	/**
	 * Change signin-eherkenning-branch: the branch in effect ('' for none)
	 * and whether the login restricted the session to it.
	 *
	 * @param array<string, mixed> $claims The live session's claims.
	 *
	 * @return array{branch: string, branchRestricted: bool}
	 */
	private function branch(array $claims): array {
		return [
			'branch' => (new BranchNumber())->normalise(value: ($claims['branch'] ?? null)),
			'branchRestricted' => (($claims['branchRestricted'] ?? false) === true && ($claims['branch'] ?? '') !== ''),
		];
	}//end branch()
}//end class
