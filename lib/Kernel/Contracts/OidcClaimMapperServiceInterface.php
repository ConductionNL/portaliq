<?php

/**
 * Contract of OidcClaimMapperService
 *
 * The public surface of OidcClaimMapperService that other components call, so they depend
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
 * Contract of OidcClaimMapperService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OidcClaimMapperServiceInterface {

	/**
	 * Map ALREADY-VALIDATED ID token claims to identityType/identityRef/
	 * subjectRef/audience per the merged config. Fails closed (null) on any
	 * unmappable claim — an empty/missing identityRef or audience, or a
	 * `claim:` subjectRef/audience reference to a claim that is absent.
	 *
	 * `subjectRef` is `null` in the returned array when the config says
	 * `derive` — the caller mints/reuses a stable server-side reference; it is
	 * NEVER a request parameter, only ever a validated claim or a server mint.
	 *
	 * @param array<string, mixed> $claims The validated ID token claim set.
	 * @param array<string, mixed> $config The merged provider config (from `applyPreset()`).
	 *
	 * @return array{identityType: string, identityRef: string, subjectRef: string|null, audience: string, branch: string}|null
	 *
	 * @spec openspec/changes/archive/2026-07-24-portal-oidc-broker-login/tasks.md#T05
	 * @spec openspec/specs/supplier-portal/spec.md#the-subject-reference-is-server-derived-never-client-supplied
	 */
	public function mapClaims(array $claims, array $config): ?array;

	/**
	 * Map the broker's LoA (an `acr`-style claim, per-org configurable via
	 * `loaClaim`) to the portal trust vocabulary. Unmapped/missing LoA, and
	 * any malformed `loaMap` VALUE, normalise to `low` — never higher
	 * (under-privilege on ambiguity, design.md).
	 *
	 * @param array<string, mixed> $claims The validated ID token claim set.
	 * @param array<string, mixed> $config The merged provider config.
	 *
	 * @return string One of `low`, `substantial`, `high`.
	 *
	 * @spec openspec/changes/archive/2026-07-24-portal-oidc-broker-login/tasks.md#T05
	 * @spec openspec/specs/supplier-portal/spec.md#broker-loa-maps-to-portal-trust-under-privileging-on-ambiguity
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) -- deliberate reuse of the ONE
	 * canonical trust-normalisation point (SessionTrust's own
	 * docblock: "the single normalisation point used by the session edge,
	 * the registry, and the contribution controller") — duplicating it here
	 * would risk the trust vocabulary drifting between call sites.
	 */
	public function mapLoaToTrust(array $claims, array $config): string;
}//end interface
