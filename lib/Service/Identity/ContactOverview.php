<?php

/**
 * Portaliq Contact Overview (own-contacts-and-invitations)
 *
 * The read side of a resident's contacts: the overview, and who they may choose.
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

/**
 * The resident's contacts, and what waits for an answer.
 *
 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
 */
class ContactOverview {
	/**
	 * Constructor.
	 *
	 * @param ContactRows $rows The contact rows.
	 */
	public function __construct(
		private readonly ContactRows $rows,
	) {
	}//end __construct()

	/**
	 * The resident's contacts, and what waits for an answer.
	 *
	 * @param string $owner The resident's subject reference.
	 * @param string $organisation The tenant.
	 *
	 * @return array<string, mixed> `incoming`, `outgoing` and `contacts` lists of rows, and `counts` per role.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
	 */
	public function overview(string $owner, string $organisation): array {
		$overview = ['incoming' => [], 'outgoing' => [], 'contacts' => [], 'counts' => ['all' => 0, 'begeleider' => 0, 'contact' => 0, 'organisatie' => 0]];
		foreach ($this->rows->rowsOf(owner: $owner, organisation: $organisation) as $row) {
			$shown = $this->rows->shown(row: $row);
			switch ((string)($row['state'] ?? '')) {
				case 'requested':
					$overview['incoming'][] = $shown;
					break;
				case 'invited':
				case 'declined':
					$overview['outgoing'][] = $shown;
					break;
				case 'approved':
					$overview['contacts'][] = $shown;
					$overview['counts']['all']++;
					$role = $shown['role'];
					if (isset($overview['counts'][$role]) === true) {
						$overview['counts'][$role]++;
					}

					break;
				default:
					break;
			}
		}

		return $overview;
	}//end overview()

	/**
	 * The approved contacts of a resident, each with the other account's
	 * reference, for choosing who takes part in a plan.
	 *
	 * @param string $owner The resident.
	 * @param string $organisation The tenant.
	 *
	 * @return array<int, array{id: string, ref: string, displayName: string}> The contacts.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t02
	 */
	public function approvedContacts(string $owner, string $organisation): array {
		$out = [];
		foreach ($this->rows->rowsOf(owner: $owner, organisation: $organisation) as $row) {
			if ((string)($row['state'] ?? '') === 'approved' && (string)($row['contactRef'] ?? '') !== '') {
				$out[] = ['id' => $this->rows->idOf(row: $row), 'ref' => (string)$row['contactRef'], 'displayName' => (string)($row['displayName'] ?? '')];
			}
		}

		return $out;
	}//end approvedContacts()

	/**
	 * The approved contacts' references of a resident, for a message thread.
	 *
	 * @param string $owner The resident.
	 * @param string $organisation The tenant.
	 *
	 * @return array<int, string> The other accounts' subject references.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t07
	 */
	public function approvedRefs(string $owner, string $organisation): array {
		$refs = [];
		foreach ($this->rows->rowsOf(owner: $owner, organisation: $organisation) as $row) {
			if ((string)($row['state'] ?? '') === 'approved' && (string)($row['contactRef'] ?? '') !== '') {
				$refs[] = (string)$row['contactRef'];
			}
		}

		return $refs;
	}//end approvedRefs()
}//end class
