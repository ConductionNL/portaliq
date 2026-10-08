<?php

/**
 * Public News Reader
 *
 * The news items staff put on one portal's public website (site-school-blocks).
 *
 * A `newsItem` is written for an audience: a school, groups or single
 * children, and a guardian reads it only when that audience matches their
 * own (NewsFeedReader). The public website is a second, explicit outlet: an
 * item shows there only when staff turned `public` on AND named this portal.
 * Nothing about the item's audience is resolved for a visitor who is not
 * signed in; the website shows the staff-written `audienceLabel` instead.
 *
 * What a visitor never gets:
 * - an item that is a draft, not public, or for another portal;
 * - an item that targets single children, whatever `public` says: news about
 *   one child is never website news;
 * - a photo that is not a published item of this portal's own media library
 *   (`media:<id>`): a photo reference to anywhere else could carry a child
 *   whose photo consent was never asked for the website;
 * - the target, the author, the read receipts or the translations.
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
 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-item-shows-on-a-portals-public-website-only-when-staff-put-it-there
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Service\Cms\MediaReferences;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads the public news of one portal.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) -- MediaReferences::isReference is the one test of a media
 * reference the CMS uses; asking it statically keeps the rule in one place.
 *
 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-item-shows-on-a-portals-public-website-only-when-staff-put-it-there
 */
class PublicNewsReader {
	/**
	 * The most items one list returns.
	 */
	public const MAX_LIMIT = 12;

	/**
	 * The most items the catalogue reads (portal-public-catalogue).
	 */
	public const ALL_LIMIT = 200;

	/**
	 * The longest intro, in characters.
	 */
	private const INTRO_LENGTH = 280;

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
	 * @param MediaReferences    $media     Resolves `media:<id>` against the portal's published library.
	 * @param LoggerInterface    $logger    The logger.
	 */
	public function __construct(
		ContainerInterface $container,
		private readonly MediaReferences $media,
		LoggerInterface $logger,
	) {
		$this->rows = new NewsRowSource(container: $container, logger: $logger);
	}//end __construct()

	/**
	 * Every public item of a portal, newest first, at most ALL_LIMIT: the
	 * news half of the public catalogue (portal-public-catalogue).
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<int, array<string, mixed>> The summaries.
	 *
	 * @spec openspec/changes/portal-public-catalogue/specs/portal-public-catalogue/spec.md#requirement-a-visitor-may-search-and-filter-a-portals-public-catalogue
	 */
	public function allFor(string $portal): array {
		$items = array_slice($this->publicRows(portal: $portal), 0, self::ALL_LIMIT);

		return array_map(fn (array $row): array => $this->summary(portal: $portal, row: $row), $items);
	}//end allFor()

	/**
	 * The newest public items of a portal, newest first.
	 *
	 * @param string $portal The portal slug.
	 * @param int    $limit  How many, clamped to 1..MAX_LIMIT.
	 *
	 * @return array<int, array<string, mixed>> Each `{id, title, intro, publishedAt, audienceLabel, image}`.
	 *
	 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-item-shows-on-a-portals-public-website-only-when-staff-put-it-there
	 */
	public function listFor(string $portal, int $limit): array {
		$limit = max(1, min(self::MAX_LIMIT, $limit));
		$items = array_slice($this->publicRows(portal: $portal), 0, $limit);

		return array_map(fn (array $row): array => $this->summary(portal: $portal, row: $row), $items);
	}//end listFor()

	/**
	 * One public item of a portal, with its body, or null when there is no
	 * such public item.
	 *
	 * @param string $portal The portal slug.
	 * @param string $id     The item id.
	 *
	 * @return array<string, mixed>|null The summary plus `body` (markdown), or null.
	 *
	 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-item-shows-on-a-portals-public-website-only-when-staff-put-it-there
	 */
	public function itemFor(string $portal, string $id): ?array {
		if ($id === '') {
			return null;
		}

		foreach ($this->publicRows(portal: $portal) as $row) {
			if ($this->rows->rowId(row: $row) === $id) {
				$body = $this->media->markdown(portal: $portal, markdown: (string)($row['body'] ?? ''));

				$item = $this->summary(portal: $portal, row: $row) + ['body' => $body];
				$event = $this->eventOf(row: $row);
				if ($event !== null) {
					$item['event'] = $event;
				}

				return $item;
			}
		}

		return null;
	}//end itemFor()

	/**
	 * The facts of the event a news item refers to, or null. Only a
	 * published event is shown, and only its dates, place, deadline and
	 * seats: its audience and its answers stay with the school.
	 *
	 * @param array<string, mixed> $row The news item.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/event-sign-up-by-a-pupil-with-seats/specs/portaliq-cms/spec.md#requirement-a-news-item-may-carry-the-sign-up-of-its-event
	 */
	private function eventOf(array $row): ?array {
		$ref = trim((string)($row['eventRef'] ?? ''));
		if ($ref === '') {
			return null;
		}

		foreach ($this->rows->findAll(schema: 'schoolEvent', filters: ['status' => 'published']) as $event) {
			if (($event['status'] ?? '') !== 'published' || $this->rows->rowId(row: $event) !== $ref) {
				continue;
			}

			$deadline = trim((string)($event['signupDeadline'] ?? ''));

			return [
				'id'                => $ref,
				'title'             => (string)($event['title'] ?? ''),
				'start'             => (string)($event['start'] ?? ''),
				'end'               => (string)($event['end'] ?? ''),
				'location'          => trim((string)($event['location'] ?? '')),
				'signupDeadline'    => $deadline,
				'closed'            => ($event['rsvpEnabled'] ?? false) !== true || EventDeadline::hasPassed(deadline: $deadline),
				'askSeats'          => ($event['askSeats'] ?? false) === true,
				'maxSeatsPerAnswer' => (int)($event['maxSeatsPerAnswer'] ?? 4),
			];
		}

		return null;
	}//end eventOf()

	/**
	 * Whether a stored row may show on a portal's website.
	 *
	 * @param array<string, mixed> $row    The stored row.
	 * @param string               $portal The portal slug.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-item-shows-on-a-portals-public-website-only-when-staff-put-it-there
	 */
	public static function isPublicOn(array $row, string $portal): bool {
		if ($portal === '' || ($row['status'] ?? '') !== 'published' || ($row['public'] ?? false) !== true) {
			return false;
		}

		if ((string)($row['portal'] ?? '') !== $portal) {
			return false;
		}

		$children = ($row['target']['childRefs'] ?? []);

		return is_array($children) === false || count($children) === 0;
	}//end isPublicOn()

	/**
	 * The public rows of a portal, newest first.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function publicRows(string $portal): array {
		$rows = array_values(
			array_filter(
				$this->rows->findAll(schema: 'newsItem'),
				static fn (array $row): bool => self::isPublicOn(row: $row, portal: $portal)
			)
		);
		usort($rows, fn (array $left, array $right): int => $this->momentOf(row: $right) <=> $this->momentOf(row: $left));

		return $rows;
	}//end publicRows()

	/**
	 * What a visitor may see of one row.
	 *
	 * @param string               $portal The portal slug.
	 * @param array<string, mixed> $row    The stored row.
	 *
	 * @return array<string, mixed>
	 */
	private function summary(string $portal, array $row): array {
		$moment    = $this->momentOf(row: $row);
		$published = '';
		if ($moment !== PHP_INT_MIN) {
			$published = gmdate('c', $moment);
		}

		return [
			'id'            => $this->rows->rowId(row: $row),
			'title'         => (string)($row['title'] ?? ''),
			'intro'         => self::introOf(body: (string)($row['body'] ?? '')),
			'publishedAt'   => $published,
			'audienceLabel' => trim((string)($row['audienceLabel'] ?? '')),
			'image'         => $this->imageOf(portal: $portal, row: $row),
		];
	}//end summary()

	/**
	 * The first photo that is a published item of this portal's library.
	 *
	 * @param string               $portal The portal slug.
	 * @param array<string, mixed> $row    The stored row.
	 *
	 * @return array{url: string, alt: string}|null
	 */
	private function imageOf(string $portal, array $row): ?array {
		foreach ((array)($row['photoRefs'] ?? []) as $ref) {
			if (MediaReferences::isReference(value: $ref) === false) {
				continue;
			}

			$image = $this->media->hero(portal: $portal, value: $ref);
			if ($image !== null) {
				return $image;
			}
		}

		return null;
	}//end imageOf()

	/**
	 * The first paragraph of a markdown body as plain text, cut on a word.
	 *
	 * @param string $body The markdown body.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-item-shows-on-a-portals-public-website-only-when-staff-put-it-there
	 */
	public static function introOf(string $body): string {
		$paragraphs = preg_split('/\R\s*\R/', trim($body));
		$first = (string)($paragraphs[0] ?? '');
		// Links keep their text, images go, and the marks an author types go.
		$first = (string)preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $first);
		$first = (string)preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $first);
		$first = (string)preg_replace('/^#+\s*/m', '', $first);
		$first = str_replace(['**', '__', '`'], '', $first);
		$first = trim((string)preg_replace('/\s+/', ' ', $first));

		if (mb_strlen($first) <= self::INTRO_LENGTH) {
			return $first;
		}

		$cut = mb_substr($first, 0, self::INTRO_LENGTH);
		$space = mb_strrpos($cut, ' ');
		if ($space !== false) {
			$cut = mb_substr($cut, 0, $space);
		}

		return rtrim($cut, ' ,.;:').'…';
	}//end introOf()

	/**
	 * When an item was published, as a Unix timestamp; PHP_INT_MIN when undated.
	 * The same order as the guardian feed: `publishedAt`, else OpenRegister's
	 * `@self.published`, else `@self.created`.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return int
	 */
	private function momentOf(array $row): int {
		$self = [];
		if (is_array($row['@self'] ?? null) === true) {
			$self = $row['@self'];
		}

		foreach ([($row['publishedAt'] ?? null), ($self['published'] ?? null), ($self['created'] ?? null)] as $value) {
			if (is_string($value) === false || $value === '') {
				continue;
			}

			$time = strtotime($value);
			if ($time !== false) {
				return $time;
			}
		}

		return PHP_INT_MIN;
	}//end momentOf()
}//end class
