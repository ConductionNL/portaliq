<?php

/**
 * Portaliq Form Sign-In Gate
 *
 * Whether a visitor's session meets the sign-in level a form asks.
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
 * @spec openspec/specs/portal-intake-form/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use OCA\Portaliq\Kernel\Contracts\PortalApplicantPrefillInterface;
use OCA\Portaliq\Kernel\Contracts\PortalFormBindingResolverInterface;
use OCA\Portaliq\Kernel\Support\SessionTrust;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * The refusal for a visitor whose session does not meet a form's sign-in
 * level. Shared by every route that reads or runs part of a form.
 *
 * @spec openspec/specs/portal-intake-form/spec.md
 */
final class FormSignInGate {
	/**
	 * Constructor.
	 *
	 * @param PortalFormBindingResolverInterface $bindings Resolves a binding's required sign-in level.
	 * @param PortalApplicantPrefillInterface    $prefill  Names the Yivi attributes a form asks for.
	 */
	public function __construct(
		private readonly PortalFormBindingResolverInterface $bindings,
		private readonly PortalApplicantPrefillInterface $prefill,
	) {
	}//end __construct()

	/**
	 * The refusal for a visitor whose session does not meet the form's
	 * sign-in level, or null when the visitor may go on.
	 *
	 * The level is the strictest of the portal's, the binding's and the
	 * form's own (portaliq#725). No session is a 401 so the page can offer the
	 * sign-in; a session below the level is a 403.
	 *
	 * @param array<string, mixed>      $site    The portal.
	 * @param array<string, mixed>      $binding The binding.
	 * @param array<string, mixed>      $render  What the binding renders to.
	 * @param array<string, mixed>|null $subject The subject, or null.
	 *
	 * @return JSONResponse|null
	 *
	 * @spec openspec/specs/portal-intake-form/spec.md
	 */
	public function refusal(array $site, array $binding, array $render, ?array $subject): ?JSONResponse {
		$required = $this->bindings->requiredTrust(site: $site, binding: $binding, render: $render);
		if ($required === null) {
			return null;
		}

		// What the sign-in page shows beside the choice: the Yivi attributes
		// the form asks for (REQ-RIF-004) and whether an employee may fill it
		// in (REQ-RIF-005).
		$body = [
			'error' => 'sign_in_required',
			'minTrust' => $required,
			'fee' => ($render['fee'] ?? null),
			'yiviAttributes' => $this->prefill->yiviRequest(binding: $binding),
			'staffMayFill' => (($binding['staffMayFill'] ?? false) === true),
		];
		if ($subject === null) {
			return new JSONResponse($body, Http::STATUS_UNAUTHORIZED);
		}

		if ((new SessionTrust())->trustSatisfies(subjectTrust: ($subject['trust'] ?? ''), minTrust: $required) === false) {
			return new JSONResponse($body, Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end refusal()
}//end class
