<?php

/**
 * Portaliq Landing Page Provisioning Service
 *
 * Handles a validated `LandingPageRequestedEvent`: resolves the target
 * portal, enforces route uniqueness within it (closing, for this action's
 * own writes only, the route-uniqueness gap `portaliq-cms` already
 * documents as not implemented fleet-wide), creates the bound `form` object,
 * then the `page` object (always `status: draft` — publishing stays an
 * editor's own action), and derives `publicUrl` from the portal's first
 * verified domain.
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
 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-contributing-app-requests-a-landing-page-via-a-typed-event
 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-requests-fail-closed-with-a-machine-readable-error-and-no-partial-write-plan
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Event\LandingPageRequestedEvent;
use Psr\Log\LoggerInterface;

/**
 * Validates and provisions a landing page + form for the ADR-041 cross-app
 * command.
 *
 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-contributing-app-requests-a-landing-page-via-a-typed-event
 */
class LandingPageProvisioningService {

	/**
	 * The register every schema this service touches lives in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The portal schema slug.
	 */
	private const PORTAL_SCHEMA = 'portal';

	/**
	 * The page schema slug.
	 */
	private const PAGE_SCHEMA = 'page';

	/**
	 * The form schema slug.
	 */
	private const FORM_SCHEMA = 'form';

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader Reads portal/page rows for validation.
	 * @param PortalObjectWriter $writer Writes the form + page objects.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Validate and provision the request, writing the outcome onto the
	 * event's own result slot. Never throws — every failure mode is
	 * expressed through `error`/`handled`, per the fail-closed contract
	 * (no partial write plan: either both objects are created, or neither
	 * is).
	 *
	 * @param LandingPageRequestedEvent $event The request event (mutated in place).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-contributing-app-requests-a-landing-page-via-a-typed-event
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-requests-fail-closed-with-a-machine-readable-error-and-no-partial-write-plan
	 */
	public function provision(LandingPageRequestedEvent $event): void {
		$portal = $event->getPortal();
		$route = $event->getRoute();

		if ($this->resolvePublishedPortal(portal: $portal) === null) {
			$this->fail(event: $event, error: 'unknown_portal');
			return;
		}

		if ($this->routeIsTaken(portal: $portal, route: $route) === true) {
			$this->fail(event: $event, error: 'duplicate_route');
			return;
		}

		$article = $event->getArticle();
		if ($this->articleIsValid(article: $article) === false) {
			$this->fail(event: $event, error: 'invalid_article');
			return;
		}

		$form = $event->getForm();
		if ($this->formIsValid(form: $form) === false) {
			$this->fail(event: $event, error: 'invalid_form');
			return;
		}

		$createdForm = $this->writer->createAnonymousObject(
			register: self::REGISTER,
			schema: self::FORM_SCHEMA,
			data: [
				'portal' => $portal,
				'pageRoute' => $route,
				'sourceApp' => $event->getSourceApp(),
				'externalReference' => $event->getExternalReference(),
				'campaign' => $event->getUtm(),
				'fields' => (array)($form['fields'] ?? []),
				'submitLabel' => (string)($form['submitLabel'] ?? ''),
				'consentText' => (string)($form['consentText'] ?? ''),
				'status' => 'active',
			]
		);
		$formId = $this->rowId(row: $createdForm);
		if ($formId === '') {
			$this->fail(event: $event, error: 'write_failed');
			return;
		}

		$createdPage = $this->writer->createAnonymousObject(
			register: self::REGISTER,
			schema: self::PAGE_SCHEMA,
			data: [
				'title' => $event->getTitle(),
				'route' => $route,
				'status' => 'draft',
				'locale' => $event->getLocale(),
				'summary' => (string)($article['summary'] ?? ''),
				'heroImage' => $article['heroImageRef'] ?? null,
				'portal' => $portal,
				'body' => $this->buildBody(article: $article, form: $form, formId: $formId),
			]
		);
		$pageId = $this->rowId(row: $createdPage);
		if ($pageId === '') {
			// The form object is now orphaned (no page references it, so
			// nothing ever serves it) — logged, not swept here; see
			// design.md Risks. The visitor-facing contract still fails
			// closed: no partial success is reported to the caller.
			$this->logger->warning(
				'Portaliq: landing page write failed after its form was created',
				['formId' => $formId, 'portal' => $portal, 'route' => $route]
			);
			$this->fail(event: $event, error: 'write_failed');
			return;
		}

		$event->setPageId($pageId);
		$event->setFormId($formId);
		$event->setPublicUrl($this->publicUrlFor(portal: $portal, route: $route));
		$event->setError(null);
		$event->setHandled(true);
	}//end provision()

	/**
	 * Mark the event handled-with-failure: no page/form id, the given error
	 * code, `handled: true`.
	 *
	 * @param LandingPageRequestedEvent $event The event to mutate.
	 * @param string $error The machine-readable failure code.
	 *
	 * @return void
	 */
	private function fail(LandingPageRequestedEvent $event, string $error): void {
		$event->setError($error);
		$event->setHandled(true);
	}//end fail()

	/**
	 * Resolve a `published` `portal` object by slug, or null.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<string, mixed>|null
	 */
	private function resolvePublishedPortal(string $portal): ?array {
		if ($portal === '') {
			return null;
		}

		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::PORTAL_SCHEMA,
			scopeField: '',
			subjectRef: '',
			limit: 1,
			filter: ['slug' => $portal, 'status' => 'published']
		);

		return ($rows[0] ?? null);
	}//end resolvePublishedPortal()

	/**
	 * Whether a `page` already exists in this portal at this route
	 * (case-insensitive). Closes the route-uniqueness gap for THIS action's
	 * own writes only (design.md Decision 7).
	 *
	 * @param string $portal The portal slug.
	 * @param string $route The requested route.
	 *
	 * @return bool
	 */
	private function routeIsTaken(string $portal, string $route): bool {
		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::PAGE_SCHEMA,
			scopeField: '',
			subjectRef: '',
			limit: 500,
			filter: ['portal' => $portal]
		);

		$needle = mb_strtolower($route);
		foreach ($rows as $row) {
			if (mb_strtolower((string)($row['route'] ?? '')) === $needle) {
				return true;
			}
		}

		return false;
	}//end routeIsTaken()

	/**
	 * Validate the article payload: non-empty summary and body.
	 *
	 * @param array<string, mixed> $article The article payload.
	 *
	 * @return bool
	 */
	private function articleIsValid(array $article): bool {
		return trim((string)($article['summary'] ?? '')) !== ''
			&& trim((string)($article['body'] ?? '')) !== '';
	}//end articleIsValid()

	/**
	 * Validate the form payload: at least one well-formed field and a
	 * non-empty submit label.
	 *
	 * @param array<string, mixed> $form The form payload.
	 *
	 * @return bool
	 */
	private function formIsValid(array $form): bool {
		$fields = (array)($form['fields'] ?? []);
		if (count($fields) === 0) {
			return false;
		}

		foreach ($fields as $field) {
			if (is_array($field) === false) {
				return false;
			}

			foreach (['id', 'label', 'type'] as $required) {
				if (trim((string)($field[$required] ?? '')) === '') {
					return false;
				}
			}
		}

		return trim((string)($form['submitLabel'] ?? '')) !== '';
	}//end formIsValid()

	/**
	 * Compose the created page's grid body: a hero widget (title/summary),
	 * a markdown widget (the article body), and the form widget bound to
	 * the created form.
	 *
	 * The form widget's `props` carry the form's `fields`/`submitLabel`/
	 * `consentText` INLINE, not just a `formId` reference — `form` objects
	 * are never publicly readable (see the schema's authorization block),
	 * so `FormBlock.vue` has no endpoint of its own to fetch them from. The
	 * page's `body` is already served by the existing public content API
	 * (`GET /api/content/page`), so embedding the shape here is the only
	 * way the public renderer ever sees it — consistent with every other
	 * widget on this page, whose props are likewise served as authored page
	 * configuration, not fetched separately.
	 *
	 * @param array<string, mixed> $article The article payload.
	 * @param array<string, mixed> $form The form payload (`fields`/`submitLabel`/`consentText`).
	 * @param string $formId The created form's id.
	 *
	 * @return array<string, mixed>
	 */
	private function buildBody(array $article, array $form, string $formId): array {
		return [
			'type' => 'grid',
			'widgets' => [
				[
					'id' => 'hero',
					'widgetKey' => 'hero',
					'gridX' => 0,
					'gridY' => 0,
					'gridWidth' => 12,
					'gridHeight' => 3,
					'props' => ['summary' => (string)($article['summary'] ?? '')],
				],
				[
					'id' => 'article',
					'widgetKey' => 'markdown',
					'gridX' => 0,
					'gridY' => 3,
					'gridWidth' => 12,
					'gridHeight' => 6,
					'props' => [
						'markdown' => (string)($article['body'] ?? ''),
						'links' => (array)($article['links'] ?? []),
					],
				],
				[
					'id' => 'form',
					'widgetKey' => 'form',
					'gridX' => 0,
					'gridY' => 9,
					'gridWidth' => 12,
					'gridHeight' => 6,
					'props' => [
						'formId' => $formId,
						'fields' => (array)($form['fields'] ?? []),
						'submitLabel' => (string)($form['submitLabel'] ?? ''),
						'consentText' => (string)($form['consentText'] ?? ''),
					],
				],
			],
		];
	}//end buildBody()

	/**
	 * Derive the page's public URL from the portal's FIRST verified domain,
	 * or null when none is configured (design.md Decision 7 — no canonical
	 * "primary domain" concept exists on `portal.domains[]` today).
	 *
	 * @param string $portal The portal slug.
	 * @param string $route The page's route.
	 *
	 * @return string|null
	 */
	private function publicUrlFor(string $portal, string $route): ?string {
		$row = $this->resolvePublishedPortal(portal: $portal);
		$domains = (array)($row['domains'] ?? []);

		$hostname = null;
		foreach ($domains as $domain) {
			if (is_array($domain) === false) {
				continue;
			}

			if (($domain['verified'] ?? false) === true) {
				$hostname = (string)($domain['hostname'] ?? '');
				break;
			}
		}

		if ($hostname === null && count($domains) > 0 && is_array($domains[0]) === true) {
			$hostname = (string)($domains[0]['hostname'] ?? '');
		}

		if ($hostname === null || $hostname === '') {
			return null;
		}

		return 'https://' . $hostname . $route;
	}//end publicUrlFor()

	/**
	 * Extract a written row's identifier (`id`/`uuid`, flat or `@self`).
	 *
	 * @param array<string, mixed>|null $row The written row, or null on write failure.
	 *
	 * @return string Empty string when unresolvable.
	 */
	private function rowId(?array $row): string {
		if ($row === null) {
			return '';
		}

		$self = ($row['@self'] ?? null);
		$candidates = [($row['id'] ?? null), ($row['uuid'] ?? null)];
		if (is_array($self) === true) {
			$candidates[] = ($self['id'] ?? null);
			$candidates[] = ($self['uuid'] ?? null);
		}

		foreach ($candidates as $candidate) {
			if ((is_string($candidate) === true || is_int($candidate) === true) && (string)$candidate !== '') {
				return (string)$candidate;
			}
		}

		return '';
	}//end rowId()
}//end class
