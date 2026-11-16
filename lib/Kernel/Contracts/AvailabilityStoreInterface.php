<?php

/**
 * Contract of AvailabilityStore
 *
 * The public surface of AvailabilityStore that other components call, so they depend
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
 * Contract of AvailabilityStore.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AvailabilityStoreInterface {

	public const DAILY_SCHEMA = 'portalAvailabilityDaily';

	public const OUTAGE_SCHEMA = 'portalAvailabilityOutage';

	/**
	 * A portal's daily records between two dates, inclusive, keyed by date.
	 *
	 * @param string $portal The portal slug.
	 * @param string $from The first date, YYYY-MM-DD.
	 * @param string $until The last date, YYYY-MM-DD.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/specs/portal-availability/spec.md#requirement-an-administrator-reads-a-twelve-month-report-req-oar-004
	 */
	public function dailyBetween(string $portal, string $from, string $until): array;

	/**
	 * A portal's open outage, or null. Every page of the portal's outages is
	 * read, so an open one behind the first page is still found rather than
	 * opened a second time.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/portal-availability/spec.md#requirement-each-published-portal-is-checked-every-five-minutes-req-oar-001
	 */
	public function openOutage(string $portal): ?array;

	/**
	 * Delete every daily record before a date and every outage that started
	 * before it.
	 *
	 * @param string $cutoff The first date kept, YYYY-MM-DD.
	 *
	 * @return int How many records were removed.
	 *
	 * @spec openspec/specs/portal-availability/spec.md#requirement-thirteen-months-are-kept-and-no-more-req-oar-003
	 */
	public function purgeBefore(string $cutoff): int;

	/**
	 * Create or update one record.
	 *
	 * @param string $schema DAILY_SCHEMA or OUTAGE_SCHEMA.
	 * @param array<string, mixed> $row The record; its `uuid` (or `@self.id`) updates in place.
	 *
	 * @return bool True when saved.
	 *
	 * @spec openspec/specs/portal-availability/spec.md#requirement-each-published-portal-is-checked-every-five-minutes-req-oar-001
	 */
	public function save(string $schema, array $row): bool;
}//end interface
