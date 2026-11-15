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
		foreach ($this->declaredFor(portal: $portal) as $set => $residents) {
			foreach ($this->installed(record: $this->read(records: $records, set: $set), residents: $residents) as $row) {
				$found[] = ['set' => $set] + $row;
			}
		}

		return $found;
	}//end residentsOf()

	/**
	 * The declared residents of one portal, by set id; a set without one is left out.
	 *
	 * @param string $portal The portal's slug.
	 *
	 * @return array<string, list<array{portal: string, key: string, account: string, card: array<string, string>}>>
	 */
	private function declaredFor(string $portal): array {
		$mine = [];
		foreach ($this->declared() as $set => $residents) {
			$ofPortal = array_values(array_filter($residents, static fn (array $resident): bool => $resident['portal'] === $portal));
			if ($ofPortal !== []) {
				$mine[$set] = $ofPortal;
			}
		}

		return $mine;
	}//end declaredFor()

	/**
	 * The residents whose portal account the set installed, with that account's id and name.
	 *
	 * @param array<string, mixed>                                                                  $record    The set's install record.
	 * @param list<array{portal: string, key: string, account: string, card: array<string, string>}> $residents The set's residents of one portal.
	 *
	 * @return list<array{key: string, uid: string, name: string, card: array<string, string>}>
	 */
	private function installed(array $record, array $residents): array {
		$rows = [];
		foreach ($residents as $resident) {
			$account = $this->installedAccount(record: $record, resident: $resident);
			if ($account !== null) {
				$rows[] = ['key' => $resident['key'], 'uid' => $account['uid'], 'name' => $account['name'], 'card' => $resident['card']];
			}
		}

		return $rows;
	}//end installed()

	/**
	 * The `exampleResident` entries of every shipped fragment, by set id.
	 *
	 * @return array<string, list<array{portal: string, key: string, account: string, card: array<string, string>}>>
	 */
	private function declared(): array {
		$declared = [];
		$files    = glob($this->fragmentFolder().'/*.json');
		if ($files === false) {
			return [];
		}

		foreach ($files as $file) {
			$entries = $this->entriesOf(file: $file);
			if ($entries !== null) {
				$declared[basename($file, '.json')] = array_values(array_filter(array_map(fn (mixed $entry) => $this->entry(entry: $entry), $entries)));
			}
		}

		return $declared;
	}//end declared()

	/**
	 * Where the fragments are: the configured folder, or the shipped one.
	 *
	 * @return string
	 */
	private function fragmentFolder(): string {
		if ($this->folder === '') {
			return dirname(__DIR__, 2).'/Settings/example-sets';
		}

		return $this->folder;
	}//end fragmentFolder()

	/**
	 * A fragment's `exampleResident` entries as a list, or null when it declares none.
	 * One resident may be written as an object, several as a list.
	 *
	 * @param string $file The fragment file.
	 *
	 * @return array<mixed>|null
	 */
	private function entriesOf(string $file): ?array {
		$fragment = json_decode((string)file_get_contents($file), true);
		$entries  = ($fragment['exampleResident'] ?? []);
		if (is_array($entries) === false) {
			return null;
		}

		if (array_is_list($entries) === false) {
			return [$entries];
		}

		return $entries;
	}//end entriesOf()

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

		return ['portal' => $entry['portal'], 'key' => $this->keyOf(entry: $entry), 'account' => $entry['account'], 'card' => $this->cardOf(entry: $entry)];
	}//end entry()

	/**
	 * The demo card's title, text and button, each when it is non-blank text.
	 *
	 * @param array<string, mixed> $entry One entry of `exampleResident`.
	 *
	 * @return array<string, string>
	 */
	private function cardOf(array $entry): array {
		$card = [];
		foreach (['title', 'text', 'button'] as $field) {
			if (is_string($entry['card'][$field] ?? null) === true && trim($entry['card'][$field]) !== '') {
				$card[$field] = trim($entry['card'][$field]);
			}
		}

		return $card;
	}//end cardOf()

	/**
	 * A resident's key: its cast key, else its portal account.
	 *
	 * @param array{account: string} $entry One entry of `exampleResident`.
	 *
	 * @return string
	 */
	private function keyOf(array $entry): string {
		if (is_string($entry['cast'] ?? null) === true && $entry['cast'] !== '') {
			return $entry['cast'];
		}

		return $entry['account'];
	}//end keyOf()

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
		$held = $this->heldAccount(record: $record, resident: $resident);
		if ($held === []) {
			return null;
		}

		return $this->setAccount(
			record: $record,
			account: $this->store->get(register: (string)$held['register'], schema: 'portalAccount', id: (string)$held['id'])
		);
	}//end installedAccount()

	/**
	 * Where the set recorded the resident's portal account, or [] when it recorded none.
	 *
	 * @param array<string, mixed>   $record   The set's install record.
	 * @param array{account: string} $resident One declared resident.
	 *
	 * @return array<string, mixed>
	 */
	private function heldAccount(array $record, array $resident): array {
		$held = ($record['objects'][self::APP.'/'.$resident['account']] ?? null);
		if (is_array($held) === false || ($held['schema'] ?? '') !== 'portalAccount') {
			return [];
		}

		return $held;
	}//end heldAccount()

	/**
	 * The account id and name of a portal account, when the set created or adopted that account id.
	 *
	 * @param array<string, mixed>      $record  The set's install record.
	 * @param array<string, mixed>|null $account The portal account, or null.
	 *
	 * @return array{uid: string, name: string}|null
	 */
	private function setAccount(array $record, ?array $account): ?array {
		$uid = (string)($account['subjectRef'] ?? '');
		if ($uid === '' || isset($record['users'][$uid]) === false) {
			return null;
		}

		return ['uid' => $uid, 'name' => $this->displayName(account: $account, uid: $uid)];
	}//end setAccount()

	/**
	 * The name on a portal account; its account id when it has none.
	 *
	 * @param array<string, mixed>|null $account The portal account.
	 * @param string                    $uid     Its account id.
	 *
	 * @return string
	 */
	private function displayName(?array $account, string $uid): string {
		$name = trim((string)($account['displayName'] ?? ''));
		if ($name === '') {
			return $uid;
		}

		return $name;
	}//end displayName()

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
