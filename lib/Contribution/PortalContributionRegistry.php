<?php

/**
 * Portaliq Portal Contribution Registry
 *
 * Discovers every app's registered IPortalContributionProvider (by the alias
 * `OCA\Portaliq\Contribution\IPortalContributionProvider::{appId}`, one per
 * enabled app) and aggregates the contributions that apply to a given
 * authenticated subject — filtered by the subject's audience. This is the
 * read-side of ADR-046: Portaliq collects declarative manifests, then renders
 * them and reads their collections through OpenRegister.
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
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Aggregates registered portal contributions for a subject.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T04
 */
class PortalContributionRegistry
{
    /**
     * Alias prefix each contributing app registers its provider under.
     */
    private const ALIAS_PREFIX = 'OCA\\Portaliq\\Contribution\\IPortalContributionProvider::';

    /**
     * Constructor.
     *
     * @param IAppManager        $appManager For enumerating installed apps.
     * @param ContainerInterface $container  For resolving each app's provider alias.
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
        $contributions = [];

        foreach ($this->discoverProviders() as $appId => $provider) {
            if ($provider->getAudience() !== $audience) {
                continue;
            }

            try {
                $contribution = $provider->getContribution($subject);
            } catch (Throwable $e) {
                $this->logger->error('Portaliq: contribution provider failed', ['app' => $appId, 'reason' => $e->getMessage()]);
                continue;
            }

            if ($contribution === null) {
                continue;
            }

            $contribution['app'] = $appId;
            $contributions[]     = $contribution;
        }

        return [
            'audience'      => $audience,
            'organisation'  => (string) ($subject['organisation'] ?? ''),
            'contributions' => $contributions,
        ];
    }//end aggregateFor()

    /**
     * Discover every enabled app's contribution provider.
     *
     * @return array<string, IPortalContributionProvider> Keyed by app id.
     */
    private function discoverProviders(): array
    {
        $providers = [];
        foreach ($this->appManager->getInstalledApps() as $appId) {
            try {
                $candidate = $this->container->get(self::ALIAS_PREFIX.$appId);
            } catch (Throwable $e) {
                // No provider registered by this app — expected for most apps.
                continue;
            }

            if ($candidate instanceof IPortalContributionProvider) {
                $providers[$appId] = $candidate;
            }
        }

        return $providers;
    }//end discoverProviders()
}//end class
