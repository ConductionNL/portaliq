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

use OCP\ICacheFactory;
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
	 * Events one client id may have stored per window.
	 *
	 * Generous on purpose. This is not a security control — a client id is
	 * chosen by the client, so anything determined to flood simply rotates it,
	 * and the control that actually bounds an abusive source is the request-
	 * level `#[AnonRateLimit]` on the controller. What this bounds is a client
	 * that has gone WRONG: a loop firing `page_view` on every scroll frame, a
	 * beacon retried without backoff. Set below what such a bug produces and
	 * far above what a person browsing produces.
	 */
	private const MAX_EVENTS_PER_WINDOW = 300;

	/**
	 * The rate-limit window, in seconds.
	 */
	private const WINDOW_SECONDS = 60;

	/**
	 * Refusals since this process started, by reason.
	 *
	 * @var array<string, int>
	 */
	private array $refusals = [];

	/**
	 * @param PortalObjectWriter $writer       Stores the events.
	 * @param LoggerInterface    $logger       Records refusal counts.
	 * @param ICacheFactory|null $cacheFactory Backs the per-client rate limit; null means no limit.
	 */
	public function __construct(
		private readonly PortalObjectWriter $writer,
		private readonly LoggerInterface $logger,
		private readonly ?ICacheFactory $cacheFactory = null,
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
	 * The traffic configuration a CLIENT needs, and nothing further.
	 *
	 * PROJECTED, NOT RELAYED. `retentionDays` and `regionGranularity` are
	 * decisions this server acts on alone; publishing them would put a portal's
	 * data-retention posture on an anonymous, publicly cacheable endpoint for
	 * no consumer at all. What a browser genuinely needs is the switch, the
	 * event list it may send, when a session lapses, and what it may do before
	 * consent.
	 *
	 * A DISABLED PORTAL PROJECTS AN EMPTY EVENT LIST, not a missing key. The
	 * client's rule then reads the same in both cases — send only what is
	 * listed — instead of needing a second branch for "the field was absent",
	 * which is the branch that gets written to default to sending something.
	 *
	 * `consent.required` defaults to TRUE on a partial or unreadable config.
	 * The safe direction is asking when it was not necessary, never measuring
	 * because a field was missing.
	 *
	 * It lives HERE, beside the collector that enforces the same configuration,
	 * so the answer a client is given and the answer the collector acts on come
	 * from one place.
	 *
	 * @param array<string, mixed> $portal The resolved portal record.
	 *
	 * @return array<string, mixed> The client's view of the traffic config.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/tasks.md
	 */
	public function clientConfig(array $portal): array {
		$traffic = (array)($portal['traffic'] ?? []);
		$enabled = $this->enabledFor(portal: $portal);
		$consent = (array)($traffic['consent'] ?? []);

		$events = [];
		if ($enabled === true) {
			$events = $this->permittedEvents(portal: $portal);
		}

		return [
			'enabled' => $enabled,
			'events' => $events,
			'sessionTimeoutMinutes' => max(1, (int)($traffic['sessionTimeoutMinutes'] ?? 30)),
			'consent' => [
				'required' => (bool)($consent['required'] ?? true),
				'preConsentEvents' => array_values(array_filter(
					(array)($consent['preConsentEvents'] ?? []),
					static fn ($event): bool => is_string($event) === true && $event !== ''
				)),
			],
		];
	}//end clientConfig()


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
	 * The refusals recorded so far, by reason.
	 *
	 * PER REQUEST, NOT PER PORTAL'S LIFETIME. The counts live on the service
	 * instance and the log line is the durable record; this accessor exists so
	 * a test can assert that a refusal was counted under the reason it claims,
	 * rather than that a number came back smaller. "One fewer was stored" is
	 * true of every failure mode at once, which is why it is not an assertion.
	 *
	 * @return array<string, int> Reason to count.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/tasks.md
	 */
	public function refusals(): array {
		return $this->refusals;
	}//end refusals()


	/**
	 * Whether this client id may store one more event in the current window.
	 *
	 * TWO LIMITS GUARD THIS ENDPOINT AND THEY GUARD DIFFERENT THINGS. The
	 * controller's `#[AnonRateLimit]` bounds a SOURCE, which is the one an
	 * abuser cannot choose; this bounds a CLIENT ID, which an abuser can
	 * rotate freely. Presenting the second as an anti-abuse control would be a
	 * false claim — it exists so one broken or looping client cannot fill a
	 * portal's own analytics with noise that reads as traffic.
	 *
	 * FAILS OPEN, DELIBERATELY. With no cache configured there is nowhere to
	 * keep a counter, and the choice is between dropping real measurements and
	 * accepting them unbounded. An analytics collector is the wrong place to
	 * fail closed: the request-level limit still stands above it, and losing a
	 * portal's traffic because its instance has no memcache would be a silent
	 * data loss nobody would connect to the cause.
	 *
	 * @param string $clientId The client-reported id.
	 *
	 * @return bool True when the event may be stored.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/tasks.md
	 */
	private function withinRate(string $clientId): bool {
		if ($this->cacheFactory === null || $this->cacheFactory->isAvailable() === false) {
			return true;
		}

		$cache = $this->cacheFactory->createDistributed('portaliq_traffic_rate');

		// A FIXED window, keyed by the window's own number. A rolling window
		// would need the timestamps kept, and keeping per-client timestamps is
		// precisely the shape of record this endpoint exists not to hold.
		$window = (int)floor(time() / self::WINDOW_SECONDS);
		$key = $window . ':' . hash('sha256', $clientId);

		$count = (int)$cache->get($key);
		if ($count >= self::MAX_EVENTS_PER_WINDOW) {
			return false;
		}

		$cache->set($key, ($count + 1), (self::WINDOW_SECONDS * 2));
		return true;
	}//end withinRate()


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

			// Checked after the shape checks and before the write, so a
			// throttled client costs a cache read rather than an object write,
			// and so a malformed event is reported as malformed rather than as
			// throttled.
			if ($this->withinRate(clientId: $clientId) === false) {
				$this->countRefusal(reason: 'rate_limited');
				continue;
			}

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
