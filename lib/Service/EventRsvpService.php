<?php

/**
 * Event RSVP Service
 *
 * Upserts a guardian's RSVP for one child on one event: a second RSVP for
 * the same guardian+child+event UPDATES the existing record rather than
 * creating a second one. The generic contribution-contract writer's plain
 * merge-update cannot express "find the existing row by a compound key,
 * else create" — hence this dedicated service, the RSVP counterpart to the
 * sibling change's `NewsReadReceiptService`.
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
 * @spec openspec/changes/events-and-signups/specs/portaliq-cms/spec.md#requirement-an-event-is-authored-per-school-group-or-child-with-guardian-rsvp
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @spec openspec/changes/events-and-signups/specs/portaliq-cms/spec.md#requirement-an-event-is-authored-per-school-group-or-child-with-guardian-rsvp
 */
class EventRsvpService {
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	private const REGISTER = 'portaliq';

	private const SCHEMA = 'eventRsvp';

	private const ALLOWED_RESPONSES = ['yes', 'no', 'maybe'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container For resolving OpenRegister services.
	 * @param EventFeedReader $feedReader Re-verifies the event is in the guardian's own audience.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly EventFeedReader $feedReader,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Upsert an RSVP. Returns false for EVERY failure shape (not found,
	 * not in audience, `rsvpEnabled` false, invalid response) — the
	 * controller maps false to a single 404, carrying no existence oracle.
	 *
	 * @param string $subjectRef The guardian's own subjectRef.
	 * @param string $eventId The event id.
	 * @param string $childRef The child the RSVP is for.
	 * @param string $response One of `yes`, `no`, `maybe`.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/events-and-signups/specs/portaliq-cms/spec.md#requirement-an-event-is-authored-per-school-group-or-child-with-guardian-rsvp
	 */
	public function rsvp(string $subjectRef, string $eventId, string $childRef, string $response): bool {
		if ($subjectRef === '' || $eventId === '' || $childRef === '' || in_array($response, self::ALLOWED_RESPONSES, true) === false) {
			return false;
		}

		$event = $this->feedReader->readOwnEvent(subjectRef: $subjectRef, id: $eventId);
		if ($event === null || ($event['rsvpEnabled'] ?? false) !== true) {
			return false;
		}

		$objectService = $this->objectService();
		if ($objectService === null) {
			return false;
		}

		$existingId = $this->findExistingRsvpId(objectService: $objectService, eventId: $eventId, subjectRef: $subjectRef, childRef: $childRef);

		try {
			$objectService->saveObject(
				object: [
					'eventRef' => $eventId,
					'guardianRef' => $subjectRef,
					'childRef' => $childRef,
					'response' => $response,
					'respondedAt' => gmdate('c'),
				],
				register: self::REGISTER,
				schema: self::SCHEMA,
				uuid: $existingId,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: event RSVP save failed', ['reason' => $e->getMessage()]);
			return false;
		}

		return true;
	}//end rsvp()

	/**
	 * The id of an existing RSVP for this guardian+child+event, if any.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $eventId The event id.
	 * @param string $subjectRef The guardian's own subjectRef.
	 * @param string $childRef The child.
	 *
	 * @return string|null
	 */
	private function findExistingRsvpId(object $objectService, string $eventId, string $subjectRef, string $childRef): ?string {
		try {
			$objectService->setRegister(register: self::REGISTER);
			$objectService->setSchema(schema: self::SCHEMA);
			$rows = $objectService->findAll(config: ['filters' => [], 'limit' => 500, 'offset' => 0], _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: event RSVP lookup failed', ['reason' => $e->getMessage()]);
			return null;
		}

		if (is_array($rows) === false) {
			return null;
		}

		foreach ($rows as $row) {
			$normalised = $this->normalise(row: $row);
			if ($normalised === null) {
				continue;
			}

			$matches = (string)($normalised['eventRef'] ?? '') === $eventId
				&& (string)($normalised['guardianRef'] ?? '') === $subjectRef
				&& (string)($normalised['childRef'] ?? '') === $childRef;

			if ($matches === true) {
				return $this->rowId(row: $normalised);
			}
		}

		return null;
	}//end findExistingRsvpId()

	/**
	 * The row's id/uuid, from a flat property or its `@self` envelope.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string|null
	 */
	private function rowId(array $row): ?string {
		if (isset($row['id']) === true) {
			return (string)$row['id'];
		}

		if (isset($row['uuid']) === true) {
			return (string)$row['uuid'];
		}

		$self = $row['@self'] ?? [];
		if (is_array($self) === true && (isset($self['id']) === true || isset($self['uuid']) === true)) {
			return (string)($self['id'] ?? $self['uuid']);
		}

		return null;
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
