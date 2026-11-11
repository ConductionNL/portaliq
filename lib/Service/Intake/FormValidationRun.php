<?php

/**
 * Portaliq Form Validation Run
 *
 * One pass of checking a submission against its form's fields.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Intake
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
 * @spec openspec/changes/intake-conditional-questions-and-drafts/specs/portal-intake-form/spec.md#requirement-the-server-skips-a-hidden-field-req-icq-002
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

/**
 * The submitted answers, what a condition reads so far, and the errors and
 * accepted answers found.
 *
 * @spec openspec/changes/intake-conditional-questions-and-drafts/specs/portal-intake-form/spec.md#requirement-the-server-skips-a-hidden-field-req-icq-002
 */
final class FormValidationRun {
	/**
	 * What a condition reads: every answer, less each field found hidden.
	 *
	 * @var array<string, mixed>
	 */
	public array $shown;

	/**
	 * The errors by field.
	 *
	 * @var array<string, string>
	 */
	public array $errors = [];

	/**
	 * The accepted answers by field.
	 *
	 * @var array<string, mixed>
	 */
	public array $accepted = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $answers What the citizen submitted.
	 */
	public function __construct(
		public readonly array $answers,
	) {
		$this->shown = $answers;
	}//end __construct()
}//end class
