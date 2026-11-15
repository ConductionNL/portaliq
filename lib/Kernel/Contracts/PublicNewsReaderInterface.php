<?php

/**
 * Contract of PublicNewsReader
 *
 * The public surface of PublicNewsReader that other components call, so they depend
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
 * Contract of PublicNewsReader.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PublicNewsReaderInterface {

	/**
	 * One public item of a portal, with its body, or null when there is no
	 * such public item.
	 *
	 * @param string $portal The portal slug.
	 * @param string $id     The item id.
	 *
	 * @return array<string, mixed>|null The summary plus `body` (markdown), or null.
	 *
	 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-item-shows-on-a-portals-public-website-only-when-staff-put-it-there
	 */
	public function itemFor(string $portal, string $id): ?array;

	/**
	 * The newest public items of a portal, newest first.
	 *
	 * @param string $portal The portal slug.
	 * @param int    $limit  How many, clamped to 1..MAX_LIMIT.
	 *
	 * @return array<int, array<string, mixed>> Each `{id, title, intro, publishedAt, audienceLabel, image}`.
	 *
	 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-item-shows-on-a-portals-public-website-only-when-staff-put-it-there
	 */
	public function listFor(string $portal, int $limit): array;
}//end interface
