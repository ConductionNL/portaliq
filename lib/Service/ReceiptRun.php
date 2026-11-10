<?php

/**
 * Portaliq Receipt Run
 *
 * The state of one WMEBV receipt while `SubmissionReceiptService` writes it:
 * the submitting subject and tenant, the contributing app and action, the
 * whitelisted data, the audience, the fields that name the action, then the
 * receipt reference and the moment of submission. It never leaves the
 * service that builds it.
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
 * @spec openspec/specs/supplier-portal/spec.md#proof-of-receipt-log-satisfying-the-wmebv-burden-of-proof
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * One receipt in progress.
 *
 * @spec openspec/specs/supplier-portal/spec.md#proof-of-receipt-log-satisfying-the-wmebv-burden-of-proof
 */
final class ReceiptRun {

	/**
	 * The contributing app.
	 *
	 * @var string
	 */
	public string $appId = '';

	/**
	 * The declared action id.
	 *
	 * @var string
	 */
	public string $actionId = '';

	/**
	 * The whitelisted submitted data.
	 *
	 * @var array<string, mixed>
	 */
	public array $whitelistedData = [];

	/**
	 * The subject's audience (notification dispatch).
	 *
	 * @var string
	 */
	public string $audience = '';

	/**
	 * The fields that name the action.
	 *
	 * @var array<string, string>
	 */
	public array $named = [];

	/**
	 * The receipt reference id.
	 *
	 * @var string
	 */
	public string $referenceId = '';

	/**
	 * The moment of submission, ISO 8601.
	 *
	 * @var string
	 */
	public string $submittedAt = '';

	/**
	 * Constructor.
	 *
	 * @param string $subjectRef   The submitting subject.
	 * @param string $organisation The subject's tenant.
	 */
	public function __construct(
		public readonly string $subjectRef,
		public readonly string $organisation,
	) {
	}//end __construct()
}//end class
