<?php

/**
 * Portaliq Action Guards
 *
 * The guards that remove a contributed action whole rather than one of its
 * keys: a subject field that cannot be read, a scope claim that names a
 * frozen assertion claim, a cross-reference guard that could not be read,
 * and an unusable guest declaration.
 *
 * @category Contribution
 * @package  OCA\Portaliq\Contribution
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
 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-an-endpoint-action-must-be-able-to-receive-the-subjects-scope-from-the-server
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

use OCA\Portaliq\Kernel\ScopeClaims;

/**
 * Removes an action whose guard cannot be honoured. Built by
 * ActionConfigNormaliser for each action it normalises.
 *
 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-an-endpoint-action-must-be-able-to-receive-the-subjects-scope-from-the-server
 *
 * @SuppressWarnings(PHPMD.StaticAccess) -- PortalJwtService::isReservedScopeClaim() is a pure check on
 *                                         the frozen assertion claims, with no state to inject.
 */
final class ActionGuards {
	/**
	 * Constructor.
	 *
	 * @param CrossRefConfigNormaliser $crossRefs Sanitises the per-action cross-reference guard.
	 */
	public function __construct(
		private readonly CrossRefConfigNormaliser $crossRefs,
	) {
	}//end __construct()

	/**
	 * Run the guards that can remove an action whole, in order.
	 *
	 * @param array<string, mixed> $action    The shaped action.
	 * @param array<int, string>   $whitelist The action's `fields` whitelist.
	 *
	 * @return array<string, mixed>|null The action, or null when a guard cannot be honoured.
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-an-endpoint-action-must-be-able-to-receive-the-subjects-scope-from-the-server
	 */
	public function guard(array $action, array $whitelist): ?array {
		// A server-stamped subject field (portal-take-assessment) is a
		// guard too: an action whose field name cannot be read is removed
		// rather than forwarded without its stamp.
		if ($this->subjectFieldIsMalformed(action: $action) === true) {
			return null;
		}

		// A declared scope claim rides in the signed assertion; one that
		// names a frozen assertion claim (`sub`, `iss`, ...) is removed with
		// its action, so it can never stand in for that claim.
		if (ScopeClaims::isReserved(scopeClaim: ($action['scopeClaim'] ?? null)) === true) {
			return null;
		}

		// The cross-reference guard, and the one normaliser that can
		// remove an action rather than a key: a create whose guard could
		// not be read must not be offered without it.
		$guarded = $this->crossRefs->normaliseAction(action: $action, whitelist: $whitelist);
		if ($guarded === null) {
			return null;
		}

		// After the cross-ref normaliser, because `onCase.crossRef` must name
		// one of the references it just sanitised. A malformed `onCase` drops
		// its own key and keeps the action: it only stops the action being
		// offered on a case page, it guards nothing.
		$guarded = (new OnCaseConfigNormaliser())->normaliseAction(action: $guarded);

		// A guest action (identity-guest-page-for-signed-links) without a
		// token field, local endpoints and `low` trust is removed whole.
		return (new GuestActionConfigNormaliser())->normaliseAction(action: $guarded);
	}//end guard()

	/**
	 * Whether an action declares a `subjectField` that is not a plain field
	 * name. Absent is fine; anything declared must match
	 * `^[a-zA-Z][a-zA-Z0-9_]*$`.
	 *
	 * @param array<string, mixed> $action The action.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-an-endpoint-action-must-be-able-to-receive-the-subjects-scope-from-the-server
	 */
	private function subjectFieldIsMalformed(array $action): bool {
		if (array_key_exists('subjectField', $action) === false) {
			return false;
		}

		return is_string($action['subjectField']) === false
			|| preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $action['subjectField']) !== 1;
	}//end subjectFieldIsMalformed()
}//end class
