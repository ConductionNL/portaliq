<?php

/**
 * Portaliq Mandate Listing (site-mandates-the-represented-manage)
 *
 * Lists the mandates given on a party's behalf and the ones a session holds.
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
 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use DateTimeImmutable;

/**
 * The read side of the mandates: given on a party's behalf, and held.
 *
 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md
 */
class MandateListing {
	/**
	 * Constructor.
	 *
	 * @param MandateStore   $store   The mandate and invitation rows.
	 * @param MandateParties $parties Who holds and who is represented.
	 * @param MandateDays    $days    Whether an end has passed.
	 */
	public function __construct(
		private readonly MandateStore $store,
		private readonly MandateParties $parties,
		private readonly MandateDays $days,
	) {
	}//end __construct()

	/**
	 * Every mandate and open invitation given on this party's behalf.
	 *
	 * @param string                 $party        The session's party.
	 * @param string                 $organisation The tenant.
	 * @param DateTimeImmutable|null $now          The moment.
	 *
	 * @return array<int, array<string, mixed>> `{kind, id, label, caseTypes, expiresAt, state, email|holder}`.
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md#requirement-the-represented-party-must-see-who-may-act-for-it-req-smr-002
	 */
	public function given(string $party, string $organisation, ?DateTimeImmutable $now = null): array {
		$moment = ($now ?? new DateTimeImmutable());
		$out    = [];
		foreach ($this->store->rows(schema: MandateStore::MANDATES, organisation: $organisation) as $row) {
			if ($this->parties->typed(value: (string)($row['onBehalfOf'] ?? '')) !== $party || ($row['status'] ?? 'active') === 'revoked') {
				continue;
			}

			$mandateState = 'active';
			if ($this->days->expired(value: (string)($row['expiresAt'] ?? ''), now: $moment) === true) {
				$mandateState = 'expired';
			}

			$out[] = [
				'kind' => 'mandate',
				'id' => $this->store->idOf(row: $row),
				'holder' => $this->parties->holderOf(row: $row),
				'label' => (string)($row['label'] ?? ''),
				'caseTypes' => array_values((array)($row['caseTypes'] ?? [])),
				'expiresAt' => (string)($row['expiresAt'] ?? ''),
				'state' => $mandateState,
			];
		}

		foreach ($this->store->rows(schema: MandateStore::INVITATIONS, organisation: $organisation) as $row) {
			$state = (string)($row['state'] ?? 'sent');
			if ($this->parties->typed(value: (string)($row['onBehalfOf'] ?? '')) !== $party || in_array($state, ['sent', 'opened'], true) === false) {
				continue;
			}

			$terms = (array)($row['mandate'] ?? []);
			$out[] = [
				'kind' => 'invitation',
				'id' => $this->store->idOf(row: $row),
				'email' => (string)($row['email'] ?? ''),
				'label' => (string)($terms['label'] ?? ''),
				'caseTypes' => array_values((array)($terms['caseTypes'] ?? [])),
				'expiresAt' => (string)($terms['expiresAt'] ?? ''),
				'state' => 'pending',
			];
		}

		return $out;
	}//end given()

	/**
	 * The mandates the session holds, in the shape "Uw machtiging" shows.
	 *
	 * @param array<int, string>     $holders      The parties the session carries.
	 * @param string                 $organisation The tenant.
	 * @param DateTimeImmutable|null $now          The moment.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md#requirement-either-side-must-be-able-to-end-a-mandate-req-smr-004
	 */
	public function held(array $holders, string $organisation, ?DateTimeImmutable $now = null): array {
		$moment = ($now ?? new DateTimeImmutable());
		$out    = [];
		foreach ($this->store->rows(schema: MandateStore::MANDATES, organisation: $organisation) as $row) {
			if (in_array($this->parties->holderOf(row: $row), $holders, true) === false || ($row['status'] ?? 'active') !== 'active') {
				continue;
			}

			if ($this->days->expired(value: (string)($row['expiresAt'] ?? ''), now: $moment) === true) {
				continue;
			}

			$out[] = [
				'id' => $this->store->idOf(row: $row),
				'label' => (string)($row['label'] ?? ''),
				'onBehalfOf' => $this->parties->typed(value: (string)($row['onBehalfOf'] ?? '')),
				'expiresAt' => (string)($row['expiresAt'] ?? ''),
				'grantedBy' => (string)($row['grantedBy'] ?? ''),
				'grantedAt' => (string)($row['grantedAt'] ?? ''),
			];
		}

		return $out;
	}//end held()
}//end class
