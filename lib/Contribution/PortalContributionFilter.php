<?php
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Scopes an aggregated contribution set to ONE portal (ADR-086 §2, ADR-046).
 *
 * @category  Service
 * @package   OCA\Portaliq\Contribution
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/portaliq
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Decides which contributions belong on a given portal.
 *
 * WHY AN ABSENT TARGET MEANS "EVERY PORTAL", WHICH LOOKS LIKE A LEAK AND IS NOT
 * ---------------------------------------------------------------------------
 * A contribution is a CAPABILITY DESCRIPTOR published by an INSTALLED APP —
 * "there is an inbox at this register/schema, reachable by this audience". It
 * is not tenant data. The per-object tenant boundary is enforced somewhere
 * else entirely: `PortalObjectReader`/`Writer` scope every read and write to
 * the subject's own scope value, and refuse with a 404 that cannot be used as
 * an existence oracle.
 *
 * So an app that declares no `portals` target is offering its capability to
 * the whole installation, and showing it on every portal discloses nothing a
 * visitor could not learn by looking at the app list. That default is also
 * what keeps ADR-046 unchanged: the twelve apps already shipping a
 * `PortalContributionProvider` keep working with no edit, which was the
 * explicit promise of the contract.
 *
 * DECLARING A TARGET IS THEREFORE OPT-IN NARROWING, NOT OPT-IN SAFETY. It is
 * for the municipality that runs a public Woo portal and a supplier portal on
 * one installation and does not want the supplier's invoice actions offered on
 * the public one. Both directions are tested: a declared target must EXCLUDE
 * the portals it does not name, and must INCLUDE the one it does — a filter
 * that dropped everything would satisfy the first test alone.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-contribution-must-be-scoped-to-the-portal-it-targets
 */
class PortalContributionFilter {


	/**
	 * Keep only the contributions that belong on `$portalSlug`.
	 *
	 * The parameter is `array<int, mixed>` and NOT `array<int, array<…>>`,
	 * which phpstan is right to insist on: the aggregate is assembled from
	 * third-party providers reached by a duck-typed call (ADR-046), so a
	 * member really can be a string, a null, or anything else a broken
	 * provider returned. Declaring the stricter type made the `is_array()`
	 * guard below dead code by contract while it was load-bearing in fact —
	 * a type that promises what the boundary cannot deliver.
	 *
	 * @param array<int, mixed> $contributions The aggregate, as received.
	 * @param string            $portalSlug    The serving portal's slug.
	 *
	 * @return array<int, array<string, mixed>> The contributions for this portal.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-contribution-must-be-scoped-to-the-portal-it-targets
	 */
	public function forPortal(array $contributions, string $portalSlug): array {
		$kept = [];

		foreach ($contributions as $contribution) {
			if (is_array($contribution) === false) {
				continue;
			}

			if ($this->targetsPortal(contribution: $contribution, portalSlug: $portalSlug) === true) {
				// The target list is an authoring concern, not a consumer one:
				// a client rendering the portal has already been told which
				// portal it is on. Dropping it also stops one portal's
				// response from naming another portal's slug.
				unset($contribution['portals']);
				$kept[] = $contribution;
			}
		}

		return $kept;
	}//end forPortal()


	/**
	 * Whether one contribution belongs on the given portal.
	 *
	 * @param array<string, mixed> $contribution The contribution.
	 * @param string               $portalSlug   The serving portal's slug.
	 *
	 * @return bool True when it belongs.
	 */
	private function targetsPortal(array $contribution, string $portalSlug): bool {
		if (array_key_exists('portals', $contribution) === false) {
			return true;
		}

		$targets = $contribution['portals'];
		if (is_array($targets) === false) {
			// A malformed target is NOT read as "no target". A provider that
			// meant to narrow and got the shape wrong would otherwise be
			// published everywhere — the failure mode this field exists to
			// prevent, arrived at by a typo. Fail closed (ADR-005).
			return false;
		}

		// An EMPTY list is a deliberate "nowhere", distinct from an absent
		// key. It is how an operator parks a contribution without
		// uninstalling the app.
		foreach ($targets as $target) {
			if (is_string($target) === true && $target === $portalSlug) {
				return true;
			}
		}

		return false;
	}//end targetsPortal()


}//end class
