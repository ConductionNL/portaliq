<?php

/**
 * Portaliq Anonymous Contributions (portal-page-provisioning)
 *
 * Builds the anonymous-reachable surface of one provider.
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
 * @spec openspec/specs/portal-page-provisioning/spec.md#requirement-anonymous-submission-must-be-available-without-an-identity-provider
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The anonymous-reachable contribution of a provider for one audience.
 *
 * @spec openspec/specs/portal-page-provisioning/spec.md#requirement-anonymous-submission-must-be-available-without-an-identity-provider
 */
class AnonymousContributions {
	/**
	 * Constructor.
	 *
	 * @param PortalProviderLocator $locator Provider lookup.
	 * @param PortalManifestNormaliser $normaliser The fail-closed v3 UI-config sanitiser.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly PortalProviderLocator $locator,
		private readonly PortalManifestNormaliser $normaliser,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve one provider/audience pair's anonymous-only contribution, or
	 * an empty list when the provider errors, returns nothing, or has no
	 * anonymous entries for that audience.
	 *
	 * @param object $provider The resolved provider.
	 * @param string $appId The app id (for logging + the `app` tag).
	 * @param string $audience The audience to consult the provider for.
	 *
	 * @return array<int, array<string, mixed>> Zero or one contribution.
	 *
	 * @spec openspec/specs/portal-page-provisioning/spec.md#requirement-anonymous-submission-must-be-available-without-an-identity-provider
	 */
	public function forAudience(object $provider, string $appId, string $audience): array {
		try {
			$contribution = $this->locator->contributionOf(provider: $provider, subject: ['audience' => $audience]);
		} catch (Throwable $e) {
			$this->logger->error(
				'Portaliq: contribution provider failed (anonymous aggregation)',
				['app' => $appId, 'audience' => $audience, 'reason' => $e->getMessage()]
			);
			return [];
		}

		if (is_array($contribution) === false) {
			return [];
		}

		$contribution['app'] = $appId;
		$contribution = (new PublicRecordsNormaliser())->attach(contribution: $contribution, provider: $provider, appId: $appId);
		$anonymousOnly = $this->keepAnonymousOnly(contribution: $contribution);
		if ($this->hasAnonymousEntries(contribution: $anonymousOnly) === false) {
			return [];
		}

		try {
			$anonymousOnly = $this->normaliser->normalise(contribution: $anonymousOnly);
		} catch (Throwable $e) {
			$this->logger->error(
				'Portaliq: manifest normalisation failed (anonymous aggregation)',
				['app' => $appId, 'audience' => $audience, 'reason' => $e->getMessage()]
			);
		}

		// The normaliser may have dropped `anonymous` from an entry that ALSO
		// declared a non-low minTrust (fail-closed mutual exclusion) — filter
		// again so no flag-stripped entry can survive into an aggregate an
		// anonymous caller consumes.
		$anonymousOnly = $this->keepAnonymousOnly(contribution: $anonymousOnly);
		if ($this->hasAnonymousEntries(contribution: $anonymousOnly) === false) {
			return [];
		}

		return [$anonymousOnly];
	}//end forAudience()

	/**
	 * Drop every collection/action entry that is not explicitly flagged
	 * `anonymous: true`.
	 *
	 * @param array<string, mixed> $contribution One provider's raw (or
	 *                                           already-normalised)
	 *                                           contribution.
	 *
	 * @return array<string, mixed> The anonymous-only contribution.
	 *
	 * @spec openspec/specs/portal-page-provisioning/spec.md#requirement-anonymous-submission-must-be-available-without-an-identity-provider
	 */
	private function keepAnonymousOnly(array $contribution): array {
		foreach (['collections', 'actions'] as $section) {
			if (is_array(($contribution[$section] ?? null)) === false) {
				$contribution[$section] = [];
				continue;
			}

			$kept = [];
			foreach ($contribution[$section] as $entry) {
				if (is_array($entry) === false) {
					continue;
				}

				if (($entry['anonymous'] ?? false) !== true) {
					continue;
				}

				$kept[] = $entry;
			}

			$contribution[$section] = $kept;
		}//end foreach

		return $contribution;
	}//end keepAnonymousOnly()

	/**
	 * Whether an (already anonymous-filtered) contribution carries at least
	 * one surviving collection or action.
	 *
	 * @param array<string, mixed> $contribution The anonymous-filtered contribution.
	 *
	 * @return bool
	 */
	private function hasAnonymousEntries(array $contribution): bool {
		return count(($contribution['collections'] ?? [])) > 0
			|| count(($contribution['actions'] ?? [])) > 0
			|| count(($contribution['publicRecords'] ?? [])) > 0;
	}//end hasAnonymousEntries()
}//end class
