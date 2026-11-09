<?php

/**
 * Portaliq Submission Gate (portal-intake-form-as-an-object)
 *
 * The one pipeline every route that files a form submission runs, so the
 * portal's own submit and the embedded frame's submit cannot drift apart.
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
 * @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use OCA\Portaliq\Service\Identity\PortalChallengeService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Between a resolved form and a destination submit: the form must accept
 * submissions here, the anti-bot challenge must hold when the form asks for
 * one, and the submission checks (validation, family and e-mail proofs,
 * calculations and decisions worked out again, statements) must pass. What
 * comes out is the checked answers and the server-side record that goes with
 * them; the browser's own calculated or decided values never get through.
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
 */
class PortalSubmissionGate {
	/**
	 * Constructor.
	 *
	 * @param PortalChallengeService $challenge The portal's own anti-bot challenge.
	 * @param PortalSubmissionChecks $checks    The checks between a validated form and a recorded submission.
	 */
	public function __construct(
		private readonly PortalChallengeService $challenge,
		private readonly PortalSubmissionChecks $checks,
	) {
	}//end __construct()

	/**
	 * The refusal for a form that takes no submission on the portal, or null.
	 *
	 * @param array<string, mixed> $render What the binding renders to.
	 *
	 * @return JSONResponse|null
	 *
	 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
	 */
	public function notSubmittable(array $render): ?JSONResponse {
		if (($render['resolvesToNoForm'] ?? false) === true || ($render['kind'] ?? '') === PortalFormBindingResolver::KIND_EXTERNAL) {
			// An external intake is filled in at its own host; the portal has
			// nothing to accept here.
			return new JSONResponse(['error' => 'form_not_submittable'], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end notSubmittable()

	/**
	 * Run the whole pipeline over one submission.
	 *
	 * The sign-in level is the caller's to check before this: the portal's own
	 * submit reads a session, the frame reads none and refuses outright.
	 *
	 * @param array<string, mixed>      $site           The resolved portal.
	 * @param array<string, mixed>      $render         What the binding renders to.
	 * @param string                    $route          The form page submitted.
	 * @param array<string, mixed>      $answers        What the visitor answered.
	 * @param array<string, mixed>|null $subject        The session's subject, or null.
	 * @param array<string, mixed>      $challenge      The challenge proof: `nonce`, `solution`, `expiresAt`, `signature`.
	 * @param array<int, string>        $statements     The keys of the statements the visitor ticked.
	 * @param array<string, string>     $verifiedEmails Proofs of verified e-mail addresses, by address.
	 *
	 * @return array{answers: array<string, mixed>, server: array<string, mixed>}|JSONResponse
	 *         The checked answers and the server-side record, or the refusal.
	 *
	 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
	 */
	public function admit(
		array $site,
		array $render,
		string $route,
		array $answers,
		?array $subject,
		array $challenge,
		array $statements,
		array $verifiedEmails
	): array|JSONResponse {
		$refusal = $this->notSubmittable(render: $render);
		if ($refusal !== null) {
			return $refusal;
		}

		if (($render['settings']['challenge'] ?? false) === true) {
			$accepted = $this->challenge->accepts(
				site: $site,
				surface: 'form',
				submission: $answers,
				nonce: (string)($challenge['nonce'] ?? ''),
				solution: (string)($challenge['solution'] ?? ''),
				expiresAt: (int)($challenge['expiresAt'] ?? 0),
				signature: (string)($challenge['signature'] ?? '')
			);
			if ($accepted === false) {
				return new JSONResponse(['error' => 'challenge_failed'], Http::STATUS_FORBIDDEN);
			}
		}

		$prepared = $this->checks->prepare(
			render: $render,
			site: $site,
			route: $route,
			answers: $answers,
			subject: $subject,
			statements: $statements,
			verifiedEmails: $verifiedEmails
		);
		if ($prepared instanceof JSONResponse) {
			return $prepared;
		}

		return [
			'answers' => $prepared['answers'],
			'server' => [
				'statements' => $prepared['statements'],
				'decisions' => $prepared['decisions'],
				'verifiedEmails' => $prepared['verified'],
				'fieldMeta' => (new DestinationMapping())->fieldMeta(computed: $prepared['computed']),
			],
		];
	}//end admit()
}//end class
