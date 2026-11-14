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

use OCA\Portaliq\Kernel\Contracts\PortalAccountLookupInterface;
use OCA\Portaliq\Kernel\Contracts\PortalContributionRegistryInterface;
use OCA\Portaliq\Kernel\Support\SessionTrust;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Aggregates registered portal contributions for a subject.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T04
 *
 * @SuppressWarnings(PHPMD.StaticAccess) -- SessionTrust's trust
 * helpers are deliberately THE single normalisation/comparison point
 * (contract-v2 design decision); calling them statically keeps one source of
 * truth on every path.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) -- the registry wires the locator, the
 * normalisers and the hidden-page and anonymous collaborators; the coupling is the list.
 */
class PortalContributionRegistry implements PortalContributionRegistryInterface {
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
	 * @param PortalAccountLookupInterface|null $accounts Reads the account's hidden pages; none hides nothing.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly PortalManifestNormaliser $normaliser = new PortalManifestNormaliser(),
		?PortalProviderLocator $locator = null,
		private readonly ?PortalAccountLookupInterface $accounts = null,
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
		$run = new AggregateRun(subject: $subject, trust: SessionTrust::normaliseTrust(trust: ($subject['trust'] ?? '')));
		foreach ($this->appManager->getInstalledApps() as $appId) {
			$this->collectContribution(run: $run, appId: (string)$appId);
		}

		// Actions that attach to another app's collection (woo-journey-entry-
		// points D3) resolve across contributions, so only once all are in.
		$contributions = (new AttachedActionResolver())->resolve(contributions: $run->contributions);

		$aggregate = [
			'audience' => $run->audience,
			'organisation' => (string)($subject['organisation'] ?? ''),
			'contributions' => $contributions,
			'stepUp' => $run->stepUp,
		];

		// The pages a clerk hid for this account, and the collections only
		// those pages showed (operate-pages-per-portal-and-client REQ-PGC-002).
		return (new PageChoice())->withoutHidden(aggregate: $aggregate, hidden: $this->hidden->forSubject(subject: $subject));
	}//end aggregateFor()

	/**
	 * Add one installed app's contribution to the aggregate, when the app
	 * provides one for the subject's audience.
	 *
	 * @param AggregateRun $run   The aggregate.
	 * @param string       $appId The installed app.
	 *
	 * @return void
	 */
	private function collectContribution(AggregateRun $run, string $appId): void {
		$run->appId    = $appId;
		$run->provider = $this->contributingProvider(run: $run);
		if ($run->provider === null) {
			return;
		}

		$contribution = $this->providedContribution(run: $run, provider: $run->provider);
		if ($contribution === null) {
			return;
		}

		$run->contributions[] = $this->withNormalisedRules(run: $run, contribution: $this->normalisedManifest(run: $run, contribution: $contribution));
	}//end collectContribution()

	/**
	 * The app's provider when it contributes and serves the subject's
	 * audience, else null.
	 *
	 * @param AggregateRun $run The aggregate, at the app being read.
	 *
	 * @return object|null
	 */
	private function contributingProvider(AggregateRun $run): ?object {
		$provider = $this->resolveProvider(appId: $run->appId);
		// The method_exists checks narrow for static analysis; duck typing
		// means a contributing app does NOT need to implement (and thus
		// hard-depend on) Portaliq's interface — only the two methods.
		if ($provider === null || method_exists($provider, 'getContribution') === false) {
			return null;
		}

		if ((new ProviderAudiences())->serves(provider: $provider, audience: $run->audience) === false) {
			return null;
		}

		return $provider;
	}//end contributingProvider()

	/**
	 * The provider's contribution for the subject, stamped with its app and
	 * its public records, or null when the provider fails or gives none.
	 *
	 * @param AggregateRun $run      The aggregate, at the app being read.
	 * @param object       $provider The app's provider.
	 *
	 * @return array<string, mixed>|null
	 */
	private function providedContribution(AggregateRun $run, object $provider): ?array {
		try {
			$contribution = $this->locator->contributionOf(provider: $provider, subject: $run->subject);
		} catch (Throwable $e) {
			$this->logger->error('Portaliq: contribution provider failed', ['app' => $run->appId, 'reason' => $e->getMessage()]);
			return null;
		}

		if (is_array($contribution) === false) {
			return null;
		}

		$contribution['app'] = $run->appId;
		return (new PublicRecordsNormaliser())->attach(contribution: $contribution, provider: $provider, appId: $run->appId);
	}//end providedContribution()

	/**
	 * The contribution filtered to the subject's trust, with the step-up
	 * hints for what was dropped recorded, and its v3 UI-configuration
	 * vocabulary sanitised.
	 *
	 * Sanitised AFTER trust filtering, so a surviving page can never
	 * reference a trust-dropped entry. The normaliser is fail-closed and
	 * never throws; guarded anyway so a provider config bug degrades to the
	 * un-normalised (but trust-filtered) manifest rather than a 500.
	 *
	 * @param AggregateRun         $run          The aggregate, at the app being read.
	 * @param array<string, mixed> $contribution The provided contribution.
	 *
	 * @return array<string, mixed>
	 */
	private function normalisedManifest(AggregateRun $run, array $contribution): array {
		$run->stepUp = array_merge($run->stepUp, $this->droppedForTrust(contribution: $contribution, trust: $run->trust, appId: $run->appId));
		$filtered    = $this->filterByTrust(contribution: $contribution, trust: $run->trust);

		// The audiences the provider serves bound a start tile's
		// `audiences` (site-nlds-widget-palette D6); input only.
		$filtered['servedAudiences'] = (new ProviderAudiences())->served(provider: $run->provider);
		try {
			$filtered = $this->normaliser->normalise(contribution: $filtered);
		} catch (Throwable $e) {
			$this->logger->error('Portaliq: manifest normalisation failed', ['app' => $run->appId, 'reason' => $e->getMessage()]);
		}

		unset($filtered['servedAudiences']);
		return $filtered;
	}//end normalisedManifest()

	/**
	 * The contribution with its row conditions and notification rules
	 * normalised: an unknown operator or a malformed condition or rule is
	 * dropped and logged with the app that declared it
	 * (update-row-action-condition).
	 *
	 * @param AggregateRun         $run          The aggregate, at the app being read.
	 * @param array<string, mixed> $contribution The sanitised contribution.
	 *
	 * @return array<string, mixed>
	 */
	private function withNormalisedRules(AggregateRun $run, array $contribution): array {
		$contribution = (new RowWhenNormaliser())->normaliseContribution(
			contribution: $contribution,
			appId: $run->appId,
			logger: $this->logger
		);

		return (new NotificationRuleNormaliser())->normaliseContribution(
			contribution: $contribution,
			appId: $run->appId,
			logger: $this->logger
		);
	}//end withNormalisedRules()

	/**
	 * Aggregate the ANONYMOUS-reachable surface across every installed
	 * provider — the no-subject sibling of `aggregateFor()`
	 * (portal-page-provisioning). Enumerates every installed app's provider
	 * exactly as `aggregateFor()` does, but for EACH audience the provider
	 * declares (duck-typed `getAudiences()`/`getAudience()`, same as
	 * `ProviderAudiences::serves()`) — there is no single subject audience to filter
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

			$served = (new ProviderAudiences())->served(provider: $provider);
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
	 * The public start tiles of the serving portal (site-nlds-widget-palette
	 * D6): every action that declares a `summary` and that a page offers,
	 * read for each audience every provider serves. A tile carries label,
	 * summary, audiences and route only (StartTileCollector), so the public
	 * list never names a field, an endpoint or any data.
	 *
	 * @return array<int, array{label: string, summary: string, audiences: array<int, string>, route: string}>
	 *
	 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-contribution-contract/spec.md#requirement-an-action-may-offer-itself-as-a-start-tile-with-a-summary-and-its-audiences-req-snw-020
	 */
	public function startTiles(): array {
		$tiles = new StartTileCollector();
		foreach ($this->appManager->getInstalledApps() as $appId) {
			$provider = $this->resolveProvider(appId: (string)$appId);
			if ($provider === null || method_exists($provider, 'getContribution') === false) {
				continue;
			}

			$served = (new ProviderAudiences())->served(provider: $provider);
			foreach ($served as $audience) {
				try {
					$contribution = $this->locator->contributionOf(provider: $provider, subject: ['audience' => $audience]);
					if (is_array($contribution) === false) {
						continue;
					}

					$contribution['servedAudiences'] = $served;
					$contribution = $this->normaliser->normalise(contribution: $contribution);
				} catch (Throwable $e) {
					$this->logger->error('Portaliq: contribution provider failed (start tiles)', ['app' => $appId, 'reason' => $e->getMessage()]);
					continue;
				}

				$contribution['app'] = (string)$appId;
				$tiles->add(contribution: $contribution);
			}
		}//end foreach

		return $tiles->all();
	}//end startTiles()

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

				if (SessionTrust::trustSatisfies($trust, ($entry['minTrust'] ?? null)) === false) {
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
				$audiences = array_merge($audiences, (new ProviderAudiences())->served(provider: $provider));
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
			if (is_array($collection) === false || SessionTrust::trustSatisfies($trust, ($collection['minTrust'] ?? null)) === true) {
				continue;
			}

			$needs = $collection['minTrust'] ?? null;
			if (is_string($needs) === false || SessionTrust::normaliseTrust(trust: $needs) !== $needs) {
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
