<?php

/**
 * Portaliq Row Forward Run
 *
 * The state of one row-scoped forward while `PortalRowActionController`
 * walks it: the resolved subject and the authorised row action, then the
 * row read under the subject's scope, its proven id and the requested
 * action. It never leaves the controller that builds it.
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
 * @spec openspec/changes/contribution-pay-screen/specs/portal-contribution-contract/spec.md#requirement-a-row-scoped-forward-must-prove-the-row-before-it-forwards
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\Contribution\RowActionInputs;

/**
 * One row-scoped forward in progress.
 */
final class RowForwardRun {

	/**
	 * The row-action input rules.
	 *
	 * @var RowActionInputs
	 */
	public readonly RowActionInputs $inputs;

	/**
	 * The row, read under the subject's collection scope.
	 *
	 * @var array<string, mixed>
	 */
	public array $row = [];

	/**
	 * The id the browser named in the path.
	 *
	 * @var string
	 */
	public string $requestedId = '';

	/**
	 * The proven row id that reaches the leaf app.
	 *
	 * @var string
	 */
	public string $rowId = '';

	/**
	 * The requested endpoint row action.
	 *
	 * @var string
	 */
	public string $actionId = '';

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param array{collection: array<string, mixed>, action: array<string, mixed>, app: string, rowApp?: string} $match The authorised row action.
	 */
	public function __construct(
		public readonly array $subject,
		public readonly array $match,
	) {
		$this->inputs = new RowActionInputs();
	}//end __construct()
}//end class
