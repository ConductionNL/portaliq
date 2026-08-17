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

/**
 * Turns one posted event into a storable record, or into a named refusal.
 *
 * EVERY REFUSAL HAS A REASON, and the reason travels back. A collector that
 * answers 204 to everything cannot be configured: a client sending an event the
 * portal disabled, a client sending a malformed one, and a client that is
 * working perfectly all look identical from the outside — which is exactly how
 * a measurement gap survives for months while a dashboard shows a confident
 * zero.
 *
 * THE INPUT IS HOSTILE. This runs behind an anonymous, unauthenticated,
 * publicly writable endpoint on a government portal. Every field is bounded,
 * every string truncated, every unknown key dropped.
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
	 * Validate and normalise one event against a portal's configuration.
	 *
	 * @param array<string, mixed> $event      The posted event.
	 * @param array<string, mixed> $config     The portal's resolved configuration.
	 * @param bool                 $hasConsent Whether the visitor has consented.
	 * @param TrafficConfigResolver $resolver  The configuration resolver.
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
	): array {
		$name = $this->string(value: ($event['name'] ?? null), max: 64);
		if ($name === '') {
			return ['ok' => false, 'reason' => 'missing-event-name'];
		}

		if ($resolver->acceptsEvent(config: $config, event: $name, hasConsent: $hasConsent) === false) {
			// THREE refusals, not one, because they send the operator to three
			// different places: the portal never enabled measurement at all,
			// the portal measures but not this event, or consent has not been
			// given yet.
			//
			// The first case has to be tested FIRST. A portal with measurement
			// off still resolves the default event list — `enabled` and
			// `events` are independent — so asking "is this event in the list"
			// answers yes for `page_view` on a portal that measures nothing,
			// and the caller is told to check its consent banner over a
			// portal that was never switched on. Found by the test below.
			if (($config['enabled'] ?? false) !== true) {
				return ['ok' => false, 'reason' => 'measurement-disabled'];
			}

			if (in_array($name, ($config['events'] ?? []), true) === true) {
				return ['ok' => false, 'reason' => 'event-requires-consent'];
			}

			return ['ok' => false, 'reason' => 'event-not-enabled'];
		}

		$clientId = $this->string(value: ($event['clientId'] ?? null), max: 64);
		$sessionId = $this->string(value: ($event['sessionId'] ?? null), max: 64);
		if ($clientId === '' || $sessionId === '') {
			return ['ok' => false, 'reason' => 'missing-correlation-id'];
		}

		// THE SEQUENCE IS WHAT MAKES A JOURNEY RECONSTRUCTABLE. Ordering by
		// arrival is wrong exactly when it matters — on a slow connection, where
		// a delayed beacon lands after the next page's — so an event without a
		// usable sequence is refused rather than stored unorderable.
		$sequence = $event['sequence'] ?? null;
		if (is_int($sequence) === false || $sequence < 0) {
			return ['ok' => false, 'reason' => 'missing-sequence'];
		}

		$location = $this->string(value: ($event['pageLocation'] ?? null), max: self::MAX_STRING);
		if ($location === '') {
			return ['ok' => false, 'reason' => 'missing-page-location'];
		}

		$normalised = [
			'name' => $name,
			'clientId' => $clientId,
			'sessionId' => $sessionId,
			'sequence' => $sequence,
			'pageLocation' => $location,
		];

		// Dimensions the portal did not enable are STRIPPED, and the event is
		// still stored. Refusing the whole event would lose a real page view
		// because a client sent one field too many.
		foreach ($this->dimensionsOf(event: $event) as $key => $value) {
			if (in_array($key, ($config['dimensions'] ?? []), true) === false) {
				continue;
			}

			$normalised[$key] = $this->string(value: $value, max: self::MAX_STRING);
		}

		return ['ok' => true, 'event' => $normalised];
	}

	/**
	 * The dimension candidates carried by a posted event.
	 *
	 * @param array<string, mixed> $event The posted event.
	 *
	 * @return array<string, mixed> Candidate dimensions, unfiltered.
	 */
	private function dimensionsOf(array $event): array {
		$out = [];
		foreach (TrafficConfigResolver::KNOWN_DIMENSIONS as $key) {
			if (array_key_exists($key, $event) === true) {
				$out[$key] = $event[$key];
			}
		}

		return $out;
	}

	/**
	 * A bounded, trimmed string — never null, never longer than `$max`.
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
