<?php

/**
 * Portaliq Site Creator
 *
 * The site head's `DCTERMS.creator`: the organisation that runs the portal,
 * with its kind from the portal's TOOI organisation type label.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Cms
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
 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portal-names-its-organisation-type-from-tooi-req-pia-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

/**
 * Words the creator of a portal's pages.
 *
 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portal-names-its-organisation-type-from-tooi-req-pia-003
 */
final class SiteCreator {

	/**
	 * "Waterschap Hollands Noorderkwartier (waterschap)": the organisation's
	 * name, else the portal's title, with the type label when there is one.
	 * '' when there is no name at all.
	 *
	 * @param array<string, mixed> $portal       The resolved portal.
	 * @param array{name?: string}|null $organisation The portal's organisation, or null when unknown.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portal-names-its-organisation-type-from-tooi-req-pia-003
	 */
	public static function content(array $portal, ?array $organisation): string {
		$name = trim((string)($organisation['name'] ?? ''));
		if ($name === '') {
			$name = trim((string)($portal['title'] ?? ''));
		}

		$kind = trim((string)($portal['organisationTypeLabel'] ?? ''));
		if ($name === '' || $kind === '') {
			return $name;
		}

		return $name . ' (' . $kind . ')';
	}//end content()
}//end class
