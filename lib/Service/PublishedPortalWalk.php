<?php

/**
 * Published Portal Walk
 *
 * Splits the published portals into the ones measured on their own and the
 * roll-ups that sum other portals.
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
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-roll-up-portal-must-sum-its-members-and-never-count-its-own
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * The published portals a traffic job walks. The using class carries
 * `$portals` (PortalResolver) and `$config` (TrafficConfigResolver).
 *
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-roll-up-portal-must-sum-its-members-and-never-count-its-own
 */
trait PublishedPortalWalk {
	/**
	 * The published portals with a slug, in their order: those measured on
	 * their own as [slug, resolved configuration] pairs, and the roll-ups
	 * as slug => member slugs.
	 *
	 * @return array{0: array<int, array{0: string, 1: array<string, mixed>}>, 1: array<string, array<int, string>>}
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-roll-up-portal-must-sum-its-members-and-never-count-its-own
	 */
	private function portalWalk(): array {
		$own     = [];
		$rollups = [];
		foreach ($this->sluggedPortals() as [$slug, $portal]) {
			$config = $this->config->resolve(portal: $portal);
			if ($config['rollupOf'] !== []) {
				$rollups[$slug] = $config['rollupOf'];
				continue;
			}

			$own[] = [$slug, $config];
		}

		return [$own, $rollups];
	}//end portalWalk()

	/**
	 * The published portals whose slug is not blank, in their order, as
	 * [trimmed slug, portal record] pairs.
	 *
	 * @return array<int, array{0: string, 1: array<string, mixed>}>
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-roll-up-portal-must-sum-its-members-and-never-count-its-own
	 */
	private function sluggedPortals(): array {
		$slugged = [];
		foreach ($this->portals->allPublishedPortals() as $portal) {
			$slug = trim((string)($portal['slug'] ?? ''));
			if ($slug !== '') {
				$slugged[] = [$slug, $portal];
			}
		}

		return $slugged;
	}//end sluggedPortals()
}//end trait
