<?php

/**
 * Portaliq Traffic Event Validator.
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
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-a-portal-must-decide-what-is-measured-and-the-collector-must-enforce-it
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Portaliq\Service\Traffic\TrafficOutcomeParams;
use Throwable;

/**
 * Turns one posted event into a storable record, or into a named refusal.
 *
 * EVERY REFUSAL HAS A REASON, and the reason travels back. A collector that
 * answers 204 to everything cannot be configured: a client sending an event the
 * portal disabled, a client sending a malformed one, and a client that is
 * working perfectly all look identical from the outside, which is exactly how
 * a measurement gap survives for months while a dashboard shows a confident
 * zero.
 *
 * THE INPUT IS HOSTILE. This runs behind an anonymous, unauthenticated,
 * publicly writable endpoint on a government portal. Every field is bounded,
 * every string truncated, every unknown key dropped.
 *
 * COOKIELESS BY DEFAULT. A client id and a session id are OPTIONAL: in the
 * default mode the browser stores nothing, the server derives a daily visitor
 * hash, and the aggregation step derives sessions from that hash and an
 * inactivity gap. A client id is only KEPT when the portal switched on
 * `persistClientId`; otherwise it is dropped even if a stale client sends one,
 * because the portal said no.
 *
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-a-portal-must-decide-what-is-measured-and-the-collector-must-enforce-it
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) -- the validator reads
 * DateTimeImmutable/DateTimeZone/Throwable and the resolver; there is no
 * abstraction that would make that fewer.
 */
class TrafficEventValidator {

	/**
	 * The longest URL, referrer or title kept.
	 *
	 * Truncated rather than refused: a long URL is ordinary (campaign
	 * parameters are verbose), and refusing the event would lose a real page
	 * view over a cosmetic limit.
	 */
	public const MAX_STRING = 512;

	/**
	 * The most events one batch may carry.
	 *
	 * A batch is a convenience for the client, not an invitation.
	 */
	public const MAX_BATCH = 50;

	/**
	 * The most keys a `params` map may carry, and the longest value in it.
	 *
	 * Twenty scalar values is more than any shipped event uses. The bound
	 * exists so a client cannot turn one event into a kilobyte-per-key store
	 * for whatever it likes, which on a government portal is somebody's data.
	 */
	public const MAX_PARAMS = 20;

	/**
	 * The longest string value kept in `params`.
	 */
	public const MAX_PARAM_VALUE = 256;

	/**
	 * How far ahead of the server clock a client timestamp may run before it
	 * is refused rather than clamped. Five minutes covers a skewed clock; an
	 * event dated next week is not a clock problem.
	 */
	public const MAX_FUTURE_SECONDS = 300;

	/**
	 * How old a client timestamp may be. A page view from a week ago is a
	 * client that replayed its queue after a very long sleep, and storing it
	 * would recompute a day's aggregate everybody already read.
	 */
	public const MAX_AGE_SECONDS = 7 * 86400;

	/**
	 * Constructor.
	 *
	 * @param TrafficOutcomeParams $outcomeParams The form and custom-dimension param rules.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TrafficOutcomeParams $outcomeParams = new TrafficOutcomeParams(),
	) {
	}

	/**
	 * Validate and normalise one event against a portal's configuration.
	 *
	 * @param array<string, mixed>   $event      The posted event.
	 * @param array<string, mixed>   $config     The portal's resolved configuration.
	 * @param bool                   $hasConsent Whether the visitor has consented.
	 * @param TrafficConfigResolver  $resolver   The configuration resolver.
	 * @param array<string, mixed>   $context    `serverSide` => true for a PHP caller.
	 * @param DateTimeImmutable|null $now        The server clock; null means now.
	 *
	 * @return array{ok: bool, reason?: string, event?: array<string, mixed>}
	 *                                                                       The outcome.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-a-portal-must-decide-what-is-measured-and-the-collector-must-enforce-it
	 */
	public function validate(
		array $event,
		array $config,
		bool $hasConsent,
		TrafficConfigResolver $resolver,
		array $context = [],
		?DateTimeImmutable $now = null,
	): array {
		$serverSide = ($context['serverSide'] ?? false) === true;
		$reason = $this->refusal(event: $event, config: $config, hasConsent: $hasConsent, resolver: $resolver, serverSide: $serverSide);
		if ($reason !== null) {
			return ['ok' => false, 'reason' => $reason];
		}

		$occurredAt = $this->occurredAt(value: ($event['timestamp'] ?? null), now: ($now ?? $this->now()));
		if ($occurredAt === null) {
			return ['ok' => false, 'reason' => 'timestamp-out-of-range'];
		}

		$normalised = [
			'name' => $this->string(value: $event['name'], max: 64),
			'sequence' => $event['sequence'],
			'occurredAt' => $occurredAt->format('Y-m-d\TH:i:s.v\Z'),
			'pageLocation' => $this->string(value: $event['pageLocation'], max: self::MAX_STRING),
			// A session id is what the client says it is; the aggregation
			// step sessionises by visitor hash when it is empty. A client id
			// is only kept when the PORTAL allowed it to exist.
			'sessionId' => $this->string(value: ($event['sessionId'] ?? null), max: 64),
			'clientId' => $this->clientId(event: $event, config: $config, hasConsent: $hasConsent),
			'params' => $this->outcomeParams->filter(
				name: $this->string(value: $event['name'], max: 64),
				params: $this->params(value: ($event['params'] ?? null)),
				config: $config
			),
		];

		// Dimensions the portal did not enable are STRIPPED, and the event is
		// still stored. Refusing the whole event would lose a real page view
		// because a client sent one field too many.
		foreach ($this->dimensionsOf(event: $event, serverSide: $serverSide) as $key => $value) {
			if (in_array($key, ($config['dimensions'] ?? []), true) === false) {
				continue;
			}

			$normalised[$key] = $this->string(value: $value, max: self::MAX_STRING);
		}

		return ['ok' => true, 'event' => $normalised];
	}

	/**
	 * Why this event is refused, or null when it may be stored.
	 *
	 * Ordered from "who is asking" to "what did they send": the caller check
	 * comes first, the portal's configuration second, the envelope last.
	 *
	 * @param array<string, mixed>  $event      The posted event.
	 * @param array<string, mixed>  $config     The resolved configuration.
	 * @param bool                  $hasConsent Whether the visitor consented.
	 * @param TrafficConfigResolver $resolver   The configuration resolver.
	 * @param bool                  $serverSide Whether the caller is server-side.
	 *
	 * @return string|null The refusal reason, or null.
	 */
	private function refusal(
		array $event,
		array $config,
		bool $hasConsent,
		TrafficConfigResolver $resolver,
		bool $serverSide,
	): ?string {
		$name = $this->string(value: ($event['name'] ?? null), max: 64);
		if ($name === '') {
			return 'missing-event-name';
		}

		// Checked BEFORE the portal's own list. A portal that enabled
		// `email_open` for its mail integration has not thereby invited
		// every browser on the internet to report opening its mail.
		if ($resolver->allowsCaller(event: $name, serverSide: $serverSide) === false) {
			return 'event-server-side-only';
		}

		if ($resolver->acceptsEvent(config: $config, event: $name, hasConsent: $hasConsent) === false) {
			return $this->configurationRefusal(name: $name, config: $config);
		}

		// THE SEQUENCE IS WHAT MAKES A JOURNEY RECONSTRUCTABLE. Ordering by
		// arrival is wrong exactly when it matters, on a slow connection
		// where a delayed beacon lands after the next page's, so an event
		// without a usable sequence is refused rather than stored unorderable.
		$sequence = $event['sequence'] ?? null;
		if (is_int($sequence) === false || $sequence < 0) {
			return 'missing-sequence';
		}

		if ($this->string(value: ($event['pageLocation'] ?? null), max: self::MAX_STRING) === '') {
			return 'missing-page-location';
		}

		return null;
	}

	/**
	 * Which of three configuration refusals applies.
	 *
	 * THREE refusals, not one, because they send the operator to three
	 * different places: the portal never enabled measurement at all, the
	 * portal measures but not this event, or consent has not been given yet.
	 *
	 * The first case has to be tested FIRST. A portal with measurement off
	 * still resolves the default event list; `enabled` and `events` are
	 * independent, so asking "is this event in the list" answers yes for
	 * `page_view` on a portal that measures nothing, and the caller is told
	 * to check its consent banner over a portal that was never switched on.
	 * Found by the test for it.
	 *
	 * @param string               $name   The event name.
	 * @param array<string, mixed> $config The resolved configuration.
	 *
	 * @return string The refusal reason.
	 */
	private function configurationRefusal(string $name, array $config): string {
		if (($config['enabled'] ?? false) !== true) {
			return 'measurement-disabled';
		}

		if (in_array($name, ($config['events'] ?? []), true) === true) {
			return 'event-requires-consent';
		}

		return 'event-not-enabled';
	}

	/**
	 * The client id the record may carry: the posted one when the portal
	 * persists ids AND the visitor consented, otherwise nothing.
	 *
	 * Dropped rather than refused. A visitor who withdrew consent still
	 * counts as a visitor; they simply stop being the SAME visitor tomorrow.
	 *
	 * @param array<string, mixed> $event      The posted event.
	 * @param array<string, mixed> $config     The resolved configuration.
	 * @param bool                 $hasConsent Whether the visitor consented.
	 *
	 * @return string The client id to store, or ''.
	 */
	private function clientId(array $event, array $config, bool $hasConsent): string {
		if (($config['persistClientId'] ?? false) !== true) {
			return '';
		}

		if (($config['consentRequired'] ?? false) === true && $hasConsent === false) {
			return '';
		}

		return $this->string(value: ($event['clientId'] ?? null), max: 64);
	}

	/**
	 * The client's timestamp, clamped to the server clock.
	 *
	 * A missing or unparseable timestamp becomes "now": a client with a
	 * broken clock still had a visitor. A timestamp slightly ahead of the
	 * server is clamped to now; one far ahead, or older than the retention of
	 * the aggregates it would rewrite, is refused.
	 *
	 * @param mixed             $value The posted timestamp.
	 * @param DateTimeImmutable $now   The server clock.
	 *
	 * @return DateTimeImmutable|null The clamped moment, or null to refuse.
	 */
	private function occurredAt(mixed $value, DateTimeImmutable $now): ?DateTimeImmutable {
		$now = $now->setTimezone(new DateTimeZone('UTC'));
		if (is_string($value) === false || trim($value) === '') {
			return $now;
		}

		try {
			$parsed = (new DateTimeImmutable(trim($value)))->setTimezone(new DateTimeZone('UTC'));
		} catch (Throwable) {
			return $now;
		}

		$delta = $parsed->getTimestamp() - $now->getTimestamp();
		if ($delta > self::MAX_FUTURE_SECONDS || -$delta > self::MAX_AGE_SECONDS) {
			return null;
		}

		if ($delta > 0) {
			return $now;
		}

		return $parsed;
	}

	/**
	 * The server clock.
	 *
	 * @return DateTimeImmutable Now, in UTC.
	 */
	private function now(): DateTimeImmutable {
		return new DateTimeImmutable('now', new DateTimeZone('UTC'));
	}

	/**
	 * The bounded `params` map: at most MAX_PARAMS scalar entries with short
	 * keys and short values. Everything else is dropped, never refused.
	 *
	 * @param mixed $value The posted map.
	 *
	 * @return array<string, string|int|float|bool> The bounded map.
	 */
	private function params(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$out = [];
		foreach ($value as $key => $item) {
			if (count($out) >= self::MAX_PARAMS) {
				break;
			}

			if (is_string($key) === false || preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $key) !== 1) {
				continue;
			}

			$scalar = $this->scalar(item: $item);
			if ($scalar !== null) {
				$out[$key] = $scalar;
			}
		}

		return $out;
	}

	/**
	 * A param value as stored: a bounded string, a number or a boolean. Null
	 * for anything else, which the caller drops.
	 *
	 * @param mixed $item The posted value.
	 *
	 * @return string|int|float|bool|null The storable value, or null.
	 */
	private function scalar(mixed $item): string|int|float|bool|null {
		if (is_string($item) === true) {
			return mb_substr($item, 0, self::MAX_PARAM_VALUE);
		}

		if (is_int($item) === true || is_float($item) === true || is_bool($item) === true) {
			return $item;
		}

		return null;
	}

	/**
	 * The dimension candidates carried by a posted event.
	 *
	 * A browser may supply the client dimensions only. A server-side caller
	 * may additionally supply the mail and campaign fields, because it IS the
	 * app that sent the mail. Nothing from either may claim a derived
	 * dimension: region, device, browser and channel are computed here from
	 * the request, not accepted from the payload.
	 *
	 * @param array<string, mixed> $event      The posted event.
	 * @param bool                 $serverSide Whether the caller is server-side.
	 *
	 * @return array<string, mixed> Candidate dimensions, unfiltered.
	 */
	private function dimensionsOf(array $event, bool $serverSide): array {
		$allowed = TrafficConfigResolver::CLIENT_DIMENSIONS;
		if ($serverSide === true) {
			$allowed = array_merge($allowed, TrafficConfigResolver::SERVER_SIDE_DIMENSIONS);
		}

		$out = [];
		foreach ($allowed as $key) {
			if (array_key_exists($key, $event) === true) {
				$out[$key] = $event[$key];
			}
		}

		return $out;
	}

	/**
	 * A bounded, trimmed string: never null, never longer than `$max`.
	 *
	 * @param mixed $value The raw value.
	 * @param int   $max   The maximum length kept.
	 *
	 * @return string The bounded string, or '' when the value was not one.
	 */
	private function string(mixed $value, int $max): string {
		if (is_string($value) === false) {
			return '';
		}

		return mb_substr(trim($value), 0, $max);
	}
}
