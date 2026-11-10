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
	use ObjectRowNormaliser;
	use ObjectServiceLookup;

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

		return (new NonEmptyStrings())->from(value: $row['staffRefs'] ?? []);
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

		$rows = (new FixtureRowReader(objectService: $this->objectService(), logger: $this->logger))->everyRow(
			source: ['register' => self::REGISTER, 'schema' => self::SCHEMA],
			failure: 'Portaliq: group staff fixture enumeration failed'
		);
		if ($rows === null) {
			return [];
		}

		return $this->groupsOf(rows: $rows, staffRef: $staffRef);
	}//end groupsTaughtBy()

	/**
	 * The groups of the fixture rows that list the staff member.
	 *
	 * @param array<int, mixed> $rows     The fixture rows.
	 * @param string            $staffRef The staff subjectRef.
	 *
	 * @return array<int, string>
	 */
	private function groupsOf(array $rows, string $staffRef): array {
		$groups = [];
		foreach ($rows as $row) {
			$normalised = $this->normalise(row: $row);
			if ($normalised === null) {
				continue;
			}

			$staffRefs = (new NonEmptyStrings())->from(value: $normalised['staffRefs'] ?? []);
			$groupRef = (string)($normalised['groupRef'] ?? '');
			if ($groupRef !== '' && in_array($staffRef, $staffRefs, true) === true) {
				$groups[] = $groupRef;
			}
		}

		return $groups;
	}//end groupsOf()
}//end class
