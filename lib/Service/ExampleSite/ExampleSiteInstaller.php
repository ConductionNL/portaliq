<?php

/**
 * Portaliq Example Site Installer
 *
 * Puts a shipped example site on an instance and takes it off again. It
 * writes only what is missing, keeps its own record of what it wrote, and
 * after writing reads the instance back to prove what arrived.
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

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\PortalThemeResolver;
use OCP\IAppConfig;

/**
 * Installs and removes an example site.
 *
 * WHY THE PROOF IS A SECOND READ. OpenRegister answers a write with the
 * object as it was sent, also for a key the schema does not know and
 * therefore does not keep. A count of answers would call a site complete
 * whose header search never reached the portal record. So the install reads
 * every type back and compares it with the declaration, key by key.
 *
 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-the-install-must-prove-what-arrived
 */
class ExampleSiteInstaller {
	/**
	 * The schema behind each list of a declaration.
	 */
	private const SCHEMAS = ['menus' => 'menu', 'pages' => 'page', 'news' => 'newsItem'];

	/**
	 * The prefix of the app-config key that holds what an install created.
	 */
	private const RECORD_PREFIX = 'example_site_';

	/**
	 * Constructor.
	 *
	 * @param ExampleSiteStore    $store     Reads, writes and deletes rows.
	 * @param IAppConfig          $appConfig Holds the record of what was created.
	 * @param PortalThemeResolver $themes    Says whether the theme app offers the site's set.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExampleSiteStore $store,
		private readonly IAppConfig $appConfig,
		private readonly PortalThemeResolver $themes,
	) {
	}//end __construct()

	/**
	 * Write what the declaration holds and the instance lacks, then prove it.
	 *
	 * @param array<string, mixed> $site A declaration from ExampleSiteCatalogue.
	 *
	 * @return array<string, mixed> `site`, `portal`, `available`, `themeOffered`,
	 *                              `types` (per schema: declared, created, kept,
	 *                              arrived), `missing`, `lost` and `ok`.
	 *
	 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-site-with-one-command
	 */
	public function install(array $site): array {
		$slug   = (string)$site['portal']['slug'];
		$report = [
			'site'         => (string)$site['id'],
			'portal'       => $slug,
			'available'    => $this->store->available(),
			'themeOffered' => $this->themeOffered(portal: $site['portal']),
			'types'        => [],
			'missing'      => [],
			'lost'         => [],
			'ok'           => false,
		];
		if ($report['available'] === false) {
			return $report;
		}

		$record  = $this->record(site: $report['site']);
		$created = ['portal' => [], 'menu' => [], 'page' => [], 'newsItem' => []];

		$report['types']['portal'] = ['declared' => 1, 'created' => 0, 'kept' => 0, 'arrived' => 0];
		if ($this->portalRow(slug: $slug) !== null) {
			$report['types']['portal']['kept'] = 1;
		} else {
			$id = $this->store->create(schema: 'portal', data: $site['portal']);
			if ($id !== null) {
				$report['types']['portal']['created'] = 1;
				$record['portal'] = $id;
				$created['portal'][] = $slug;
			}
		}

		foreach (self::SCHEMAS as $list => $schema) {
			$counts   = ['declared' => count($site[$list]), 'created' => 0, 'kept' => 0, 'arrived' => 0];
			$existing = $this->keyed(schema: $schema, slug: $slug);
			foreach ($site[$list] as $object) {
				$key = self::keyOf(schema: $schema, object: $object);
				if (isset($existing[$key]) === true) {
					$counts['kept']++;
					continue;
				}

				$id = $this->store->create(schema: $schema, data: ($object + ['portal' => $slug]));
				if ($id !== null) {
					$counts['created']++;
					$record[$schema][] = $id;
					$created[$schema][] = $key;
				}
			}

			$report['types'][$schema] = $counts;
		}

		$this->remember(site: $report['site'], record: $record);

		return $this->proven(site: $site, report: $report, created: $created);
	}//end install()

	/**
	 * Delete what the install recorded, and nothing else.
	 *
	 * @param string $site The site's id.
	 * @param string $slug The slug of its portal.
	 *
	 * @return array<string, mixed> `recorded` (false when there is no record),
	 *                              `deleted` and `gone` per schema, `failed`
	 *                              ids, and `portal`: `deleted`, `kept-content`,
	 *                              `kept-not-ours` or `failed`.
	 *
	 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-remove-an-example-site
	 */
	public function remove(string $site, string $slug): array {
		$record = $this->record(site: $site);
		$report = [
			'site'     => $site,
			'recorded' => ($record['portal'] !== '' || $record['menu'] !== [] || $record['page'] !== [] || $record['newsItem'] !== []),
			'deleted'  => [],
			'gone'     => [],
			'failed'   => [],
			'portal'   => 'kept-not-ours',
		];
		if ($report['recorded'] === false || $this->store->available() === false) {
			return $report;
		}

		$left = ['portal' => $record['portal'], 'menu' => [], 'page' => [], 'newsItem' => []];
		foreach (array_reverse(self::SCHEMAS) as $schema) {
			$present = [];
			foreach ($this->rowsOf(schema: $schema, slug: $slug) as $row) {
				$present[ExampleSiteStore::idOf(row: $row)] = true;
			}

			$report['deleted'][$schema] = 0;
			$report['gone'][$schema]    = 0;
			foreach ($record[$schema] as $id) {
				if (isset($present[$id]) === false) {
					$report['gone'][$schema]++;
					continue;
				}

				if ($this->store->delete(schema: $schema, id: $id) === true) {
					$report['deleted'][$schema]++;
					continue;
				}

				$report['failed'][] = $schema . ' ' . $id;
				$left[$schema][]    = $id;
			}
		}

		$report['portal'] = $this->removePortal(recordedId: $record['portal'], slug: $slug);
		if ($report['portal'] === 'deleted' || $report['portal'] === 'kept-not-ours') {
			$left['portal'] = '';
		}

		$this->remember(site: $site, record: $left);

		return $report;
	}//end remove()

	/**
	 * Every declared path the stored value does not hold as declared.
	 *
	 * An empty declared list or object names nothing that could be lost. A
	 * date is the same date in another time zone's notation.
	 *
	 * @param mixed  $declared What the declaration holds.
	 * @param mixed  $stored   What the instance holds at the same place.
	 * @param string $path     The path so far, dot-separated.
	 *
	 * @return array<int, string> The lost paths.
	 *
	 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-the-install-must-prove-what-arrived
	 */
	public static function lostPaths(mixed $declared, mixed $stored, string $path = ''): array {
		if (is_array($declared) === false) {
			if (self::sameValue(declared: $declared, stored: $stored) === true) {
				return [];
			}

			return [$path];
		}

		$lost = [];
		foreach ($declared as $key => $value) {
			$here = (string)$key;
			if ($path !== '') {
				$here = $path . '.' . $key;
			}

			if (is_array($stored) === false || array_key_exists($key, $stored) === false) {
				if ($value !== []) {
					$lost[] = $here;
				}

				continue;
			}

			$lost = array_merge($lost, self::lostPaths(declared: $value, stored: $stored[$key], path: $here));
		}

		return $lost;
	}//end lostPaths()

	/**
	 * What tells one declared object from another, within its portal.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $object The object.
	 *
	 * @return string A menu's position and title, a page's route, a news item's title.
	 *
	 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-site-with-one-command
	 */
	public static function keyOf(string $schema, array $object): string {
		if ($schema === 'menu') {
			return (int)($object['position'] ?? 0) . ' ' . (string)($object['title'] ?? '');
		}

		if ($schema === 'page') {
			return (string)($object['route'] ?? '');
		}

		return (string)($object['title'] ?? '');
	}//end keyOf()

	/**
	 * Read the instance back and fill in what arrived, what is missing and
	 * which keys the objects of this run lost.
	 *
	 * @param array<string, mixed>              $site    The declaration.
	 * @param array<string, mixed>              $report  The report so far.
	 * @param array<string, array<int, string>> $created The keys this run created, per schema.
	 *
	 * @return array<string, mixed> The finished report.
	 */
	private function proven(array $site, array $report, array $created): array {
		$slug   = $report['portal'];
		$portal = $this->portalRow(slug: $slug);
		if ($portal === null) {
			$report['missing'][] = 'portal ' . $slug;
		} else {
			$report['types']['portal']['arrived'] = 1;
			if ($created['portal'] !== []) {
				foreach (self::lostPaths(declared: $site['portal'], stored: $portal) as $path) {
					$report['lost'][] = 'portal ' . $slug . ': ' . $path;
				}
			}
		}

		foreach (self::SCHEMAS as $list => $schema) {
			$stored = $this->keyed(schema: $schema, slug: $slug);
			foreach ($site[$list] as $object) {
				$key = self::keyOf(schema: $schema, object: $object);
				if (isset($stored[$key]) === false) {
					$report['missing'][] = $schema . ' ' . $key;
					continue;
				}

				$report['types'][$schema]['arrived']++;
				if (in_array($key, $created[$schema], true) === false) {
					continue;
				}

				foreach (self::lostPaths(declared: $object, stored: $stored[$key]) as $path) {
					$report['lost'][] = $schema . ' ' . $key . ': ' . $path;
				}
			}
		}

		$report['ok'] = ($report['missing'] === [] && $report['lost'] === []);

		return $report;
	}//end proven()

	/**
	 * Delete the portal when this install created it and nothing is left in it.
	 *
	 * @param string $recordedId The id the install recorded, or '' when the portal was there before.
	 * @param string $slug       The portal's slug.
	 *
	 * @return string `deleted`, `kept-content`, `kept-not-ours` or `failed`.
	 */
	private function removePortal(string $recordedId, string $slug): string {
		$portal = $this->portalRow(slug: $slug);
		if ($recordedId === '' || $portal === null || ExampleSiteStore::idOf(row: $portal) !== $recordedId) {
			return 'kept-not-ours';
		}

		if ($this->rowsOf(schema: 'menu', slug: $slug) !== [] || $this->rowsOf(schema: 'page', slug: $slug) !== []) {
			return 'kept-content';
		}

		if ($this->store->delete(schema: 'portal', id: $recordedId) === true) {
			return 'deleted';
		}

		return 'failed';
	}//end removePortal()

	/**
	 * The portal row with this slug, or null.
	 *
	 * @param string $slug The portal's slug.
	 *
	 * @return array<string, mixed>|null
	 */
	private function portalRow(string $slug): ?array {
		foreach ($this->store->find(schema: 'portal', filters: []) as $row) {
			if (($row['slug'] ?? null) === $slug) {
				return $row;
			}
		}

		return null;
	}//end portalRow()

	/**
	 * The rows of one schema that belong to the portal.
	 *
	 * The filter is checked again here: a store that ignored it would hand
	 * over another portal's rows, and a remove would then see them as present.
	 *
	 * @param string $schema The schema slug.
	 * @param string $slug   The portal's slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rowsOf(string $schema, string $slug): array {
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
	 */
	private function keyed(string $schema, string $slug): array {
		$keyed = [];
		foreach ($this->rowsOf(schema: $schema, slug: $slug) as $row) {
			$keyed[self::keyOf(schema: $schema, object: $row)] = $row;
		}

		return $keyed;
	}//end keyed()

	/**
	 * Whether the theme app offers the set the portal names.
	 *
	 * @param array<string, mixed> $portal The declared portal.
	 *
	 * @return bool True also for a portal that names no set.
	 */
	private function themeOffered(array $portal): bool {
		$theme = (string)($portal['theme'] ?? '');

		return $theme === '' || $this->themes->stylesheetFor(theme: $theme) !== null;
	}//end themeOffered()

	/**
	 * Whether a stored scalar is the declared one.
	 *
	 * @param mixed $declared The declared value.
	 * @param mixed $stored   The stored value.
	 *
	 * @return bool
	 */
	private static function sameValue(mixed $declared, mixed $stored): bool {
		if ($declared === $stored) {
			return true;
		}

		if (is_numeric($declared) === true && is_numeric($stored) === true) {
			return (float)$declared === (float)$stored;
		}

		if (is_string($declared) === true && is_string($stored) === true
			&& preg_match('/^\d{4}-\d{2}-\d{2}T/', $declared) === 1
		) {
			$left  = strtotime($declared);
			$right = strtotime($stored);

			return $left !== false && $left === $right;
		}

		return false;
	}//end sameValue()

	/**
	 * What an earlier install of this site created.
	 *
	 * @param string $site The site's id.
	 *
	 * @return array{portal: string, menu: array<int, string>, page: array<int, string>, newsItem: array<int, string>}
	 */
	private function record(string $site): array {
		$record  = ['portal' => '', 'menu' => [], 'page' => [], 'newsItem' => []];
		$decoded = json_decode($this->appConfig->getValueString(Application::APP_ID, self::RECORD_PREFIX . $site, ''), true);
		if (is_array($decoded) === false) {
			return $record;
		}

		if (is_string($decoded['portal'] ?? null) === true) {
			$record['portal'] = $decoded['portal'];
		}

		foreach (self::SCHEMAS as $schema) {
			foreach ((array)($decoded[$schema] ?? []) as $id) {
				if (is_string($id) === true && $id !== '') {
					$record[$schema][] = $id;
				}
			}
		}

		return $record;
	}//end record()

	/**
	 * Keep the record, or drop the key when nothing is left in it.
	 *
	 * @param string               $site   The site's id.
	 * @param array<string, mixed> $record What the install created and still exists.
	 *
	 * @return void
	 */
	private function remember(string $site, array $record): void {
		if ($record['portal'] === '' && $record['menu'] === [] && $record['page'] === [] && $record['newsItem'] === []) {
			$this->appConfig->deleteKey(Application::APP_ID, self::RECORD_PREFIX . $site);
			return;
		}

		$this->appConfig->setValueString(Application::APP_ID, self::RECORD_PREFIX . $site, (string)json_encode($record));
	}//end remember()
}//end class
