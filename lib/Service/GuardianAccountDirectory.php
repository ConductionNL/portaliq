<?php

/**
 * Guardian Account Directory
 *
 * Lists the guardians portaliq can reach: the active portal accounts of the
 * `parent` audience, and which of them a news target reaches by the audience
 * LeafGuardianAudienceReader reads from the school app. The newsletter
 * preflight, the newsletter send check and the emergency push ask "which
 * guardians does this target reach?" through
 * GuardianAudienceFixtureReader::guardiansMatching(), which asks this class
 * for every guardian without a fixture row
 * (guardian-enumeration-from-the-school-app).
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
 * @spec openspec/changes/guardian-enumeration-from-the-school-app/tasks.md#T1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The active guardian accounts, as subjectRefs.
 *
 * @spec openspec/changes/guardian-enumeration-from-the-school-app/tasks.md#T1
 *
 * @SuppressWarnings(PHPMD.StaticAccess) -- NewsAudienceMatcher::matches() is
 * the ONE stateless match predicate the news feed and every enumeration
 * share, so the rule can never fork between them.
 */
class GuardianAccountDirectory {
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	private const REGISTER = 'portaliq';

	private const SCHEMA = 'portalAccount';

	/**
	 * The audience a guardian's portal account carries.
	 */
	private const AUDIENCE = 'parent';

	/**
	 * Rows per page. The listing pages until a short page comes back.
	 */
	private const PAGE = 500;

	/**
	 * A hard stop, so a misbehaving store can never loop forever.
	 */
	private const MAX_PAGES = 40;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container For resolving OpenRegister services.
	 * @param LoggerInterface $logger The logger.
	 * @param LeafGuardianAudienceReader $leafAudience Reads a guardian's audience from the school app.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly LeafGuardianAudienceReader $leafAudience,
	) {
	}//end __construct()

	/**
	 * The active guardians whose school app audience matches a target, by the
	 * same rule the news feed uses. Guardians in `$exclude` are skipped: the
	 * interim fixture already decided them, and a fixture row wins.
	 *
	 * @param array{schoolRef?: string, groupRefs?: array<int, string>, childRefs?: array<int, string>} $target The target.
	 * @param array<int, string> $exclude Guardians not to resolve.
	 *
	 * @return array<int, string> Distinct guardian subjectRefs.
	 *
	 * @spec openspec/changes/guardian-enumeration-from-the-school-app/tasks.md#T2
	 */
	public function guardiansMatching(array $target, array $exclude = []): array {
		// A keyed set: an in_array() per guardian made this quadratic.
		$excluded = array_fill_keys(array_map('strval', $exclude), true);
		$matched = [];
		foreach ($this->activeGuardianRefs() as $guardianRef) {
			if (isset($excluded[$guardianRef]) === true) {
				continue;
			}

			$audience = $this->leafAudience->resolveAudience(subjectRef: $guardianRef);
			if ($audience !== null && NewsAudienceMatcher::matches(target: $target, audience: $audience) === true) {
				$matched[] = $guardianRef;
			}
		}

		return $matched;
	}//end guardiansMatching()

	/**
	 * The distinct subjectRefs of every active `parent` portal account. A
	 * pending or void account cannot sign in or receive a push, so it is not
	 * listed. Fails closed to an empty list when OpenRegister is unavailable.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/guardian-enumeration-from-the-school-app/tasks.md#T1
	 */
	public function activeGuardianRefs(): array {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return [];
		}

		// Keyed by subjectRef, so deduplicating stays linear: an in_array()
		// per row made 20,000 accounts 200 million comparisons.
		$refs = [];
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$rows = $this->page(objectService: $objectService, offset: ($page * self::PAGE));
			foreach ($rows as $row) {
				$ref = $this->activeGuardianRef(row: $row);
				if ($ref !== null) {
					$refs[$ref] = true;
				}
			}

			if (count($rows) < self::PAGE) {
				break;
			}
		}

		return array_map('strval', array_keys($refs));
	}//end activeGuardianRefs()

	/**
	 * One page of active parent accounts.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param int $offset The offset.
	 *
	 * @return array<int, mixed>
	 */
	private function page(object $objectService, int $offset): array {
		try {
			$objectService->setRegister(register: self::REGISTER);
			$objectService->setSchema(schema: self::SCHEMA);
			$rows = $objectService->findAll(
				config: [
					'filters' => ['audience' => self::AUDIENCE, 'status' => 'active'],
					'limit' => self::PAGE,
					'offset' => $offset,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: guardian account listing failed', ['reason' => $e->getMessage()]);
			return [];
		}

		if (is_array($rows) === false) {
			return [];
		}

		return array_values($rows);
	}//end page()

	/**
	 * The row's subjectRef when it is an active parent account, else null.
	 * The filter is checked again here, because a store that ignores a filter
	 * must not turn a supplier into a guardian.
	 *
	 * @param mixed $row The row.
	 *
	 * @return string|null
	 */
	private function activeGuardianRef(mixed $row): ?string {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		if (is_array($row) === false
			|| ($row['audience'] ?? null) !== self::AUDIENCE
			|| ($row['status'] ?? null) !== 'active'
		) {
			return null;
		}

		$ref = ($row['subjectRef'] ?? null);
		if (is_string($ref) === false || $ref === '') {
			return null;
		}

		return $ref;
	}//end activeGuardianRef()

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
