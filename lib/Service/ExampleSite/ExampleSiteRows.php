<?php

/**
 * Portaliq Example Site Rows
 *
 * The rows of one portal as an example site install sees them: the portal by
 * its slug, and its menus, pages and news items by what tells them apart.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\ExampleSite
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
 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-site-with-one-command
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\ExampleSite;

/**
 * Finds a portal's rows and names them.
 *
 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-site-with-one-command
 */
class ExampleSiteRows {
	/**
	 * Constructor.
	 *
	 * @param ExampleSiteStore $store Reads the rows.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExampleSiteStore $store,
	) {
	}//end __construct()

	/**
	 * The portal row with this slug, or null.
	 *
	 * @param string $slug The portal's slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-site-with-one-command
	 */
	public function portal(string $slug): ?array {
		foreach ($this->store->find(schema: 'portal', filters: []) as $row) {
			if (($row['slug'] ?? null) === $slug) {
				return $row;
			}
		}

		return null;
	}//end portal()

	/**
	 * The rows of one schema that belong to the portal.
	 *
	 * The filter is checked again here: a store that ignored it would hand
	 * over another portal's rows, and an install would then see a page of
	 * another portal as its own.
	 *
	 * @param string $schema The schema slug.
	 * @param string $slug   The portal's slug.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-the-install-must-prove-what-arrived
	 */
	public function rowsOf(string $schema, string $slug): array {
		$rows = [];
		foreach ($this->store->find(schema: $schema, filters: ['portal' => $slug]) as $row) {
			if (($row['portal'] ?? null) === $slug) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end rowsOf()

	/**
	 * The portal's rows of one schema, by what tells them apart.
	 *
	 * @param string $schema The schema slug.
	 * @param string $slug   The portal's slug.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-site-with-one-command
	 */
	public function keyed(string $schema, string $slug): array {
		$keyed = [];
		foreach ($this->rowsOf(schema: $schema, slug: $slug) as $row) {
			$keyed[$this->keyOf(schema: $schema, object: $row)] = $row;
		}

		return $keyed;
	}//end keyed()

	/**
	 * The ids of the portal's rows of one schema.
	 *
	 * @param string $schema The schema slug.
	 * @param string $slug   The portal's slug.
	 *
	 * @return array<string, bool> Id to true.
	 *
	 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-remove-an-example-site
	 */
	public function ids(string $schema, string $slug): array {
		$ids = [];
		foreach ($this->rowsOf(schema: $schema, slug: $slug) as $row) {
			$ids[$this->store->idOf(row: $row)] = true;
		}

		return $ids;
	}//end ids()

	/**
	 * What tells one object from another, within its portal.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $object The object.
	 *
	 * @return string A menu's position and title, a page's route, a news item's title.
	 *
	 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-site-with-one-command
	 */
	public function keyOf(string $schema, array $object): string {
		if ($schema === 'menu') {
			return (int)($object['position'] ?? 0) . ' ' . (string)($object['title'] ?? '');
		}

		if ($schema === 'page') {
			return (string)($object['route'] ?? '');
		}

		return (string)($object['title'] ?? '');
	}//end keyOf()
}//end class
