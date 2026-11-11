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
	 * @param array<int, string> $served Every audience the provider serves (bounds a start tile's `audiences`).
	 *
	 * @return array<int, array<string, mixed>> Zero or one contribution.
	 *
	 * @spec openspec/specs/portal-page-provisioning/spec.md#requirement-anonymous-submission-must-be-available-without-an-identity-provider
	 */
	public function forAudience(object $provider, string $appId, string $audience, array $served = []): array {
		$read = new AnonymousRead(provider: $provider, appId: $appId, audience: $audience, served: $served);
		$contribution = $this->providerContribution(read: $read);
		if ($contribution === null) {
			return [];
		}

		$anonymousOnly = $this->keepAnonymousOnly(contribution: $this->tagged(read: $read, contribution: $contribution));
		if ($this->hasAnonymousEntries(contribution: $anonymousOnly) === false) {
			return [];
		}

		// The normaliser may have dropped `anonymous` from an entry that ALSO
		// declared a non-low minTrust (fail-closed mutual exclusion) — filter
		// again so no flag-stripped entry can survive into an aggregate an
		// anonymous caller consumes.
		$anonymousOnly = $this->keepAnonymousOnly(contribution: $this->normalised(read: $read, contribution: $anonymousOnly));
		if ($this->hasAnonymousEntries(contribution: $anonymousOnly) === false) {
			return [];
		}

		return [$anonymousOnly];
	}//end forAudience()

	/**
	 * What the provider contributes for the audience, or null when it fails
	 * (logged) or answers no array.
	 *
	 * @param AnonymousRead $read The read.
	 *
	 * @return array<string, mixed>|null
	 */
	private function providerContribution(AnonymousRead $read): ?array {
		try {
			$contribution = $this->locator->contributionOf(provider: $read->provider, subject: ['audience' => $read->audience]);
		} catch (Throwable $e) {
			$this->logger->error(
				'Portaliq: contribution provider failed (anonymous aggregation)',
				['app' => $read->appId, 'audience' => $read->audience, 'reason' => $e->getMessage()]
			);
			return null;
		}

		if (is_array($contribution) === false) {
			return null;
		}

		return $contribution;
	}//end providerContribution()

	/**
	 * The contribution tagged with its app, with its public records attached.
	 *
	 * @param AnonymousRead        $read         The read.
	 * @param array<string, mixed> $contribution The provider's contribution.
	 *
	 * @return array<string, mixed>
	 */
	private function tagged(AnonymousRead $read, array $contribution): array {
		$contribution['app'] = $read->appId;

		return (new PublicRecordsNormaliser())->attach(contribution: $contribution, provider: $read->provider, appId: $read->appId);
	}//end tagged()

	/**
	 * The contribution through the manifest normaliser, which sees the
	 * provider's served audiences (they bound a start tile's `audiences`;
	 * input only). A failing normaliser is logged and the entries are kept
	 * as they were.
	 *
	 * @param AnonymousRead        $read         The read.
	 * @param array<string, mixed> $contribution The anonymous-only contribution.
	 *
	 * @return array<string, mixed>
	 */
	private function normalised(AnonymousRead $read, array $contribution): array {
		$contribution['servedAudiences'] = $read->served;
		try {
			$contribution = $this->normaliser->normalise(contribution: $contribution);
		} catch (Throwable $e) {
			$this->logger->error(
				'Portaliq: manifest normalisation failed (anonymous aggregation)',
				['app' => $read->appId, 'audience' => $read->audience, 'reason' => $e->getMessage()]
			);
		}

		unset($contribution['servedAudiences']);

		return $contribution;
	}//end normalised()

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
