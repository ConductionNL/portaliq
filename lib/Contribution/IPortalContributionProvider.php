<?php

/**
 * Portaliq Portal Contribution Provider Interface
 *
 * The client-facing extension of the integration registry (ADR-019 / ADR-046).
 * A domain app implements this and registers it under the service alias
 * `OCA\Portaliq\Contribution\IPortalContributionProvider::{appId}` so Portaliq's
 * PortalContributionRegistry can discover it (mirrors OpenRegister's MCP tool
 * provider discovery). The provider declares — for one external audience — which
 * OpenRegister collections a subject may see, which actions they may take, and
 * which notification keys they receive. No portal logic lives in the app; no
 * domain logic lives in Portaliq.
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
 * Contract a domain app implements to contribute to a subject's portal.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T04
 */
interface IPortalContributionProvider
{
    /**
     * The external audience this provider contributes to.
     *
     * @return string "supplier" or "client".
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T04
     */
    public function getAudience(): string;

    /**
     * Describe this app's contribution for a specific authenticated subject, or
     * null when the app has nothing to contribute to that subject.
     *
     * The returned array is a declarative manifest — never raw domain data:
     * `app`, `label`, `collections` (each with register/schema/label), `actions`
     * (each with id/label/endpoint), and `notifications` (rule keys). Portaliq
     * renders it and reads the collections through OpenRegister, RBAC-scoped to
     * the subject; the app is never called to *list* data (ADR-022).
     *
     * @param array<string, mixed> $subject The resolved subject (subjectRef,
     *                                      audience, organisation, ...), all
     *                                      server-derived.
     *
     * @return array<string, mixed>|null The contribution manifest, or null.
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T04
     */
    public function getContribution(array $subject): ?array;
}//end interface
