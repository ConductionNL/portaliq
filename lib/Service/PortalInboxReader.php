<?php

/**
 * Portaliq Portal Inbox Reader
 *
 * Aggregates every `kind: inbox` collection across a subject's aggregated
 * contributions into ONE merged, sorted, provenance-tagged inbox
 * (portal-inbox-v2). Each collection is read through the SAME per-row
 * subject + tenant + trust boundary as a normal collection read — this class
 * adds no new authorisation logic of its own, it only fans a single subject's
 * aggregate out across every inbox collection and merges the results. Rows
 * are sorted by `receivedAt` descending and tagged with their source
 * `appId`/label so the SPA can show provenance. Fails closed to an empty
 * inbox on any per-collection error (PortalObjectReader already degrades to
 * `[]` on a missing/erroring OpenRegister) — never a partial leak.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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
 * @spec openspec/changes/portal-inbox-v2/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use Psr\Log\LoggerInterface;

/**
 * Aggregates every inbox collection across a subject's contributions.
 *
 * @spec openspec/changes/portal-inbox-v2/tasks.md#T01
 */
class PortalInboxReader {
	/**
	 * Row cap per inbox collection — bounds a single subject's aggregated
	 * inbox read while comfortably covering portal-scale message volumes.
	 */
	private const ROW_LIMIT = 200;

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader The subject-scoped OR reader every
	 *                                   inbox collection is read through —
	 *                                   identical per-row tenant + trust
	 *                                   boundary as any other collection.
	 * @param LoggerInterface|null $logger Optional so the many existing
	 *                                     single-argument construction sites
	 *                                     (and their tests) keep working; NC's
	 *                                     container autowires it in production.
	 *                                     Only used to record a REFUSED
	 *                                     unscoped read, never on a normal path.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly ?LoggerInterface $logger = null,
	) {
	}//end __construct()

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
	 * @spec openspec/changes/portal-inbox-v2/tasks.md#T01
	 */
	public function aggregateInbox(array $subject, array $aggregate): array {
		$rows = [];
		foreach (($aggregate['contributions'] ?? []) as $contribution) {
			if (is_array($contribution) === false) {
				continue;
			}

			$appId = (string)($contribution['app'] ?? '');
			$label = (string)($contribution['label'] ?? $appId);

			foreach (($contribution['collections'] ?? []) as $collection) {
				if (is_array($collection) === false || ($collection['kind'] ?? '') !== 'inbox') {
					continue;
				}

				foreach ($this->readInboxCollection(subject: $subject, collection: $collection, contributingApp: $appId) as $row) {
					// Provenance envelope: appId/label per spec, plus the
					// register/schema/collection id the SPA needs to address
					// this exact row through the mark-read endpoint (which is
					// parametrised on register/schema, disambiguated the SAME
					// way as every other scoped endpoint via `?collection=`).
					$row['_source'] = [
						'appId' => $appId,
						'label' => $label,
						'register' => (string)($collection['register'] ?? ''),
						'schema' => (string)($collection['schema'] ?? ''),
						'collection' => (string)($collection['id'] ?? ''),
					];

					$rows[] = $row;
				}
			}//end foreach
		}//end foreach

		usort(
			$rows,
			static function (array $a, array $b): int {
				return strcmp((string)($b['receivedAt'] ?? ''), (string)($a['receivedAt'] ?? ''));
			}
		);

		return $rows;
	}//end aggregateInbox()

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
	 * @spec openspec/changes/portal-inbox-v2/tasks.md#T04
	 */
	public function unreadCount(array $subject, array $aggregate): int {
		$count = 0;
		foreach ($this->aggregateInbox(subject: $subject, aggregate: $aggregate) as $row) {
			if (($row['read'] ?? false) !== true) {
				++$count;
			}
		}

		return $count;
	}//end unreadCount()

	/**
	 * Read one inbox collection, subject-scoped, through the standard reader.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param array<string, mixed> $collection The declared inbox collection.
	 * @param string $contributingApp The owning app (scopeClaim namespace).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function readInboxCollection(array $subject, array $collection, string $contributingApp): array {
		$scopeField = (string)($collection['scopeField'] ?? 'subjectRef');
		$scopeClaim = (string)($collection['scopeClaim'] ?? '');
		$via = ($collection['via'] ?? null);
		$subjectRef = (string)($subject['subjectRef'] ?? '');

		// FAIL CLOSED. An inbox collection is subject data by definition, so a
		// read with no way to scope it must return nothing rather than
		// everything.
		//
		// PortalObjectReader is unscoped-by-omission on purpose: scopedFilters()
		// adds the scope filter only `if ($scopeField !== '' && $scopeValue !== '')`,
		// verifyScope()'s per-row check is guarded by `$scopeField !== ''`, and
		// findAll() runs with `_rbac: false, _multitenancy: false` because portal
		// subjects are not Nextcloud users. That combination is correct for
		// PortalContributionProvider::activePortalPages(), which reads
		// `portalPage` CONFIGURATION objects and documents the no-op — but on
		// this path the three of them line up into an unfiltered read of another
		// subject's records.
		//
		// The registry does not close it either: nothing in lib/Contribution/
		// validates `scopeField`, and the register schema types it as a bare
		// `string` with no minLength, described as omittable "on an anonymous
		// collection". This reader has no notion of anonymous — it selects on
		// `kind === 'inbox'` alone — so a contributing app that omits the field,
		// sends `""`, or sends a non-string that casts to `""` gets every row of
		// that schema. Same shape as opencatalogi#828.
		//
		// `via` counts as scoping: it joins one hop and filters the outer rows
		// itself (readViaCollection returns before scopedFilters is reached).
		// `scopeClaim` counts too: it resolves a server-side claim and returns []
		// when the claim is absent.
		$hasScope = ($scopeField !== '' || $scopeClaim !== '' || $via !== null);
		if ($hasScope === false || $subjectRef === '') {
			$this->logger?->warning(
				'Portaliq: refusing an unscoped inbox read — the collection declares no scopeField, scopeClaim or via',
				[
					'app' => $contributingApp,
					'collection' => (string)($collection['id'] ?? ''),
					'register' => (string)($collection['register'] ?? ''),
					'schema' => (string)($collection['schema'] ?? ''),
					'hasSubject' => ($subjectRef !== ''),
				]
			);
			return [];
		}

		return $this->reader->readCollection(
			register: (string)($collection['register'] ?? ''),
			schema: (string)($collection['schema'] ?? ''),
			scopeField: $scopeField,
			subjectRef: $subjectRef,
			organisation: (string)($subject['organisation'] ?? ''),
			limit: self::ROW_LIMIT,
			scopeClaim: (string)($collection['scopeClaim'] ?? ''),
			contributingApp: $contributingApp,
			via: ($collection['via'] ?? null),
			audience: (string)($subject['audience'] ?? ''),
			fields: ($collection['fields'] ?? null),
			filter: (array)($collection['filter'] ?? [])
		);
	}//end readInboxCollection()
}//end class
