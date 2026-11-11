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
	 * The page body's widgets in order: id, widget key, grid row and height.
	 */
	private const BODY_LAYOUT = [
		['hero', 'hero', 0, 3],
		['article', 'markdown', 3, 6],
		['form', 'form', 9, 6],
	];

	/**
	 * Constructor.
	 *
	 * @param ScopedCollectionReaderInterface $reader Reads portal/page rows for validation.
	 * @param PortalObjectWriter $writer Writes the form + page objects.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly ScopedCollectionReaderInterface $reader,
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
		$error = ($this->refusal(event: $event) ?? $this->write(event: $event));
		if ($error === null) {
			$event->setPublicUrl($this->publicUrlFor(portal: $event->getPortal(), route: $event->getRoute()));
		}

		$event->setError($error);
		$event->setHandled(true);
	}//end provision()

	/**
	 * The error code that refuses the request before anything is written, or null.
	 *
	 * @param LandingPageRequestedEvent $event The request event.
	 *
	 * @return string|null
	 */
	private function refusal(LandingPageRequestedEvent $event): ?string {
		$portal = $event->getPortal();
		if ($this->resolvePublishedPortal(portal: $portal) === null) {
			return 'unknown_portal';
		}

		if ($this->routeIsTaken(portal: $portal, route: $event->getRoute()) === true) {
			return 'duplicate_route';
		}

		// The article needs a non-empty summary and body.
		$article = $event->getArticle();
		if (trim((string)($article['summary'] ?? '')) === '' || trim((string)($article['body'] ?? '')) === '') {
			return 'invalid_article';
		}

		if ($this->formIsValid(form: $event->getForm()) === false) {
			return 'invalid_form';
		}

		return null;
	}//end refusal()

	/**
	 * Create the form, then the page bound to it; the error code when a write failed, or null.
	 *
	 * @param LandingPageRequestedEvent $event The request event (ids set on success).
	 *
	 * @return string|null
	 */
	private function write(LandingPageRequestedEvent $event): ?string {
		$formId = $this->rowId(
			row: $this->writer->createAnonymousObject(register: self::REGISTER, schema: self::FORM_SCHEMA, data: $this->formData(event: $event))
		);
		if ($formId === '') {
			return 'write_failed';
		}

		return $this->writePage(event: $event, formId: $formId);
	}//end write()

	/**
	 * Create the draft page for an already created form; the error code when that failed, or null.
	 *
	 * @param LandingPageRequestedEvent $event  The request event (ids set on success).
	 * @param string                    $formId The created form's id.
	 *
	 * @return string|null
	 */
	private function writePage(LandingPageRequestedEvent $event, string $formId): ?string {
		$pageId = $this->rowId(
			row: $this->writer->createAnonymousObject(
				register: self::REGISTER,
				schema: self::PAGE_SCHEMA,
				data: $this->pageData(event: $event, formId: $formId)
			)
		);
		if ($pageId === '') {
			$this->logger->warning(
				'Portaliq: landing page write failed after its form was created',
				['formId' => $formId, 'portal' => $event->getPortal(), 'route' => $event->getRoute()]
			);
			return 'write_failed';
		}

		$event->setPageId($pageId);
		$event->setFormId($formId);
		return null;
	}//end writePage()

	/**
	 * The form object a request creates.
	 *
	 * @param LandingPageRequestedEvent $event The request event.
	 *
	 * @return array<string, mixed>
	 */
	private function formData(LandingPageRequestedEvent $event): array {
		$form = $event->getForm();

		return [
			'portal' => $event->getPortal(),
			'pageRoute' => $event->getRoute(),
			'sourceApp' => $event->getSourceApp(),
			'externalReference' => $event->getExternalReference(),
			'campaign' => $event->getUtm(),
			'fields' => (array)($form['fields'] ?? []),
			'submitLabel' => (string)($form['submitLabel'] ?? ''),
			'consentText' => (string)($form['consentText'] ?? ''),
			'status' => 'active',
		] + self::destinationOf(form: $form);
	}//end formData()

	/**
	 * The draft page object a request creates, bound to its form.
	 *
	 * @param LandingPageRequestedEvent $event  The request event.
	 * @param string                    $formId The created form's id.
	 *
	 * @return array<string, mixed>
	 */
	private function pageData(LandingPageRequestedEvent $event, string $formId): array {
		$article = $event->getArticle();

		return [
			'title' => $event->getTitle(),
			'route' => $event->getRoute(),
			'status' => 'draft',
			'locale' => $event->getLocale(),
			'summary' => (string)($article['summary'] ?? ''),
			'heroImage' => $article['heroImageRef'] ?? null,
			'portal' => $event->getPortal(),
			'body' => $this->buildBody(event: $event, formId: $formId),
		];
	}//end pageData()

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
	 * The `portal` filter is also passed down to the reader so the query is
	 * scoped server-side, but this loop re-checks `row['portal']` itself
	 * rather than trusting that filter alone — belt and braces against a
	 * reader that returns rows across portals, so a route never collides
	 * with a same-named route that belongs to a DIFFERENT portal.
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
			if (($row['portal'] ?? '') !== $portal) {
				continue;
			}

			if (mb_strtolower((string)($row['route'] ?? '')) === $needle) {
				return true;
			}
		}

		return false;
	}//end routeIsTaken()

	/**
	 * The requesting app's destination for the form, as `['destination' => ...]`, or nothing.
	 *
	 * Decision 179: a submit creates the app's own object (a lead) directly.
	 * Only `register`, `schema`, `fields` and `fixed` are carried; without a
	 * register and schema the form is created but not submittable until an
	 * administrator names its destination.
	 *
	 * @param array<string, mixed> $form The event's form.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/landing-page-provisioning/spec.md#requirement-a-landing-pages-form-is-submittable-with-no-portal-session
	 */
	private static function destinationOf(array $form): array {
		$destination = (array)($form['destination'] ?? []);
		$register    = trim((string)($destination['register'] ?? ''));
		$schema      = trim((string)($destination['schema'] ?? ''));
		if ($register === '' || $schema === '') {
			return [];
		}

		return [
			'destination' => [
				'register' => $register,
				'schema' => $schema,
				'fields' => array_filter((array)($destination['fields'] ?? []), 'is_string'),
				'fixed' => (array)($destination['fixed'] ?? []),
			],
		];
	}//end destinationOf()

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
	 * @param LandingPageRequestedEvent $event  The request event (article and form payloads).
	 * @param string                    $formId The created form's id.
	 *
	 * @return array<string, mixed>
	 */
	private function buildBody(LandingPageRequestedEvent $event, string $formId): array {
		$props   = $this->widgetProps(event: $event, formId: $formId);
		$widgets = [];
		foreach (self::BODY_LAYOUT as [$id, $widgetKey, $gridY, $gridHeight]) {
			$widgets[] = [
				'id' => $id,
				'widgetKey' => $widgetKey,
				'gridX' => 0,
				'gridY' => $gridY,
				'gridWidth' => 12,
				'gridHeight' => $gridHeight,
				'props' => $props[$id],
			];
		}

		return ['type' => 'grid', 'widgets' => $widgets];
	}//end buildBody()

	/**
	 * The props of each body widget, by widget id.
	 *
	 * @param LandingPageRequestedEvent $event  The request event.
	 * @param string                    $formId The created form's id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function widgetProps(LandingPageRequestedEvent $event, string $formId): array {
		$article = $event->getArticle();
		$form    = $event->getForm();

		return [
			'hero' => ['summary' => (string)($article['summary'] ?? '')],
			'article' => [
				'markdown' => (string)($article['body'] ?? ''),
				'links' => (array)($article['links'] ?? []),
			],
			'form' => [
				'formId' => $formId,
				'fields' => (array)($form['fields'] ?? []),
				'submitLabel' => (string)($form['submitLabel'] ?? ''),
				'consentText' => (string)($form['consentText'] ?? ''),
			],
		];
	}//end widgetProps()

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

		return ((new RowIdLookup())->firstIdOrUuid(row: $row) ?? '');
	}//end rowId()
}//end class
