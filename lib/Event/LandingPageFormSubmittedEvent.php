<?php

/**
 * Portaliq LandingPageFormSubmittedEvent
 *
 * Producer-side event Portaliq dispatches after a visitor's landing-page
 * form submission is durably written to `landingPageSubmission`
 * (landing-page-provisioning, ADR-041). In THIS direction the contributing
 * app is the TARGET, so per ADR-041 convention the receiving class lives in
 * the CONSUMING app's own namespace (e.g. `OCA\Pipelinq\Event\
 * LandingPageFormSubmittedEvent`) — this file is the SHAPE a consumer's copy
 * MUST match (see contract.md), not itself dispatched as `OCA\Portaliq\...`.
 * `LandingPageSubmissionDispatchListener` resolves the consumer's FQCN by
 * `sourceApp` and `class_exists()`-guards it, logging and continuing (never
 * throwing) when the class does not exist yet — a deliberate departure from
 * ADR-041's default "throw" posture, because the visitor's submission is
 * already durable and must never fail on account of an as-yet-unbuilt
 * consumer (see design.md Decision 2 / Risk 1).
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
 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-submission-is-relayed-to-the-contributing-app-as-a-fail-safe-not-a-fail-closed-cross-app-event
 */

declare(strict_types=1);

namespace OCA\Portaliq\Event;

use OCP\EventDispatcher\Event;

/**
 * Cross-app notification event: a landing-page form was submitted.
 *
 * The frozen constructor shape a consuming app's own event class MUST match
 * — pinned by `LandingPageFormSubmittedEventTest` in this repo (this class
 * itself is used as the fixture/reference implementation for that pin; a
 * real consumer defines its OWN copy in its own namespace per ADR-041).
 *
 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-submission-is-relayed-to-the-contributing-app-as-a-fail-safe-not-a-fail-closed-cross-app-event
 */
class LandingPageFormSubmittedEvent extends Event {

	/**
	 * The consumer's own lead/record id (optional result slot; Portaliq
	 * never reads this back — the submission is already durable before this
	 * event dispatches).
	 *
	 * @var string|null
	 */
	private ?string $leadId = null;

	/**
	 * Whether the consumer handled this notification (optional result slot).
	 *
	 * @var boolean
	 */
	private bool $handled = false;

	/**
	 * Construct the notification event.
	 *
	 * @param string $sourceApp The app that requested the originating form (echoed back).
	 * @param string $formId The submitted form's OpenRegister id.
	 * @param string $pageId The bound page's OpenRegister id.
	 * @param string $pageRoute The bound page's route.
	 * @param string $portal The portal slug the page belongs to.
	 * @param string $externalReference The requester's own reference, echoed from the form.
	 * @param array<string, mixed> $values The whitelisted visible-field values the visitor submitted.
	 * @param array<string, mixed> $utmFirstTouch `{campaign, source, medium, term, content}` captured at first landing.
	 * @param array<string, mixed> $utmLastTouch Same shape, captured at the submitting visit.
	 * @param string $referrer `document.referrer` captured at first touch.
	 * @param string $submittedAt Server-stamped ISO-8601 timestamp.
	 * @param string $nonce Server-generated, replay-resistance only.
	 * @param string $correlationId Correlation id for tracing across both directions of this contract.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) PUBLISHED CROSS-APP
	 * CONTRACT (contract.md) — see LandingPageRequestedEvent's docblock for
	 * the same rationale.
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $formId,
		private readonly string $pageId,
		private readonly string $pageRoute,
		private readonly string $portal,
		private readonly string $externalReference,
		private readonly array $values,
		private readonly array $utmFirstTouch,
		private readonly array $utmLastTouch,
		private readonly string $referrer,
		private readonly string $submittedAt,
		private readonly string $nonce,
		private readonly string $correlationId = '',
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Get the app that requested the originating form.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-submission-is-relayed-to-the-contributing-app-as-a-fail-safe-not-a-fail-closed-cross-app-event
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * Get the submitted form's id.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-submission-is-relayed-to-the-contributing-app-as-a-fail-safe-not-a-fail-closed-cross-app-event
	 */
	public function getFormId(): string {
		return $this->formId;
	}//end getFormId()

	/**
	 * Get the bound page's id.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-submission-is-relayed-to-the-contributing-app-as-a-fail-safe-not-a-fail-closed-cross-app-event
	 */
	public function getPageId(): string {
		return $this->pageId;
	}//end getPageId()

	/**
	 * Get the bound page's route.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-submission-is-relayed-to-the-contributing-app-as-a-fail-safe-not-a-fail-closed-cross-app-event
	 */
	public function getPageRoute(): string {
		return $this->pageRoute;
	}//end getPageRoute()

	/**
	 * Get the portal slug.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-submission-is-relayed-to-the-contributing-app-as-a-fail-safe-not-a-fail-closed-cross-app-event
	 */
	public function getPortal(): string {
		return $this->portal;
	}//end getPortal()

	/**
	 * Get the requester's own external reference.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-submission-is-relayed-to-the-contributing-app-as-a-fail-safe-not-a-fail-closed-cross-app-event
	 */
	public function getExternalReference(): string {
		return $this->externalReference;
	}//end getExternalReference()

	/**
	 * Get the whitelisted submitted field values.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-submission-is-relayed-to-the-contributing-app-as-a-fail-safe-not-a-fail-closed-cross-app-event
	 */
	public function getValues(): array {
		return $this->values;
	}//end getValues()

	/**
	 * Get the first-touch UTM block.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-submission-is-relayed-to-the-contributing-app-as-a-fail-safe-not-a-fail-closed-cross-app-event
	 */
	public function getUtmFirstTouch(): array {
		return $this->utmFirstTouch;
	}//end getUtmFirstTouch()

	/**
	 * Get the last-touch UTM block.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-submission-is-relayed-to-the-contributing-app-as-a-fail-safe-not-a-fail-closed-cross-app-event
	 */
	public function getUtmLastTouch(): array {
		return $this->utmLastTouch;
	}//end getUtmLastTouch()

	/**
	 * Get the captured referrer.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-submission-is-relayed-to-the-contributing-app-as-a-fail-safe-not-a-fail-closed-cross-app-event
	 */
	public function getReferrer(): string {
		return $this->referrer;
	}//end getReferrer()

	/**
	 * Get the server-stamped submission timestamp.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-submission-is-relayed-to-the-contributing-app-as-a-fail-safe-not-a-fail-closed-cross-app-event
	 */
	public function getSubmittedAt(): string {
		return $this->submittedAt;
	}//end getSubmittedAt()

	/**
	 * Get the server-generated nonce.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-submission-is-relayed-to-the-contributing-app-as-a-fail-safe-not-a-fail-closed-cross-app-event
	 */
	public function getNonce(): string {
		return $this->nonce;
	}//end getNonce()

	/**
	 * Get the correlation id.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-submission-is-relayed-to-the-contributing-app-as-a-fail-safe-not-a-fail-closed-cross-app-event
	 */
	public function getCorrelationId(): string {
		return $this->correlationId;
	}//end getCorrelationId()

	/**
	 * Get the consumer's own lead/record id (optional result slot).
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-submission-is-relayed-to-the-contributing-app-as-a-fail-safe-not-a-fail-closed-cross-app-event
	 */
	public function getLeadId(): ?string {
		return $this->leadId;
	}//end getLeadId()

	/**
	 * Set the consumer's own lead/record id (written by the consumer's listener).
	 *
	 * @param string $leadId The consumer's own record id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-submission-is-relayed-to-the-contributing-app-as-a-fail-safe-not-a-fail-closed-cross-app-event
	 */
	public function setLeadId(string $leadId): void {
		$this->leadId = $leadId;
	}//end setLeadId()

	/**
	 * Whether the consumer handled this notification (optional result slot).
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-submission-is-relayed-to-the-contributing-app-as-a-fail-safe-not-a-fail-closed-cross-app-event
	 */
	public function isHandled(): bool {
		return $this->handled;
	}//end isHandled()

	/**
	 * Mark whether the consumer handled this notification.
	 *
	 * @param bool $handled True when a consumer listener ran.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-submission-is-relayed-to-the-contributing-app-as-a-fail-safe-not-a-fail-closed-cross-app-event
	 */
	public function setHandled(bool $handled): void {
		$this->handled = $handled;
	}//end setHandled()
}//end class
