<?php

/**
 * Tells the inbox which messages also reached the resident's government
 * message box.
 *
 * A message gets `_deliveries: [{channel: messageBox, label, at}]` once its
 * message box send was `delivered` or `read`. A pending, failed or simulated
 * send adds nothing: the resident sees only a real delivery
 * (inbox-berichtenbox-channel, REQ-MBC-004). The log is read only for an
 * organisation that offers the channel, and only the resident's own rows.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Notifications
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-the-resident-sees-only-a-real-delivery-req-mbc-004
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Notifications;

use OCA\Portaliq\Service\ScopedCollectionReaderInterface;
use OCA\Portaliq\Service\PortalOrganisationConfigService;

/**
 * Adds the delivery line's data to inbox rows.
 *
 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-the-resident-sees-only-a-real-delivery-req-mbc-004
 */
class MessageBoxDeliveries {

	/**
	 * How many of the resident's log rows are looked at.
	 *
	 * @var int
	 */
	private const LOG_LIMIT = 500;

	/**
	 * Wire the deliveries.
	 *
	 * @param ScopedCollectionReaderInterface              $reader    Reads the resident's account and log.
	 * @param PortalOrganisationConfigService $orgConfig Whether the organisation offers the channel.
	 */
	public function __construct(
		private readonly ScopedCollectionReaderInterface $reader,
		private readonly PortalOrganisationConfigService $orgConfig,
	) {
	}//end __construct()

	/**
	 * Add `_deliveries` to each row whose message box send was delivered.
	 *
	 * @param array<string, mixed>             $subject The resolved subject.
	 * @param array<int, array<string, mixed>> $rows    Inbox rows, each with its `_source`.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-the-resident-sees-only-a-real-delivery-req-mbc-004
	 */
	public function annotate(array $subject, array $rows): array {
		$organisation = (string)($subject['organisation'] ?? '');
		$offer = $this->orgConfig->messageBox(orgSlug: $organisation);
		if ($offer === null || $rows === []) {
			return $rows;
		}

		$delivered = $this->delivered(subjectRef: (string)($subject['subjectRef'] ?? ''), organisation: $organisation);
		foreach ($rows as $index => $row) {
			$rows[$index]['_deliveries'] = [];
			foreach ($this->keysOf(row: $row) as $key) {
				if (isset($delivered[$key]) === true) {
					$rows[$index]['_deliveries'] = [['channel' => MessageBoxChannel::CHANNEL, 'label' => $offer['label'], 'at' => $delivered[$key]]];
					break;
				}
			}
		}

		return $rows;
	}//end annotate()

	/**
	 * The messages whose message box send was delivered or read, as
	 * `app|collection|id` keys, each with the moment it was.
	 *
	 * @param string $subjectRef   The resident.
	 * @param string $organisation The organisation.
	 *
	 * @return array<string, string>
	 */
	private function delivered(string $subjectRef, string $organisation): array {
		if ($subjectRef === '') {
			return [];
		}

		$accountId = $this->accountIdOf(subjectRef: $subjectRef, organisation: $organisation);
		if ($accountId === '') {
			return [];
		}

		$rows = $this->reader->readCollection(
			register: 'portaliq',
			schema: 'portalNotification',
			scopeField: 'accountRef',
			subjectRef: $accountId,
			organisation: $organisation,
			limit: self::LOG_LIMIT
		);

		return $this->deliveredIn(rows: $rows);
	}//end delivered()

	/**
	 * The id of the resident's portal account, or '' when there is none.
	 *
	 * @param string $subjectRef   The resident.
	 * @param string $organisation The organisation.
	 *
	 * @return string
	 */
	private function accountIdOf(string $subjectRef, string $organisation): string {
		$accounts = $this->reader->readCollection(
			register: 'portaliq',
			schema: 'portalAccount',
			scopeField: 'subjectRef',
			subjectRef: $subjectRef,
			organisation: $organisation,
			limit: 2
		);

		return $this->idOf(row: ($accounts[0] ?? []))[0] ?? '';
	}//end accountIdOf()

	/**
	 * The `app|collection|id` keys of the shown message box sends in the log, each with its moment.
	 *
	 * @param array<int, array<string, mixed>> $rows The log rows.
	 *
	 * @return array<string, string>
	 */
	private function deliveredIn(array $rows): array {
		$delivered = [];
		foreach ($rows as $row) {
			$link = $this->shownLink(row: $row);
			if ($link === null) {
				continue;
			}

			$delivered[$this->linkKey(link: $link)] = $this->momentOf(row: $row);
		}

		return $delivered;
	}//end deliveredIn()

	/**
	 * The record link of a shown message box send, or null for any other row.
	 *
	 * @param array<string, mixed> $row The log row.
	 *
	 * @return array<string, mixed>|null
	 */
	private function shownLink(array $row): array|null {
		$link = ($row['recordLink'] ?? null);
		if ($this->shownSend(row: $row) === false || is_array($link) === false) {
			return null;
		}

		return $link;
	}//end shownLink()

	/**
	 * Whether a log row is a message box send that was delivered or read.
	 *
	 * @param array<string, mixed> $row The log row.
	 *
	 * @return bool
	 */
	private function shownSend(array $row): bool {
		$shown = in_array(($row['status'] ?? null), MessageBoxStatus::SHOWN, true);

		return ($row['channel'] ?? null) === MessageBoxChannel::CHANNEL && $shown === true;
	}//end shownSend()

	/**
	 * A record link as its `app|collection|id` key.
	 *
	 * @param array<string, mixed> $link The record link.
	 *
	 * @return string
	 */
	private function linkKey(array $link): string {
		return (string)($link['app'] ?? '').'|'.(string)($link['collection'] ?? '').'|'.(string)($link['id'] ?? '');
	}//end linkKey()

	/**
	 * When a send was delivered: the moment its log row last changed (the
	 * status report), else its last attempt; '' when neither is known.
	 *
	 * @param array<string, mixed> $row The log row.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-the-resident-sees-only-a-real-delivery-req-mbc-004
	 */
	private function momentOf(array $row): string {
		$self = ($row['@self'] ?? []);
		if (is_array($self) === true && isset($self['updated']) === true) {
			return (string)$self['updated'];
		}

		return (string)($row['lastAttemptAt'] ?? '');
	}//end momentOf()

	/**
	 * The keys an inbox row answers to: its app and collection with each id it carries.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array<int, string>
	 */
	private function keysOf(array $row): array {
		$source = ($row['_source'] ?? []);
		if (is_array($source) === false) {
			return [];
		}

		$prefix = (string)($source['appId'] ?? '').'|'.(string)($source['collection'] ?? '').'|';
		return array_map(static fn (string $id): string => $prefix.$id, $this->idOf(row: $row));
	}//end keysOf()

	/**
	 * Every id a row carries: `id`, `uuid`, and the same inside `@self`.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array<int, string>
	 */
	private function idOf(array $row): array {
		$self = ($row['@self'] ?? []);
		if (is_array($self) === false) {
			$self = [];
		}

		$ids = [];
		foreach ([($row['id'] ?? null), ($row['uuid'] ?? null), ($self['id'] ?? null), ($self['uuid'] ?? null)] as $candidate) {
			if ((is_string($candidate) === true || is_int($candidate) === true) && (string)$candidate !== '') {
				$ids[] = (string)$candidate;
			}
		}

		return $ids;
	}//end idOf()
}//end class
