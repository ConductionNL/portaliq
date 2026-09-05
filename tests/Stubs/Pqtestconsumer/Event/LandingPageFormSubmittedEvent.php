<?php

/**
 * Test fixture: a hypothetical consumer app's copy of
 * `LandingPageFormSubmittedEvent` (landing-page-provisioning). Mirrors the
 * frozen constructor shape `contract.md` documents for a real consumer
 * (e.g. pipelinq's own file, out of scope for this repo) — used ONLY by
 * `LandingPageSubmissionDispatchListenerTest` to prove the positive
 * `class_exists()` resolution path without depending on any real app.
 *
 * NOT autoloaded via composer (no PSR-4 mapping for `OCA\Pqtestconsumer\`) —
 * `require_once`d directly by the test that needs it, exactly like this
 * app's `class_exists()` resolution would find a REAL consumer's file once
 * one is installed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pqtestconsumer\Event;

use OCP\EventDispatcher\Event;

class LandingPageFormSubmittedEvent extends Event {

	public array $captured;

	public function __construct(
		string $sourceApp,
		string $formId,
		string $pageId,
		string $pageRoute,
		string $portal,
		string $externalReference,
		array $values,
		array $utmFirstTouch,
		array $utmLastTouch,
		string $referrer,
		string $submittedAt,
		string $nonce,
		string $correlationId = '',
	) {
		parent::__construct();
		$this->captured = [
			'sourceApp' => $sourceApp,
			'formId' => $formId,
			'pageId' => $pageId,
			'pageRoute' => $pageRoute,
			'portal' => $portal,
			'externalReference' => $externalReference,
			'values' => $values,
			'utmFirstTouch' => $utmFirstTouch,
			'utmLastTouch' => $utmLastTouch,
			'referrer' => $referrer,
			'submittedAt' => $submittedAt,
			'nonce' => $nonce,
			'correlationId' => $correlationId,
		];
	}//end __construct()
}//end class
