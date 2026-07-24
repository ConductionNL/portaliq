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
use OCA\Portaliq\Service\NotificationDispatchService;

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
     * low-trust dev-login session never sees. The example collection also
     * declares a `fields` projection whitelist so a dev install demonstrates
     * read-side field projection (rows created via `createExample` come back
     * as title/status + identifier only); the other collections stay
     * undeclared as the full-row backward-compat reference.
     *
     * A reverse `via` (`match: 'scopeField'`, contract v2.2) collection is also
     * declared, deliberately self-referential so it stays exercisable on a
     * dev install with the existing demo schemas only — a realistic
     * parent→child→grades reverse join needs a domain app's own schemas.
     *
     * A `type: update` action (portal-scoped-crud, ADR-062 Phase 1) patches the
     * subject's OWN exampleDocument title, exercising the write-IDOR-safe update
     * path (ownership re-verified against OR before any write; scope re-stamped).
     *
     * @param array<string, mixed> $subject The resolved subject.
     *
     * @return array<string, mixed>|null
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T04
     * @spec openspec/changes/contract-v2/tasks.md#T9
     * @spec openspec/changes/field-projection/tasks.md#T3
     * @spec openspec/changes/reverse-scope-join/tasks.md#T3
     * @spec openspec/changes/portal-scoped-crud/tasks.md#T4
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) -- this is a single
     * declarative return: one manifest literal exercising the full contract
     * vocabulary (collections, claim/via scoping, create/update/endpoint
     * actions) so the demo portal stays the exercisable reference; it is data,
     * not branching logic.
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
                    // Field projection (read-side): the portal returns ONLY
                    // title + status (+ the row identifier) per row — the
                    // scopeField value and any other property never leave.
                    'id'            => 'exampleCollection',
                    'register'      => 'portaliq',
                    'schema'        => 'exampleDocument',
                    'scopeField'    => 'subjectRef',
                    'fields'        => ['title', 'status'],
                    'label'         => 'Voorbeeldgegevens',
                    'listable'      => true,
                    // Contribution-manifest-v3 (presentation-only): per-column
                    // render hints, a detail layout, and a default sort. None of
                    // these widens access — a column naming a projected-away
                    // field (e.g. the scopeField) renders blank, never leaks.
                    'columns'       => [
                        ['field' => 'title', 'label' => 'Onderwerp'],
                        ['field' => 'status', 'label' => 'Status', 'render' => 'badge'],
                    ],
                    'detail'        => ['layout' => 'card', 'fields' => ['title', 'status']],
                    'defaultSort'   => ['field' => 'title', 'direction' => 'asc'],
                    // Contribution-manifest-v3 (status transitions): per-row
                    // buttons wired to a `type: update` action whose server-
                    // enforced `set` fixes the transition target — a client can
                    // never choose an arbitrary status.
                    'rowActions'    => ['closeExample'],
                    // Opt into the scoped file-upload block (ADR-063): a subject
                    // may attach evidence/attachments to their OWN example object,
                    // stored in the object's OpenRegister folder.
                    'filesUpload'   => true,
                    // Opt into the scoped file-DOWNLOAD block (portal-document-
                    // download): a subject may retrieve a file attached to their
                    // OWN example object, after the same ownership + tenant +
                    // trust re-verification as the scoped read.
                    'filesDownload' => true,
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
                [
                    // Contract v2.2 (reverse-scope-join): the reverse
                    // `match: 'scopeField'` join mechanic, exercisable with the
                    // existing demo schemas only. The join pre-pass resolves
                    // the subject's OWN portalAccount (join scopeField
                    // `subjectRef`), collects its `subjectRef` as the target
                    // VALUE, then keeps `exampleDocument` rows whose OWN
                    // `subjectRef` (the collection scopeField) is in that set.
                    // Deliberately self-referential — a realistic
                    // guardian→learner→grades reverse join needs a domain app's
                    // schemas (scholiq), out of scope here — but it drives the
                    // reverse code path end-to-end, so rows created via
                    // `createExample` surface through it too.
                    'id'         => 'exampleReverseJoin',
                    'register'   => 'portaliq',
                    'schema'     => 'exampleDocument',
                    'scopeField' => 'subjectRef',
                    'via'        => [
                        'register'    => 'portaliq',
                        'schema'      => 'portalAccount',
                        'scopeField'  => 'subjectRef',
                        'targetField' => 'subjectRef',
                        'match'       => 'scopeField',
                    ],
                    'label'      => 'Voorbeeld omgekeerde koppeling',
                    'listable'   => true,
                ],
            ],
            'actions'       => [
                [
                    'id'               => 'createExample',
                    'type'             => 'create',
                    'label'            => 'Nieuw voorbeeld',
                    'register'         => 'portaliq',
                    'schema'           => 'exampleDocument',
                    'fields'           => ['title', 'status'],
                    // Contribution-manifest-v3 (presentation-only): per-field form
                    // hints + option providers. `fieldConfigs` may only describe a
                    // WHITELISTED field — a config for a field outside `fields` is
                    // dropped by the normaliser, so it can never widen the submit.
                    'fieldConfigs'     => [
                        'title'  => ['label' => 'Onderwerp', 'required' => true, 'size' => 'large', 'placeholder' => 'Waar gaat het over?'],
                        'status' => ['label' => 'Status'],
                    ],
                    'optionsProviders' => [
                        // A static dropdown. A `collection` provider would instead
                        // be, e.g.:
                        // 'contract' => ['type' => 'collection',
                        // 'register' => 'procest', 'schema' => 'supplierContract',
                        // 'labelField' => 'name', 'valueField' => 'id']
                        // and the portal populates it through the SUBJECT-SCOPED
                        // collection endpoint, so it can only ever offer rows the
                        // subject may already read.
                        'status' => [
                            'type'    => 'static',
                            'options' => [
                                ['value' => 'open', 'label' => 'Open'],
                                ['value' => 'closed', 'label' => 'Afgehandeld'],
                            ],
                        ],
                    ],
                    'submitLabel'      => 'Aanmaken',
                    'successMessage'   => 'Voorbeeld aangemaakt',
                ],
                [
                    // Portal-scoped update (portal-scoped-crud, ADR-062 Phase 1):
                    // patches ONLY the whitelisted `title` of the subject's OWN
                    // exampleDocument. Ownership is re-verified against
                    // OpenRegister before any write; the scope field is
                    // re-stamped server-side, so the id can never be used to
                    // patch another subject's row (closes #16).
                    'id'       => 'updateExample',
                    'type'     => 'update',
                    'label'    => 'Voorbeeld bijwerken',
                    'register' => 'portaliq',
                    'schema'   => 'exampleDocument',
                    'fields'   => ['title'],
                ],
                [
                    // Contribution-manifest-v3 status transition: a `type: update`
                    // action whose server-enforced `set` fixes `status` to
                    // `closed`. Surfaced as a per-row button via the collection's
                    // `rowActions`. The client sends no field data — the server
                    // applies `set` (only whitelisted fields) and re-stamps the
                    // scope, so the transition target can never be tampered with.
                    'id'       => 'closeExample',
                    'type'     => 'update',
                    'label'    => 'Afhandelen',
                    'register' => 'portaliq',
                    'schema'   => 'exampleDocument',
                    'fields'   => ['status'],
                    'set'      => ['status' => 'closed'],
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
            // Contribution-manifest-v3: an explicit page composition. Every block
            // reference resolves within THIS contribution (after trust filtering);
            // an unresolvable/cross-contribution ref is dropped by the normaliser.
            // Omit `pages` entirely to let Portaliq synthesise one default page per
            // listable collection (the v2 rendering).
            'pages'         => [
                [
                    'id'     => 'voorbeeld',
                    'label'  => 'Voorbeeld',
                    'icon'   => 'FileDocument',
                    'blocks' => [
                        [
                            'type'     => 'richText',
                            'markdown' => '## Voorbeeldportaal'."\n".'Beheer uw voorbeeldgegevens. Klik een rij aan voor details en bijlagen.',
                        ],
                        ['type' => 'action', 'action' => 'createExample'],
                        ['type' => 'collection', 'collection' => 'exampleCollection'],
                        // A detail block for the selected row — the filesUpload
                        // opt-in on the collection surfaces the upload control here.
                        ['type' => 'detail', 'collection' => 'exampleCollection'],
                    ],
                ],
                [
                    'id'     => 'berichten',
                    'label'  => 'Berichten',
                    'icon'   => 'Email',
                    'blocks' => [
                        ['type' => 'collection', 'collection' => 'inbox'],
                    ],
                ],
            ],
            // Portal-notifications-dispatch: an app opts IN per rule key — an
            // app declaring neither of these gets no email for that trigger
            // (fail-closed). Illustrative here so the demo contribution
            // exercises the out-of-band nudge end-to-end.
            'notifications' => [
                NotificationDispatchService::RULE_MESSAGE_CREATED,
                NotificationDispatchService::RULE_STATUS_CHANGED,
            ],
        ];
    }//end getContribution()
}//end class
