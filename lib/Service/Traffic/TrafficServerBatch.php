<?php

/**
 * Portaliq Traffic Server Batch.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 *
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-server-side-caller-must-hold-the-portals-token
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

/**
 * A server-side batch (portal-traffic-reporting) regrouped by the visitor
 * each event belongs to, so the ingest step sees one address and agent
 * per group the way it sees one per live request.
 *
 * Pure: the batch in, the groups out. Lifted out of the collector so the
 * controller stays what it is, the envelope around the ingest.
 *
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-server-side-caller-must-hold-the-portals-token
 */
class TrafficServerBatch {

	/**
	 * The batch's events grouped by the visitor they belong to, each
	 * event's own `remoteAddress` and `userAgent` winning over the batch's.
	 *
	 * @param array<string, mixed> $batch The decoded batch.
	 *
	 * @return array<int, array{ip: string, userAgent: string, events: array<int, mixed>}> The groups.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-server-side-caller-must-hold-the-portals-token
	 */
	public function byVisitor(array $batch): array {
		$defaultAddress = (string)($batch['remoteAddress'] ?? '');
		$defaultAgent = (string)($batch['userAgent'] ?? '');
		$groups = [];
		foreach (array_values((array)($batch['events'] ?? [])) as $event) {
			$address = $defaultAddress;
			$agent = $defaultAgent;
			if (is_array($event) === true) {
				$address = (string)($event['remoteAddress'] ?? $defaultAddress);
				$agent = (string)($event['userAgent'] ?? $defaultAgent);
				unset($event['remoteAddress'], $event['userAgent']);
			}

			$key = $address . "\0" . $agent;
			$groups[$key] ??= ['ip' => $address, 'userAgent' => $agent, 'events' => []];
			$groups[$key]['events'][] = $event;
		}

		return array_values($groups);
	}
}
