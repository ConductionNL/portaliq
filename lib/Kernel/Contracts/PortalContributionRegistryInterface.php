<?php

/**
 * Contract of PortalContributionRegistry
 *
 * The public surface of PortalContributionRegistry that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Portaliq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel\Contracts;

/**
 * Contract of PortalContributionRegistry.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalContributionRegistryInterface {

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
	public function aggregateAnonymous(): array;

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
	public function aggregateFor(array $subject): array;

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
	public function servedAudiences(): array;

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
	public function startTiles(): array;
}//end interface
