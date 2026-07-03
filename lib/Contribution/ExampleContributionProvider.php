<?php

/**
 * Portaliq Example Contribution Provider
 *
 * A sample IPortalContributionProvider (like the template's ExampleWidget) that
 * declares a demo supplier contribution so the registry, the contributions
 * endpoint, and the portal shell can be exercised end-to-end before a real app
 * (procest) ships its provider. Delete this class and its alias in
 * Application.php once real contributions exist.
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

/**
 * Demo supplier contribution — illustrative only.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T04
 */
class ExampleContributionProvider implements IPortalContributionProvider
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
                    'id'       => 'exampleCollection',
                    'register' => 'portaliq',
                    'schema'   => 'example',
                    'label'    => 'Voorbeeldgegevens',
                    'listable' => true,
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
