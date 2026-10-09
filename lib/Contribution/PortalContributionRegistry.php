<?php

/**
 * Portaliq Portal Contribution Registry
 *
 * Discovers every installed app's contribution provider by convention FQCN
 * (`OCA\{Namespace}\Portal\PortalContributionProvider`) and aggregates the
 * contributions that apply to a given authenticated subject — filtered by the
 * subject's audience AND trust level (contract v2). Providers are duck-typed
 * (`getAudiences()` preferred, `getAudience()` fallback, + getContribution), so
 * a contributing app does NOT hard-depend on Portaliq's interface. Collections
 * and actions whose `minTrust` exceeds the subject's trust are dropped here, in
 * the ONE aggregation path every authorisation lookup flows through; entries
 * with an unrecognised `minTrust` are dropped for every subject (fail-closed).
 * This is the read-side of ADR-046: Portaliq collects declarative manifests,
 * then renders them and reads their collections through OpenRegister.
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
 * @spec openspec/changes/supplier-portal/tasks.md#T04
 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T2
 * @spec openspec/specs/portal-page-provisioning/spec.md#requirement-anonymous-submission-must-be-available-without-an-identity-provider
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

use OCA\Portaliq\Service\Identity\PortalAccountLookup;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Aggregates registered portal contributions for a subject.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T04
 *
 * @SuppressWarnings(PHPMD.StaticAccess) -- PortalSessionService's trust
 * helpers are deliberately THE single normalisation/comparison point
 * (contract-v2 design decision); calling them statically keeps one source of
 * truth on every path.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) -- the registry wires the locator, the
 * normalisers and the hidden-page and anonymous collaborators; the coupling is the list.
 */
class PortalContributionRegistry {
	/**
	 * Locates each app's provider class. Built here from the injected
	 * dependencies when not supplied, so the constructor signature every
	 * existing caller and test uses keeps working unchanged.
	 *
	 * @var PortalProviderLocator
	 */
	private readonly PortalProviderLocator $locator;

	/**
	 * Reads the pages hidden for a subject's account.
	 *
	 * @var HiddenPagesReader
	 */
	private readonly HiddenPagesReader $hidden;

	/**
	 * Builds the anonymous-reachable contribution of a provider.
	 *
	 * @var AnonymousContributions
	 */
	private readonly AnonymousContributions $anonymous;

	/**
	 * Constructor.
	 *
	 * `$container` is deliberately NOT promoted to a property: since the provider
	 * lookup moved to PortalProviderLocator, the registry itself never touches the
	 * container again, and a promoted-but-unread dependency is dead weight. It
	 * stays in the signature — unpromoted, forwarded to the locator — because
	 * every caller and test constructs this class positionally, and dropping the
	 * argument would silently shift `$logger` into the container's slot.
	 *
	 * @param IAppManager $appManager For enumerating installed apps.
	 * @param ContainerInterface $container For constructing each app's provider.
	 * @param LoggerInterface $logger The logger.
	 * @param PortalManifestNormaliser $normaliser The fail-closed v3 UI-config sanitiser.
	 * @param PortalProviderLocator|null $locator Provider lookup; built from the above when null.
	 * @param PortalAccountLookup|null $accounts Reads the account's hidden pages; none hides nothing.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly PortalManifestNormaliser $normaliser = new PortalManifestNormaliser(),
		?PortalProviderLocator $locator = null,
		private readonly ?PortalAccountLookup $accounts = null,
	) {
		$this->locator   = ($locator ?? new PortalProviderLocator($appManager, $container, $logger));
		$this->hidden    = new HiddenPagesReader(accounts: $accounts, logger: $logger);
		$this->anonymous = new AnonymousContributions(locator: $this->locator, normaliser: $this->normaliser, logger: $this->logger);
	}//end __construct()

	/**
	 * Aggregate the contributions that apply to a subject.
	 *
	 * @param array<string, mixed> $subject The resolved subject (audience +
	 *                                      organisation + subjectRef, all
	 *                                      server-derived).
	 *
	 * @return array<string, mixed> `{ audience, organisation, contributions[] }`.
	 *
	 * @spec openspec/changes/supplier-portal/tasks.md#T04
	 * @spec openspec/changes/update-row-action-condition/specs/portal-contribution-contract/spec.md#requirement-a-malformed-row-condition-must-be-dropped-with-a-warning-req-urc-002
	 */
	public function aggregateFor(array $subject): array {
		$audience = (string)($subject['audience'] ?? '');
		$trust = PortalSessionService::normaliseTrust(trust: ($subject['trust'] ?? ''));
		$contributions = [];
		$stepUp = [];

		foreach ($this->appManager->getInstalledApps() as $appId) {
			$provider = $this->resolveProvider(appId: (string)$appId);
			// The method_exists checks narrow for static analysis; duck typing
			// means a contributing app does NOT need to implement (and thus
			// hard-depend on) Portaliq's interface — only the two methods.
			if ($provider === null || method_exists($provider, 'getContribution') === false) {
				continue;
			}

			if ($this->servesAudience(provider: $provider, audience: $audience) === false) {
				continue;
			}

			try {
				$contribution = $this->locator->contributionOf(provider: $provider, subject: $subject);
			} catch (Throwable $e) {
				$this->logger->error('Portaliq: contribution provider failed', ['app' => $appId, 'reason' => $e->getMessage()]);
				continue;
			}

			if (is_array($contribution) === false) {
				continue;
			}

			$contribution['app'] = $appId;
			$contribution = (new PublicRecordsNormaliser())->attach(contribution: $contribution, provider: $provider, appId: (string)$appId);
			$stepUp = array_merge($stepUp, $this->droppedForTrust(contribution: $contribution, trust: $trust, appId: (string)$appId));
			$filtered = $this->filterByTrust(contribution: $contribution, trust: $trust);

			// Sanitise the v3 UI-configuration vocabulary AFTER trust filtering,
			// so a surviving page can never reference a trust-dropped entry. The
			// normaliser is fail-closed and never throws; guard anyway so a
			// provider config bug degrades to the un-normalised (but trust-
			// filtered) manifest rather than a 500.
			// The audiences the provider serves bound a start tile's
			// `audiences` (site-nlds-widget-palette D6); input only.
			$filtered['servedAudiences'] = $this->providerAudiences(provider: $provider);
			try {
				$filtered = $this->normaliser->normalise(contribution: $filtered);
			} catch (Throwable $e) {
				$this->logger->error('Portaliq: manifest normalisation failed', ['app' => $appId, 'reason' => $e->getMessage()]);
			}

			unset($filtered['servedAudiences']);

			// A row action's `rowWhen` (update-row-action-condition): an
			// unknown operator or a malformed update condition is dropped and
			// logged with the app that declared it.
			$filtered = (new RowWhenNormaliser())->normaliseContribution(
				contribution: $filtered,
				appId: (string)$appId,
				logger: $this->logger
			);

			$contributions[] = (new NotificationRuleNormaliser())->normaliseContribution(
				contribution: $filtered,
				appId: (string)$appId,
				logger: $this->logger
			);
		}//end foreach

		// Actions that attach to another app's collection (woo-journey-entry-
		// points D3) resolve across contributions, so only once all are in.
		$contributions = (new AttachedActionResolver())->resolve(contributions: $contributions);

		$aggregate = [
			'audience' => $audience,
			'organisation' => (string)($subject['organisation'] ?? ''),
			'contributions' => $contributions,
			'stepUp' => $stepUp,
		];

		// The pages a clerk hid for this account, and the collections only
		// those pages showed (operate-pages-per-portal-and-client REQ-PGC-002).
		return (new PageChoice())->withoutHidden(aggregate: $aggregate, hidden: $this->hidden->forSubject(subject: $subject));
	}//end aggregateFor()

	/**
	 * Aggregate the ANONYMOUS-reachable surface across every installed
	 * provider — the no-subject sibling of `aggregateFor()`
	 * (portal-page-provisioning). Enumerates every installed app's provider
	 * exactly as `aggregateFor()` does, but for EACH audience the provider
	 * declares (duck-typed `getAudiences()`/`getAudience()`, same as
	 * `servesAudience()`) — there is no single subject audience to filter
	 * by, so every audience the provider serves is consulted. Only
	 * `collections`/`actions` entries explicitly flagged `anonymous: true`
	 * survive into the returned contribution — every other entry in the same
	 * contribution (private, subject-scoped siblings) is dropped BEFORE
	 * normalisation, mirroring `filterByTrust()`'s shape (filtering on a
	 * different flag) so a surviving page can never reference a dropped
	 * entry. A provider/audience pair contributing zero anonymous entries is
	 * omitted entirely — an anonymous caller never sees an empty shell.
	 *
	 * The fail-closed anonymous/minTrust mutual exclusion lives in
	 * `PortalManifestNormaliser` and can itself drop the `anonymous` flag
	 * from an entry that also declares a non-`low` `minTrust`; the anonymous
	 * filter therefore runs a SECOND time after normalisation so a
	 * flag-stripped entry can never survive into an aggregate an anonymous
	 * caller consumes.
	 *
	 * @return array<string, mixed> `{ contributions[] }` — no `audience` /
	 *                              `organisation` keys, since there is no
	 *                              subject.
	 *
	 * @spec openspec/specs/portal-page-provisioning/spec.md#requirement-anonymous-submission-must-be-available-without-an-identity-provider
	 */
	public function aggregateAnonymous(): array {
		$contributions = [];

		foreach ($this->appManager->getInstalledApps() as $appId) {
			$provider = $this->resolveProvider(appId: (string)$appId);
			if ($provider === null || method_exists($provider, 'getContribution') === false) {
				continue;
			}

			$served = $this->providerAudiences(provider: $provider);
			foreach ($served as $audience) {
				$contributions = array_merge(
					$contributions,
					$this->anonymous->forAudience(provider: $provider, appId: (string)$appId, audience: $audience, served: $served)
				);
			}
		}//end foreach

		return ['contributions' => $contributions];
	}//end aggregateAnonymous()

	/**
	 * The full set of audiences a provider serves (contract v2, A2 duck
	 * typing — same preference order as `servesAudience()`): `getAudiences()`
	 * when present, else the single `getAudience()` value, else none.
	 *
	 * @param object $provider The resolved provider.
	 *
	 * @return array<int, string>
	 */
	private function providerAudiences(object $provider): array {
		if (method_exists($provider, 'getAudiences') === true) {
			$audiences = $provider->getAudiences();
			if (is_array($audiences) === false) {
				return [];
			}

			return array_values(array_filter($audiences, static fn ($a) => is_string($a) === true && $a !== ''));
		}

		if (method_exists($provider, 'getAudience') === true) {
			$audience = $provider->getAudience();
			if (is_string($audience) === true && $audience !== '') {
				return [$audience];
			}
		}

		return [];
	}//end providerAudiences()

	/**
	 * Whether a provider serves the subject's audience (contract v2, A2).
	 *
	 * Prefers the duck-typed `getAudiences(): array` when present (multi-
	 * audience providers); falls back to the v1 `getAudience(): string`. A
	 * provider exposing neither serves nobody (fail-closed). The audience
	 * vocabulary is an open string set — no enum is enforced here.
	 *
	 * @param object $provider The resolved provider.
	 * @param string $audience The subject's audience.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T2
	 */
	private function servesAudience(object $provider, string $audience): bool {
		if (method_exists($provider, 'getAudiences') === true) {
			$audiences = $provider->getAudiences();
			if (is_array($audiences) === false) {
				return false;
			}

			return in_array($audience, $audiences, true);
		}

		if (method_exists($provider, 'getAudience') === true) {
			return $provider->getAudience() === $audience;
		}

		return false;
	}//end servesAudience()

	/**
	 * Drop every collection and action whose `minTrust` the subject does not
	 * satisfy (contract v2, A3). Filtering here — inside the ONE aggregation
	 * path that index(), collection(), create(), and action() all authorise
	 * against — enforces trust on every data path with one source of truth;
	 * the controller re-checks the matched entry as defense in depth. A
	 * missing `minTrust` defaults to `low`; an unrecognised value renders the
	 * entry unsatisfiable for every subject (fail-closed, ADR-005).
	 *
	 * @param array<string, mixed> $contribution One app's contribution manifest.
	 * @param string $trust The subject's normalised trust.
	 *
	 * @return array<string, mixed> The trust-filtered contribution.
	 *
	 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T2
	 */
	private function filterByTrust(array $contribution, string $trust): array {
		foreach (['collections', 'actions'] as $section) {
			if (is_array(($contribution[$section] ?? null)) === false) {
				continue;
			}

			$kept = [];
			foreach ($contribution[$section] as $entry) {
				if (is_array($entry) === false) {
					continue;
				}

				if (PortalSessionService::trustSatisfies($trust, ($entry['minTrust'] ?? null)) === false) {
					continue;
				}

				$kept[] = $entry;
			}

			$contribution[$section] = $kept;
		}//end foreach

		return $contribution;
	}//end filterByTrust()

	/**
	 * Every audience an installed app's provider serves.
	 *
	 * The change-rule index asks each of these for its contributions without
	 * a signed-in subject: an OpenRegister save can come from a handler or a
	 * job, and the question is whether any app wants a resident told.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-declared-change-reaches-the-residents-inbox-req-nap-002
	 */
	public function servedAudiences(): array {
		$audiences = [];
		foreach ($this->appManager->getInstalledApps() as $appId) {
			$provider = $this->resolveProvider(appId: (string)$appId);
			if ($provider !== null) {
				$audiences = array_merge($audiences, $this->providerAudiences(provider: $provider));
			}
		}

		return array_values(array_unique($audiences));
	}//end servedAudiences()

	/**
	 * Resolve one app's contribution provider, or null when it ships none.
	 *
	 * Delegates to PortalProviderLocator, which owns the FQCN derivation and
	 * documents why guessing the namespace from the app id was wrong.
	 *
	 * @param string $appId The app id.
	 *
	 * @return object|null
	 */
	private function resolveProvider(string $appId): ?object {
		return $this->locator->locate(appId: $appId);
	}//end resolveProvider()


	/**
	 * The collections this subject's trust drops, as `stepUp` entries: app,
	 * collection id, label and the trust they need, and never a row or a count
	 * (site-member-voting-record-and-confidential-papers REQ-SCR-004).
	 *
	 * @param array<string, mixed> $contribution One app's contribution.
	 * @param string $trust The subject's normalised trust.
	 * @param string $appId The app id.
	 *
	 * @return array<int, array<string, string>> The entries.
	 *
	 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t6
	 */
	private function droppedForTrust(array $contribution, string $trust, string $appId): array {
		$dropped = [];
		foreach ((array)($contribution['collections'] ?? []) as $collection) {
			if (is_array($collection) === false || PortalSessionService::trustSatisfies($trust, ($collection['minTrust'] ?? null)) === true) {
				continue;
			}

			$needs = $collection['minTrust'] ?? null;
			if (is_string($needs) === false || PortalSessionService::normaliseTrust(trust: $needs) !== $needs) {
				// An unrecognised level is unsatisfiable for everyone: no login would help, so it is no step-up.
				continue;
			}

			$label = $collection['label'] ?? '';
			if (is_string($label) === false) {
				$label = '';
			}

			$dropped[] = ['app' => $appId, 'collection' => (string)($collection['id'] ?? ''), 'label' => $label, 'minTrust' => $needs];
		}

		return $dropped;
	}//end droppedForTrust()
}//end class
