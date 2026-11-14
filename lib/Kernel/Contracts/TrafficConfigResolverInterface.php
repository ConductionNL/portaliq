<?php

/**
 * Contract of TrafficConfigResolver
 *
 * The public surface of TrafficConfigResolver that other components call, so they depend
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
 * Contract of TrafficConfigResolver.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TrafficConfigResolverInterface {

	/**
	 * Resolve a portal record's `traffic` block into a complete configuration.
	 *
	 * @param array<string, mixed> $portal The resolved portal record.
	 *
	 * @return array{enabled: bool, kind: string, events: string[], dimensions: string[],
	 *               sessionTimeoutMinutes: int, retentionDays: int,
	 *               consentRequired: bool, preConsentEvents: string[],
	 *               regionGranularity: string, persistClientId: bool,
	 *               sensitive: array<string, bool>,
	 *               goals: array<int, array<string, mixed>>,
	 *               funnels: array<int, array<string, mixed>>,
	 *               customDimensions: array<int, array<string, string>>,
	 *               segments: array<int, array<string, mixed>>, rollupOf: string[],
	 *               reports: array<int, array<string, mixed>>, alerts: array<int, array<string, mixed>>,
	 *               experiments: array<int, array<string, mixed>>} The effective configuration.
	 *
	 * @spec openspec/changes/archive/2026-11-04-portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-a-portal-must-decide-what-is-measured-and-the-collector-must-enforce-it
	 * @spec openspec/specs/portal-traffic-outcomes/spec.md#requirement-goals-must-be-evaluated-from-the-portals-own-definitions
	 * @spec openspec/specs/portal-traffic-reporting/spec.md#requirement-a-segment-must-be-a-saved-filter-over-sessions
	 * @spec openspec/specs/portal-traffic-experiments/spec.md#requirement-heatmaps-must-be-off-by-default-and-hold-positions-never-content
	 */
	public function resolve(array $portal): array;
}//end interface
