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
 * @spec openspec/changes/contract-v2/tasks.md#T2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

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
 */
class PortalContributionRegistry
{
    /**
     * FQCN template each contributing app implements its provider at. `%s` is
     * the app's namespace (ucfirst of the app id). Discovering by concrete class
     * — rather than an alias — is what makes cross-app discovery work: the DI
     * container constructs any autoloadable class by reflection, whereas a
     * registerServiceAlias only resolves inside the registering app's container.
     */
    private const PROVIDER_CLASS = 'OCA\\%s\\Portal\\PortalContributionProvider';

    /**
     * Constructor.
     *
     * @param IAppManager        $appManager For enumerating installed apps.
     * @param ContainerInterface $container  For constructing each app's provider.
     * @param LoggerInterface    $logger     The logger.
     */
    public function __construct(
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
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
     */
    public function aggregateFor(array $subject): array
    {
        $audience      = (string) ($subject['audience'] ?? '');
        $trust         = PortalSessionService::normaliseTrust(trust: ($subject['trust'] ?? ''));
        $contributions = [];

        foreach ($this->appManager->getInstalledApps() as $appId) {
            $provider = $this->resolveProvider(appId: (string) $appId);
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
                $contribution = $provider->getContribution($subject);
            } catch (Throwable $e) {
                $this->logger->error('Portaliq: contribution provider failed', ['app' => $appId, 'reason' => $e->getMessage()]);
                continue;
            }

            if (is_array($contribution) === false) {
                continue;
            }

            $contribution['app'] = $appId;
            $contributions[]     = $this->filterByTrust(contribution: $contribution, trust: $trust);
        }//end foreach

        return [
            'audience'      => $audience,
            'organisation'  => (string) ($subject['organisation'] ?? ''),
            'contributions' => $contributions,
        ];
    }//end aggregateFor()

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
     * @spec openspec/changes/contract-v2/tasks.md#T2
     */
    private function servesAudience(object $provider, string $audience): bool
    {
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
     * @param string               $trust        The subject's normalised trust.
     *
     * @return array<string, mixed> The trust-filtered contribution.
     *
     * @spec openspec/changes/contract-v2/tasks.md#T2
     */
    private function filterByTrust(array $contribution, string $trust): array
    {
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
     * Resolve one app's contribution provider by convention FQCN, or null.
     *
     * An app contributes by shipping `OCA\{Namespace}\Portal\PortalContributionProvider`
     * with `getAudience()` + `getContribution()` — no need to implement (and thus
     * depend on) Portaliq's interface. The namespace is ucfirst(appId); camel-cased
     * app ids (e.g. `openregister` → `OpenRegister`) would need the info.xml
     * `<namespace>`, a follow-up as OpenRegister's MCP discovery does.
     *
     * @param string $appId The app id.
     *
     * @return object|null
     */
    private function resolveProvider(string $appId): ?object
    {
        $candidate = sprintf(self::PROVIDER_CLASS, ucfirst($appId));
        if (class_exists($candidate) === false) {
            return null;
        }

        try {
            $instance = $this->container->get($candidate);
        } catch (Throwable $e) {
            $this->logger->debug('Portaliq: contribution provider not resolvable', ['app' => $appId, 'reason' => $e->getMessage()]);
            return null;
        }

        if (is_object($instance) === true) {
            return $instance;
        }

        return null;
    }//end resolveProvider()
}//end class
