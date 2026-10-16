<?php

/**
 * Portaliq Example Site Catalogue
 *
 * Reads the example sites this app ships, one declaration per file under
 * `lib/Settings/sites/`. A declaration holds a portal record, its menus, its
 * pages and its news items, as an administrator installs them with
 * `occ portaliq:example-site:install`.
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

use OCP\App\IAppManager;

/**
 * The shipped example sites.
 *
 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-site-with-one-command
 */
class ExampleSiteCatalogue {
	/**
	 * The object lists a declaration holds besides its portal, by key.
	 */
	public const LISTS = ['menus', 'pages', 'news'];

	/**
	 * Constructor.
	 *
	 * @param string|null $directory Where the declarations live; the shipped folder when null.
	 * @param IAppManager|null $apps Tells which apps are installed, for a page that needs one; none means none is.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ?string $directory = null,
		private readonly ?IAppManager $apps = null,
	) {
	}//end __construct()

	/**
	 * The ids of the sites that can be installed, in alphabetical order.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-site-with-one-command
	 */
	public function ids(): array {
		$ids = [];
		foreach ((array)glob($this->folder() . '/*.json') as $file) {
			$id = basename((string)$file, '.json');
			if ($this->find(id: $id) !== null) {
				$ids[] = $id;
			}
		}

		sort($ids);

		return $ids;
	}//end ids()

	/**
	 * One declaration, or null when no usable one carries that id.
	 *
	 * A declaration is usable when it parses, names itself by its file name,
	 * holds a portal with a slug and a title, and each of its lists is a list.
	 * An id is at most 40 characters: the install keeps its record under an
	 * app-config key that starts with `example_site_`, and such a key holds 64.
	 *
	 * @param string $id The site's id, as its file is named.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-site-with-one-command
	 */
	public function find(string $id): ?array {
		$site = $this->decoded(id: $id);
		if ($site === null || $this->namesAPortal(site: $site) === false) {
			return null;
		}

		foreach (self::LISTS as $list) {
			$site[$list] = ($site[$list] ?? []);
			if (is_array($site[$list]) === false || array_is_list($site[$list]) === false) {
				return null;
			}
		}

		$site['pages'] = $this->pagesWithTheirApps(value: $site['pages']);

		return $site;
	}//end find()

	/**
	 * Keep an object that needs an app only when that app is installed, and
	 * strip the `requiresApp` key so it is never written to the store. It holds
	 * for a page and for a link inside a page, at any depth.
	 *
	 * @param array<int|string, mixed> $value The declared pages, or anything inside them.
	 *
	 * @return array<int|string, mixed> What to install.
	 *
	 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t7
	 */
	private function pagesWithTheirApps(array $value): array {
		$kept = [];
		foreach ($value as $key => $item) {
			if (is_array($item) === true) {
				$app = ($item['requiresApp'] ?? null);
				unset($item['requiresApp']);
				if (is_string($app) === true && ($this->apps === null || $this->apps->isInstalled($app) === false)) {
					continue;
				}

				$item = $this->pagesWithTheirApps(value: $item);
			}

			$kept[$key] = $item;
		}

		if (array_is_list($value) === true) {
			return array_values($kept);
		}

		return $kept;
	}//end pagesWithTheirApps()

	/**
	 * The file with this id, parsed, when it names itself by that id.
	 *
	 * @param string $id The site's id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function decoded(string $id): ?array {
		$file = $this->folder() . '/' . $id . '.json';
		if (preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $id) !== 1 || is_file($file) === false) {
			return null;
		}

		$site = json_decode((string)file_get_contents($file), true);
		if (is_array($site) === false || ($site['id'] ?? null) !== $id) {
			return null;
		}

		return $site;
	}//end decoded()

	/**
	 * Whether the declaration holds a portal with a slug and a title.
	 *
	 * @param array<string, mixed> $site The parsed declaration.
	 *
	 * @return bool
	 */
	private function namesAPortal(array $site): bool {
		$portal = ($site['portal'] ?? null);
		if (is_array($portal) === false) {
			return false;
		}

		foreach (['slug', 'title'] as $key) {
			if (is_string($portal[$key] ?? null) === false || $portal[$key] === '') {
				return false;
			}
		}

		return true;
	}//end namesAPortal()

	/**
	 * The folder the declarations are read from.
	 *
	 * @return string
	 */
	private function folder(): string {
		return ($this->directory ?? dirname(__DIR__, 2) . '/Settings/sites');
	}//end folder()
}//end class
