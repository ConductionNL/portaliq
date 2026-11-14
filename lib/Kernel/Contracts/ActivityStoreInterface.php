<?php

/**
 * Contract of ActivityStore
 *
 * The public surface of ActivityStore that other components call, so they depend
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
 * Contract of ActivityStore.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ActivityStoreInterface {

	/**
	 * The activity schema.
	 */
	public const OFFER = 'activityOffer';

	/**
	 * The row's primary id: `id`, else `uuid`, else the `@self` id.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The id, or '' when the row carries none.
	 *
	 * @spec openspec/changes/archive/2026-09-28-extracurricular-activity-offer/design.md#d6-a-store-class-instead-of-openregister-calls-in-every-service
	 */
	public function idOf(array $row): string;

	/**
	 * One row of a schema by its id, uuid or slug, or null.
	 *
	 * @param string $schema One of the schema constants.
	 * @param string $id The id, uuid or slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/archive/2026-09-28-extracurricular-activity-offer/design.md#d6-a-store-class-instead-of-openregister-calls-in-every-service
	 */
	public function lookup(string $schema, string $id): ?array;

	/**
	 * Save one row: create it, or update it when an id is given.
	 *
	 * @param string $schema One of the schema constants.
	 * @param array<string, mixed> $data The object data, without the `@self` envelope.
	 * @param string $id The id to update, or '' to create.
	 *
	 * @return array<string, mixed>|null The saved row, or null on failure.
	 *
	 * @spec openspec/changes/archive/2026-09-28-extracurricular-activity-offer/design.md#d6-a-store-class-instead-of-openregister-calls-in-every-service
	 */
	public function save(string $schema, array $data, string $id = ''): ?array;
}//end interface
