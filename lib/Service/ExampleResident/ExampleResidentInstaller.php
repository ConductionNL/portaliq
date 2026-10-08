<?php

/**
 * Portaliq Example Resident Installer
 *
 * Gives an example site its resident: a Nextcloud account to sign in with, a
 * portal account under the same id, the sign-in mode on the portal, and the
 * cases, question and messages of the declaration. It writes only what its
 * own record does not hold yet, and it never takes over an account it did not
 * make. Afterwards it reads the instance back, like the example site's
 * install, because OpenRegister answers a write with the object as it was
 * sent, also for a key the schema does not keep.
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

use DateTimeImmutable;
use DateTimeZone;
use OCA\Portaliq\Service\ExampleSite\ExampleSiteProof;
use Throwable;

/**
 * Installs an example resident.
 *
 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-resident-with-one-command
 */
class ExampleResidentInstaller {
	/**
	 * Constructor.
	 *
	 * @param ExampleResidentStore   $store   Reads rows back.
	 * @param ExampleResidentUser    $user    The Nextcloud account.
	 * @param ExampleResidentWayIn   $wayIn   The portal account and the sign-in mode.
	 * @param ExampleResidentObjects $objects Writes the declared objects.
	 * @param ExampleResidentRecord  $record  Keeps what was created.
	 * @param ExampleSiteProof       $proof   Compares declared with stored.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExampleResidentStore $store,
		private readonly ExampleResidentUser $user,
		private readonly ExampleResidentWayIn $wayIn,
		private readonly ExampleResidentObjects $objects,
		private readonly ExampleResidentRecord $record,
		private readonly ExampleSiteProof $proof,
	) {
	}//end __construct()

	/**
	 * Write what the declaration holds and the record lacks, then prove it.
	 *
	 * @param array<string, mixed>   $resident A declaration from ExampleResidentCatalogue.
	 * @param string                 $userId   The Nextcloud account id to use; '' takes the declaration's.
	 * @param string                 $password The password for a new account; '' makes one.
	 * @param DateTimeImmutable|null $today    The day dates are counted from; now when null.
	 *
	 * @return array<string, mixed> `id`, `portal`, `userId`, `stopped` ('' or why nothing was written), `user`
	 *                              (`created` or `kept`), `password` (only when this run made one), `account`
	 *                              and `signIn` (`created`/`added` or `kept`), `types`, `dropped`, `missing`,
	 *                              `lost` and `ok`.
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-resident-with-one-command
	 */
	public function install(array $resident, string $userId = '', string $password = '', ?DateTimeImmutable $today = null): array {
		$record = $this->record->read(id: (string)$resident['id']);
		if ($userId === '') {
			$userId = (string)$resident['resident']['userId'];
			if ($record['userId'] !== '') {
				$userId = $record['userId'];
			}
		}

		$report = [
			'id'       => (string)$resident['id'],
			'portal'   => (string)$resident['portal'],
			'userId'   => $userId,
			'stopped'  => '',
			'user'     => 'kept',
			'password' => '',
			'account'  => 'kept',
			'signIn'   => 'kept',
			'types'    => [],
			'dropped'  => [],
			'missing'  => [],
			'lost'     => [],
			'ok'       => false,
		];

		$portal            = null;
		$report['stopped'] = $this->stoppedBy(resident: $resident, userId: $userId, record: $record, portal: $portal);
		if ($report['stopped'] !== '' || $portal === null) {
			return $report;
		}

		$record['userId'] = $userId;
		if ($this->user->exists(userId: $userId) === false) {
			$made = $this->makeUser(resident: $resident, userId: $userId, password: $password);
			if ($made['error'] !== '') {
				$report['stopped'] = 'Nextcloud did not create the account "' . $userId . '": ' . $made['error'];
				return $report;
			}

			$report['user']        = 'created';
			$report['password']    = $made['generated'];
			$record['userCreated'] = true;
			$this->record->write(id: $report['id'], record: $record);
		}

		$declaredAccount = $this->wayIn->accountFor(resident: (array)$resident['resident'], subject: $userId);
		$report          = $this->openWayIn(resident: $resident, portal: $portal, account: $declaredAccount, report: $report, record: $record);
		$this->record->write(id: $report['id'], record: $record);

		$written = $this->objects->write(
			declared: (array)$resident['objects'],
			subject: $userId,
			recorded: $record['objects'],
			today: ($today ?? $this->today(timezone: (string)($resident['timezone'] ?? 'UTC')))
		);

		$record['objects'] = $written['recorded'];
		$this->record->write(id: $report['id'], record: $record);

		$report['types']   = $written['types'];
		$report['dropped'] = $written['dropped'];

		return $this->proven(resident: $resident, report: $report, record: $record, written: ($written['written'] + ['@account' => $declaredAccount]));
	}//end install()

	/**
	 * Write the portal account and add the sign-in mode, each when the record does not hold it yet.
	 *
	 * @param array<string, mixed> $resident The declaration.
	 * @param array<string, mixed> $portal   The portal row.
	 * @param array<string, mixed> $account  The portal account to write, from ExampleResidentWayIn::accountFor().
	 * @param array<string, mixed> $report   The report so far.
	 * @param array<string, mixed> $record   The record; gains the account id and the mode.
	 *
	 * @return array<string, mixed> The report, with `account` and `signIn` filled in.
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-the-resident-must-sign-in-without-a-test-door
	 */
	private function openWayIn(array $resident, array $portal, array $account, array $report, array &$record): array {
		if ($record['account'] === '' || $this->wayIn->account(id: $record['account']) === null) {
			$record['account'] = $this->wayIn->createAccount(account: $account);
			$report['account'] = 'created';
		}

		$mode = (string)$resident['signIn']['mode'];
		if ($this->wayIn->offered(portal: $portal, mode: $mode) === false) {
			$offer            = $this->wayIn->offer(portal: $portal, signIn: (array)$resident['signIn']);
			$report['signIn'] = 'added';
			$record['signIn'] = ['mode' => $mode, 'label' => $offer['label']];
		}

		return $report;
	}//end openWayIn()

	/**
	 * This moment in the declaration's time zone, so "09:12" is a quarter
	 * past nine where the example municipality is.
	 *
	 * @param string $timezone The declaration's time zone.
	 *
	 * @return DateTimeImmutable
	 */
	private function today(string $timezone): DateTimeImmutable {
		try {
			return new DateTimeImmutable('now', new DateTimeZone($timezone));
		} catch (Throwable) {
			return new DateTimeImmutable('now');
		}
	}//end today()

	/**
	 * Why the install cannot start, or ''.
	 *
	 * It stops before the first write when OpenRegister is missing, when the
	 * example site is not installed, when an earlier install used another
	 * account id, and when the account id belongs to somebody: an existing
	 * Nextcloud account or portal account that this command did not make is
	 * never taken over.
	 *
	 * @param array<string, mixed>      $resident The declaration.
	 * @param string                    $userId   The account id to use.
	 * @param array<string, mixed>      $record   What an earlier install created.
	 * @param array<string, mixed>|null $portal   Receives the portal row.
	 *
	 * @return string
	 */
	private function stoppedBy(array $resident, string $userId, array $record, ?array &$portal): string {
		if ($this->store->available() === false) {
			return 'OpenRegister is not available, so nothing was written. Enable openregister and run this again.';
		}

		$portal = $this->wayIn->portal(slug: (string)$resident['portal']);
		if ($portal === null) {
			return 'There is no portal "' . $resident['portal'] . '" on this instance. Install the example site first:'
				. ' occ portaliq:example-site:install ' . $resident['id'];
		}

		if ($record['userId'] !== '' && $record['userId'] !== $userId) {
			return 'This example resident is installed as "' . $record['userId'] . '". Remove it first to use another account id.';
		}

		return $this->takenBy(userId: $userId, record: $record);
	}//end stoppedBy()

	/**
	 * Why the account id cannot be used, or '': it names a Nextcloud account
	 * or a portal account that this command did not make.
	 *
	 * @param string               $userId The account id to use.
	 * @param array<string, mixed> $record What an earlier install created.
	 *
	 * @return string
	 */
	private function takenBy(string $userId, array $record): string {
		$ours = ($record['userId'] === $userId && $record['userCreated'] === true);
		if ($this->user->exists(userId: $userId) === true && $ours === false) {
			return 'A Nextcloud account "' . $userId . '" exists and this command did not make it, so it is left alone.'
				. ' Choose another id with --user.';
		}

		$account = $this->wayIn->accountOf(subject: $userId);
		if ($account !== '' && $account !== $record['account']) {
			return 'A portal account for "' . $userId . '" exists and this command did not make it, so it is left alone.'
				. ' Choose another id with --user.';
		}

		return '';
	}//end takenBy()

	/**
	 * Create the Nextcloud account, with the given password or a new one.
	 *
	 * @param array<string, mixed> $resident The declaration.
	 * @param string               $userId   The account id.
	 * @param string               $password The administrator's password, or ''.
	 *
	 * @return array{error: string, generated: string} Why it failed, and the password when this call made one.
	 */
	private function makeUser(array $resident, string $userId, string $password): array {
		$generated = '';
		if ($password === '') {
			$generated = $this->user->newPassword();
			$password  = $generated;
		}

		$error = $this->user->create(userId: $userId, displayName: (string)$resident['resident']['displayName'], password: $password);
		if ($error !== '') {
			$generated = '';
		}

		return ['error' => $error, 'generated' => $generated];
	}//end makeUser()

	/**
	 * Read the instance back and fill in what arrived, what is missing and
	 * which keys the objects of this run lost.
	 *
	 * An object from an earlier run is counted and not compared: a colleague
	 * may have moved the case on since, and that is not a loss.
	 *
	 * @param array<string, mixed>                $resident The declaration.
	 * @param array<string, mixed>                $report   The report so far.
	 * @param array<string, mixed>                $record   The record as written.
	 * @param array<string, array<string, mixed>> $written  The data this run wrote, by key; `@account` is the portal account.
	 *
	 * @return array<string, mixed> The finished report.
	 */
	private function proven(array $resident, array $report, array $record, array $written): array {
		$report = $this->provenWayIn(resident: $resident, report: $report, record: $record, account: $written['@account']);
		foreach ((array)$resident['objects'] as $object) {
			$key = (string)$object['key'];
			if (isset($record['objects'][$key]) === false) {
				// Never written: named among the dropped ones already.
				continue;
			}

			$report = $this->provenObject(object: $object, id: $record['objects'][$key]['id'], declared: ($written[$key] ?? []), report: $report);
		}

		$report['ok'] = ($report['dropped'] === [] && $report['missing'] === [] && $report['lost'] === []);

		return $report;
	}//end proven()

	/**
	 * Read the portal account and the portal back.
	 *
	 * @param array<string, mixed> $resident The declaration.
	 * @param array<string, mixed> $report   The report so far.
	 * @param array<string, mixed> $record   The record as written.
	 * @param array<string, mixed> $account  The portal account this run meant to write.
	 *
	 * @return array<string, mixed> The report.
	 */
	private function provenWayIn(array $resident, array $report, array $record, array $account): array {
		$stored = $this->wayIn->account(id: $record['account']);
		if ($stored === null) {
			$report['missing'][] = 'portal account ' . $report['userId'];
		} else if ($report['account'] === 'created') {
			foreach ($this->proof->lostPaths(declared: $account, stored: $stored) as $path) {
				$report['lost'][] = 'portal account ' . $report['userId'] . ': ' . $path;
			}
		}

		$portal = $this->wayIn->portal(slug: $report['portal']);
		if ($portal === null || $this->wayIn->offered(portal: $portal, mode: (string)$resident['signIn']['mode']) === false) {
			$report['missing'][] = 'sign-in mode ' . $resident['signIn']['mode'] . ' on portal ' . $report['portal'];
		}

		return $report;
	}//end provenWayIn()

	/**
	 * Read one object back and compare it with what this run wrote.
	 *
	 * @param array<string, mixed> $object   The declared object.
	 * @param string               $id       The id the record holds for it.
	 * @param array<string, mixed> $declared What this run wrote, or [] for an object from an earlier run.
	 * @param array<string, mixed> $report   The report so far.
	 *
	 * @return array<string, mixed> The report.
	 */
	private function provenObject(array $object, string $id, array $declared, array $report): array {
		$type   = $object['register'] . ' ' . $object['schema'];
		$stored = $this->store->get(register: $object['register'], schema: $object['schema'], id: $id);
		if ($stored === null) {
			$report['missing'][] = $type . ' ' . $object['key'];
			return $report;
		}

		$report['types'][$type]['arrived']++;
		foreach ((array)($object['jsonFields'] ?? []) as $field) {
			// Kept as JSON text; a register may hand it back as text or as the list.
			if (is_string($stored[$field] ?? null) === true) {
				$stored[$field] = json_decode($stored[$field], true);
			}
		}

		foreach ($this->proof->lostPaths(declared: $declared, stored: $stored) as $path) {
			$report['lost'][] = $type . ' ' . $object['key'] . ': ' . $path;
		}

		return $report;
	}//end provenObject()
}//end class
