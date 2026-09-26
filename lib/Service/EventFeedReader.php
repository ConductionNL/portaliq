<?php

/**
 * Event Feed Reader
 *
 * Guardian-facing read path for `event` objects — mirrors
 * `NewsFeedReader` from the sibling `news-and-newsletter-authoring` change
 * exactly (same 3-way targeting shape, same reason a dedicated reader is
 * used instead of the generic `portal-contribution-contract` engine). Each
 * returned event is annotated with the CALLING guardian's own RSVP, never
 * another guardian's.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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
 * @spec openspec/changes/events-and-signups/design.md#architecture-overview
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @spec openspec/changes/events-and-signups/design.md#architecture-overview
 *
 * @SuppressWarnings(PHPMD.StaticAccess) -- NewsAudienceMatcher::matches() is
 * deliberately the ONE stateless match predicate every caller shares (see
 * the sibling news-and-newsletter-authoring change), so the two features'
 * audience rules can never fork.
 */
class EventFeedReader {
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	private const REGISTER = 'portaliq';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container For resolving OpenRegister services.
	 * @param GuardianAudienceFixtureReader $audienceReader Resolves the guardian's own audience.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly GuardianAudienceFixtureReader $audienceReader,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every PUBLISHED `event` in the calling guardian's own resolved
	 * audience, each carrying `myRsvp` (the guardian's own response, or null).
	 *
	 * @param string $subjectRef The guardian's own subjectRef.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/events-and-signups/specs/portaliq-cms/spec.md#requirement-an-event-is-authored-per-school-group-or-child-with-guardian-rsvp
	 */
	public function feedFor(string $subjectRef): array {
		$audience = $this->audienceReader->resolveAudience(subjectRef: $subjectRef);
		$rows = $this->findAll(schema: 'event');
		$rsvps = $this->findAll(schema: 'eventRsvp');

		$matched = [];
		foreach ($rows as $row) {
			if (($row['status'] ?? '') !== 'published') {
				continue;
			}

			$target = [];
			if (is_array($row['target'] ?? null) === true) {
				$target = $row['target'];
			}

			if (NewsAudienceMatcher::matches(target: $target, audience: $audience) === false) {
				continue;
			}

			$row['myRsvp'] = $this->myRsvp(eventId: $this->rowId(row: $row), subjectRef: $subjectRef, rsvps: $rsvps);
			$matched[] = $row;
		}

		return $matched;
	}//end feedFor()

	/**
	 * One `event` by id, scoped to the calling guardian's own audience.
	 * Returns null for EVERY failure shape — an identical null in each case.
	 *
	 * @param string $subjectRef The guardian's own subjectRef.
	 * @param string $id The event id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/events-and-signups/design.md#api-design
	 */
	public function readOwnEvent(string $subjectRef, string $id): ?array {
		if ($id === '') {
			return null;
		}

		$audience = $this->audienceReader->resolveAudience(subjectRef: $subjectRef);
		$rows = $this->findAll(schema: 'event');

		foreach ($rows as $row) {
			if ($this->rowId(row: $row) !== $id) {
				continue;
			}

			if (($row['status'] ?? '') !== 'published') {
				return null;
			}

			$target = [];
			if (is_array($row['target'] ?? null) === true) {
				$target = $row['target'];
			}

			if (NewsAudienceMatcher::matches(target: $target, audience: $audience) === false) {
				return null;
			}

			return $row;
		}

		return null;
	}//end readOwnEvent()

	/**
	 * The guardian's own RSVP response for one event, or null.
	 *
	 * @param string $eventId The event id.
	 * @param string $subjectRef The guardian's own subjectRef.
	 * @param array<int, array<string, mixed>> $rsvps Every fetched RSVP row.
	 *
	 * @return string|null
	 */
	private function myRsvp(string $eventId, string $subjectRef, array $rsvps): ?string {
		foreach ($rsvps as $rsvp) {
			if ((string)($rsvp['eventRef'] ?? '') === $eventId && (string)($rsvp['guardianRef'] ?? '') === $subjectRef) {
				return (string)($rsvp['response'] ?? null);
			}
		}

		return null;
	}//end myRsvp()

	/**
	 * Fetch every row of a schema in this app's register, unfiltered.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function findAll(string $schema): array {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return [];
		}

		try {
			$objectService->setRegister(register: self::REGISTER);
			$objectService->setSchema(schema: $schema);
			$rows = $objectService->findAll(config: ['filters' => [], 'limit' => 500, 'offset' => 0], _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: event feed read failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
			return [];
		}

		if (is_array($rows) === false) {
			return [];
		}

		$normalised = [];
		foreach ($rows as $row) {
			$row = $this->normalise(row: $row);
			if ($row !== null) {
				$normalised[] = $row;
			}
		}

		return $normalised;
	}//end findAll()

	/**
	 * The row's id/uuid, from a flat property or its `@self` envelope.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private function rowId(array $row): string {
		if (isset($row['id']) === true) {
			return (string)$row['id'];
		}

		if (isset($row['uuid']) === true) {
			return (string)$row['uuid'];
		}

		$self = $row['@self'] ?? [];
		if (is_array($self) === true) {
			return (string)($self['id'] ?? $self['uuid'] ?? '');
		}

		return '';
	}//end rowId()

	/**
	 * Normalise an OpenRegister row (array or object) to an associative array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>|null
	 */
	private function normalise(mixed $row): ?array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$data = $row->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return null;
	}//end normalise()

	/**
	 * Resolve OpenRegister's ObjectService, or null when unavailable.
	 *
	 * @return object|null
	 */
	private function objectService(): ?object {
		try {
			$service = $this->container->get(self::OBJECT_SERVICE);
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($service) === true) {
			return $service;
		}

		return null;
	}//end objectService()
}//end class
