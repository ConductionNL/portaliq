<?php

/**
 * Contract of OidcStateStoreService
 *
 * The public surface of OidcStateStoreService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Portaliq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel\Contracts;

/**
 * Contract of OidcStateStoreService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OidcStateStoreServiceInterface {

	/**
	 * Consume a `state` EXACTLY ONCE. Fails closed to null on: unknown state,
	 * an already-used row (replay), an expired row, or an OpenRegister read/
	 * write failure — all indistinguishable to the caller (no oracle).
	 *
	 * Marks the row `used` BEFORE returning it, so a concurrent or replayed
	 * consumption of the SAME state can never both succeed.
	 *
	 * @param string $state The OIDC `state` parameter to consume.
	 *
	 * @return array{nonce: string, codeVerifier: string, org: string, provider: string, returnTo: string, route: string, silent: bool}|null
	 *
	 * @spec openspec/changes/archive/2026-07-24-portal-oidc-broker-login/tasks.md#T02
	 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T08
	 * @spec openspec/specs/supplier-portal/spec.md#every-validation-failure-is-an-identical-generic-error
	 */
	public function consume(string $state): ?array;

	/**
	 * Store one pending OIDC round trip, keyed by `$state`.
	 *
	 * @param string $state The OIDC `state` parameter (single-use key).
	 * @param string $nonce The OIDC `nonce` this request carries.
	 * @param string $codeVerifier The PKCE code verifier.
	 * @param string $org The `?org=` slug the login was started for.
	 * @param string $provider The provider preset.
	 * @param string $returnTo The SPA path to redirect to once minted.
	 * @param bool $silent Whether the start asked the broker for no prompt
	 *                     (signin-session-idle-warning-and-sso D5).
	 *
	 * @return bool True when the row was written; false on any write failure
	 *              (the caller — `oidcStart()` — fails the whole request
	 *              closed rather than issue a redirect with no matching state).
	 *
	 * @spec openspec/changes/archive/2026-07-24-portal-oidc-broker-login/tasks.md#T02
	 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T07
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) -- `silent` is one recorded
	 * fact of the round trip, stored as-is on the row; the method does the
	 * same thing either way.
	 */
	public function create(
		string $state,
		string $nonce,
		string $codeVerifier,
		string $org,
		string $provider,
		string $returnTo,
		bool $silent = false,
	): bool;
}//end interface
