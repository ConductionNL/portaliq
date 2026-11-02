<?php

/**
 * Portaliq Accessibility Controller
 *
 * The accessibility measurement and the statement it feeds
 * (site-accessibility-statement): an administrator, or a group the action
 * matrix names for `portal.measure-accessibility`, reads the settings,
 * records the audit and stores a run. The public statement is served by
 * ContentAccessibilityController.
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
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use DateTimeImmutable;
use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\ActionAuthService;
use OCA\Portaliq\Service\Cms\AccessibilityMeasurements;
use OCA\Portaliq\Service\Cms\AccessibilityRun;
use OCA\Portaliq\Service\Cms\AccessibilityStatement;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Three guarded routes on a portal.
 *
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
 */
class AccessibilityController extends Controller {

	/**
	 * The action that may measure and record the audit.
	 */
	public const ACTION_MEASURE = 'portal.measure-accessibility';

	/**
	 * @param IRequest                  $request      The request.
	 * @param AccessibilityMeasurements $measurements Where runs and settings live.
	 * @param AccessibilityRun          $run          The run's shape.
	 * @param AccessibilityStatement    $statement    The statement builder.
	 * @param ActionAuthService         $actionAuth   The action matrix.
	 * @param IUserSession              $userSession  The signed-in user.
	 * @param ITimeFactory              $clock        The clock.
	 */
	public function __construct(
		IRequest $request,
		private readonly AccessibilityMeasurements $measurements,
		private readonly AccessibilityRun $run,
		private readonly AccessibilityStatement $statement,
		private readonly ActionAuthService $actionAuth,
		private readonly IUserSession $userSession,
		private readonly ITimeFactory $clock,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The settings, the pages a run measures and the statement as it stands.
	 *
	 * @param string $slug The portal.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-status-never-goes-beyond-the-evidence-req-sas-003
	 */
	#[NoAdminRequired]
	public function index(string $slug): JSONResponse {
		$refusal = $this->refuseUnlessAllowed();
		if ($refusal !== null) {
			return $refusal;
		}

		$portal = $this->measurements->portalBySlug(slug: $slug);
		if ($portal === null) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($this->view(portal: $portal));
	}//end index()

	/**
	 * Record the audit, the register entry and the added pages.
	 *
	 * @param string                  $slug        The portal.
	 * @param array<array-key, mixed> $audit       `{party, date, reportUrl, result}`.
	 * @param string                  $registerUrl The register entry.
	 * @param array<array-key, mixed> $pages       Added site routes.
	 *
	 * @return JSONResponse 422 with the reason when an A or B claim lacks its audit.
	 *
	 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-status-never-goes-beyond-the-evidence-req-sas-003
	 */
	#[NoAdminRequired]
	public function update(string $slug, array $audit = [], string $registerUrl = '', array $pages = []): JSONResponse {
		$refusal = $this->refuseUnlessAllowed();
		if ($refusal !== null) {
			return $refusal;
		}

		$portal = $this->measurements->portalBySlug(slug: $slug);
		if ($portal === null) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		$saved = $this->measurements->saveSettings(portal: $portal, audit: $audit, registerUrl: $registerUrl, pages: $pages, now: $this->now());
		if (isset($saved['error']) === true) {
			$status = Http::STATUS_UNPROCESSABLE_ENTITY;
			if ($saved['error'] === 'save_failed') {
				$status = Http::STATUS_BAD_GATEWAY;
			}

			return new JSONResponse(['error' => $saved['error']], $status);
		}

		return new JSONResponse($this->view(portal: $saved));
	}//end update()

	/**
	 * Store one run of the measurement.
	 *
	 * @param string                  $slug       The portal.
	 * @param string                  $axeVersion The axe-core version.
	 * @param array<array-key, mixed> $tags       The rule sets.
	 * @param string                  $theme      The theme in use.
	 * @param array<array-key, mixed> $pages      Per page what was found.
	 *
	 * @return JSONResponse 201 with the measurement, or the refusal.
	 *
	 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
	 */
	#[NoAdminRequired]
	public function store(string $slug, string $axeVersion = '', array $tags = [], string $theme = '', array $pages = []): JSONResponse {
		$refusal = $this->refuseUnlessAllowed();
		if ($refusal !== null) {
			return $refusal;
		}

		$portal = $this->measurements->portalBySlug(slug: $slug);
		if ($portal === null) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		$run = $this->run->normalise(axeVersion: $axeVersion, tags: $tags, theme: $theme, pages: $pages);
		if (isset($run['error']) === true) {
			return new JSONResponse(['error' => $run['error']], Http::STATUS_BAD_REQUEST);
		}

		$user   = $this->userSession->getUser();
		$stored = $this->measurements->store(portal: $portal, run: $run, userId: (string)$user?->getUID(), now: $this->now());
		if ($stored === null) {
			return new JSONResponse(['error' => 'save_failed'], Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse(['measurement' => $stored], Http::STATUS_CREATED);
	}//end store()

	/**
	 * What the admin widget shows.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 *
	 * @return array<string, mixed>
	 */
	private function view(array $portal): array {
		$audit = (array)($portal['accessibilityAudit'] ?? []);

		return [
			'audit' => $audit,
			'auditVerdict' => $this->statement->verdict(audit: $audit, now: $this->now()),
			'registerUrl' => (string)($portal['accessibilityRegisterUrl'] ?? ''),
			'addedPages' => array_values(array_filter((array)($portal['accessibilityPages'] ?? []), 'is_string')),
			'pages' => $this->measurements->pagesFor(portal: $portal),
			'statement' => $this->statement->build(
				portal: $portal,
				measurement: $this->measurements->latest(slug: (string)($portal['slug'] ?? '')),
				locale: 'en',
				now: $this->now()
			),
		];
	}//end view()

	/**
	 * 401 without a user, 403 when the matrix does not allow the action.
	 *
	 * @return JSONResponse|null Null when allowed.
	 */
	private function refuseUnlessAllowed(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->actionAuth->can(user: $user, action: self::ACTION_MEASURE) === false) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end refuseUnlessAllowed()

	/**
	 * The clock as an immutable date.
	 *
	 * @return DateTimeImmutable
	 */
	private function now(): DateTimeImmutable {
		return $this->clock->now();
	}//end now()
}//end class
