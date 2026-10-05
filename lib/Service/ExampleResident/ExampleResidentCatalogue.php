<?php

/**
 * Portaliq Example Resident Catalogue
 *
 * The example residents this app ships: one JSON declaration per example
 * site under `lib/Settings/sites/residents/`. A declaration names the portal
 * it belongs to, the resident, the way in, and the objects that fill the
 * resident's own area.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\ExampleResident
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
 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-resident-with-one-command
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\ExampleResident;

/**
 * The shipped example residents.
 *
 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-resident-with-one-command
 */
class ExampleResidentCatalogue {
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
	 * The ids of the residents that can be installed, in alphabetical order.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-resident-with-one-command
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
	 * Usable means: it parses, names itself by its file name, names a portal,
	 * a resident with a user id and a display name, a way in, and a list of
	 * objects that each carry a key, a register, a schema and data. An id is
	 * at most 36 characters, because the record lives under an app-config key
	 * that starts with `example_resident_` and such a key holds 64.
	 *
	 * @param string $id The declaration's id, as its file is named.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-resident-with-one-command
	 */
	public function find(string $id): ?array {
		$file = $this->folder() . '/' . $id . '.json';
		if (preg_match('/^[a-z0-9][a-z0-9-]{0,35}$/', $id) !== 1 || is_file($file) === false) {
			return null;
		}

		$resident = json_decode((string)file_get_contents($file), true);
		if (is_array($resident) === false || ($resident['id'] ?? null) !== $id) {
			return null;
		}

		if ($this->namesAResident(resident: $resident) === false || $this->listsObjects(resident: $resident) === false) {
			return null;
		}

		return $resident;
	}//end find()

	/**
	 * Whether the declaration names its portal, its resident and the way in.
	 *
	 * @param array<string, mixed> $resident The parsed declaration.
	 *
	 * @return bool
	 */
	private function namesAResident(array $resident): bool {
		$texts = [
			($resident['portal'] ?? null),
			($resident['resident']['userId'] ?? null),
			($resident['resident']['displayName'] ?? null),
			($resident['resident']['audience'] ?? null),
			($resident['resident']['organisation'] ?? null),
			($resident['signIn']['mode'] ?? null),
		];
		foreach ($texts as $text) {
			if (is_string($text) === false || $text === '') {
				return false;
			}
		}

		return true;
	}//end namesAResident()

	/**
	 * Whether `objects` is a list of objects with a key of their own, a register, a schema and data.
	 *
	 * @param array<string, mixed> $resident The parsed declaration.
	 *
	 * @return bool
	 */
	private function listsObjects(array $resident): bool {
		$objects = ($resident['objects'] ?? null);
		if (is_array($objects) === false || array_is_list($objects) === false) {
			return false;
		}

		$keys = [];
		foreach ($objects as $object) {
			$key = ($object['key'] ?? null);
			if (is_string($key) === false || $key === '' || isset($keys[$key]) === true) {
				return false;
			}

			$keys[$key] = true;
			if (is_string($object['register'] ?? null) === false || is_string($object['schema'] ?? null) === false) {
				return false;
			}

			if (is_array($object['data'] ?? null) === false || $object['data'] === []) {
				return false;
			}
		}

		return true;
	}//end listsObjects()

	/**
	 * The folder the declarations are read from.
	 *
	 * @return string
	 */
	private function folder(): string {
		return ($this->directory ?? dirname(__DIR__, 2) . '/Settings/sites/residents');
	}//end folder()
}//end class
