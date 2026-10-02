<?php

/**
 * Portaliq Change Rule Notices
 *
 * Who hears about a change, and in which words, when a change rule says so
 * itself (claim-addressed-change-notices).
 *
 * Who: when the rule names its recipients by a claim
 * (claim-addressed-change-notices): the active portal accounts whose claim of
 * the contributing app holds the value the record has at the rule's
 * recipient field, and of those only the accounts that may read the record.
 *
 * The second step is the collection's own scoped read, run as each candidate
 * (PortalObjectReader::readObject with the collection's scopeField,
 * scopeClaim, via, filter and fields). A guardian who holds the claim but is
 * no longer linked to the child is refused there, exactly as the portal would
 * refuse them the record, and so hears nothing. The row that read returns is
 * already projected to the resident, so a notice built from it can only print
 * what the resident may see.
 *
 * Which words: the rule's own text for the new value, rendered by
 * ChangeNoticeText in the portal's language.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Notifications
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
 * @spec openspec/changes/claim-addressed-change-notices/specs/portal-notifications-and-preferences/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Notifications;

use OCA\Portaliq\Service\Identity\PortalAccountsByClaim;
use OCA\Portaliq\Service\PortalObjectReader;
use OCP\IDateTimeZone;

/**
 * Resolves the accounts a claim-addressed change rule reaches, and the words
 * a rule declares.
 *
 * @spec openspec/changes/claim-addressed-change-notices/specs/portal-notifications-and-preferences/spec.md
 */
class ChangeRuleNotices {

	/**
	 * Constructor.
	 *
	 * @param PortalAccountsByClaim $accounts Finds the accounts holding the claim.
	 * @param PortalObjectReader    $reader   Reads the record as each of them.
	 * @param IDateTimeZone|null    $timeZone The instance time zone, for date-time placeholders.
	 */
	public function __construct(
		private readonly PortalAccountsByClaim $accounts,
		private readonly PortalObjectReader $reader,
		private readonly ?IDateTimeZone $timeZone = null,
	) {
	}//end __construct()

	/**
	 * The rule's own subject and body for the new value, or null when it has
	 * no words for it.
	 *
	 * @param array<string, mixed> $messages The rule's messages, by value.
	 * @param string               $value    The field's new value.
	 * @param array<string, mixed> $row      The record as the resident may read it.
	 * @param string               $language The portal's language.
	 *
	 * @return array{subject: string, body: string}|null
	 *
	 * @spec openspec/changes/claim-addressed-change-notices/specs/portal-notifications-and-preferences/spec.md
	 */
	public function text(array $messages, string $value, array $row, string $language): ?array {
		return (new ChangeNoticeText(timeZone: $this->timeZone))->render(messages: $messages, value: $value, row: $row, language: $language);
	}//end text()

	/**
	 * The accounts that hear about this record, each with the record as they
	 * may read it.
	 *
	 * @param string               $appId      The contributing app.
	 * @param string               $field      The record field holding the claim value.
	 * @param string               $claim      The app's claim name.
	 * @param array<string, mixed> $collection The rule's collection.
	 * @param array<string, mixed> $data       The record after the change.
	 * @param string               $recordId   The record's uuid.
	 *
	 * @return array<int, array{account: array<string, mixed>, row: array<string, mixed>}>
	 *
	 * @spec openspec/changes/claim-addressed-change-notices/specs/portal-notifications-and-preferences/spec.md
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- each argument is one
	 * part of the declared rule; an options array would hide which one a
	 * caller forgot.
	 */
	public function recipients(string $appId, string $field, string $claim, array $collection, array $data, string $recordId): array {
		$value = ($data[$field] ?? null);
		if (is_string($value) === false || $value === '' || $recordId === '') {
			return [];
		}

		$recipients = [];
		$seen = [];
		foreach ($this->accounts->find(appId: $appId, claim: $claim, value: $value) as $account) {
			$subjectRef = (string)($account['subjectRef'] ?? '');
			if (isset($seen[$subjectRef]) === true) {
				continue;
			}

			$seen[$subjectRef] = true;
			$row = $this->reader->readObject(
				register: (string)($collection['register'] ?? ''),
				schema: (string)($collection['schema'] ?? ''),
				scopeField: (string)($collection['scopeField'] ?? 'subjectRef'),
				subjectRef: $subjectRef,
				id: $recordId,
				organisation: (string)($account['organisation'] ?? ''),
				scopeClaim: (string)($collection['scopeClaim'] ?? ''),
				contributingApp: $appId,
				via: ($collection['via'] ?? null),
				audience: (string)($account['audience'] ?? ''),
				fields: ($collection['fields'] ?? null),
				filter: (array)($collection['filter'] ?? [])
			);
			if ($row !== null) {
				$recipients[] = ['account' => $account, 'row' => $row];
			}
		}//end foreach

		return $recipients;
	}//end recipients()
}//end class
