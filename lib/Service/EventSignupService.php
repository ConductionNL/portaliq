<?php

/**
 * Event Signup Service
 *
 * Guardian sign-up for a volunteer/material role on an event
 * (activiteitenplanner/ouderhulp — findings 9.8). Enforces the role's
 * `capacity` SERVER-SIDE, re-counting current sign-ups immediately before
 * accepting a new one — a disabled button is not a security boundary.
 * Best-effort, not a database-level lock (see design.md Risk 2): a
 * double-booked slot under true concurrent sign-ups is a staff-visible data
 * quality issue, not a security failure.
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
 * @spec openspec/changes/events-and-signups/specs/portaliq-cms/spec.md#requirement-a-sign-up-role-enforces-its-capacity-server-side
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @spec openspec/changes/events-and-signups/specs/portaliq-cms/spec.md#requirement-a-sign-up-role-enforces-its-capacity-server-side
 */
class EventSignupService {
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	private const REGISTER = 'portaliq';

	private const SCHEMA = 'eventSignup';

	public const REASON_ROLE_NOT_FOUND = 'role-not-found';

	public const REASON_ROLE_FULL = 'role-full';

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
	 * Attempt a sign-up. Returns null on success; a reason string on refusal
	 * — the event/role not being reachable by this guardian is
	 * {@see self::REASON_ROLE_NOT_FOUND} (the controller maps that to 404,
	 * no existence oracle), a full role is {@see self::REASON_ROLE_FULL}
	 * (422). Nothing is written on ANY refusal path.
	 *
	 * @param string $subjectRef The guardian's own subjectRef.
	 * @param string $eventId The event id.
	 * @param string $roleId The signup role id.
	 * @param string $childRef The child the sign-up is for (may be empty for an adult-only role).
	 * @param string $note An optional note (e.g. "brings cups").
	 *
	 * @return string|null The refusal reason, or null on success.
	 *
	 * @spec openspec/changes/events-and-signups/specs/portaliq-cms/spec.md#requirement-a-sign-up-role-enforces-its-capacity-server-side
	 */
	public function attemptSignup(string $subjectRef, string $eventId, string $roleId, string $childRef = '', string $note = ''): ?string {
		if ($subjectRef === '' || $eventId === '' || $roleId === '') {
			return self::REASON_ROLE_NOT_FOUND;
		}

		$event = $this->feedReader->readOwnEvent(subjectRef: $subjectRef, id: $eventId);
		if ($event === null) {
			return self::REASON_ROLE_NOT_FOUND;
		}

		$role = $this->findRole(event: $event, roleId: $roleId);
		if ($role === null) {
			return self::REASON_ROLE_NOT_FOUND;
		}

		$objectService = $this->objectService();
		if ($objectService === null) {
			return self::REASON_ROLE_NOT_FOUND;
		}

		$currentCount = $this->countSignups(objectService: $objectService, eventId: $eventId, roleId: $roleId);
		$capacity = (int)($role['capacity'] ?? 0);
		if ($currentCount >= $capacity) {
			return self::REASON_ROLE_FULL;
		}

		try {
			$objectService->saveObject(
				object: [
					'eventRef' => $eventId,
					'roleId' => $roleId,
					'guardianRef' => $subjectRef,
					'childRef' => $childRef,
					'note' => $note,
					'signedUpAt' => gmdate('c'),
				],
				register: self::REGISTER,
				schema: self::SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: event signup save failed', ['reason' => $e->getMessage()]);
			return self::REASON_ROLE_NOT_FOUND;
		}

		return null;
	}//end attemptSignup()

	/**
	 * Find a declared role on an event by id.
	 *
	 * @param array<string, mixed> $event The event row.
	 * @param string $roleId The role id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findRole(array $event, string $roleId): ?array {
		$roles = $event['signupRoles'] ?? [];
		if (is_array($roles) === false) {
			return null;
		}

		foreach ($roles as $role) {
			if (is_array($role) === true && (string)($role['id'] ?? '') === $roleId) {
				return $role;
			}
		}

		return null;
	}//end findRole()

	/**
	 * Count existing sign-ups for one role on one event, immediately before
	 * accepting a new one.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $eventId The event id.
	 * @param string $roleId The role id.
	 *
	 * @return int
	 */
	private function countSignups(object $objectService, string $eventId, string $roleId): int {
		try {
			$objectService->setRegister(register: self::REGISTER);
			$objectService->setSchema(schema: self::SCHEMA);
			$rows = $objectService->findAll(config: ['filters' => [], 'limit' => 500, 'offset' => 0], _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: event signup count failed', ['reason' => $e->getMessage()]);
			// Fail closed to "no room" rather than allowing an unbounded
			// signup when the count itself cannot be trusted.
			return PHP_INT_MAX;
		}

		if (is_array($rows) === false) {
			return PHP_INT_MAX;
		}

		$count = 0;
		foreach ($rows as $row) {
			$normalised = $this->normalise(row: $row);
			if ($normalised === null) {
				continue;
			}

			if ((string)($normalised['eventRef'] ?? '') === $eventId && (string)($normalised['roleId'] ?? '') === $roleId) {
				$count++;
			}
		}

		return $count;
	}//end countSignups()

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
