<?php

/**
 * Portaliq Page Regions Controller
 *
 * Where the page editor saves a page's regions.
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
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
 * @spec openspec/changes/portal-page-composition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalRegionResolver;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalUnscopedStore;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Saving a page's regions.
 *
 * THE WRITE PATH IS NARROW ON PURPOSE. It accepts regions and nothing else: not
 * a page's title, not its route, not its status. An editor that could rewrite a
 * whole page record from the browser would be an editor one malformed request
 * away from unpublishing a live government page, and none of those fields are
 * things this editor edits.
 *
 * @spec openspec/changes/portal-page-composition/tasks.md
 */
class PageRegionsController extends Controller {

	/**
	 * The register CMS pages live in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The page schema.
	 */
	private const SCHEMA = 'page';


	/**
	 * @param string               $appName  The app id.
	 * @param IRequest             $request  The request.
	 * @param PortalResolver       $resolver Resolves the portal being edited.
	 * @param PortalUnscopedStore  $store    Reads the page being edited.
	 * @param PortalObjectWriter   $writer   Persists the change.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PortalResolver $resolver,
		private readonly PortalUnscopedStore $store,
		private readonly PortalObjectWriter $writer,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()


	/**
	 * Replace one page's regions.
	 *
	 * `#[NoAdminRequired]` rather than admin-only: the people who edit a
	 * portal's pages are not always the instance's administrators. CSRF
	 * protection is NOT waived — this is a write from a first-party page that
	 * has a request token, unlike the anonymous collector.
	 *
	 * @param array|null $regions The regions to store.
	 * @param string     $route   The page's route.
	 * @param string     $portal  The portal slug.
	 *
	 * @return DataResponse The stored regions, or an error.
	 *
	 * @spec openspec/changes/portal-page-composition/tasks.md
	 */
	#[NoAdminRequired]
	public function update(?array $regions = null, string $route = '', string $portal = ''): DataResponse {
		if (is_array($regions) === false) {
			return new DataResponse(data: ['error' => 'regions_required'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		$resolved = $this->resolver->resolve(request: $this->request, portalSlug: $portal);
		if ($resolved === null) {
			return new DataResponse(data: ['error' => 'not_found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		$slug = (string)($resolved['slug'] ?? '');
		$page = $this->pageFor(slug: $slug, route: $route);
		if ($page === null) {
			return new DataResponse(data: ['error' => 'not_found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		$flat = $this->flatten(regions: $regions);
		if ($flat === null) {
			return new DataResponse(data: ['error' => 'unknown_region'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		// STORED AS A FLAT WIDGET LIST WITH `slot`, which is the shape every
		// existing consumer already reads — the built-in renderer, the
		// Docusaurus plugin, the e2e grid check. Writing a `regions` map
		// instead would make a page saved by the editor unreadable to all
		// three, and the region grouping is derived on read anyway.
		$body = (array)($page['body'] ?? []);
		$body['type'] = 'grid';
		$body['widgets'] = $flat;

		$saved = $this->writer->createAnonymousObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			data: array_merge($page, ['body' => $body]),
			uuid: (string)($page['id'] ?? '')
		);

		if ($saved === null) {
			return new DataResponse(data: ['error' => 'write_failed'], statusCode: Http::STATUS_BAD_GATEWAY);
		}

		return new DataResponse(data: ['widgets' => $flat]);
	}//end update()


	/**
	 * The page record for a route on a portal, or null.
	 *
	 * @param string $slug  The portal slug.
	 * @param string $route The route.
	 *
	 * @return array<string, mixed>|null The page.
	 */
	private function pageFor(string $slug, string $route): ?array {
		$rows = $this->store->readObjects(
			register: self::REGISTER,
			schema: self::SCHEMA,
			filters: ['portal' => $slug, 'route' => $route]
		);

		foreach ($rows as $row) {
			if ((string)($row['route'] ?? '') === $route) {
				return $row;
			}
		}

		return null;
	}//end pageFor()


	/**
	 * A regions map as the flat widget list the contract stores.
	 *
	 * REFUSES AN UNKNOWN REGION rather than dropping it. A widget written into
	 * a region nothing renders is a widget an author placed, saved, and will
	 * never see again — and the page would look correct in the editor that
	 * wrote it.
	 *
	 * @param array<string, mixed> $regions The regions.
	 *
	 * @return array<int, array<string, mixed>>|null The widgets, or null when a region is unknown.
	 */
	private function flatten(array $regions): ?array {
		$widgets = [];

		foreach ($regions as $region => $blocks) {
			if (in_array($region, PortalRegionResolver::REGIONS, true) === false) {
				return null;
			}

			$position = 0;
			foreach ((array)$blocks as $block) {
				if (is_array($block) === false) {
					continue;
				}

				$widget = [
					'id' => (string)($block['id'] ?? ''),
					'widgetKey' => (string)($block['widgetKey'] ?? ''),
					'slot' => $region,
					'gridX' => (int)($block['gridX'] ?? 0),
					// Its own row when the editor did not give it one, so two
					// blocks never land on the same cell and overlap.
					'gridY' => (int)($block['gridY'] ?? ($position * 4)),
					'gridWidth' => (int)($block['gridWidth'] ?? 12),
					'gridHeight' => (int)($block['gridHeight'] ?? 4),
				];

				// THE KEY IS OMITTED WHEN THERE IS NOTHING IN IT, and both of
				// the obvious alternatives were tried against the live schema
				// and refused. `{}` gives "expects object but got empty ({}) …
				// set this to null to clear the field"; `null` then gives
				// "should be type 'object' but is 'null'". The two messages
				// contradict each other, so the only accepted shape for a block
				// an author has not configured yet is no `props` key at all.
				//
				// THE STRIPPING STILL APPLIES to a block that HAS props: an
				// editor must not be the way `style` or `class` reach page data
				// (task 5.3), and a check on the write path holds for every
				// client rather than only the one this app ships.
				$props = $this->safeProps(props: (array)($block['props'] ?? []));
				if ($props !== null) {
					$widget['props'] = $props;
				}

				$widgets[] = $widget;
				$position++;
			}
		}

		return $widgets;
	}//end flatten()


	/**
	 * A block's props without the styling escape hatches.
	 *
	 * @param array<string, mixed> $props The props.
	 *
	 * @return array<string, mixed>|null The props, or null when there are none.
	 */
	private function safeProps(array $props): ?array {
		unset($props['style'], $props['class']);

		// EMPTY MEANS NULL, because the schema says so. A newly inserted block
		// has no props yet, and OpenRegister refuses `{}` for an object
		// property with "expects object but got empty ({}) … set this to null
		// to clear the field" — the whole save then failed with a 502 that
		// named nothing an author could act on.
		if ($props === []) {
			return null;
		}

		return $props;
	}//end safeProps()


}//end class
