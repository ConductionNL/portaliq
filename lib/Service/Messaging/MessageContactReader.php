<?php

/**
 * Portaliq Message Contact Reader (site-messages-per-record)
 *
 * Who a signed-in resident may start a conversation with, and about which of
 * their records. Every contribution of the resident's audience may declare
 * `contacts` on a collection (MessageContactsKeys); for each row of that
 * collection the resident owns (read through the same scoped reader as the
 * collection itself), portaliq asks the app's provider method for the people
 * of the organisation the resident may write to about that row.
 *
 * The same reader proves a new conversation: a staff member is only a valid
 * recipient when the provider names them for a row the resident owns, read
 * again at that moment. Nothing the browser sends is trusted.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Messaging
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
 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-a-resident-may-start-a-conversation-only-with-a-contact-of-their-own-record
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Messaging;

use OCA\Portaliq\Contribution\MessageContactsKeys;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Contribution\PortalProviderLocator;
use OCA\Portaliq\Contribution\TimelineProviderMethod;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalSessionService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads a resident's message contacts per record, and proves one.
 *
 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-a-resident-may-start-a-conversation-only-with-a-contact-of-their-own-record
 */
class MessageContactReader {
	/**
	 * The most rows of one collection asked about.
	 */
	private const ROW_CAP = 50;

	/**
	 * Constructor.
	 *
	 * @param PortalContributionRegistry $registry The contributions of the resident's audience.
	 * @param PortalObjectReader         $reader   The scoped reader every collection read goes through.
	 * @param PortalProviderLocator      $locator  Finds the contributing app's provider.
	 * @param LoggerInterface            $logger   Logs a provider that failed.
	 */
	public function __construct(
		private readonly PortalContributionRegistry $registry,
		private readonly PortalObjectReader $reader,
		private readonly PortalProviderLocator $locator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The resident's contacts, one entry per person per record, and the form's
	 * own words: `{composeLabel, composeHint, contacts: [{staffRef, name, role,
	 * recordRef, recordLabel, collection}]}`.
	 *
	 * @param array<string, mixed> $subject The resolved portal subject.
	 *
	 * @return array{composeLabel: string, composeHint: string, contacts: array<int, array<string, string>>}
	 *
	 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-a-resident-may-start-a-conversation-only-with-a-contact-of-their-own-record
	 */
	public function contactsFor(array $subject): array {
		$out = ['composeLabel' => '', 'composeHint' => '', 'contacts' => []];
		foreach ($this->declaring(subject: $subject) as $match) {
			$declared = $match['collection']['contacts'];
			if ($out['composeLabel'] === '') {
				$out['composeLabel'] = (string)($declared['composeLabel'] ?? '');
				$out['composeHint']  = (string)($declared['composeHint'] ?? '');
			}

			foreach ($this->rows(subject: $subject, match: $match) as $row) {
				$out['contacts'] = array_merge($out['contacts'], $this->contactsOfRow(match: $match, row: $row));
			}
		}

		return $out;
	}//end contactsFor()

	/**
	 * The one contact a new conversation names, proven now: the record is
	 * read again through the scoped reader and the provider asked again. Null
	 * when the record is not the resident's or the provider does not name
	 * that person for it.
	 *
	 * @param array<string, mixed> $subject   The resolved portal subject.
	 * @param string               $staffRef  The person the resident writes to.
	 * @param string               $recordRef The record the conversation is about.
	 *
	 * @return array<string, string>|null
	 *
	 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-a-resident-may-start-a-conversation-only-with-a-contact-of-their-own-record
	 */
	public function contactFor(array $subject, string $staffRef, string $recordRef): ?array {
		if ($staffRef === '' || $recordRef === '') {
			return null;
		}

		foreach ($this->declaring(subject: $subject) as $match) {
			$collection = $match['collection'];
			$row        = $this->reader->readObject(
				register: (string)($collection['register'] ?? ''),
				schema: (string)($collection['schema'] ?? ''),
				scopeField: (string)($collection['scopeField'] ?? 'subjectRef'),
				subjectRef: (string)($subject['subjectRef'] ?? ''),
				id: $recordRef,
				organisation: (string)($subject['organisation'] ?? ''),
				scopeClaim: (string)($collection['scopeClaim'] ?? ''),
				contributingApp: $match['app'],
				via: ($collection['via'] ?? null),
				audience: (string)($subject['audience'] ?? ''),
				fields: ($collection['fields'] ?? null),
				filter: (array)($collection['filter'] ?? [])
			);
			if ($row === null) {
				continue;
			}

			foreach ($this->contactsOfRow(match: $match, row: $row, recordRef: $recordRef) as $contact) {
				if ($contact['staffRef'] === $staffRef) {
					return $contact;
				}
			}
		}

		return null;
	}//end contactFor()

	/**
	 * The collections of the resident's audience that declare `contacts`,
	 * and whose trust the resident's session satisfies.
	 *
	 * @param array<string, mixed> $subject The resolved portal subject.
	 *
	 * @return array<int, array{app: string, collection: array<string, mixed>}>
	 */
	private function declaring(array $subject): array {
		try {
			$aggregate = $this->registry->aggregateFor($subject);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: message contacts lookup failed', ['reason' => $e->getMessage()]);
			return [];
		}

		$out = [];
		foreach ((array)($aggregate['contributions'] ?? []) as $contribution) {
			foreach ((array)($contribution['collections'] ?? []) as $collection) {
				if (is_array($collection['contacts'] ?? null) === false
					|| PortalSessionService::trustSatisfies(($subject['trust'] ?? ''), ($collection['minTrust'] ?? null)) === false
				) {
					continue;
				}

				$out[] = ['app' => (string)($contribution['app'] ?? ''), 'collection' => $collection];
			}
		}

		return $out;
	}//end declaring()

	/**
	 * The rows of one declaring collection the resident owns.
	 *
	 * @param array<string, mixed>                                 $subject The resolved portal subject.
	 * @param array{app: string, collection: array<string, mixed>} $match   The collection and its app.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(array $subject, array $match): array {
		$collection = $match['collection'];

		return $this->reader->readCollection(
			register: (string)($collection['register'] ?? ''),
			schema: (string)($collection['schema'] ?? ''),
			scopeField: (string)($collection['scopeField'] ?? 'subjectRef'),
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			organisation: (string)($subject['organisation'] ?? ''),
			limit: self::ROW_CAP,
			scopeClaim: (string)($collection['scopeClaim'] ?? ''),
			contributingApp: $match['app'],
			via: ($collection['via'] ?? null),
			audience: (string)($subject['audience'] ?? ''),
			fields: ($collection['fields'] ?? null),
			filter: (array)($collection['filter'] ?? [])
		);
	}//end rows()

	/**
	 * The provider's people for one owned row, each with the record it is about.
	 *
	 * @param array{app: string, collection: array<string, mixed>} $match     The collection and its app.
	 * @param array<string, mixed>                                 $row       The owned row.
	 * @param string|null                                          $recordRef The row's id when the caller already knows it.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function contactsOfRow(array $match, array $row, ?string $recordRef = null): array {
		$recordRef = ($recordRef ?? $this->rowId(row: $row));
		if ($recordRef === null) {
			return [];
		}

		$method   = (string)$match['collection']['contacts']['provider'];
		$provider = $this->locator->locate(appId: $match['app']);
		if ($provider === null || (new TimelineProviderMethod())->callableOn(provider: $provider, method: $method) === false) {
			return [];
		}

		try {
			$entries = $provider->{$method}($recordRef);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: message contacts provider failed', ['app' => $match['app'], 'method' => $method, 'reason' => $e->getMessage()]);
			return [];
		}

		if (is_array($entries) === false) {
			return [];
		}

		$label = $this->recordLabel(row: $row, fields: (array)($match['collection']['contacts']['recordLabelFields'] ?? []));
		$out   = [];
		foreach ((new MessageContactsKeys())->contacts(entries: $entries) as $contact) {
			$out[] = $contact + ['recordRef' => $recordRef, 'recordLabel' => $label, 'collection' => (string)($match['collection']['id'] ?? '')];
		}

		return $out;
	}//end contactsOfRow()

	/**
	 * The words that name a record: its label fields joined ("Vera, Groep 7").
	 *
	 * @param array<string, mixed> $row    The row.
	 * @param array<int, mixed>    $fields The label fields.
	 *
	 * @return string
	 */
	private function recordLabel(array $row, array $fields): string {
		$parts = [];
		foreach ($fields as $field) {
			$value = ($row[$field] ?? null);
			if (is_string($value) === true && trim($value) !== '') {
				$parts[] = trim($value);
			}
		}

		return implode(', ', $parts);
	}//end recordLabel()

	/**
	 * A row's identity: `id`, `uuid` or the `@self` envelope's id.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string|null
	 */
	private function rowId(array $row): ?string {
		foreach ([($row['id'] ?? null), ($row['uuid'] ?? null), ($row['@self']['id'] ?? null)] as $candidate) {
			if (is_string($candidate) === true && $candidate !== '') {
				return $candidate;
			}
		}

		return null;
	}//end rowId()
}//end class
