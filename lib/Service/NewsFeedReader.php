<?php

/**
 * News Feed Reader
 *
 * Guardian-facing read path for `newsItem`/`newsletter` objects. Follows the
 * `portal-contribution-contract`'s OWN conventions without routing through
 * its generic engine (3-way school/group/child OR-targeting is not a shape
 * that engine's one-hop `via` join expresses in a single declaration — see
 * design.md "Architecture Overview"): the subject is always supplied by the
 * caller from its OWN validated session, never resolved here; an
 * out-of-audience id and a non-existent id answer IDENTICALLY (no existence
 * oracle); an unresolved audience yields zero rows, never an error.
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
 * @spec openspec/changes/news-and-newsletter-authoring/design.md#architecture-overview
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @spec openspec/changes/news-and-newsletter-authoring/design.md#architecture-overview
 *
 * @SuppressWarnings(PHPMD.StaticAccess) -- NewsAudienceMatcher::matches() is
 * deliberately the ONE stateless match predicate every caller shares (see
 * GuardianAudienceFixtureReader::guardiansMatching()), so the preflight count
 * and this read path can never disagree.
 */
class NewsFeedReader {
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	private const REGISTER = 'portaliq';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container For resolving OpenRegister services.
	 * @param GuardianAudienceFixtureReader $audienceReader Resolves the guardian's own audience.
	 * @param NewsPhotoConsentGate $photoGate Redacts photos per the consent gate.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly GuardianAudienceFixtureReader $audienceReader,
		private readonly NewsPhotoConsentGate $photoGate,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every PUBLISHED `newsItem` in the calling guardian's own resolved
	 * audience. An empty/unresolvable audience yields an empty feed, never an
	 * error (fail-closed empty).
	 *
	 * @param string $subjectRef The guardian's own subjectRef.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/news-and-newsletter-authoring/specs/portaliq-cms/spec.md#requirement-a-newsitem-is-authored-per-school-group-or-child-and-tracks-read-receipts
	 */
	public function feedFor(string $subjectRef): array {
		$audience = $this->audienceReader->resolveAudience(subjectRef: $subjectRef);
		$rows = $this->findAllPublished(schema: 'newsItem');

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

			$matched[] = $this->photoGate->apply(item: $row);
		}

		return $matched;
	}//end feedFor()

	/**
	 * One `newsItem` by id, scoped to the calling guardian's own audience.
	 * Returns null for EVERY failure shape (not found, not published,
	 * out-of-audience) — an identical null in each case, so the caller's 404
	 * carries no existence oracle.
	 *
	 * @param string $subjectRef The guardian's own subjectRef.
	 * @param string $id The newsItem id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/news-and-newsletter-authoring/design.md#api-design
	 */
	public function readOwnItem(string $subjectRef, string $id): ?array {
		if ($id === '') {
			return null;
		}

		$audience = $this->audienceReader->resolveAudience(subjectRef: $subjectRef);
		$rows = $this->findAllPublished(schema: 'newsItem');

		foreach ($rows as $row) {
			if ((string)($this->rowId(row: $row)) !== $id) {
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

			return $this->photoGate->apply(item: $row);
		}

		return null;
	}//end readOwnItem()

	/**
	 * Every SENT (`sentAt` not null) `newsletter` in the guardian's own
	 * resolved audience, most recently sent first.
	 *
	 * @param string $subjectRef The guardian's own subjectRef.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/news-and-newsletter-authoring/specs/portaliq-cms/spec.md#requirement-a-newsletter-composes-existing-news-items-with-an-archive
	 */
	public function archiveFor(string $subjectRef): array {
		$audience = $this->audienceReader->resolveAudience(subjectRef: $subjectRef);
		$rows = $this->findAllPublished(schema: 'newsletter');

		$matched = [];
		foreach ($rows as $row) {
			$sentAt = $row['sentAt'] ?? null;
			if ($sentAt === null || $sentAt === '') {
				continue;
			}

			$target = [];
			if (is_array($row['target'] ?? null) === true) {
				$target = $row['target'];
			}

			if (NewsAudienceMatcher::matches(target: $target, audience: $audience) === false) {
				continue;
			}

			$matched[] = $row;
		}

		usort($matched, static fn (array $a, array $b): int => strcmp((string)($b['sentAt'] ?? ''), (string)($a['sentAt'] ?? '')));

		return $matched;
	}//end archiveFor()

	/**
	 * Fetch every row of a schema in this app's register, unfiltered — the
	 * matching happens in PHP against the already-fetched rows since the
	 * OR-of-three-dimensions target shape is not a filterable OR query
	 * (design.md "Architecture Overview").
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function findAllPublished(string $schema): array {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return [];
		}

		try {
			$objectService->setRegister(register: self::REGISTER);
			$objectService->setSchema(schema: $schema);
			$rows = $objectService->findAll(config: ['filters' => [], 'limit' => 500, 'offset' => 0], _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: news feed read failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
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
	}//end findAllPublished()

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
