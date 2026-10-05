<?php

/**
 * Portaliq Example Site Remover
 *
 * Takes an installed example site off an instance: it deletes the objects
 * the install recorded and nothing else, also inside the same portal.
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
 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-remove-an-example-site
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\ExampleSite;

/**
 * Removes an example site from its install's own record.
 *
 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-remove-an-example-site
 */
class ExampleSiteRemover {
	/**
	 * Constructor.
	 *
	 * @param ExampleSiteStore  $store  Deletes rows.
	 * @param ExampleSiteRows   $rows   Finds the portal's rows.
	 * @param ExampleSiteRecord $record Knows what the install created.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExampleSiteStore $store,
		private readonly ExampleSiteRows $rows,
		private readonly ExampleSiteRecord $record,
	) {
	}//end __construct()

	/**
	 * Delete what the install recorded, and nothing else.
	 *
	 * @param string $site The site's id.
	 * @param string $slug The slug of its portal.
	 *
	 * @return array<string, mixed> `recorded` (false when there is no record),
	 *                              `available`, `deleted` and `gone` per schema,
	 *                              `failed` ids, and `portal`: `deleted`,
	 *                              `kept-content`, `kept-not-ours` or `failed`.
	 *
	 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-remove-an-example-site
	 */
	public function remove(string $site, string $slug): array {
		$record = $this->record->read(site: $site);
		$report = [
			'site'      => $site,
			'recorded'  => ($this->record->isEmpty(record: $record) === false),
			'available' => $this->store->available(),
			'deleted'   => [],
			'gone'      => [],
			'failed'    => [],
			'portal'    => 'kept-not-ours',
		];
		if ($report['recorded'] === false || $report['available'] === false) {
			return $report;
		}

		$left = ['portal' => $record['portal'], 'menu' => [], 'page' => [], 'newsItem' => []];
		foreach (ExampleSiteRecord::SCHEMAS as $schema) {
			$outcome = $this->removeRows(schema: $schema, slug: $slug, ids: $record[$schema]);
			$report['deleted'][$schema] = $outcome['deleted'];
			$report['gone'][$schema]    = $outcome['gone'];
			$left[$schema]              = $outcome['failed'];
			foreach ($outcome['failed'] as $id) {
				$report['failed'][] = $schema . ' ' . $id;
			}
		}

		$report['portal'] = $this->removePortal(recordedId: $record['portal'], slug: $slug);
		if ($report['portal'] === 'deleted' || $report['portal'] === 'kept-not-ours') {
			$left['portal'] = '';
		}

		$this->record->write(site: $site, record: $left);

		return $report;
	}//end remove()

	/**
	 * Delete the recorded rows of one schema that are still in the portal.
	 *
	 * @param string             $schema The schema slug.
	 * @param string             $slug   The portal's slug.
	 * @param array<int, string> $ids    The recorded ids.
	 *
	 * @return array{deleted: int, gone: int, failed: array<int, string>}
	 */
	private function removeRows(string $schema, string $slug, array $ids): array {
		$present = $this->rows->ids(schema: $schema, slug: $slug);
		$outcome = ['deleted' => 0, 'gone' => 0, 'failed' => []];
		foreach ($ids as $id) {
			if (isset($present[$id]) === false) {
				$outcome['gone']++;
				continue;
			}

			if ($this->store->delete(schema: $schema, id: $id) === true) {
				$outcome['deleted']++;
				continue;
			}

			$outcome['failed'][] = $id;
		}

		return $outcome;
	}//end removeRows()

	/**
	 * Delete the portal when this install created it and nothing is left in it.
	 *
	 * @param string $recordedId The id the install recorded, or '' when the portal was there before.
	 * @param string $slug       The portal's slug.
	 *
	 * @return string `deleted`, `kept-content`, `kept-not-ours` or `failed`.
	 */
	private function removePortal(string $recordedId, string $slug): string {
		$portal = $this->rows->portal(slug: $slug);
		if ($recordedId === '' || $portal === null || $this->store->idOf(row: $portal) !== $recordedId) {
			return 'kept-not-ours';
		}

		if ($this->rows->rowsOf(schema: 'menu', slug: $slug) !== [] || $this->rows->rowsOf(schema: 'page', slug: $slug) !== []) {
			return 'kept-content';
		}

		if ($this->store->delete(schema: 'portal', id: $recordedId) === true) {
			return 'deleted';
		}

		return 'failed';
	}//end removePortal()
}//end class
