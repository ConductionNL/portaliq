<?php

/**
 * Portaliq Start Tile Collector (site-nlds-widget-palette, design D6)
 *
 * Gathers the start tiles of the serving portal from normalised
 * contributions: every action that declares a `summary` and that a page of
 * its contribution offers. A tile carries the action's label, its summary,
 * its audiences and the in-site route of that page, and nothing else: no
 * field names, endpoints or data, because the list is public.
 *
 * @category Contribution
 * @package  OCA\Portaliq\Contribution
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
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-contribution-contract/spec.md#requirement-an-action-may-offer-itself-as-a-start-tile-with-a-summary-and-its-audiences-req-snw-020
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Collects start tiles across contributions, one per app and action.
 *
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-contribution-contract/spec.md#requirement-an-action-may-offer-itself-as-a-start-tile-with-a-summary-and-its-audiences-req-snw-020
 */
class StartTileCollector {
	/**
	 * The signed-in area every contribution page lives under (src/shared/portalNav.js).
	 */
	private const ACCOUNT_ROUTE = '/mijn';

	/**
	 * The tiles so far, keyed by `<app>:<action id>`.
	 *
	 * @var array<string, array{label: string, summary: string, audiences: array<int, string>, route: string}>
	 */
	private array $tiles = [];

	/**
	 * Add the tiles of one normalised contribution.
	 *
	 * @param array<string, mixed> $contribution The normalised contribution, with its `app`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-contribution-contract/spec.md#requirement-an-action-may-offer-itself-as-a-start-tile-with-a-summary-and-its-audiences-req-snw-020
	 */
	public function add(array $contribution): void {
		$source = ['app' => (string)($contribution['app'] ?? ''), 'pages' => $this->pageOfAction(pages: ($contribution['pages'] ?? null))];
		foreach ((array)($contribution['actions'] ?? []) as $action) {
			$tile = $this->tileOf(action: $action, source: $source);
			if ($tile !== null) {
				$this->keep(tile: $tile);
			}
		}
	}//end add()

	/**
	 * One action as a tile keyed `app:id`, or null when it is not a start
	 * tile of this contribution.
	 *
	 * @param mixed                $action The declared action.
	 * @param array<string, mixed> $source The contributing app and the page that places each action.
	 *
	 * @return array<string, mixed>|null
	 */
	private function tileOf(mixed $action, array $source): ?array {
		if (is_array($action) === false || $this->offersTile(action: $action, source: $source) === false) {
			return null;
		}

		$id = (string)($action['id'] ?? '');
		return [
			'key'       => $source['app'] . ':' . $id,
			'label'     => $action['label'],
			'summary'   => $action['summary'],
			'audiences' => array_values(array_filter((array)($action['audiences'] ?? []), 'is_string')),
			'route'     => self::ACCOUNT_ROUTE . '/' . rawurlencode($source['app']) . '/' . rawurlencode($source['pages'][$id]),
		];
	}//end tileOf()

	/**
	 * Whether an action offers a start tile: an app, a string summary and
	 * label, and a page that places it.
	 *
	 * @param array<string, mixed> $action The declared action.
	 * @param array<string, mixed> $source The contributing app and the page that places each action.
	 *
	 * @return bool
	 */
	private function offersTile(array $action, array $source): bool {
		return $source['app'] !== '' && is_string($action['summary'] ?? null) === true && is_string($action['label'] ?? null) === true
			&& isset($source['pages'][(string)($action['id'] ?? '')]) === true;
	}//end offersTile()

	/**
	 * Keep a tile; a tile already kept only gains the new audiences, without
	 * duplicates.
	 *
	 * @param array<string, mixed> $tile The tile, with its `key`.
	 *
	 * @return void
	 */
	private function keep(array $tile): void {
		$key = $tile['key'];
		unset($tile['key']);
		if (isset($this->tiles[$key]) === true) {
			$this->tiles[$key]['audiences'] = array_values(array_unique(array_merge($this->tiles[$key]['audiences'], $tile['audiences'])));
			return;
		}

		$this->tiles[$key] = $tile;
	}//end keep()

	/**
	 * Every tile, in the order first seen.
	 *
	 * @return array<int, array{label: string, summary: string, audiences: array<int, string>, route: string}>
	 *
	 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-contribution-contract/spec.md#requirement-an-action-may-offer-itself-as-a-start-tile-with-a-summary-and-its-audiences-req-snw-020
	 */
	public function all(): array {
		return array_values($this->tiles);
	}//end all()

	/**
	 * The first page that offers each action, by action id.
	 *
	 * @param mixed $pages The contribution's pages.
	 *
	 * @return array<string, string> Page id by action id.
	 */
	private function pageOfAction(mixed $pages): array {
		$out = [];
		foreach ((array)$pages as $page) {
			$pageId = (string)($page['id'] ?? '');
			if (is_array($page) === false || $pageId === '') {
				continue;
			}

			foreach ((array)($page['blocks'] ?? []) as $block) {
				$action = (string)($block['action'] ?? '');
				if (($block['type'] ?? '') === 'action' && $action !== '' && isset($out[$action]) === false) {
					$out[$action] = $pageId;
				}
			}
		}

		return $out;
	}//end pageOfAction()
}//end class
