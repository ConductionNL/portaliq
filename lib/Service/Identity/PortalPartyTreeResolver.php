<?php

/**
 * Portaliq Portal Party Tree Resolver
 *
 * Which entities a mandate reaches, read from the party relations OpenRegister
 * already holds, at the moment of the request.
 *
 * Portaliq keeps no hierarchy of its own on purpose. A group changes by being
 * recorded as changed, not by somebody remembering to revoke eleven grants: a
 * subsidiary sold this morning is out of the answer this afternoon, and one
 * acquired this morning is in it, with nothing written either way.
 *
 * The walk is bounded in both directions it can run away in: a maximum depth
 * and a page size per level. Past the bound the resolution REFUSES. It does
 * not return the part it managed, because a partial list presented as a
 * complete one is the failure mode worth preventing here: somebody would read
 * it as "the group has no more cases".
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Identity
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
 * @spec openspec/changes/portal-visibility-follows-the-party-tree/specs/portal-visibility-and-the-party-tree/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use OCA\Portaliq\Service\PortalObjectReader;

/**
 * Walks the party tree, bounded, without keeping any of it.
 *
 * @spec openspec/changes/portal-visibility-follows-the-party-tree/specs/portal-visibility-and-the-party-tree/spec.md
 */
class PortalPartyTreeResolver {
	/**
	 * The default maximum depth of the walk.
	 */
	public const DEFAULT_MAX_DEPTH = 4;

	/**
	 * The default number of children read per entity.
	 */
	public const DEFAULT_PAGE_SIZE = 100;

	/**
	 * The register the party relations live in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The schema recording a party.
	 */
	private const SCHEMA = 'organisation';

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader Reads the party relations.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
	) {
	}//end __construct()

	/**
	 * The entities a mandate on `$root` reaches, root included.
	 *
	 * @param string $root The entity the mandate names.
	 * @param bool $reachesDown Whether the mandate reaches below it.
	 * @param array<string, mixed> $bounds `maxDepth` and `pageSize` overrides.
	 *
	 * @return array{entities: array<int, string>, refused: bool, bound: array<string, int>}
	 *         `refused` true means the group is past the bound and NOTHING may
	 *         be listed from it; `entities` is then empty.
	 *
	 * @spec openspec/changes/portal-visibility-follows-the-party-tree/specs/portal-visibility-and-the-party-tree/spec.md
	 */
	public function entitiesFor(string $root, bool $reachesDown, array $bounds = []): array {
		$maxDepth = (int)($bounds['maxDepth'] ?? self::DEFAULT_MAX_DEPTH);
		$pageSize = (int)($bounds['pageSize'] ?? self::DEFAULT_PAGE_SIZE);
		$bound = ['maxDepth' => $maxDepth, 'pageSize' => $pageSize];

		if ($root === '') {
			return ['entities' => [], 'refused' => false, 'bound' => $bound];
		}

		if ($reachesDown === false) {
			// A flat mandate stays flat: no relation is even read.
			return ['entities' => [$root], 'refused' => false, 'bound' => $bound];
		}

		$walked = $this->walkDown(root: $root, maxDepth: $maxDepth, pageSize: $pageSize);
		if ($walked === null) {
			return ['entities' => [], 'refused' => true, 'bound' => $bound];
		}

		return ['entities' => $walked, 'refused' => false, 'bound' => $bound];
	}//end entitiesFor()

	/**
	 * Every entity below a root, or null when the walk hit a bound.
	 *
	 * Hitting a bound is a refusal, never a short answer: a listing that
	 * quietly stopped at the levels that fitted would read as "this is all of
	 * them", and the caller cannot tell the difference.
	 *
	 * @param string $root The entity to walk from.
	 * @param int $maxDepth How deep the walk may go.
	 * @param int $pageSize How many children one level may hold.
	 *
	 * @return array<int, string>|null The entities, or null when refused.
	 *
	 * @spec openspec/changes/portal-visibility-follows-the-party-tree/specs/portal-visibility-and-the-party-tree/spec.md
	 */
	private function walkDown(string $root, int $maxDepth, int $pageSize): ?array {
		$entities = [$root => true];
		$frontier = [$root];
		$depth = 0;
		while ($frontier !== []) {
			$depth++;
			if ($depth > $maxDepth) {
				return null;
			}

			$next = [];
			foreach ($frontier as $parent) {
				$children = $this->childrenOf(parent: $parent, pageSize: $pageSize);
				if (count($children) >= $pageSize) {
					// More children than one page: the level is not fully
					// read, so the answer would be partial.
					return null;
				}

				foreach ($children as $child) {
					if ($child === '' || isset($entities[$child]) === true) {
						// A cycle in the recorded relations would otherwise
						// walk forever; a seen entity is not walked again.
						continue;
					}

					$entities[$child] = true;
					$next[] = $child;
				}
			}

			$frontier = $next;
		}//end while

		return array_keys($entities);
	}//end walkDown()

	/**
	 * The entities recorded directly below one entity.
	 *
	 * Every read carries a limit: there is no unbounded read of the relations
	 * anywhere on this path.
	 *
	 * @param string $parent The entity to read below.
	 * @param int $pageSize The read's limit.
	 *
	 * @return array<int, string>
	 */
	private function childrenOf(string $parent, int $pageSize): array {
		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'parent',
			subjectRef: $parent,
			organisation: '',
			limit: $pageSize
		);

		$children = [];
		foreach ($rows as $row) {
			if (is_array($row) === false || ($row['parent'] ?? '') !== $parent) {
				continue;
			}

			$identifier = (string)($row['slug'] ?? $row['uuid'] ?? $row['id'] ?? '');
			if ($identifier !== '') {
				$children[] = $identifier;
			}
		}

		return $children;
	}//end childrenOf()
}//end class
