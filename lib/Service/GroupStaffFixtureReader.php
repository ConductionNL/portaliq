<?php

/**
 * Group Staff Fixture Reader
 *
 * INTERIM stand-in for "which staff teach which group" — the counterpart to
 * `GuardianAudienceFixtureReader` needed so a guardian can only start a
 * thread with a teacher who actually teaches their child's group (proposal.md
 * Risk 1). Backed by the `groupStaffFixture` schema (register `portaliq`);
 * retired together with the guardian-audience fixture once learniq's real
 * staff-assignment data ships.
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
 * @spec openspec/changes/guardian-direct-messages/design.md#participation-model
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @spec openspec/changes/guardian-direct-messages/design.md#participation-model
 */
class GroupStaffFixtureReader {
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	private const REGISTER = 'portaliq';

	private const SCHEMA = 'groupStaffFixture';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container For resolving OpenRegister services.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The staff subjectRefs who teach a group. Fail-closed EMPTY when the
	 * fixture has no row for this group or OpenRegister is unavailable.
	 *
	 * @param string $groupRef The group.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/guardian-direct-messages/design.md#participation-model
	 */
	public function staffForGroup(string $groupRef): array {
		if ($groupRef === '') {
			return [];
		}

		$objectService = $this->objectService();
		if ($objectService === null) {
			return [];
		}

		try {
			$objectService->setRegister(register: self::REGISTER);
			$objectService->setSchema(schema: self::SCHEMA);
			$rows = $objectService->findAll(
				config: ['filters' => ['groupRef' => $groupRef], 'limit' => 1, 'offset' => 0],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: group staff fixture read failed', ['reason' => $e->getMessage()]);
			return [];
		}

		if (is_array($rows) === false || count($rows) === 0) {
			return [];
		}

		$row = $this->normalise(row: $rows[0]);
		if ($row === null) {
			return [];
		}

		return $this->stringList(value: $row['staffRefs'] ?? []);
	}//end staffForGroup()

	/**
	 * Whether a staff member teaches a group.
	 *
	 * @param string $staffRef The staff subjectRef.
	 * @param string $groupRef The group.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/guardian-direct-messages/design.md#participation-model
	 */
	public function staffTeachesGroup(string $staffRef, string $groupRef): bool {
		if ($staffRef === '') {
			return false;
		}

		return in_array($staffRef, $this->staffForGroup(groupRef: $groupRef), true);
	}//end staffTeachesGroup()

	/**
	 * Every group a staff member teaches — scans every fixture row.
	 *
	 * @param string $staffRef The staff subjectRef.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/guardian-direct-messages/design.md#participation-model
	 */
	public function groupsTaughtBy(string $staffRef): array {
		if ($staffRef === '') {
			return [];
		}

		$objectService = $this->objectService();
		if ($objectService === null) {
			return [];
		}

		try {
			$objectService->setRegister(register: self::REGISTER);
			$objectService->setSchema(schema: self::SCHEMA);
			$rows = $objectService->findAll(config: ['filters' => [], 'limit' => 500, 'offset' => 0], _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: group staff fixture enumeration failed', ['reason' => $e->getMessage()]);
			return [];
		}

		if (is_array($rows) === false) {
			return [];
		}

		$groups = [];
		foreach ($rows as $row) {
			$normalised = $this->normalise(row: $row);
			if ($normalised === null) {
				continue;
			}

			$staffRefs = $this->stringList(value: $normalised['staffRefs'] ?? []);
			$groupRef = (string)($normalised['groupRef'] ?? '');
			if ($groupRef !== '' && in_array($staffRef, $staffRefs, true) === true) {
				$groups[] = $groupRef;
			}
		}

		return $groups;
	}//end groupsTaughtBy()

	/**
	 * Coerce a value to a list of strings, dropping anything else.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return array<int, string>
	 */
	private function stringList(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$list = [];
		foreach ($value as $item) {
			if (is_string($item) === true && $item !== '') {
				$list[] = $item;
			}
		}

		return $list;
	}//end stringList()

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
