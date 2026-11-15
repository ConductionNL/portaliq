<?php

/**
 * Portaliq Reveal Decision
 *
 * A custodian's answer to a request to learn who filed a report: the request
 * and the report it is about, who answers, the case type that names the
 * custodian, whether it is allowed, and what they say about it.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Reports
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

namespace OCA\Portaliq\Service\Reports;

use OCP\IUser;

/**
 * A custodian's answer to a reveal request.
 *
 * @spec openspec/specs/report-without-an-account/spec.md
 */
final class RevealDecision {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $request   The pending request.
	 * @param array<string, mixed> $report    The report it is about.
	 * @param IUser                $custodian The user answering.
	 * @param array<string, mixed> $caseType  The case type, for the declaration.
	 * @param bool                 $allow     Whether it is allowed.
	 * @param string               $reason    What the custodian says about their answer.
	 */
	public function __construct(
		public readonly array $request,
		public readonly array $report,
		public readonly IUser $custodian,
		public readonly array $caseType,
		public readonly bool $allow,
		public readonly string $reason = '',
	) {
	}//end __construct()
}//end class
