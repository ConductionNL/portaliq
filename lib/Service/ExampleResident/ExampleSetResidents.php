<?php

/**
 * Portaliq Example Set Residents
 *
 * The example residents of portaliq's example-set fragments, as installed by
 * OpenRegister's example-set loader. A fragment in
 * `lib/Settings/example-sets/<set>.json` names its resident in
 * `exampleResident`: the portal, the key of the portal account object and the
 * key of the change that added the sign-in mode. Who the resident is comes
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
	 * The installed example resident of a portal, or null.
	 *
	 * @param string $portal The portal's slug.
	 *
	 * @return array{set: string, uid: string, mode: string, modeAdded: bool}|null The set id, the Nextcloud account id,
	 *         the sign-in mode, and whether the set added that mode to the portal.
	 *
	 * @spec openspec/changes/resident-on-example-set/specs/example-resident/spec.md#requirement-the-example-resident-comes-from-the-example-set
	 */
	public function forPortal(string $portal): ?array {
		$records = $this->recordService();
		if ($records === null || $portal === '') {
			return null;
		}

		foreach ($this->declared() as $set => $resident) {
			if ($resident['portal'] !== $portal) {
				continue;
			}

			$uid = $this->installedUid(record: $this->read(records: $records, set: $set), resident: $resident);
			if ($uid !== '') {
				return [
					'set'       => $set,
					'uid'       => $uid,
					'mode'      => $resident['mode'],
					'modeAdded' => isset($this->read(records: $records, set: $set)['changes'][self::APP.'/'.$resident['signIn']]),
				];
			}
		}

		return null;
	}//end forPortal()

	/**
	 * The `exampleResident` of every shipped fragment, by set id.
	 *
	 * @return array<string, array{portal: string, account: string, signIn: string, mode: string}>
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
			$resident = (array)($fragment['exampleResident'] ?? []);
			if (is_string($resident['portal'] ?? null) === false || is_string($resident['account'] ?? null) === false) {
				continue;
			}

			$declared[basename($file, '.json')] = [
				'portal'  => $resident['portal'],
				'account' => $resident['account'],
				'signIn'  => (string)($resident['signIn'] ?? ''),
				'mode'    => (string)($resident['mode'] ?? ''),
			];
		}

		return $declared;
	}//end declared()

	/**
	 * The account id of the recorded portal account, when the set made or adopted that account; '' otherwise.
	 *
	 * @param array<string, mixed>  $record   The set's install record.
	 * @param array<string, string> $resident The fragment's `exampleResident`.
	 *
	 * @return string
	 */
	private function installedUid(array $record, array $resident): string {
		$held = ($record['objects'][self::APP.'/'.$resident['account']] ?? null);
		if (is_array($held) === false || ($held['schema'] ?? '') !== 'portalAccount') {
			return '';
		}

		$account = $this->store->get(register: (string)$held['register'], schema: 'portalAccount', id: (string)$held['id']);
		$uid     = (string)($account['subjectRef'] ?? '');
		if ($uid === '' || isset($record['users'][$uid]) === false) {
			return '';
		}

		return $uid;
	}//end installedUid()

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
