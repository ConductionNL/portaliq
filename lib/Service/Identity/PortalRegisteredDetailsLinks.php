<?php

/**
 * Portaliq Portal Registered Details Links
 *
 * The two request links of the "My details" section
 * (identity-registered-details design D4): "Report an error in these
 * details" and "Something wrong at this address?". Each is a published
 * portalFormBinding of the serving portal, named on the portal record under
 * `registeredDetails`. An unset or unpublished binding gives no link, never a
 * link that fails.
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
 * @spec openspec/changes/identity-registered-details/specs/registered-details/spec.md#requirement-a-resident-can-ask-for-a-correction-req-ird-004
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCP\IURLGenerator;

/**
 * Resolves the correction and address investigation links of one portal.

 *
 * @spec openspec/changes/identity-registered-details/specs/registered-details/spec.md
 */
class PortalRegisteredDetailsLinks {

	/**
	 * The built-in site renderer (`portalPage#site`).
	 */
	private const SITE_ROUTE = 'portaliq.portalPage.site';

	/**
	 * Constructor.
	 *
	 * @param PortalFormBindingResolver $bindings The portal's published form bindings.
	 * @param IURLGenerator             $urls     Builds the site link.
	 */
	public function __construct(
		private readonly PortalFormBindingResolver $bindings,
		private readonly IURLGenerator $urls,
	) {
	}//end __construct()

	/**
	 * The links one portal offers for one kind of record.
	 *
	 * @param array<string, mixed>|null $portal The serving portal, or null.
	 * @param string                    $kind   person or company.
	 *
	 * @return array{correction: ?string, addressInvestigation: ?string}
	 *
	 * @spec openspec/changes/identity-registered-details/specs/registered-details/spec.md#requirement-a-resident-can-ask-for-a-correction-req-ird-004
	 */
	public function forPortal(?array $portal, string $kind): array {
		$links = ['correction' => null, 'addressInvestigation' => null];
		$slug  = (string)($portal['slug'] ?? '');
		$bound = (array)($portal['registeredDetails'] ?? []);
		if ($slug === '' || $bound === []) {
			return $links;
		}

		$routes = [];
		foreach ($this->bindings->publishedBindings(portal: $slug) as $binding) {
			$id = (string)(($binding['@self'] ?? [])['id'] ?? $binding['id'] ?? $binding['uuid'] ?? '');
			if ($id !== '' && (string)($binding['route'] ?? '') !== '') {
				$routes[$id] = (string)$binding['route'];
			}
		}

		$links['correction'] = $this->link(slug: $slug, route: ($routes[(string)($bound['correctionFormBinding'] ?? '')] ?? ''));
		if ($kind === 'person') {
			$links['addressInvestigation'] = $this->link(slug: $slug, route: ($routes[(string)($bound['addressInvestigationFormBinding'] ?? '')] ?? ''));
		}

		return $links;
	}//end forPortal()

	/**
	 * The site link to one route of one portal, or null for no route.
	 *
	 * @param string $slug  The portal slug.
	 * @param string $route The in-site route.
	 *
	 * @return string|null
	 */
	private function link(string $slug, string $route): ?string {
		if ($route === '') {
			return null;
		}

		return $this->urls->linkToRoute(self::SITE_ROUTE, ['portal' => $slug, 'route' => $route]);
	}//end link()
}//end class
