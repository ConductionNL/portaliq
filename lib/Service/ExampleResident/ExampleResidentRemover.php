<?php

/**
 * Portaliq Example Resident Remover
 *
 * Takes an example resident off an instance again: the objects, the portal
 * account, the sign-in mode and the Nextcloud account, each only when the
 * install's own record says the install made it.
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

/**
 * Removes an example resident from its install's own record.
 *
 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-remove-an-example-resident
 */
class ExampleResidentRemover {
	/**
	 * Constructor.
	 *
	 * @param ExampleResidentStore  $store  Deletes rows.
	 * @param ExampleResidentUser   $user   The Nextcloud account.
	 * @param ExampleResidentWayIn  $wayIn  The portal account and the sign-in mode.
	 * @param ExampleResidentRecord $record Knows what the install created.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExampleResidentStore $store,
		private readonly ExampleResidentUser $user,
		private readonly ExampleResidentWayIn $wayIn,
		private readonly ExampleResidentRecord $record,
	) {
	}//end __construct()

	/**
	 * Delete what the install recorded, and nothing else.
	 *
	 * The objects go last-written first, so a message goes before the case it
	 * is about.
	 *
	 * @param string $id   The declaration's id.
	 * @param string $slug The slug of its portal.
	 *
	 * @return array<string, mixed> `recorded` (false when there is no record), `available`, `userId`, `types`
	 *                              (per register and schema: `deleted` and `gone`), `failed`, and `account`,
	 *                              `signIn` and `user`: `deleted`/`withdrawn`, `kept-not-ours` or `failed`.
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-remove-an-example-resident
	 */
	public function remove(string $id, string $slug): array {
		$record = $this->record->read(id: $id);
		$report = [
			'id'        => $id,
			'recorded'  => ($this->record->isEmpty(record: $record) === false),
			'available' => $this->store->available(),
			'userId'    => $record['userId'],
			'types'     => [],
			'failed'    => [],
			'account'   => 'kept-not-ours',
			'signIn'    => 'kept-not-ours',
			'user'      => 'kept-not-ours',
		];
		if ($report['recorded'] === false || $report['available'] === false) {
			return $report;
		}

		foreach (array_reverse($record['objects'], true) as $key => $object) {
			$type = $object['register'] . ' ' . $object['schema'];
			$report['types'][$type] ??= ['deleted' => 0, 'gone' => 0];
			if ($this->store->get(register: $object['register'], schema: $object['schema'], id: $object['id']) === null) {
				$report['types'][$type]['gone']++;
				unset($record['objects'][$key]);
				continue;
			}

			if ($this->store->delete(register: $object['register'], schema: $object['schema'], id: $object['id']) === true) {
				$report['types'][$type]['deleted']++;
				unset($record['objects'][$key]);
				continue;
			}

			$report['failed'][] = $type . ' ' . $key;
		}

		$report['account'] = $this->removeAccount(record: $record);
		$report['signIn']  = $this->removeSignIn(record: $record, slug: $slug);
		$report['user']    = $this->removeUser(record: $record);
		foreach (['account', 'signIn', 'user'] as $part) {
			if ($report[$part] === 'failed') {
				$report['failed'][] = $part;
			}
		}

		$this->record->write(id: $id, record: $record);

		return $report;
	}//end remove()

	/**
	 * Delete the portal account the install made.
	 *
	 * @param array<string, mixed> $record The record; its entry is cleared when the account is gone.
	 *
	 * @return string `deleted`, `kept-not-ours` or `failed`.
	 */
	private function removeAccount(array &$record): string {
		if ($record['account'] === '') {
			return 'kept-not-ours';
		}

		if ($this->wayIn->account(id: $record['account']) !== null && $this->wayIn->deleteAccount(id: $record['account']) === false) {
			return 'failed';
		}

		$record['account'] = '';

		return 'deleted';
	}//end removeAccount()

	/**
	 * Take the sign-in mode off the portal when the install added it.
	 *
	 * @param array<string, mixed> $record The record; its entry is cleared when the mode is gone.
	 * @param string               $slug   The portal's slug.
	 *
	 * @return string `withdrawn`, `kept-not-ours` or `failed`.
	 */
	private function removeSignIn(array &$record, string $slug): string {
		$mode = (string)$record['signIn']['mode'];
		if ($mode === '') {
			return 'kept-not-ours';
		}

		$portal = $this->wayIn->portal(slug: $slug);
		if ($portal !== null && $this->wayIn->withdraw(portal: $portal, mode: $mode, label: $record['signIn']['label']) === false) {
			return 'failed';
		}

		$record['signIn'] = ['mode' => '', 'label' => false];

		return 'withdrawn';
	}//end removeSignIn()

	/**
	 * Delete the Nextcloud account when the install made it.
	 *
	 * @param array<string, mixed> $record The record; its entry is cleared when the account is gone.
	 *
	 * @return string `deleted`, `kept-not-ours` or `failed`.
	 */
	private function removeUser(array &$record): string {
		if ($record['userCreated'] !== true || $record['userId'] === '') {
			$record['userId']      = '';
			$record['userCreated'] = false;
			return 'kept-not-ours';
		}

		if ($this->user->delete(userId: $record['userId']) === false) {
			return 'failed';
		}

		$record['userId']      = '';
		$record['userCreated'] = false;

		return 'deleted';
	}//end removeUser()
}//end class
