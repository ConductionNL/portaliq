<?php

/**
 * Leaf Guardian Audience Reader
 *
 * The real audience source behind GuardianAudienceFixtureReader's seam
 * (openspec/changes/news-and-newsletter-authoring/design.md "Audience source
 * seam"): a school app that serves the `parent` audience declares, in its
 * contribution, which of its own collections name the guardian's children
 * and their groups:
 *
 *     'guardianAudience' => [
 *         'children' => 'parentChildren',      // a collection id; row ids are the children
 *         'schoolField' => 'schoolId',         // the child row field naming the school
 *         'groups' => ['collection' => 'parentGroupMemberships', 'field' => 'cohortId'],
 *     ]
 *
 * This class reads those collections for the guardian, through the SAME
 * subject-scoped reader every portal collection uses (scopeClaim, via,
 * per-row verification), and answers the audience shape the news, the
 * activity feed and the messages match against. Found testing a primary
 * school: the fixture held no row for a real guardian, so a school-wide news
 * item never reached any parent.
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
 * @spec openspec/changes/news-audience-from-the-school-app/tasks.md#T1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves a guardian's audience from the school app's own collections.
 *
 * @spec openspec/changes/news-audience-from-the-school-app/tasks.md#T1
 */
class LeafGuardianAudienceReader {

	/**
	 * The audience a guardian signs in as.
	 */
	private const AUDIENCE = 'parent';

	/**
	 * The trust level the audience lookup reads at. The lookup only decides
	 * which news a signed-in guardian matches; it never returns the rows it
	 * reads, and the news endpoints check the guardian's own session.
	 */
	private const LOOKUP_TRUST = 'substantial';

	/**
	 * Constructor.
	 *
	 * @param PortalContributionRegistry $registry The contributions for the parent audience.
	 * @param PortalObjectReader $reader The subject-scoped collection reader.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly PortalContributionRegistry $registry,
		private readonly PortalObjectReader $reader,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The guardian's audience from every contribution that declares a
	 * `guardianAudience`, or null when none does or none resolves.
	 *
	 * @param string $subjectRef The guardian's own subjectRef.
	 *
	 * @return array<string, mixed>|null The audience (schoolRef, groupRefs, childRefs, photoConsent), or null.
	 *
	 * @spec openspec/changes/news-audience-from-the-school-app/tasks.md#T1
	 */
	public function resolveAudience(string $subjectRef): ?array {
		if ($subjectRef === '') {
			return null;
		}

		$audience = ['schoolRef' => '', 'groupRefs' => [], 'childRefs' => [], 'photoConsent' => []];
		foreach ($this->declaringContributions(subjectRef: $subjectRef) as $contribution) {
			$audience = $this->merge(audience: $audience, contribution: $contribution, subjectRef: $subjectRef);
		}

		if ($audience['childRefs'] === []) {
			return null;
		}

		$audience['groupRefs'] = array_values(array_unique($audience['groupRefs']));
		$audience['childRefs'] = array_values(array_unique($audience['childRefs']));

		return $audience;
	}//end resolveAudience()

	/**
	 * The parent-audience contributions that declare a `guardianAudience`.
	 *
	 * @param string $subjectRef The guardian's own subjectRef.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function declaringContributions(string $subjectRef): array {
		$subject = ['subjectRef' => $subjectRef, 'audience' => self::AUDIENCE, 'organisation' => '', 'trust' => self::LOOKUP_TRUST];
		try {
			$aggregate = $this->registry->aggregateFor(subject: $subject);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: guardian audience lookup failed', ['reason' => $e->getMessage()]);
			return [];
		}

		return array_values(
			array_filter(
				(array)($aggregate['contributions'] ?? []),
				static fn ($contribution): bool => is_array($contribution['guardianAudience'] ?? null) === true
			)
		);
	}//end declaringContributions()

	/**
	 * Add one contribution's children, school and groups to the audience.
	 * No purpose the school app declares maps onto "news" yet, so photo
	 * consent stays empty and the photo gate keeps failing closed.
	 *
	 * @param array<string, mixed> $audience The audience so far.
	 * @param array<string, mixed> $contribution The declaring contribution.
	 * @param string $subjectRef The guardian's own subjectRef.
	 *
	 * @return array<string, mixed> The audience with this contribution added.
	 */
	private function merge(array $audience, array $contribution, string $subjectRef): array {
		$declaration = $contribution['guardianAudience'];
		$schoolField = (string)($declaration['schoolField'] ?? '');
		foreach ($this->rows(contribution: $contribution, collectionId: (string)($declaration['children'] ?? ''), subjectRef: $subjectRef) as $child) {
			$audience['childRefs'] = array_merge($audience['childRefs'], $this->ids(row: $child));
			if ($audience['schoolRef'] === '' && is_string($child[$schoolField] ?? null) === true) {
				$audience['schoolRef'] = $child[$schoolField];
			}
		}

		$groups = (array)($declaration['groups'] ?? []);
		$groupField = (string)($groups['field'] ?? '');
		foreach ($this->rows(contribution: $contribution, collectionId: (string)($groups['collection'] ?? ''), subjectRef: $subjectRef) as $row) {
			if (is_string($row[$groupField] ?? null) === true && $row[$groupField] !== '') {
				$audience['groupRefs'][] = $row[$groupField];
			}
		}

		return $audience;
	}//end merge()

	/**
	 * The guardian's rows of one declared collection, unprojected.
	 *
	 * @param array<string, mixed> $contribution The contribution.
	 * @param string $collectionId The collection id.
	 * @param string $subjectRef The guardian's own subjectRef.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(array $contribution, string $collectionId, string $subjectRef): array {
		if ($collectionId === '') {
			return [];
		}

		foreach ((array)($contribution['collections'] ?? []) as $collection) {
			if (($collection['id'] ?? null) !== $collectionId) {
				continue;
			}

			return $this->reader->readCollection(
				register: (string)($collection['register'] ?? ''),
				schema: (string)($collection['schema'] ?? ''),
				scopeField: (string)($collection['scopeField'] ?? 'subjectRef'),
				subjectRef: $subjectRef,
				organisation: '',
				limit: 200,
				scopeClaim: (string)($collection['scopeClaim'] ?? ''),
				contributingApp: (string)($contribution['app'] ?? ''),
				via: ($collection['via'] ?? null),
				audience: self::AUDIENCE,
				fields: null,
				filter: (array)($collection['filter'] ?? [])
			);
		}

		return [];
	}//end rows()

	/**
	 * A row's own identifiers.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array<int, string>
	 */
	private function ids(array $row): array {
		$id = ($row['id'] ?? ($row['uuid'] ?? ($row['@self']['id'] ?? null)));
		if (is_string($id) === false || $id === '') {
			return [];
		}

		return [$id];
	}//end ids()
}//end class
