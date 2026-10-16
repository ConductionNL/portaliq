<?php

/**
 * Portaliq Content Accessibility Controller
 *
 * The public accessibility statement of every portal
 * (site-accessibility-statement REQ-SAS-002), part of the public content API.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-each-portal-publishes-a-statement-in-the-national-model-req-sas-002
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use DateTimeImmutable;
use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Cms\AccessibilityMeasurements;
use OCA\Portaliq\Service\Cms\AccessibilityStatement;
use OCA\Portaliq\Service\PortalResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;

/**
 * One public read.
 *
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-each-portal-publishes-a-statement-in-the-national-model-req-sas-002
 */
class ContentAccessibilityController extends Controller {

	/**
	 * @param IRequest                  $request      The request.
	 * @param AccessibilityMeasurements $measurements Where runs live.
	 * @param AccessibilityStatement    $statement    The statement builder.
	 * @param PortalResolver            $resolver     The public portal resolver.
	 * @param ITimeFactory              $clock        The clock.
	 */
	public function __construct(
		IRequest $request,
		private readonly AccessibilityMeasurements $measurements,
		private readonly AccessibilityStatement $statement,
		private readonly PortalResolver $resolver,
		private readonly ITimeFactory $clock,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The public statement of the portal the request names.
	 *
	 * Public on every portal, whatever its sign-in modes: the statement is a
	 * duty towards everybody, signed in or not.
	 *
	 * @param string|null $portal Explicit portal slug.
	 * @param string|null $locale The language of the sentences.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-each-portal-publishes-a-statement-in-the-national-model-req-sas-002
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function statement(?string $portal = null, ?string $locale = null): JSONResponse {
		$resolved = $this->resolver->resolve(request: $this->request, portalSlug: $portal);
		if ($resolved === null) {
			$response = new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
			$response->addHeader('Cache-Control', 'private, no-store');
			return $response;
		}

		$slug     = (string)($resolved['slug'] ?? '');
		$response = new JSONResponse(
			[
				'statement' => $this->statement->build(
					portal: $resolved,
					measurement: $this->measurements->latest(slug: $slug),
					locale: (string)$locale,
					now: $this->now()
				),
			]
		);
		$response->addHeader('Cache-Control', 'public, max-age=300, must-revalidate');

		return $response;
	}//end statement()

	/**
	 * The clock as an immutable date.
	 *
	 * @return DateTimeImmutable
	 */
	private function now(): DateTimeImmutable {
		return $this->clock->now();
	}//end now()
}//end class
