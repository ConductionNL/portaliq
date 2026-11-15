<?php

/**
 * Contract of TrafficIngestService
 *
 * The public surface of TrafficIngestService that other components call, so they depend
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
 * Contract of TrafficIngestService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TrafficIngestServiceInterface {

	/**
	 * Ingest events for a portal named by slug.
	 *
	 * The entry point for other apps in this instance. A slug that resolves
	 * to no published portal refuses the whole batch as `unknown-portal`.
	 *
	 * @param string                           $portalSlug The portal's slug.
	 * @param array<int, array<string, mixed>> $events     The events.
	 * @param array<string, mixed>             $context    ip, userAgent, acceptLanguage, consent, serverSide.
	 *
	 * @return array{accepted: int, refused: array<string, int>} What happened.
	 *
	 * @spec openspec/changes/archive/2026-11-04-portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-mail-events-must-only-be-written-server-side
	 */
	public function ingest(string $portalSlug, array $events, array $context = []): array;

	/**
	 * Ingest events for an already resolved portal.
	 *
	 * @param array<string, mixed>             $portal  The portal record.
	 * @param array<int, array<string, mixed>> $events  The events.
	 * @param array<string, mixed>             $context ip, userAgent, acceptLanguage, consent, serverSide, allowOld.
	 *
	 * @return array{accepted: int, refused: array<string, int>} What happened.
	 *
	 * @spec openspec/changes/archive/2026-11-04-portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-an-ip-address-must-not-be-stored
	 * @spec openspec/specs/portal-traffic-reporting/spec.md#requirement-a-roll-up-portal-must-sum-its-members-and-never-count-its-own
	 */
	public function ingestForPortal(array $portal, array $events, array $context = []): array;
}//end interface
