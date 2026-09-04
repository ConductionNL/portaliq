<?php

/**
 * Portaliq LandingPageRequestedEvent
 *
 * Public cross-app event a contributing app dispatches to ask Portaliq to
 * provision a draft CMS landing page with a bound lead-capture form
 * (landing-page-provisioning, ADR-041). Portaliq is the TARGET of this
 * event, so the class lives in Portaliq's own namespace, mirroring
 * decidesk's DecisionRequestedEvent / procest's ContractDecisionDelegationService
 * pattern: dispatched via Nextcloud's IEventDispatcher and handled
 * synchronously by LandingPageRequestedEventListener, so the producer reads
 * the result slot off the SAME instance right after dispatchTyped() returns.
 * No network hop, no new HTTP route (ADR-108, gate-27).
 *
 * @category Event
 * @package  OCA\Portaliq\Event
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
 */

declare(strict_types=1);

namespace OCA\Portaliq\Event;

use OCP\EventDispatcher\Event;

/**
 * Cross-app request event: a contributing app asks Portaliq to provision a
 * landing page + form.
 *
 * All request fields are immutable (constructor-injected). Nextcloud typed
 * dispatch is synchronous, so the result slot (pageId/route/publicUrl/
 * formId/error/handled) is written by Portaliq's listener and read by the
 * producer right after dispatch — the standard NC request/response-over-
 * the-bus pattern (ADR-041).
 *
 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-contributing-app-requests-a-landing-page-via-a-typed-event
 */
class LandingPageRequestedEvent extends Event {

	/**
	 * The created page's OpenRegister id (result slot).
	 *
	 * @var string|null
	 */
	private ?string $pageId = null;

	/**
	 * The created form's OpenRegister id (result slot).
	 *
	 * @var string|null
	 */
	private ?string $formId = null;

	/**
	 * The page's public URL, or null when the portal has no domain
	 * configured (result slot).
	 *
	 * @var string|null
	 */
	private ?string $publicUrl = null;

	/**
	 * A machine-readable failure code, or null on success (result slot).
	 * One of: unknown_portal | duplicate_route | invalid_article | invalid_form.
	 *
	 * @var string|null
	 */
	private ?string $error = null;

	/**
	 * Whether Portaliq's listener handled this request (result slot).
	 *
	 * @var boolean
	 */
	private bool $handled = false;

	/**
	 * Construct the request event.
	 *
	 * @param string $sourceApp The contributing app requesting the page.
	 * @param string $portal The target portal's slug.
	 * @param string $route The desired in-portal route (leading slash), unique within the portal.
	 * @param string $title The page title.
	 * @param string $locale The page's content locale.
	 * @param array<string, mixed> $article `{summary, body, heroImageRef?, links?}`.
	 * @param array<string, mixed> $form `{fields[], submitLabel, consentText?}`.
	 * @param array<string, mixed> $utm `{campaign?, source?, medium?}`.
	 * @param string $externalReference The requester's own reference (idempotency/linking).
	 * @param string $correlationId Correlation id for tracing across both directions of this contract.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) This parameter list is a
	 * PUBLISHED CROSS-APP CONTRACT (contract.md), not an internal signature —
	 * see decidesk's DecisionRequestedEvent for the precedent this mirrors.
	 * Consumer apps construct this event POSITIONALLY through the class-string
	 * `\OCA\Portaliq\Event\LandingPageRequestedEvent`, guarded by
	 * `class_exists()` so they stay installable without Portaliq.
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $portal,
		private readonly string $route,
		private readonly string $title,
		private readonly string $locale,
		private readonly array $article,
		private readonly array $form,
		private readonly array $utm = [],
		private readonly string $externalReference = '',
		private readonly string $correlationId = '',
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Get the contributing app that requested the page.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-contributing-app-requests-a-landing-page-via-a-typed-event
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * Get the target portal's slug.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-contributing-app-requests-a-landing-page-via-a-typed-event
	 */
	public function getPortal(): string {
		return $this->portal;
	}//end getPortal()

	/**
	 * Get the requested in-portal route.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-contributing-app-requests-a-landing-page-via-a-typed-event
	 */
	public function getRoute(): string {
		return $this->route;
	}//end getRoute()

	/**
	 * Get the requested page title.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-contributing-app-requests-a-landing-page-via-a-typed-event
	 */
	public function getTitle(): string {
		return $this->title;
	}//end getTitle()

	/**
	 * Get the requested content locale.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-contributing-app-requests-a-landing-page-via-a-typed-event
	 */
	public function getLocale(): string {
		return $this->locale;
	}//end getLocale()

	/**
	 * Get the article payload (`summary`/`body`/`heroImageRef`/`links`).
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-contributing-app-requests-a-landing-page-via-a-typed-event
	 */
	public function getArticle(): array {
		return $this->article;
	}//end getArticle()

	/**
	 * Get the form payload (`fields`/`submitLabel`/`consentText`).
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-contributing-app-requests-a-landing-page-via-a-typed-event
	 */
	public function getForm(): array {
		return $this->form;
	}//end getForm()

	/**
	 * Get the requested UTM campaign block.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-contributing-app-requests-a-landing-page-via-a-typed-event
	 */
	public function getUtm(): array {
		return $this->utm;
	}//end getUtm()

	/**
	 * Get the requester's own external reference.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-contributing-app-requests-a-landing-page-via-a-typed-event
	 */
	public function getExternalReference(): string {
		return $this->externalReference;
	}//end getExternalReference()

	/**
	 * Get the correlation id.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-contributing-app-requests-a-landing-page-via-a-typed-event
	 */
	public function getCorrelationId(): string {
		return $this->correlationId;
	}//end getCorrelationId()

	/**
	 * Get the created page's OpenRegister id (result slot).
	 *
	 * @return string|null Null until handled, or on failure.
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-contributing-app-requests-a-landing-page-via-a-typed-event
	 */
	public function getPageId(): ?string {
		return $this->pageId;
	}//end getPageId()

	/**
	 * Set the created page's OpenRegister id (written by Portaliq's listener).
	 *
	 * @param string $pageId The created page's id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-contributing-app-requests-a-landing-page-via-a-typed-event
	 */
	public function setPageId(string $pageId): void {
		$this->pageId = $pageId;
	}//end setPageId()

	/**
	 * Get the created form's OpenRegister id (result slot).
	 *
	 * @return string|null Null until handled, or on failure.
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-contributing-app-requests-a-landing-page-via-a-typed-event
	 */
	public function getFormId(): ?string {
		return $this->formId;
	}//end getFormId()

	/**
	 * Set the created form's OpenRegister id (written by Portaliq's listener).
	 *
	 * @param string $formId The created form's id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-contributing-app-requests-a-landing-page-via-a-typed-event
	 */
	public function setFormId(string $formId): void {
		$this->formId = $formId;
	}//end setFormId()

	/**
	 * Get the page's public URL (result slot).
	 *
	 * @return string|null Null when the portal has no verified domain, or on failure.
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-contributing-app-requests-a-landing-page-via-a-typed-event
	 */
	public function getPublicUrl(): ?string {
		return $this->publicUrl;
	}//end getPublicUrl()

	/**
	 * Set the page's public URL (written by Portaliq's listener).
	 *
	 * @param string|null $publicUrl The public URL, or null when no domain is configured.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-contributing-app-requests-a-landing-page-via-a-typed-event
	 */
	public function setPublicUrl(?string $publicUrl): void {
		$this->publicUrl = $publicUrl;
	}//end setPublicUrl()

	/**
	 * Get the machine-readable failure code (result slot).
	 *
	 * @return string|null One of unknown_portal|duplicate_route|invalid_article|invalid_form, or null on success.
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-contributing-app-requests-a-landing-page-via-a-typed-event
	 */
	public function getError(): ?string {
		return $this->error;
	}//end getError()

	/**
	 * Set the machine-readable failure code (written by Portaliq's listener).
	 *
	 * @param string|null $error The failure code, or null on success.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-contributing-app-requests-a-landing-page-via-a-typed-event
	 */
	public function setError(?string $error): void {
		$this->error = $error;
	}//end setError()

	/**
	 * Whether Portaliq's listener handled this request.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-contributing-app-requests-a-landing-page-via-a-typed-event
	 */
	public function isHandled(): bool {
		return $this->handled;
	}//end isHandled()

	/**
	 * Mark whether Portaliq's listener handled this request.
	 *
	 * @param bool $handled True once the listener has run (success or failure).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-contributing-app-requests-a-landing-page-via-a-typed-event
	 */
	public function setHandled(bool $handled): void {
		$this->handled = $handled;
	}//end setHandled()
}//end class
