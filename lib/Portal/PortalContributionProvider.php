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
     * {@inheritDoc}
     *
     * @param array<string, mixed> $subject The resolved subject.
     *
     * @return array<string, mixed>|null
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T04
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
            ],
            'actions'       => [
                [
                    'id'       => 'exampleAction',
                    'label'    => 'Voorbeeldactie',
                    'endpoint' => null,
                ],
            ],
            'notifications' => [],
        ];
    }//end getContribution()
}//end class
