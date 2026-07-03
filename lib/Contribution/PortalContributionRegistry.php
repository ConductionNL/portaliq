<?php

/**
 * Portaliq Portal Contribution Registry
 *
 * Discovers every installed app's contribution provider by convention FQCN
 * (`OCA\{Namespace}\Portal\PortalContributionProvider`) and aggregates the
 * contributions that apply to a given authenticated subject — filtered by the
 * subject's audience. Providers are duck-typed (getAudience + getContribution),
 * so a contributing app does NOT hard-depend on Portaliq's interface. This is
 * the read-side of ADR-046: Portaliq collects declarative manifests, then
 * renders them and reads their collections through OpenRegister.
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
        $contributions = [];

        foreach ($this->appManager->getInstalledApps() as $appId) {
            $provider = $this->resolveProvider(appId: (string) $appId);
            // The method_exists checks narrow for static analysis; duck typing
            // means a contributing app does NOT need to implement (and thus
            // hard-depend on) Portaliq's interface — only the two methods.
            if ($provider === null
                || method_exists($provider, 'getAudience') === false
                || method_exists($provider, 'getContribution') === false
            ) {
                continue;
            }

            if ($provider->getAudience() !== $audience) {
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
            $contributions[]     = $contribution;
        }//end foreach

        return [
            'audience'      => $audience,
            'organisation'  => (string) ($subject['organisation'] ?? ''),
            'contributions' => $contributions,
        ];
    }//end aggregateFor()

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
