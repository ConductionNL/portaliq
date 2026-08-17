<?php

/**
 * Portaliq Portal Traffic Service
 *
 * Validates and stores client-reported traffic events.
 *
 * TAKES A REGION, NEVER AN IP. The controller resolves the address and discards
 * it before calling in here, so no code path through this class can store, log
 * or aggregate one — the signature is the guarantee, not a comment asking
 * people to be careful.
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
 * @spec openspec/changes/portal-traffic-analytics/tasks.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use Psr\Log\LoggerInterface;

/**
 * Validates and stores client-reported traffic events.
 *
 * TAKES A REGION, NEVER AN IP. The controller resolves the address and drops it
 * before calling in here, so there is no code path through this class that
 * could store, log or aggregate one — the type signature is the guarantee, not
 * a comment asking people to be careful.
 *
 * @spec openspec/changes/portal-traffic-analytics/tasks.md
 */
class PortalTrafficService {

	/**
	 * The register these events live in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The schema these events live in.
	 */
	private const SCHEMA = 'portalTrafficEvent';

	/**
	 * The shipped vocabulary. Anything else is refused and counted.
	 */
	private const KNOWN_EVENTS = [
		'page_view',
		'session_start',
		'scroll',
		'outbound_click',
		'file_download',
		'search',
		'form_submit',
	];

	/**
	 * A `params` map larger than this is truncated — it is a bounded field.
	 */
	private const MAX_PARAMS = 20;

	/**
	 * Refusals since this process started, by reason.
	 *
	 * @var array<string, int>
	 */
	private array $refusals = [];

	/**
	 * @param PortalObjectWriter $writer Stores the events.
	 * @param LoggerInterface    $logger Records refusal counts.
	 */
	public function __construct(
		private readonly PortalObjectWriter $writer,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()


	/**
	 * Whether this portal measures anything at all.
	 *
	 * Absent configuration means NO. Measurement on a public government portal
	 * is something an operator decides, not something a default decides for
	 * them.
	 *
	 * @param array<string, mixed> $portal The resolved portal.
	 *
	 * @return bool True when enabled.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/tasks.md
	 */
	public function enabledFor(array $portal): bool {
		$traffic = (array)($portal['traffic'] ?? []);
		return ($traffic['enabled'] ?? false) === true;
	}//end enabledFor()


	/**
	 * The events this portal permits.
	 *
	 * An enabled portal that names no events permits NONE. "Enabled but
	 * unconfigured" collecting everything is how a portal ends up measuring
	 * something nobody agreed to.
	 *
	 * @param array<string, mixed> $portal The resolved portal.
	 *
	 * @return array<int, string> The permitted event names.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/tasks.md
	 */
	public function permittedEvents(array $portal): array {
		$traffic = (array)($portal['traffic'] ?? []);
		$events = (array)($traffic['events'] ?? []);

		return array_values(array_intersect($events, self::KNOWN_EVENTS));
	}//end permittedEvents()


	/**
	 * Resolve a request address to the coarseness this portal asked for.
	 *
	 * The address is a PARAMETER here and a return value never derived from it
	 * beyond this call. `none` yields '' — a portal that wants no region gets
	 * no region, rather than one stored "just in case".
	 *
	 * This is deliberately not a geo-IP lookup yet: shipping a placeholder that
	 * returns a plausible country would produce numbers nobody could tell from
	 * measured ones. It returns '' until a real resolver is wired in, and the
	 * field stays empty rather than wrong.
	 *
	 * @param string               $address The request address, used and discarded.
	 * @param array<string, mixed> $portal The resolved portal.
	 *
	 * @return string The coarse region, or ''.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/tasks.md
	 */
	public function regionFor(string $address, array $portal): string {
		$traffic = (array)($portal['traffic'] ?? []);
		$granularity = (string)($traffic['regionGranularity'] ?? 'country');
		if ($granularity === 'none' || $address === '') {
			return '';
		}

		// No resolver is wired in yet. Returning '' keeps the field honest;
		// returning a guess would put unmeasured values beside measured ones.
		return '';
	}//end regionFor()


	/**
	 * Count a refusal, so it is visible to an operator rather than silent.
	 *
	 * A collector that drops what it cannot accept and says nothing is
	 * indistinguishable from a portal nobody visits.
	 *
	 * @param string $reason Why the event or batch was refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-traffic-analytics/tasks.md
	 */
	public function countRefusal(string $reason): void {
		$this->refusals[$reason] = (($this->refusals[$reason] ?? 0) + 1);
		$this->logger->info(
			'[portaliq] traffic event refused',
			['reason' => $reason, 'count' => $this->refusals[$reason]]
		);
	}//end countRefusal()


	/**
	 * Store an accepted batch.
	 *
	 * Each event is validated on its own and a refused one does not take the
	 * batch down with it — the batch-level refusals (size, shape) already
	 * happened in the controller, and beyond that one malformed beacon should
	 * not cost a visitor's whole journey.
	 *
	 * A `(sessionId, sequence)` pair seen twice within one batch is refused:
	 * a client that resets its counter would otherwise overwrite its own
	 * journey with a second event claiming the same position.
	 *
	 * @param array<string, mixed> $portal The resolved portal.
	 * @param array<int, mixed>    $events The batch.
	 * @param string               $region The coarse region, already derived.
	 *
	 * @return int How many events were stored.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/tasks.md
	 */
	public function record(array $portal, array $events, string $region): int {
		if ($this->enabledFor(portal: $portal) === false) {
			$this->countRefusal(reason: 'disabled');
			return 0;
		}

		$permitted = $this->permittedEvents(portal: $portal);
		$slug = (string)($portal['slug'] ?? '');
		$seen = [];
		$stored = 0;

		foreach ($events as $event) {
			$event = (array)$event;
			$refusal = $this->refusalFor(event: $event, permitted: $permitted, seen: $seen);
			if ($refusal !== '') {
				$this->countRefusal(reason: $refusal);
				continue;
			}

			$name = trim((string)($event['name'] ?? ''));
			$clientId = trim((string)($event['clientId'] ?? ''));
			$sessionId = trim((string)($event['sessionId'] ?? ''));
			$sequence = (int)($event['sequence'] ?? -1);
			$seen[$sessionId . ':' . $sequence] = true;

			$created = $this->writer->createAnonymousObject(
				register: self::REGISTER,
				schema: self::SCHEMA,
				data: [
					// The portal is the SERVER's answer, never the payload's.
					'portal' => $slug,
					'clientId' => $clientId,
					'sessionId' => $sessionId,
					'sequence' => $sequence,
					'name' => $name,
					'timestamp' => trim((string)($event['timestamp'] ?? '')),
					'receivedAt' => gmdate('c'),
					'pageLocation' => $this->pathOf(location: (string)($event['pageLocation'] ?? '')),
					'pageReferrer' => trim((string)($event['pageReferrer'] ?? '')),
					'pageTitle' => trim((string)($event['pageTitle'] ?? '')),
					'region' => $region,
					'params' => $this->boundedParams(params: (array)($event['params'] ?? [])),
				]
			);

			if ($created !== null) {
				$stored++;
			}
		}

		return $stored;
	}//end record()


	/**
	 * Why one event cannot be stored, or '' when it can.
	 *
	 * Extracted from `record()` so the loop reads as "decide, then store" and
	 * so each refusal reason has one place to be named. Returning the REASON
	 * rather than a boolean is what lets the caller count them: a collector
	 * that drops what it cannot accept and says nothing is indistinguishable
	 * from a portal nobody visits.
	 *
	 * @param array<string, mixed> $event     The reported event.
	 * @param array<int, string>   $permitted The portal's permitted names.
	 * @param array<string, bool>  $seen      Sequences already taken in this batch.
	 *
	 * @return string The refusal reason, or ''.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/tasks.md
	 */
	private function refusalFor(array $event, array $permitted, array $seen): string {
		$name = trim((string)($event['name'] ?? ''));
		if ($name === '' || in_array($name, $permitted, true) === false) {
			return 'event_not_permitted';
		}

		$clientId = trim((string)($event['clientId'] ?? ''));
		$sessionId = trim((string)($event['sessionId'] ?? ''));
		$sequence = (int)($event['sequence'] ?? -1);
		if ($clientId === '' || $sessionId === '' || $sequence < 0) {
			return 'incomplete';
		}

		if (isset($seen[$sessionId . ':' . $sequence]) === true) {
			return 'duplicate_sequence';
		}

		return '';
	}//end refusalFor()

	/**
	 * Reduce a reported location to its path and query.
	 *
	 * The origin is stripped SERVER-side rather than trusted from the client:
	 * a full URL is how one portal's analytics ends up holding another host's
	 * addresses, and the path is the only part an aggregate needs.
	 *
	 * @param string $location The reported location.
	 *
	 * @return string The path and query.
	 */
	private function pathOf(string $location): string {
		$location = trim($location);
		if ($location === '') {
			return '';
		}

		$path = (string)parse_url($location, PHP_URL_PATH);
		$query = (string)parse_url($location, PHP_URL_QUERY);
		if ($path === '') {
			return '';
		}

		if ($query !== '') {
			return $path . '?' . $query;
		}

		return $path;
	}//end pathOf()


	/**
	 * Bound the `params` map, in size and in type.
	 *
	 * An unbounded map is how personal data reaches an analytics store by
	 * accident — a client that puts a form's contents in `params` should lose
	 * them here, not have them stored because nothing said otherwise.
	 *
	 * @param array<string, mixed> $params The reported map.
	 *
	 * @return array<string, string> The bounded map.
	 */
	private function boundedParams(array $params): array {
		$out = [];
		foreach ($params as $key => $value) {
			if (count($out) >= self::MAX_PARAMS) {
				$this->countRefusal(reason: 'params_truncated');
				break;
			}

			if (is_scalar($value) === false) {
				continue;
			}

			$out[substr((string)$key, 0, 64)] = substr((string)$value, 0, 256);
		}

		return $out;
	}//end boundedParams()


}//end class
