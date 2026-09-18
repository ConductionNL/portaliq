<?php

/**
 * The citizen's entry point as content an editor arranges, not a coded page.
 *
 * REQ-PIFO-006: pages, topics and layouts, placed without a developer. What
 * this class does is describe the STARTING arrangement so an editor has
 * something to rearrange, rather than an empty portal and a support ticket.
 *
 * 🔴 IT SEEDS ONCE AND NEVER OVERWRITES. An editor who moves a topic, renames
 * a page or deletes one has made a decision, and a provisioner that re-asserts
 * its own layout on the next run undoes that decision silently, at whatever
 * hour the job happens to run. The editor's version is not a drift to be
 * corrected; it is the point of the requirement. So an existing route is left
 * exactly as it is and reported as skipped, with the reason.
 *
 * 🔴 AND IT NEVER INVENTS CATALOGUE ENTRIES. The topics are containers; what
 * goes in them is whatever opencatalogi has actually published, read at render
 * time. Seeding example requests would put entries in front of citizens that
 * no municipality had decided to offer.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Intake
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://Portaliq.app
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

/**
 * The entry point's starting pages, topics and layout.
 */
class PortalEntryPointContent {

	/**
	 * The route the entry point is seeded at.
	 *
	 * @var string
	 */
	public const ENTRY_ROUTE = 'aanvragen';

	/**
	 * The layout an entry-point page uses.
	 *
	 * @var string
	 */
	public const LAYOUT = 'catalogue-topics';

	/**
	 * The pages the entry point starts with.
	 *
	 * Draft, not published. A page that appears on a live portal the moment an
	 * app is installed is a page nobody chose to publish, in whatever wording
	 * this file happened to carry.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<int, array<string, mixed>> The pages.
	 */
	public function pagesFor(string $portal): array {
		return [
			[
				'portal' => $portal,
				'route' => self::ENTRY_ROUTE,
				'title' => 'Aanvragen en melden',
				'summary' => 'Alles wat u bij ons kunt aanvragen of melden, bij elkaar.',
				'layout' => self::LAYOUT,
				'status' => 'draft',
				'locale' => 'nl',
			],
		];
	}//end pagesFor()

	/**
	 * The topics the entry point starts with.
	 *
	 * Named for what a resident wants to do, not for the department that
	 * handles it. A citizen looking to report a broken street light does not
	 * know which directorate owns street lighting, and should not have to.
	 *
	 * @return array<int, array<string, mixed>> The topics.
	 */
	public function topics(): array {
		return [
			['slug' => 'wonen-en-verhuizen', 'title' => 'Wonen en verhuizen', 'order' => 1],
			['slug' => 'melding-openbare-ruimte', 'title' => 'Iets melden in de buurt', 'order' => 2],
			['slug' => 'werk-en-inkomen', 'title' => 'Werk en inkomen', 'order' => 3],
			['slug' => 'documenten', 'title' => 'Documenten en aktes', 'order' => 4],
			['slug' => 'overig', 'title' => 'Overige aanvragen', 'order' => 5],
		];
	}//end topics()

	/**
	 * What a seed run would do, given what already exists.
	 *
	 * @param string             $portal         The portal slug.
	 * @param array<int, string> $existingRoutes The routes the portal already has.
	 *
	 * @return array<string, mixed> The plan: what is created, what is left alone and why.
	 */
	public function plan(string $portal, array $existingRoutes): array {
		$create = [];
		$skip = [];

		foreach ($this->pagesFor(portal: $portal) as $page) {
			$route = (string)$page['route'];
			if (in_array($route, $existingRoutes, true) === true) {
				// 🔴 LEFT ALONE, AND SAID SO. An editor who renamed or moved
				// this page made a decision; re-asserting the seeded version
				// undoes it silently at whatever hour the job runs.
				$skip[] = [
					'route' => $route,
					'reason' => sprintf(
						'"%s" already exists on this portal and is left exactly as it is. '
						.'Whoever arranged it decided how it should look.',
						$route
					),
				];
				continue;
			}

			$create[] = $page;
		}

		// The topics come with the page. Seeding them beside a page that was
		// left alone would drop containers onto somebody else's arrangement.
		$topics = [];
		if ($create !== []) {
			$topics = $this->topics();
		}

		return [
			'create' => $create,
			'skip' => $skip,
			'topics' => $topics,
			'createdCount' => count($create),
			'skippedCount' => count($skip),
		];
	}//end plan()
}//end class
