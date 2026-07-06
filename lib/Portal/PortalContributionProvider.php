<?php

/**
 * Portaliq's own (demo) Portal Contribution Provider
 *
 * A contributing app exposes its portal contribution by implementing the class
 * `OCA\{Namespace}\Portal\PortalContributionProvider` (implementing
 * IPortalContributionProvider). PortalContributionRegistry discovers each app's
 * provider by that concrete FQCN — the DI container constructs any autoloadable
 * class by reflection, so discovery works across apps (mirroring OpenRegister's
 * MCP tool-provider discovery). This is Portaliq's own demo contribution so the
 * portal is exercisable before a real app (procest) ships its provider; delete
 * it once real contributions exist.
 *
 * @category Portal
 * @package  OCA\Portaliq\Portal
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
 * @spec openspec/changes/contract-v2/tasks.md#T9
 */

declare(strict_types=1);

namespace OCA\Portaliq\Portal;

use OCA\Portaliq\Contribution\IPortalContributionProvider;

/**
 * Demo supplier contribution — illustrative only.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T04
 */
class PortalContributionProvider implements IPortalContributionProvider
{
    /**
     * {@inheritDoc}
     *
     * @return string
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T04
     */
    public function getAudience(): string
    {
        return 'supplier';
    }//end getAudience()

    /**
     * The audiences this provider contributes to (contract v2, A2). The
     * registry prefers this duck-typed list over getAudience(); the v1 method
     * above stays as the demo of the backward-compatible fallback.
     *
     * @return array<int, string>
     *
     * @spec openspec/changes/contract-v2/tasks.md#T9
     */
    public function getAudiences(): array
    {
        return ['supplier'];
    }//end getAudiences()

    /**
     * {@inheritDoc}
     *
     * Exercises the full v2 vocabulary on a dev install: a claim-scoped
     * collection (`scopeClaim` resolved against the seeded dev-supplier
     * account's placeholder claim; the claimless dev-client seed proves the
     * fail-closed empty path), an endpoint action with a placeholder
     * instance-local path, and a `minTrust: substantial` action that a
     * low-trust dev-login session never sees.
     *
     * @param array<string, mixed> $subject The resolved subject.
     *
     * @return array<string, mixed>|null
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T04
     * @spec openspec/changes/contract-v2/tasks.md#T9
     */
    public function getContribution(array $subject): ?array
    {
        // Only contributes to suppliers; the registry already filters by
        // audience, but a real provider would also check subject scope here.
        if (($subject['audience'] ?? '') !== 'supplier') {
            return null;
        }

        return [
            'label'         => 'Voorbeeld',
            'collections'   => [
                [
                    'id'         => 'exampleCollection',
                    'register'   => 'portaliq',
                    'schema'     => 'exampleDocument',
                    'scopeField' => 'subjectRef',
                    'label'      => 'Voorbeeldgegevens',
                    'listable'   => true,
                ],
                [
                    'id'         => 'inbox',
                    'kind'       => 'inbox',
                    'register'   => 'portaliq',
                    'schema'     => 'portalMessage',
                    'scopeField' => 'subjectRef',
                    'label'      => 'Berichten',
                    'listable'   => true,
                ],
                [
                    // Contract v2 (A4): scoped by the server-managed claim
                    // `claims.portaliq.exampleContactId` (bare form = own
                    // namespace), not by the subjectRef.
                    'id'         => 'exampleClaimScoped',
                    'register'   => 'portaliq',
                    'schema'     => 'exampleDocument',
                    'scopeField' => 'subjectRef',
                    'scopeClaim' => 'exampleContactId',
                    'label'      => 'Gekoppelde voorbeeldgegevens',
                    'listable'   => true,
                ],
            ],
            'actions'       => [
                [
                    'id'       => 'createExample',
                    'type'     => 'create',
                    'label'    => 'Nieuw voorbeeld',
                    'register' => 'portaliq',
                    'schema'   => 'exampleDocument',
                    'fields'   => ['title'],
                ],
                [
                    // Contract v2 (A6): endpoint bearer-forward action with a
                    // placeholder instance-local path (the app's own public
                    // health endpoint, so the forward is exercisable on dev).
                    'id'       => 'exampleForward',
                    'label'    => 'Voorbeeld-doorstuuractie',
                    'endpoint' => '/apps/portaliq/api/health',
                    'method'   => 'GET',
                ],
                [
                    // Contract v2 (A3): gated above the dev-login trust level —
                    // a `low` session proves the manifest filter by its absence.
                    'id'       => 'exampleTrusted',
                    'label'    => 'Vertrouwde voorbeeldactie',
                    'endpoint' => '/apps/portaliq/api/health',
                    'method'   => 'GET',
                    'minTrust' => 'substantial',
                ],
            ],
            'notifications' => [],
        ];
    }//end getContribution()
}//end class
