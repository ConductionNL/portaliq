<?php

/**
 * Guardian Audience Fixture Reader
 *
 * INTERIM stand-in for learniq's `portal-contribution-guardian-audiences`
 * change (another lane, in flight — shared shape with the sibling
 * `news-and-newsletter-authoring`/`events-and-signups` changes, same lane).
 * Resolves a guardian's own school/group/child audience from the
 * `guardianAudienceFixture` schema (register `portaliq`). This change only
 * needs the group-membership half (`groupRefs`) to check whether a guardian
 * may participate in a group message thread; the photo-consent and
 * newsletter-enumeration methods the sibling changes add are NOT needed
 * here and are intentionally omitted to keep this class's surface matched
 * to what `guardian-direct-messages` actually uses.
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
class GuardianAudienceFixtureReader {
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	private const REGISTER = 'portaliq';

	private const SCHEMA = 'guardianAudienceFixture';

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
	 * Resolve one guardian's audience. Fail-closed EMPTY (never an error,
	 * never throws) when the fixture has no row for this subject or
	 * OpenRegister is unavailable.
	 *
	 * @param string $subjectRef The guardian's own subjectRef.
	 *
	 * @return array{schoolRef: string, groupRefs: array<int, string>, childRefs: array<int, string>}
	 *
	 * @spec openspec/changes/guardian-direct-messages/design.md#participation-model
	 */
	public function resolveAudience(string $subjectRef): array {
		$empty = ['schoolRef' => '', 'groupRefs' => [], 'childRefs' => []];

		if ($subjectRef === '') {
			return $empty;
		}

		$objectService = $this->objectService();
		if ($objectService === null) {
			return $empty;
		}

		try {
			$objectService->setRegister(register: self::REGISTER);
			$objectService->setSchema(schema: self::SCHEMA);
			$rows = $objectService->findAll(
				config: ['filters' => ['guardianRef' => $subjectRef], 'limit' => 1, 'offset' => 0],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: guardian audience fixture read failed', ['reason' => $e->getMessage()]);
			return $empty;
		}

		if (is_array($rows) === false || count($rows) === 0) {
			return $empty;
		}

		$row = $this->normalise(row: $rows[0]);
		if ($row === null) {
			return $empty;
		}

		return [
			'schoolRef' => (string)($row['schoolRef'] ?? ''),
			'groupRefs' => $this->stringList(value: $row['groupRefs'] ?? []),
			'childRefs' => $this->stringList(value: $row['childRefs'] ?? []),
		];
	}//end resolveAudience()

	/**
	 * Whether a guardian's own audience includes a group.
	 *
	 * @param string $subjectRef The guardian's own subjectRef.
	 * @param string $groupRef The group to check.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/guardian-direct-messages/design.md#participation-model
	 */
	public function guardianReachesGroup(string $subjectRef, string $groupRef): bool {
		if ($groupRef === '') {
			return false;
		}

		return in_array($groupRef, $this->resolveAudience(subjectRef: $subjectRef)['groupRefs'], true);
	}//end guardianReachesGroup()

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
