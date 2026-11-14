<?php

/**
 * The assertion claims a declared scope claim may not take
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-28-case-actions-sign-a-document/tasks.md#T03
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel;

/**
 * The reserved scope-claim rule, readable from every component.
 *
 * @spec openspec/changes/archive/2026-09-28-case-actions-sign-a-document/tasks.md#T03
 */
final class ScopeClaims {

	/**
	 * The nine frozen assertion claims. A declared scope claim may never take
	 * one of these names (case-actions-sign-a-document D3).
	 *
	 * @var string[]
	 */
	public const RESERVED_ASSERTION_CLAIMS = ['sub', 'audience', 'organisation', 'trust', 'jti', 'use', 'iat', 'exp', 'iss'];

	/**
	 * The optional assertion claim that names the company branch the session
	 * acts for (decision 173). Only the session fills it, so a declared scope
	 * claim may not take this name either.
	 */
	public const BRANCH_ASSERTION_CLAIM = 'branch';

	/**
	 * Whether a declared scope claim names one of the nine frozen assertion
	 * claims (case-actions-sign-a-document T03) or the optional `branch`
	 * claim only the session fills (decision 173). Anything that is not a
	 * non-empty string is not a declared claim and so not reserved.
	 *
	 * @param mixed $scopeClaim The declared `scopeClaim` value.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-09-28-case-actions-sign-a-document/tasks.md#T03
	 */
	public static function isReserved(mixed $scopeClaim): bool {
		if (is_string($scopeClaim) === false || $scopeClaim === '') {
			return false;
		}

		$name = self::bareClaimName(scopeClaim: $scopeClaim);
		return in_array($name, self::RESERVED_ASSERTION_CLAIMS, true) === true || $name === self::BRANCH_ASSERTION_CLAIM;
	}//end isReserved()

	/**
	 * A scope claim without its app prefix: the part after the first `.`.
	 *
	 * @param string $scopeClaim The declared scope claim.
	 *
	 * @return string
	 */
	private static function bareClaimName(string $scopeClaim): string {
		$dot = strpos($scopeClaim, '.');
		if ($dot === false) {
			return $scopeClaim;
		}

		return substr($scopeClaim, ($dot + 1));
	}//end bareClaimName()
}//end class
