<?php

/**
 * Portaliq Report Filing
 *
 * The state of one report filed without an account while
 * `ReportController` takes it: the portal it arrived on and the report,
 * then the contact the reporter chose to give, the case type it is filed
 * under and the challenge proof the filing carried. It never leaves the
 * controller that builds it.
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
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
 * @spec openspec/specs/report-without-an-account/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

/**
 * One filing in progress.
 *
 * @spec openspec/specs/report-without-an-account/spec.md#requirement-a-report-is-accepted-without-an-account-req-rwa-001
 */
final class ReportFiling {

	/**
	 * What the reporter chose to give.
	 *
	 * @var array<string, mixed>
	 */
	public array $contact = [];

	/**
	 * The case type whose declaration governs it.
	 *
	 * @var string
	 */
	public string $caseType = '';

	/**
	 * The register the case type lives in.
	 *
	 * @var string
	 */
	public string $register = '';

	/**
	 * The schema the case type lives in.
	 *
	 * @var string
	 */
	public string $schema = '';

	/**
	 * The challenge nonce, its solution, the expiry issued with it and this
	 * instance's signature over it.
	 *
	 * @var array{nonce: string, solution: string, expiresAt: int, signature: string}
	 */
	public array $challenge = ['nonce' => '', 'solution' => '', 'expiresAt' => 0, 'signature' => ''];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $site   The portal the filing arrived on.
	 * @param array<string, mixed> $report `subject`, `body` and the answers.
	 */
	public function __construct(
		public readonly array $site,
		public readonly array $report,
	) {
	}//end __construct()
}//end class
