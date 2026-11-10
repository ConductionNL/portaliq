<?php

/**
 * Portaliq Mandated Read
 *
 * The state of one mandate's read of one case collection while
 * `PortalCaseListReader` walks the entities it reaches: the collection and
 * its app, the mandate and its description, the party it is held for and
 * the entity being read. It never leaves the reader that builds it.
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
 * @spec openspec/changes/portal-visibility-follows-the-party-tree/specs/portal-visibility-and-the-party-tree/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * One mandate's read in progress.
 *
 * @spec openspec/specs/portal-visibility-and-the-party-tree/spec.md#requirement-the-case-app-decides-whether-its-cases-may-be-reached-through-a-parent-req-ptv-002
 */
final class MandatedRead {

	/**
	 * The mandate, described.
	 *
	 * @var array<string, mixed>
	 */
	public array $described = [];

	/**
	 * The party the mandate is held for.
	 *
	 * @var string
	 */
	public string $party = '';

	/**
	 * The entity being read.
	 *
	 * @var string
	 */
	public string $entity = '';

	/**
	 * Constructor.
	 *
	 * @param CaseListSource       $source  The case collection and its app.
	 * @param array<string, mixed> $mandate The mandate being spent.
	 */
	public function __construct(
		public readonly CaseListSource $source,
		public readonly array $mandate,
	) {
	}//end __construct()
}//end class
