<?php

/**
 * Portaliq Queued Submission
 *
 * One validated form submission as the intake queue records it: where it was
 * submitted, its answers and who sent them, and what the portal checked along
 * the way (accepted statements, worked-out fields, decided steps, verified
 * addresses).
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
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

/**
 * A validated submission handed to the intake queue.
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
 */
final class QueuedSubmission {
	/**
	 * Constructor.
	 *
	 * @param string                            $portal         The portal it was submitted on.
	 * @param string                            $route          The form page it came from.
	 * @param array<string, mixed>              $answers        The validated answers.
	 * @param string                            $subjectRef     The portal identity, or '' when anonymous.
	 * @param string                            $origin         The framing origin, or '' on the portal itself.
	 * @param array<int, array<string, string>> $statements     The statements accepted, with text version and time.
	 * @param array<int, string>                $computed       The names of the fields the portal worked out or a decision filled.
	 * @param array<string, string>             $decisions      The outcome of each decided step, by step id.
	 * @param array<int, array<string, string>> $verifiedEmails The addresses verified with a code, each with the time.
	 */
	public function __construct(
		public readonly string $portal,
		public readonly string $route,
		public readonly array $answers,
		public readonly string $subjectRef = '',
		public readonly string $origin = '',
		public readonly array $statements = [],
		public readonly array $computed = [],
		public readonly array $decisions = [],
		public readonly array $verifiedEmails = [],
	) {
	}//end __construct()
}//end class
