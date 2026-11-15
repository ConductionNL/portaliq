<?php

/**
 * Contract of PortalInboxReader
 *
 * The public surface of PortalInboxReader that other components call, so they depend
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
 * Contract of PortalInboxReader.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalInboxReaderInterface {

	/**
	 * The notices portaliq writes itself (an answered question, a matched
	 * saved search, a published decision, a submission receipt, a delivered
	 * task) as one built-in inbox source. Every resident's inbox reads it,
	 * whether or not a contribution declares an inbox over `portalMessage`,
	 * through the same scoped read as a declared source: on `subjectRef`, with
	 * the subject's own reference and organisation.
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-portaliqs-own-notices-reach-the-residents-inbox-req-nap-009
	 */
	public const OWN_MESSAGES = [
		'id' => 'portalMessages',
		'kind' => 'inbox',
		'register' => 'portaliq',
		'schema' => 'portalMessage',
		'scopeField' => 'subjectRef',
	];

	/**
	 * Merge every `kind: inbox` collection across the subject's aggregated
	 * contributions into one sorted, provenance-tagged inbox.
	 *
	 * Every collection is read through PortalObjectReader::readCollection() —
	 * the identical per-row subject + tenant boundary as a normal collection
	 * read (an inbox collection's `minTrust` was already re-checked when the
	 * aggregate was built by PortalContributionRegistry, and the caller is
	 * expected to have re-verified it as defense in depth like every other
	 * endpoint). Rows are merged across apps, sorted by `receivedAt`
	 * descending (rows without a `receivedAt` sort last), and each carries a
	 * `_source` envelope (`appId`, `label`) so the SPA can render provenance.
	 *
	 * @param array<string, mixed> $subject The resolved subject (subjectRef/
	 *                                      audience/organisation).
	 * @param array<string, mixed> $aggregate The subject's aggregated
	 *                                        contribution manifest (already
	 *                                        trust-filtered by the registry).
	 *
	 * @return array<int, array<string, mixed>> The merged inbox rows.
	 *
	 * @spec openspec/changes/archive/2026-07-23-portal-inbox-v2/tasks.md#T01
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-portaliqs-own-notices-reach-the-residents-inbox-req-nap-009
	 * @spec openspec/specs/supplier-portal/spec.md#requirement-an-inbox-collection-names-its-own-message-fields-req-imf-001
	 * @spec openspec/specs/portal-inbox-reply/spec.md#requirement-files-that-came-with-a-message-open-req-ira-004
	 */
	public function aggregateInbox(array $subject, array $aggregate): array;

	/**
	 * The subject's own unread count across every inbox collection —
	 * computed within the SAME aggregation pass `aggregateInbox()` uses
	 * (no separate/duplicated OR scan), so `GET /portal/api/contributions`
	 * can carry it without a second round-trip.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param array<string, mixed> $aggregate The subject's aggregated
	 *                                        contribution manifest.
	 *
	 * @return int The subject's own unread message count.
	 *
	 * @spec openspec/changes/archive/2026-07-23-portal-inbox-v2/tasks.md#T04
	 */
	public function unreadCount(array $subject, array $aggregate): int;
}//end interface
