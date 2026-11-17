<?php

/**
 * Portaliq Example Resident Record
 *
 * What an install of an example resident created, kept in app config under
 * `example_resident_<id>`. The remove command deletes from this record and
 * from nothing else, so it can never take a real resident's case.
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
 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-remove-an-example-resident
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\ExampleResident;

use OCA\Portaliq\Kernel\AppIdentity;
use OCP\IAppConfig;

/**
 * The record of one example resident's install.
 *
 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-remove-an-example-resident
 */
class ExampleResidentRecord {
	/**
	 * The prefix of the app-config key that holds a record.
	 */
	private const PREFIX = 'example_resident_';

	/**
	 * A record that names nothing.
	 */
	private const BLANK = [
		'userId'      => '',
		'userCreated' => false,
		'account'     => '',
		'signIn'      => ['mode' => '', 'label' => false],
		'objects'     => [],
	];

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
	 * What an earlier install created.
	 *
	 * @param string $id The declaration's id.
	 *
	 * @return array{userId: string, userCreated: bool, account: string, signIn: array{mode: string, label: bool},
	 *               objects: array<string, array{register: string, schema: string, id: string, subject: string}>}
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-remove-an-example-resident
	 */
	public function read(string $id): array {
		$record  = self::BLANK;
		$decoded = json_decode($this->appConfig->getValueString(AppIdentity::APP_ID, self::PREFIX . $id, ''), true);
		if (is_array($decoded) === false) {
			return $record;
		}

		$record = $this->withAccount(record: $record, decoded: $decoded);
		$record['objects'] = $this->objectsOf(objects: $record['objects'], decoded: $decoded);

		return $record;
	}//end read()

	/**
	 * The record with the user, the account and the sign-in it was given.
	 *
	 * @param array<string, mixed> $record  The blank record.
	 * @param array<mixed>         $decoded The stored record.
	 *
	 * @return array<string, mixed>
	 */
	private function withAccount(array $record, array $decoded): array {
		$record['userId']      = (string)($decoded['userId'] ?? '');
		$record['userCreated'] = (($decoded['userCreated'] ?? false) === true);
		$record['account']     = (string)($decoded['account'] ?? '');
		$record['signIn']      = [
			'mode'  => (string)($decoded['signIn']['mode'] ?? ''),
			'label' => (($decoded['signIn']['label'] ?? false) === true),
		];

		return $record;
	}//end withAccount()

	/**
	 * The objects with every well-formed stored object added, by key.
	 *
	 * @param array<string, array<string, string>> $objects The objects so far.
	 * @param array<mixed>                         $decoded The stored record.
	 *
	 * @return array<string, array{register: string, schema: string, id: string, subject: string}>
	 */
	private function objectsOf(array $objects, array $decoded): array {
		foreach ((array)($decoded['objects'] ?? []) as $key => $object) {
			if (is_array($object) === false || is_string($object['id'] ?? null) === false || $object['id'] === '') {
				continue;
			}

			$objects[(string)$key] = [
				'register' => (string)($object['register'] ?? ''),
				'schema'   => (string)($object['schema'] ?? ''),
				'id'       => $object['id'],
				'subject'  => (string)($object['subject'] ?? ''),
			];
		}

		return $objects;
	}//end objectsOf()

	/**
	 * Whether a record names nothing.
	 *
	 * @param array<string, mixed> $record The record.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-remove-an-example-resident
	 */
	public function isEmpty(array $record): bool {
		return $record['userId'] === '' && $record['account'] === '' && $record['signIn']['mode'] === '' && $record['objects'] === [];
	}//end isEmpty()

	/**
	 * Keep the record, or drop the key when nothing is left in it.
	 *
	 * @param string               $id     The declaration's id.
	 * @param array<string, mixed> $record What the install created and still exists.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-remove-an-example-resident
	 */
	public function write(string $id, array $record): void {
		if ($this->isEmpty(record: $record) === true) {
			$this->appConfig->deleteKey(AppIdentity::APP_ID, self::PREFIX . $id);
			return;
		}

		$this->appConfig->setValueString(AppIdentity::APP_ID, self::PREFIX . $id, (string)json_encode($record));
	}//end write()
}//end class
