<?php

/**
 * Portal By Slug
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 *
 * @spec openspec/specs/portal-traffic-reporting/spec.md#requirement-an-access-log-must-import-as-page-views-without-assets-or-bots
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * Finds one published portal by its exact slug. The using class carries
 * `$portals` (PublishedPortals or PortalResolver).
 *
 * @spec openspec/specs/portal-traffic-reporting/spec.md#requirement-an-access-log-must-import-as-page-views-without-assets-or-bots
 */
trait PortalBySlug {
	/**
	 * The first published portal whose slug is exactly this one, or null.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/portal-traffic-reporting/spec.md#requirement-an-access-log-must-import-as-page-views-without-assets-or-bots
	 */
	private function portalBySlug(string $slug): ?array {
		foreach ($this->portals->allPublishedPortals() as $candidate) {
			if (($candidate['slug'] ?? null) === $slug) {
				return $candidate;
			}
		}

		return null;
	}//end portalBySlug()
}//end trait
