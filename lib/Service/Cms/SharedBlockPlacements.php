<?php

/**
 * Portaliq shared block placements (site-shared-page-blocks)
 *
 * The pages that place one shared block, for the block's detail page.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Cms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/site-shared-page-blocks/tasks.md#t06
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

use OCA\Portaliq\Kernel\Contracts\PortalObjectReaderInterface;
use OCA\Portaliq\Kernel\Contracts\SharedBlockPlacementsInterface;
use OCA\Portaliq\Service\CmsRows;
use OCA\Portaliq\Service\RowIdLookup;

/**
 * Lists the pages whose published layout places a shared block.
 *
 * The block is read as the signed-in user, so whoever may not read it gets
 * nothing. Its pages are then read from the portals of the block's own
 * organisation only: a page of another organisation can never place it,
 * because the reader expands a block only on portals of its organisation.
 * Only the published `body` counts, not the `draftBody`: a placement that
 * is still a draft is not on any page yet.
 *
 * @spec openspec/changes/site-shared-page-blocks/tasks.md#t06
 */
class SharedBlockPlacements implements SharedBlockPlacementsInterface {

	/**
	 * The register the CMS content lives in.
	 *
	 * @var string
	 */
	private const REGISTER = 'portaliq';

	/**
	 * Most pages read per portal.
	 *
	 * @var int
	 */
	private const PAGE_LIMIT = 1000;

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReaderInterface $reader Reads the block as the user, and the pages of a portal.
	 * @param CmsRows                     $rows   Reads the portals of an organisation.
	 *
	 * @spec openspec/changes/site-shared-page-blocks/tasks.md#t06
	 */
	public function __construct(
		private readonly PortalObjectReaderInterface $reader,
		private readonly CmsRows $rows,
	) {
	}//end __construct()

	/**
	 * The pages of the block's organisation whose published layout places the block.
	 *
	 * @param string $blockId The shared block's id or uuid.
	 *
	 * @return array<int, array{id: string, title: string, route: string, status: string, portal: string, portalTitle: string}>|null
	 *   The pages, ordered by portal and route; null when the signed-in user cannot read the block.
	 *
	 * @spec openspec/changes/site-shared-page-blocks/tasks.md#t06
	 */
	public function pagesOf(string $blockId): ?array {
		$blockId = trim($blockId);
		$block   = $this->reader->readObjectAsUser(register: self::REGISTER, schema: 'sharedBlock', id: $blockId);
		if ($block === null) {
			return null;
		}

		$organisation = (string)($block['organisation'] ?? '');
		if ($organisation === '') {
			return [];
		}

		$ids   = $this->blockIds(block: $block, requested: $blockId);
		$pages = [];
		foreach ($this->portalsOf(organisation: $organisation) as $portal) {
			foreach ($this->pagesOfPortal(slug: (string)$portal['slug']) as $page) {
				if (self::places(body: ($page['body'] ?? null), blockIds: $ids) === true) {
					$pages[] = $this->summary(page: $page, portal: $portal);
				}
			}
		}

		usort(
			$pages,
			static fn (array $a, array $b): int => [$a['portalTitle'], $a['route']] <=> [$b['portalTitle'], $b['route']]
		);

		return $pages;
	}//end pagesOf()

	/**
	 * Whether a page body places one of the block's ids in its grid.
	 *
	 * @param mixed    $body     The page's published body.
	 * @param string[] $blockIds The ids the block is known by.
	 *
	 * @return bool True when a `sharedBlock` widget of the body names the block.
	 *
	 * @spec openspec/changes/site-shared-page-blocks/tasks.md#t06
	 */
	public static function places(mixed $body, array $blockIds): bool {
		if (is_array($body) === false || is_array($body['widgets'] ?? null) === false) {
			return false;
		}

		foreach ($body['widgets'] as $widget) {
			if (is_array($widget) === false || ($widget['widgetKey'] ?? '') !== 'sharedBlock') {
				continue;
			}

			$placed = trim((string)(($widget['props'] ?? [])['block'] ?? ''));
			if ($placed !== '' && in_array($placed, $blockIds, true) === true) {
				return true;
			}
		}

		return false;
	}//end places()

	/**
	 * The ids the block is known by: the one asked for and its own id and uuid.
	 *
	 * @param array<string, mixed> $block     The block row.
	 * @param string               $requested The id the caller asked for.
	 *
	 * @return string[] The non-empty ids, once each.
	 */
	private function blockIds(array $block, string $requested): array {
		$lookup = new RowIdLookup();
		$ids    = [$requested, (string)($lookup->firstIdOrUuid(row: $block) ?? ''), (string)($lookup->firstUuidOrId(row: $block) ?? '')];

		return array_values(array_unique(array_filter($ids, static fn (string $id): bool => $id !== '')));
	}//end blockIds()

	/**
	 * The portals of an organisation that have a slug, each checked again for the organisation.
	 *
	 * @param string $organisation The block's organisation.
	 *
	 * @return array<int, array<string, mixed>> The portals.
	 */
	private function portalsOf(string $organisation): array {
		return array_values(
			array_filter(
				$this->rows->query(schema: 'portal', filters: ['organisation' => $organisation]),
				static fn (array $portal): bool => (string)($portal['organisation'] ?? '') === $organisation && (string)($portal['slug'] ?? '') !== ''
			)
		);
	}//end portalsOf()

	/**
	 * The pages of one portal, each checked again for the portal.
	 *
	 * @param string $slug The portal's slug.
	 *
	 * @return array<int, array<string, mixed>> The pages.
	 */
	private function pagesOfPortal(string $slug): array {
		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: 'page',
			scopeField: 'portal',
			subjectRef: $slug,
			limit: self::PAGE_LIMIT
		);

		return array_values(
			array_filter($rows, static fn (mixed $page): bool => is_array($page) === true && (string)($page['portal'] ?? '') === $slug)
		);
	}//end pagesOfPortal()

	/**
	 * What the block's detail shows of one page.
	 *
	 * @param array<string, mixed> $page   The page row.
	 * @param array<string, mixed> $portal The page's portal.
	 *
	 * @return array{id: string, title: string, route: string, status: string, portal: string, portalTitle: string}
	 */
	private function summary(array $page, array $portal): array {
		$slug = (string)$portal['slug'];

		return [
			'id'          => (string)((new RowIdLookup())->firstIdOrUuid(row: $page) ?? ''),
			'title'       => (string)($page['title'] ?? ''),
			'route'       => (string)($page['route'] ?? ''),
			'status'      => (string)($page['status'] ?? ''),
			'portal'      => $slug,
			'portalTitle' => (string)($portal['title'] ?? $slug),
		];
	}//end summary()
}//end class
