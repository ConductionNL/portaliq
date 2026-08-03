<?php

/**
 * Portaliq's built-in, config-driven Portal Contribution Provider
 *
 * A contributing app exposes its portal contribution by implementing the class
 * `OCA\{Namespace}\Portal\PortalContributionProvider` (implementing
 * IPortalContributionProvider). PortalContributionRegistry discovers each app's
 * provider by that concrete FQCN — the DI container constructs any autoloadable
 * class by reflection, so discovery works across apps (mirroring OpenRegister's
 * MCP tool-provider discovery).
 *
 * portal-page-provisioning replaces this class's previous hardcoded-demo body
 * (see git history) with a CONFIG-DRIVEN read: it reads ACTIVE (`status:
 * active`) `portalPage` OpenRegister objects (register `portaliq`, schema
 * `portalPage`) through the existing PortalObjectReader — no direct
 * OpenRegister client call in this class (ADR-022) — and converts each 1:1
 * into the manifest shape `IPortalContributionProvider::getContribution()`
 * already documents, before handing it to the SAME `PortalManifestNormaliser`
 * every provider's output passes through (no normaliser code change needed).
 * This is the designed replacement point the previous demo's own docblock
 * flagged ("delete it once real contributions exist" — see design.md Open
 * Question OQ1): provisioning a citizen-facing portal page becomes writing a
 * `portalPage` object through the standard OpenRegister objects API, zero
 * Portaliq PHP per page. The former hardcoded demo manifest now ships as a
 * seed `portalPage` object (`lib/Settings/portaliq_register.json`,
 * `dev-citizen-intake`), preserving the dev-exercisable-out-of-the-box
 * property this class always had.
 *
 * `readCollection()` is called with an EMPTY `scopeField`/`subjectRef` —
 * deliberately: `portalPage` objects are configuration, not subject data,
 * so there is no per-subject scope to enforce here. The `status: active`
 * filter is the only narrowing; PortalObjectReader's per-row
 * scope-verification is a no-op when `scopeField === ''` (its `verifyScope()`
 * guard is `$scopeField !== '' && …`), so every active `portalPage` object
 * legitimately comes back. A `draft` object is excluded by the filter and
 * therefore never appears in any aggregate — including `aggregateAnonymous()`,
 * which consults this SAME method (contract-v2's `getContribution()` duck
 * type, called once per active audience).
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
 * @spec openspec/specs/portal-page-provisioning/spec.md#requirement-an-app-must-be-able-to-provision-a-portal-page-as-data
 * @spec openspec/specs/portal-page-provisioning/spec.md#requirement-an-app-must-be-able-to-provision-a-portal-page-as-data
 * @spec openspec/specs/portal-page-provisioning/spec.md#requirement-an-app-must-be-able-to-provision-a-portal-page-as-data
 */

declare(strict_types=1);

namespace OCA\Portaliq\Portal;

use OCA\Portaliq\Contribution\IPortalContributionProvider;
use OCA\Portaliq\Service\PortalObjectReader;
use Psr\Log\LoggerInterface;

/**
 * Config-driven contribution provider: reads active `portalPage` objects.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T04
 * @spec openspec/specs/portal-page-provisioning/spec.md#requirement-an-app-must-be-able-to-provision-a-portal-page-as-data
 */
class PortalContributionProvider implements IPortalContributionProvider
{
    /**
     * The register/schema `portalPage` objects live in.
     */
    private const REGISTER = 'portaliq';

    private const SCHEMA = 'portalPage';

    /**
     * Constructor.
     *
     * @param PortalObjectReader $objectReader Reads `portalPage` objects
     *                                         (ADR-022 — no direct OR client
     *                                         call in this class).
     * @param LoggerInterface    $logger       The logger.
     */
    public function __construct(
        private readonly PortalObjectReader $objectReader,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * {@inheritDoc}
     *
     * The v1 fallback the registry consults only when `getAudiences()` is
     * absent — it never is here, since this class always implements it — kept
     * for interface compliance (the method is NOT duck-typed/optional).
     *
     * @return string
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T04
     */
    public function getAudience(): string
    {
        $audiences = $this->getAudiences();
        return ($audiences[0] ?? '');
    }//end getAudience()

    /**
     * The distinct `audience` values across every ACTIVE `portalPage` object
     * (portal-page-provisioning) — dynamic, replacing the previous hardcoded
     * `['supplier']`. Empty when no `portalPage` objects exist yet, or when
     * OpenRegister is unavailable; this class degrades to contributing
     * nothing, never null/throws (the same fail-closed "provider degrades to
     * nothing" contract every duck-typed provider honours per
     * `PortalContributionRegistry::aggregateFor()`'s try/catch).
     *
     * @return array<int, string>
     *
     * @spec openspec/specs/portal-page-provisioning/spec.md#requirement-an-app-must-be-able-to-provision-a-portal-page-as-data
     */
    public function getAudiences(): array
    {
        $audiences = [];
        foreach ($this->activePortalPages() as $row) {
            $audience = ($row['audience'] ?? null);
            if (is_string($audience) === true && $audience !== '' && in_array($audience, $audiences, true) === false) {
                $audiences[] = $audience;
            }
        }

        return $audiences;
    }//end getAudiences()

    /**
     * {@inheritDoc}
     *
     * Reads every ACTIVE `portalPage` object whose `audience` matches the
     * subject's, converts the FIRST matching one (by object id, ascending —
     * deterministic) 1:1 into the manifest shape. Multiple active objects for
     * the SAME audience is a data-authoring concern (design.md, Open Question
     * OQ2): this NEVER merges — merging two independently-authored manifests
     * could silently combine an unrelated app's field whitelist with
     * another's — it picks one deterministically and logs a warning on the
     * collision.
     *
     * A collection/action entry that does not declare its OWN `minTrust`
     * inherits the contribution-level `minTrust` (the schema's documented
     * default-with-override semantics); an entry that DOES declare one is
     * never touched — the entry's own value always wins.
     *
     * @param array<string, mixed> $subject The resolved subject.
     *
     * @return array<string, mixed>|null
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T04
     * @spec openspec/specs/portal-page-provisioning/spec.md#requirement-an-app-must-be-able-to-provision-a-portal-page-as-data
     * @spec openspec/specs/portal-page-provisioning/spec.md#requirement-an-app-must-be-able-to-provision-a-portal-page-as-data
     */
    public function getContribution(array $subject): ?array
    {
        $audience = (string) ($subject['audience'] ?? '');
        if ($audience === '') {
            return null;
        }

        $matches = [];
        foreach ($this->activePortalPages() as $row) {
            if (($row['audience'] ?? null) === $audience) {
                $matches[] = $row;
            }
        }

        if ($matches === []) {
            return null;
        }

        usort($matches, fn (array $a, array $b): int => $this->rowId(row: $a) <=> $this->rowId(row: $b));

        if (count($matches) > 1) {
            $this->logger->warning(
                'Portaliq: multiple active portalPage objects for one audience — picking the first, not merging',
                ['audience' => $audience, 'count' => count($matches)]
            );
        }

        return $this->toContribution(row: $matches[0]);
    }//end getContribution()

    /**
     * Convert one `portalPage` object row into the manifest shape every
     * provider's `getContribution()` returns.
     *
     * @param array<string, mixed> $row The `portalPage` object row.
     *
     * @return array<string, mixed>
     */
    private function toContribution(array $row): array
    {
        $contributionMinTrust = ($row['minTrust'] ?? null);
        if (is_string($contributionMinTrust) === false || $contributionMinTrust === '') {
            $contributionMinTrust = null;
        }

        $collections = (array) ($row['collections'] ?? []);
        $actions     = (array) ($row['actions'] ?? []);

        $contribution = [
            'label'       => (string) ($row['label'] ?? ''),
            'collections' => $this->applyContributionMinTrust(entries: $collections, contributionMinTrust: $contributionMinTrust),
            'actions'     => $this->applyContributionMinTrust(entries: $actions, contributionMinTrust: $contributionMinTrust),
            'pages'       => (array) ($row['pages'] ?? []),
        ];

        // `notifications` is the per-rule-key opt-in NotificationDispatchService
        // reads straight off the contribution (`$contribution['notifications']`)
        // — an app declaring no matching key gets no out-of-band email for that
        // trigger. It is forwarded only when the row actually declares a list,
        // so the fail-closed default (no key => no email) is preserved exactly:
        // an absent or malformed value leaves the array key off entirely, which
        // is the same shape a provider that never declared one produces.
        //
        // Without this, `portal-page-provisioning`'s move to a config-driven
        // provider left the opt-in UNREACHABLE for a data-provisioned page: the
        // deleted hardcoded demo declared RULE_MESSAGE_CREATED and
        // RULE_STATUS_CHANGED, and nothing carried them over.
        $notifications = ($row['notifications'] ?? null);
        if (is_array($notifications) === true && $notifications !== []) {
            $contribution['notifications'] = array_values($notifications);
        }

        return $contribution;
    }//end toContribution()

    /**
     * Fill the contribution-level `minTrust` default onto every entry that
     * does not declare its OWN `minTrust`; an entry with its own value is
     * never touched — the entry-level value always wins (the schema's
     * documented default-with-override semantics).
     *
     * @param array<int, mixed> $entries              The raw collections or actions.
     * @param string|null       $contributionMinTrust The contribution-level default, or null when absent.
     *
     * @return array<int, mixed>
     */
    private function applyContributionMinTrust(array $entries, ?string $contributionMinTrust): array
    {
        if ($contributionMinTrust === null) {
            return $entries;
        }

        foreach ($entries as $index => $entry) {
            if (is_array($entry) === true && array_key_exists('minTrust', $entry) === false) {
                $entries[$index]['minTrust'] = $contributionMinTrust;
            }
        }

        return $entries;
    }//end applyContributionMinTrust()

    /**
     * Read every ACTIVE `portalPage` object. `scopeField`/`subjectRef` are
     * deliberately empty — `portalPage` rows are configuration, not subject
     * data, so PortalObjectReader's per-row scope check (a no-op when
     * `scopeField === ''`) never excludes a row; `status: active` is the
     * only narrowing filter. Degrades to `[]` on any OpenRegister error
     * (PortalObjectReader's own fail-closed contract).
     *
     * @return array<int, array<string, mixed>>
     */
    private function activePortalPages(): array
    {
        return $this->objectReader->readCollection(
            register: self::REGISTER,
            schema: self::SCHEMA,
            scopeField: '',
            subjectRef: '',
            limit: 200,
            filter: ['status' => 'active']
        );
    }//end activePortalPages()

    /**
     * Resolve a row's identifier (`id`/`uuid`, flat or in `@self`) for the
     * deterministic first-match ordering.
     *
     * @param array<string, mixed> $row The normalised row.
     *
     * @return string
     */
    private function rowId(array $row): string
    {
        $self     = ($row['@self'] ?? null);
        $selfId   = null;
        $selfUuid = null;
        if (is_array($self) === true) {
            $selfId   = ($self['id'] ?? null);
            $selfUuid = ($self['uuid'] ?? null);
        }

        $candidates = [($row['id'] ?? null), ($row['uuid'] ?? null), $selfId, $selfUuid];
        foreach ($candidates as $candidate) {
            if ((is_string($candidate) === true || is_int($candidate) === true) && (string) $candidate !== '') {
                return (string) $candidate;
            }
        }

        return '';
    }//end rowId()
}//end class
