<?php

/**
 * Whether one portal has a home page, for the administrator who configures it.
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
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-without-a-published-home-page-must-be-reported-as-a-configuration-error
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

use OCA\Portaliq\Service\PortalObjectReader;

/**
 * Classifies a portal's root route into published, draft or missing.
 *
 * A home page is a page of this portal whose `route` is exactly `/` and whose
 * `status` is `published`. The portal root is a CMS page slot like any other,
 * so an absent page there answers not found, and that answer stays. This
 * service exists so the state is reported where a portal is configured instead
 * of being discovered by a visitor.
 *
 * The read is FOR ADMINISTRATORS ONLY, and the caller is what makes that true.
 * It reports that a DRAFT page exists at a route, which is the existence
 * oracle `CmsReader` withholds from the public content API on purpose. Only
 * {@see \OCA\Portaliq\Controller\PortalHomePageController} calls it, and that
 * controller declares no opt-out attribute, so Nextcloud admits administrators
 * alone.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-without-a-published-home-page-must-be-reported-as-a-configuration-error
 */
class PortalHomePage {

	/**
	 * The route a portal's home page is served at.
	 */
	public const ROOT_ROUTE = '/';

	/**
	 * The register the portal's pages live in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The page schema slug.
	 */
	private const SCHEMA = 'page';

	/**
	 * The status a page must carry to be served.
	 */
	private const PUBLISHED = 'published';

	/**
	 * How many rows at one route are worth reading. A route is unique within a
	 * portal, so more than one is already a fault; a handful is read anyway so
	 * a duplicate cannot hide the published row behind a draft.
	 */
	private const LIMIT = 10;

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader Reads the portal's pages.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
	) {
	}//end __construct()

	/**
	 * The home-page state of one portal.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return array{state: string, route: string, pageId: string|null, pageTitle: string|null}
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-without-a-published-home-page-must-be-reported-as-a-configuration-error
	 */
	public function verdict(string $slug): array {
		if ($slug === '') {
			return self::classify(rows: []);
		}

		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'portal',
			subjectRef: $slug,
			limit: self::LIMIT,
			filter: ['route' => self::ROOT_ROUTE],
		);

		return self::classify(rows: $rows);
	}//end verdict()

	/**
	 * Classify the rows read at the portal's root route.
	 *
	 * The query filter is a narrowing, not a verdict: the route is compared
	 * again here, so a reader that widened the filter cannot turn a page at
	 * `/over-ons` into a home page. A published row wins over a draft, because
	 * what a visitor gets is the published one.
	 *
	 * @param array<int, mixed> $rows The rows read at the root route.
	 *
	 * @return array{state: string, route: string, pageId: string|null, pageTitle: string|null}
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-without-a-published-home-page-must-be-reported-as-a-configuration-error
	 */
	public static function classify(array $rows): array {
		$draft = null;
		foreach ($rows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			if ((string)($row['route'] ?? '') !== self::ROOT_ROUTE) {
				continue;
			}

			if ((string)($row['status'] ?? '') === self::PUBLISHED) {
				return self::state(state: self::PUBLISHED, row: $row);
			}

			if ($draft === null) {
				$draft = $row;
			}
		}

		if ($draft !== null) {
			return self::state(state: 'draft', row: $draft);
		}

		return self::state(state: 'missing', row: null);
	}//end classify()

	/**
	 * One verdict, with the page it names when there is one.
	 *
	 * @param string $state The classification.
	 * @param array<string, mixed>|null $row The page the state was read from.
	 *
	 * @return array{state: string, route: string, pageId: string|null, pageTitle: string|null}
	 */
	private static function state(string $state, ?array $row): array {
		$title = null;
		if ($row !== null && is_string($row['title'] ?? null) === true && $row['title'] !== '') {
			$title = (string)$row['title'];
		}

		$pageId = null;
		if ($row !== null) {
			$pageId = self::rowId(row: $row);
		}

		return [
			'state'     => $state,
			'route'     => self::ROOT_ROUTE,
			'pageId'    => $pageId,
			'pageTitle' => $title,
		];
	}//end state()

	/**
	 * The identifier of a stored row, flat or inside the `@self` envelope.
	 *
	 * Both shapes are read because both occur, exactly as `CmsReader` reads
	 * them: OpenRegister's object API returns a flat `id` alongside the
	 * envelope, and a row that has been projected elsewhere may carry only one.
	 *
	 * @param array<string, mixed> $row The stored row.
	 *
	 * @return string|null The identifier, or null when the row carries none.
	 */
	private static function rowId(array $row): ?string {
		$candidates = [
			($row['id'] ?? null),
			($row['uuid'] ?? null),
		];

		$self = ($row['@self'] ?? null);
		if (is_array($self) === true) {
			$candidates[] = ($self['id'] ?? null);
			$candidates[] = ($self['uuid'] ?? null);
		}

		foreach ($candidates as $candidate) {
			if (is_string($candidate) === true && $candidate !== '') {
				return $candidate;
			}

			if (is_int($candidate) === true) {
				return (string)$candidate;
			}
		}

		return null;
	}//end rowId()
}//end class
