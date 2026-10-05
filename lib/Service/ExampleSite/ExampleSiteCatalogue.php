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
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ?string $directory = null,
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
		if (preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $id) !== 1) {
			return null;
		}

		$file = $this->folder() . '/' . $id . '.json';
		if (is_file($file) === false) {
			return null;
		}

		$site = json_decode((string)file_get_contents($file), true);
		if (is_array($site) === false || ($site['id'] ?? null) !== $id) {
			return null;
		}

		$portal = ($site['portal'] ?? null);
		if (is_array($portal) === false
			|| is_string($portal['slug'] ?? null) === false || $portal['slug'] === ''
			|| is_string($portal['title'] ?? null) === false || $portal['title'] === ''
		) {
			return null;
		}

		foreach (self::LISTS as $list) {
			$site[$list] = ($site[$list] ?? []);
			if (is_array($site[$list]) === false || array_is_list($site[$list]) === false) {
				return null;
			}
		}

		return $site;
	}//end find()

	/**
	 * The folder the declarations are read from.
	 *
	 * @return string
	 */
	private function folder(): string {
		return ($this->directory ?? dirname(__DIR__, 2) . '/Settings/sites');
	}//end folder()
}//end class
