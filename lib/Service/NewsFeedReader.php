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

use OCA\Portaliq\Service\Messaging\GuardianMessageTranslator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/changes/news-and-newsletter-authoring/design.md#architecture-overview
 *
 * @SuppressWarnings(PHPMD.StaticAccess) -- NewsAudienceMatcher::matches() is
 * deliberately the ONE stateless match predicate every caller shares (see
 * GuardianAudienceFixtureReader::guardiansMatching()), so the preflight count
 * and this read path can never disagree.
 */
class NewsFeedReader {
	/**
	 * The OpenRegister read of news rows.
	 *
	 * @var NewsRowSource
	 */
	private readonly NewsRowSource $rows;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container For resolving OpenRegister services.
	 * @param GuardianAudienceFixtureReader $audienceReader Resolves the guardian's own audience.
	 * @param NewsPhotoConsentGate $photoGate Redacts photos per the consent gate.
	 * @param LoggerInterface $logger The logger.
	 * @param GuardianMessageTranslator|null $translator Shows a news body in the reader's
	 *                                                   language (news-item-translation).
	 *                                                   Nullable and trailing so a reader
	 *                                                   built by hand keeps its old shape.
	 */
	public function __construct(
		ContainerInterface $container,
		private readonly GuardianAudienceFixtureReader $audienceReader,
		private readonly NewsPhotoConsentGate $photoGate,
		LoggerInterface $logger,
		private readonly ?GuardianMessageTranslator $translator = null,
	) {
		$this->rows = new NewsRowSource(container: $container, logger: $logger);
	}//end __construct()

	/**
	 * Every PUBLISHED `newsItem` in the calling guardian's own resolved
	 * audience. An empty/unresolvable audience yields an empty feed, never an
	 * error (fail-closed empty).
	 *
	 * With a `$language` the body of each item is shown in it: a stored AI
	 * translation is reused, at most three new ones are made per request and
	 * stored on the item, and the item carries `translation` (decision D24).
	 * Translation runs on the STORED rows, before the photo consent gate
	 * redacts the reader's copy, so a stored translation never drops a photo.
	 *
	 * @param string $subjectRef The guardian's own subjectRef.
	 * @param string $language The reader's `messageLanguage`, '' for as written.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/news-and-newsletter-authoring/specs/portaliq-cms/spec.md#requirement-a-newsitem-is-authored-per-school-group-or-child-and-tracks-read-receipts
	 * @spec openspec/changes/news-item-translation/specs/guardian-message-translation/spec.md#requirement-a-news-item-keeps-its-ai-translations-next-to-the-original
	 * @spec openspec/changes/news-title-and-newsletter-translation/specs/guardian-message-translation/spec.md#requirement-a-news-title-is-translated-with-its-body
	 */
	public function feedFor(string $subjectRef, string $language = ''): array {
		$audience = $this->audienceReader->resolveAudience(subjectRef: $subjectRef);
		$matched  = $this->translated(items: $this->itemsFor(audience: $audience), subjectRef: $subjectRef, language: $language);

		return array_map(fn (array $row): array => $this->photoGate->apply(item: $row), $matched);
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
		foreach ($this->itemsFor(audience: $audience) as $row) {
			if ($this->rows->rowId(row: $row) === $id) {
				return $this->photoGate->apply(item: $row);
			}
		}

		return null;
	}//end readOwnItem()

	/**
	 * Every SENT (`sentAt` not null) `newsletter` in the guardian's own
	 * resolved audience, most recently sent first.
	 *
	 * Each newsletter carries `items`: the news items it references that are
	 * published and in the reader's audience, translated and photo-gated
	 * exactly as the feed serves them, so the archive shows the same
	 * translation and notice as the News page.
	 *
	 * @param string $subjectRef The guardian's own subjectRef.
	 * @param string $language The reader's `messageLanguage`, '' for as written.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/news-and-newsletter-authoring/specs/portaliq-cms/spec.md#requirement-a-newsletter-composes-existing-news-items-with-an-archive
	 * @spec openspec/changes/news-title-and-newsletter-translation/specs/guardian-message-translation/spec.md#requirement-the-newsletter-archive-shows-its-items-as-the-news-page-does
	 */
	public function archiveFor(string $subjectRef, string $language = ''): array {
		$audience = $this->audienceReader->resolveAudience(subjectRef: $subjectRef);
		$rows = $this->rows->findAll(schema: 'newsletter');

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

		return $this->withItems(newsletters: $matched, audience: $audience, subjectRef: $subjectRef, language: $language);
	}//end archiveFor()

	/**
	 * Each newsletter with the items it references, in its order. One
	 * translation pass covers every item of the archive, so the per-request
	 * bound holds across newsletters.
	 *
	 * @param array<int, array<string, mixed>> $newsletters The sent newsletters in the reader's audience.
	 * @param array<string, mixed> $audience The reader's audience.
	 * @param string $subjectRef The reader.
	 * @param string $language The reader's language.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function withItems(array $newsletters, array $audience, string $subjectRef, string $language): array {
		$referenced = [];
		foreach ($newsletters as $newsletter) {
			foreach ((array)($newsletter['itemRefs'] ?? []) as $ref) {
				$referenced[(string)$ref] = true;
			}
		}

		$items = [];
		foreach ($this->itemsFor(audience: $audience) as $item) {
			if (isset($referenced[$this->rows->rowId(row: $item)]) === true) {
				$items[] = $item;
			}
		}

		$byId = [];
		foreach ($this->translated(items: $items, subjectRef: $subjectRef, language: $language) as $item) {
			$byId[$this->rows->rowId(row: $item)] = $this->photoGate->apply(item: $item);
		}

		foreach ($newsletters as $index => $newsletter) {
			$newsletters[$index]['items'] = [];
			foreach ((array)($newsletter['itemRefs'] ?? []) as $ref) {
				if (isset($byId[(string)$ref]) === true) {
					$newsletters[$index]['items'][] = $byId[(string)$ref];
				}
			}
		}

		return $newsletters;
	}//end withItems()

	/**
	 * The published news items in the reader's audience, as stored.
	 *
	 * @param array<string, mixed> $audience The reader's audience.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function itemsFor(array $audience): array {
		$matched = [];
		foreach ($this->rows->findAll(schema: 'newsItem') as $row) {
			if (($row['status'] ?? '') !== 'published') {
				continue;
			}

			$target = [];
			if (is_array($row['target'] ?? null) === true) {
				$target = $row['target'];
			}

			if (NewsAudienceMatcher::matches(target: $target, audience: $audience) === true) {
				$matched[] = $row;
			}
		}

		return $matched;
	}//end itemsFor()

	/**
	 * The items in the reader's language, title and body in one entry. Runs on
	 * the stored rows, before the photo gate, so a translation write keeps the
	 * photos the reader's copy withholds.
	 *
	 * @param array<int, array<string, mixed>> $items The stored rows.
	 * @param string $subjectRef The reader.
	 * @param string $language The reader's language, '' for as written.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function translated(array $items, string $subjectRef, string $language): array {
		if ($this->translator === null || $language === '') {
			return $items;
		}

		return $this->translator->forReader(messages: $items, readerRef: $subjectRef, language: $language, schema: 'newsItem', titleField: 'title');
	}//end translated()

}//end class
