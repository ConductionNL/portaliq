<?php

/**
 * Portaliq Example Site Record
 *
 * What an install of an example site created, kept in the app config so a
 * remove deletes exactly that and nothing else.
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

use OCA\Portaliq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * The ids an install created, per example site.
 *
 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-remove-an-example-site
 */
class ExampleSiteRecord {
	/**
	 * The schemas a record lists ids for, besides the portal.
	 */
	public const SCHEMAS = ['menu', 'page', 'newsItem'];

	/**
	 * The prefix of the app-config key that holds a site's record.
	 */
	private const PREFIX = 'example_site_';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Holds the records.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * What an earlier install of this site created.
	 *
	 * @param string $site The site's id.
	 *
	 * @return array{portal: string, menu: array<int, string>, page: array<int, string>, newsItem: array<int, string>}
	 *
	 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-remove-an-example-site
	 */
	public function read(string $site): array {
		$record  = ['portal' => '', 'menu' => [], 'page' => [], 'newsItem' => []];
		$decoded = json_decode($this->appConfig->getValueString(Application::APP_ID, self::PREFIX . $site, ''), true);
		if (is_array($decoded) === false) {
			return $record;
		}

		if (is_string($decoded['portal'] ?? null) === true) {
			$record['portal'] = $decoded['portal'];
		}

		foreach (self::SCHEMAS as $schema) {
			$record[$schema] = array_values(
				array_filter((array)($decoded[$schema] ?? []), static fn ($id): bool => is_string($id) === true && $id !== '')
			);
		}

		return $record;
	}//end read()

	/**
	 * Whether a record names nothing.
	 *
	 * @param array<string, mixed> $record The record.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-remove-an-example-site
	 */
	public function isEmpty(array $record): bool {
		return $record['portal'] === '' && $record['menu'] === [] && $record['page'] === [] && $record['newsItem'] === [];
	}//end isEmpty()

	/**
	 * Keep the record, or drop the key when nothing is left in it.
	 *
	 * @param string               $site   The site's id.
	 * @param array<string, mixed> $record What the install created and still exists.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-remove-an-example-site
	 */
	public function write(string $site, array $record): void {
		if ($this->isEmpty(record: $record) === true) {
			$this->appConfig->deleteKey(Application::APP_ID, self::PREFIX . $site);
			return;
		}

		$this->appConfig->setValueString(Application::APP_ID, self::PREFIX . $site, (string)json_encode($record));
	}//end write()
}//end class
