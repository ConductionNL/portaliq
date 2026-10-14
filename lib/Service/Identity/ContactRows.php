<?php

/**
 * Portaliq Contact Rows (own-contacts-and-invitations)
 *
 * Reads the contact rows of a resident, and the accounts and invitations they point at.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Identity
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
 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use DateTimeImmutable;
use OCA\Portaliq\Service\PortalObjectReader;

/**
 * The contact rows of one resident and the questions asked of them.
 *
 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
 */
class ContactRows {
	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader The scoped reader.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
	) {
	}//end __construct()

	/**
	 * One of the resident's own rows, in one of some states, or null.
	 *
	 * @param array<string, mixed> $subject The resident.
	 * @param string $id The row id.
	 * @param array<int, string> $states The states it may be in.
	 *
	 * @return array<string, mixed>|null The row.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
	 */
	public function ownRow(array $subject, string $id, array $states): ?array {
		if ($id === '') {
			return null;
		}

		foreach ($this->rowsOf(owner: (string)($subject['subjectRef'] ?? ''), organisation: (string)($subject['organisation'] ?? '')) as $row) {
			if ($this->idOf(row: $row) === $id && in_array((string)($row['state'] ?? ''), $states, true) === true) {
				return $row;
			}
		}

		return null;
	}//end ownRow()

	/**
	 * Every row a resident holds, read through the scoped reader.
	 *
	 * @param string $owner The resident.
	 * @param string $organisation The tenant.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
	 */
	public function rowsOf(string $owner, string $organisation): array {
		if ($owner === '') {
			return [];
		}

		return $this->reader->readCollection(
			register: PortalContactService::REGISTER,
			schema: PortalContactService::SCHEMA,
			scopeField: 'owner',
			subjectRef: $owner,
			organisation: $organisation,
			limit: 200
		);
	}//end rowsOf()

	/**
	 * The active account that holds an address, or null.
	 *
	 * @param string $email The address.
	 * @param string $organisation The tenant.
	 *
	 * @return array<string, mixed>|null The account.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
	 */
	public function accountByEmail(string $email, string $organisation): ?array {
		$rows = $this->reader->readCollection(
			register: PortalContactService::REGISTER,
			schema: 'portalAccount',
			scopeField: 'email',
			subjectRef: $email,
			organisation: $organisation,
			limit: 5
		);
		foreach ($rows as $row) {
			$active = ((string)($row['status'] ?? '') === 'active' && (string)($row['subjectRef'] ?? '') !== '');
			if ($active === true && strtolower((string)($row['email'] ?? '')) === $email) {
				return $row;
			}
		}

		return null;
	}//end accountByEmail()

	/**
	 * The invitation a link's secret belongs to.
	 *
	 * @param string $token The secret.
	 * @param string $organisation The tenant.
	 *
	 * @return array<string, mixed>|null The row.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
	 */
	public function byToken(string $token, string $organisation): ?array {
		$rows = $this->reader->readCollection(
			register: PortalContactService::REGISTER,
			schema: PortalContactService::SCHEMA,
			scopeField: 'tokenHash',
			subjectRef: hash('sha256', $token),
			organisation: $organisation,
			limit: 2
		);

		return ($rows[0] ?? null);
	}//end byToken()

	/**
	 * How many invitations the rows hold from today.
	 *
	 * @param array<int, array<string, mixed>> $rows The resident's rows.
	 * @param DateTimeImmutable $now The moment.
	 *
	 * @return int The count; a withdrawn invitation still counts, it was sent.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
	 */
	public function sentToday(array $rows, DateTimeImmutable $now): int {
		$day   = $now->format('Y-m-d');
		$count = 0;
		foreach ($rows as $row) {
			if (str_starts_with((string)($row['sentAt'] ?? ''), $day) === true && (string)($row['state'] ?? '') !== 'requested') {
				$count++;
			}
		}

		return $count;
	}//end sentToday()

	/**
	 * Whether the address already has an open invitation, or the account is already linked or asked.
	 *
	 * @param array<int, array<string, mixed>> $rows The resident's rows.
	 * @param string $email The address.
	 * @param array<string, mixed>|null $account The account behind the address.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
	 */
	public function alreadyOpenOrLinked(array $rows, string $email, ?array $account): bool {
		foreach ($rows as $row) {
			if (in_array((string)($row['state'] ?? ''), ['invited', 'requested', 'approved'], true) === false) {
				continue;
			}

			if (strtolower((string)($row['email'] ?? '')) === $email) {
				return true;
			}

			if ($account !== null && (string)($row['contactRef'] ?? '') === (string)$account['subjectRef']) {
				return true;
			}
		}

		return false;
	}//end alreadyOpenOrLinked()

	/**
	 * Whether an invitation's link has lapsed.
	 *
	 * @param array<string, mixed> $row The row.
	 * @param DateTimeImmutable $now The moment.
	 *
	 * @return bool True when it has expired or carries no expiry.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
	 */
	public function expired(array $row, DateTimeImmutable $now): bool {
		$expires = (string)($row['expiresAt'] ?? '');
		if ($expires === '') {
			return true;
		}

		return new DateTimeImmutable($expires) <= $now;
	}//end expired()

	/**
	 * A row as the browser may see it: no token hash, and a declined request
	 * reads only as not accepted.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array<string, mixed> The row to show.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
	 */
	public function shown(array $row): array {
		return [
			'id'          => $this->idOf(row: $row),
			'displayName' => (string)($row['displayName'] ?? ''),
			'line'        => (string)($row['line'] ?? ''),
			'role'        => (string)($row['role'] ?? 'contact'),
			'state'       => (string)($row['state'] ?? ''),
			'email'       => (string)($row['email'] ?? ''),
			'message'     => (string)($row['message'] ?? ''),
			'sentAt'      => (string)($row['sentAt'] ?? ''),
			'expiresAt'   => (string)($row['expiresAt'] ?? ''),
		];
	}//end shown()

	/**
	 * A row's identifier, wherever OpenRegister put it.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The id, or ''.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
	 */
	public function idOf(array $row): string {
		return (string)($row['id'] ?? $row['uuid'] ?? ($row['@self']['id'] ?? ''));
	}//end idOf()
}//end class
