<?php

/**
 * Portaliq Example Set Residents
 *
 * The example residents of portaliq's example-set fragments, as installed by
 * OpenRegister's example-set loader. A fragment in
 * `lib/Settings/example-sets/<set>.json` names its resident in
 * `exampleResident`: per resident the portal, its cast key, the key of its
 * portal account object and the demo card. Who the resident is comes
 * from OpenRegister's install record of the set, which only `occ` writes: the
 * portal account must be an object the set recorded, and its account id must
 * be an account the set created or adopted. A portal account somebody else
 * wrote never makes anyone the example resident.
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
 * @spec openspec/changes/resident-on-example-set/specs/example-resident/spec.md#requirement-the-example-resident-comes-from-the-example-set
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\ExampleResident;

use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Finds the installed example resident of a portal.
 *
 * @spec openspec/changes/resident-on-example-set/specs/example-resident/spec.md#requirement-the-example-resident-comes-from-the-example-set
 */
class ExampleSetResidents {
	/**
	 * OpenRegister's example-set install record, fetched by string id:
	 * OpenRegister is an optional sibling app, so its classes are never imported.
	 */
	private const RECORD = 'OCA\\OpenRegister\\Service\\ExampleSet\\ExampleSetRecord';

	/**
	 * The app whose objects the record keys as `portaliq/<key>`.
	 */
	private const APP = 'portaliq';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface   $container Hands out OpenRegister's record.
	 * @param ExampleResidentStore $store     Reads the portal account back.
	 * @param string               $folder    Where portaliq keeps its fragments; '' for the shipped folder.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly ExampleResidentStore $store,
		private readonly string $folder='',
	) {
	}//end __construct()

	/**
	 * Whether OpenRegister's example-set record can be read at all.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/resident-on-example-set/specs/example-resident/spec.md#requirement-the-example-resident-comes-from-the-example-set
	 */
	public function available(): bool {
		return $this->recordService() !== null;
	}//end available()

	/**
	 * The installed example residents of a portal, in the order the fragment
	 * declares them. A fragment may declare one resident (an object) or
	 * several (a list); each must pass the same test: its portal account is an
	 * object the set recorded, and that account's id is one the set created or
	 * adopted. One that fails is left out, the others stand.
	 *
	 * @param string $portal The portal's slug.
	 *
	 * @return list<array{set: string, key: string, uid: string, name: string, card: array<string, string>}>
	 *         The set id, the resident's key (its cast key), the Nextcloud account id, the name on the
	 *         portal account, and the demo card the fragment wrote.
	 *
	 * @spec openspec/changes/demo-sign-in-beside-the-account/specs/example-resident/spec.md#requirement-a-demo-may-sign-in-each-example-resident-with-one-click
	 */
	public function residentsOf(string $portal): array {
		$records = $this->recordService();
		if ($records === null || $portal === '') {
			return [];
		}

		$found = [];
		foreach ($this->declared() as $set => $residents) {
			$record = null;
			foreach ($residents as $resident) {
				if ($resident['portal'] !== $portal) {
					continue;
				}

				$record ??= $this->read(records: $records, set: $set);
				$account  = $this->installedAccount(record: $record, resident: $resident);
				if ($account === null) {
					continue;
				}

				$found[] = [
					'set'  => $set,
					'key'  => $resident['key'],
					'uid'  => $account['uid'],
					'name' => $account['name'],
					'card' => $resident['card'],
				];
			}
		}

		return $found;
	}//end residentsOf()

	/**
	 * The `exampleResident` entries of every shipped fragment, by set id.
	 *
	 * @return array<string, list<array{portal: string, key: string, account: string, card: array<string, string>}>>
	 */
	private function declared(): array {
		$folder = $this->folder;
		if ($folder === '') {
			$folder = dirname(__DIR__, 2).'/Settings/example-sets';
		}

		$declared = [];
		$files    = glob($folder.'/*.json');
		if ($files === false) {
			return [];
		}

		foreach ($files as $file) {
			$fragment = json_decode((string)file_get_contents($file), true);
			$entries  = ($fragment['exampleResident'] ?? []);
			if (is_array($entries) === false) {
				continue;
			}

			// One resident may be written as an object, several as a list.
			if (array_is_list($entries) === false) {
				$entries = [$entries];
			}

			$residents = [];
			foreach ($entries as $entry) {
				$resident = $this->entry(entry: $entry);
				if ($resident !== null) {
					$residents[] = $resident;
				}
			}

			$declared[basename($file, '.json')] = $residents;
		}

		return $declared;
	}//end declared()

	/**
	 * One declared resident, or null when it names no portal or no portal account.
	 *
	 * @param mixed $entry One entry of `exampleResident`.
	 *
	 * @return array{portal: string, key: string, account: string, card: array<string, string>}|null
	 */
	private function entry(mixed $entry): ?array {
		if (is_array($entry) === false || is_string($entry['portal'] ?? null) === false || is_string($entry['account'] ?? null) === false) {
			return null;
		}

		$card = [];
		foreach (['title', 'text', 'button'] as $field) {
			if (is_string($entry['card'][$field] ?? null) === true && trim($entry['card'][$field]) !== '') {
				$card[$field] = trim($entry['card'][$field]);
			}
		}

		$key = $entry['account'];
		if (is_string($entry['cast'] ?? null) === true && $entry['cast'] !== '') {
			$key = $entry['cast'];
		}

		return ['portal' => $entry['portal'], 'key' => $key, 'account' => $entry['account'], 'card' => $card];
	}//end entry()

	/**
	 * The account id and name of the recorded portal account, when the set
	 * made or adopted that account id; null otherwise.
	 *
	 * @param array<string, mixed>                 $record   The set's install record.
	 * @param array{account: string, key: string} $resident One declared resident.
	 *
	 * @return array{uid: string, name: string}|null
	 */
	private function installedAccount(array $record, array $resident): ?array {
		$held = ($record['objects'][self::APP.'/'.$resident['account']] ?? null);
		if (is_array($held) === false || ($held['schema'] ?? '') !== 'portalAccount') {
			return null;
		}

		$account = $this->store->get(register: (string)$held['register'], schema: 'portalAccount', id: (string)$held['id']);
		$uid     = (string)($account['subjectRef'] ?? '');
		if ($uid === '' || isset($record['users'][$uid]) === false) {
			return null;
		}

		$name = trim((string)($account['displayName'] ?? ''));
		if ($name === '') {
			$name = $uid;
		}

		return ['uid' => $uid, 'name' => $name];
	}//end installedAccount()

	/**
	 * One set's install record, or an empty one.
	 *
	 * @param object $records OpenRegister's ExampleSetRecord.
	 * @param string $set     The set id.
	 *
	 * @return array<string, mixed>
	 */
	private function read(object $records, string $set): array {
		try {
			return (array)$records->read(set: $set);
		} catch (Throwable) {
			return [];
		}
	}//end read()

	/**
	 * OpenRegister's ExampleSetRecord, or null when OpenRegister does not have it.
	 *
	 * @return object|null
	 */
	private function recordService(): ?object {
		try {
			$service = $this->container->get(self::RECORD);
		} catch (Throwable) {
			return null;
		}

		if (is_object($service) === true && method_exists($service, 'read') === true) {
			return $service;
		}

		return null;
	}//end recordService()
}//end class
