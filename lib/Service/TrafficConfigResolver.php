<?php

/**
 * Portaliq Traffic Configuration Resolver.
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
 * Resolves one portal's traffic-measurement configuration, with the shipped
 * defaults filled in.
 *
 * WHY THIS IS A CLASS AND NOT A `??` CHAIN AT THE CALL SITE
 *
 * Three places need the same answer — the collector deciding what to accept,
 * the public content contract telling the client what to send, and the admin UI
 * showing what is on. A default resolved separately in three places is three
 * defaults, and they drift in the direction of whichever one was edited last.
 *
 * MEASUREMENT IS OFF UNTIL A PORTAL TURNS IT ON. A portal that has never heard
 * of this feature must not begin recording its visitors because the app was
 * upgraded; consent and retention are decisions its operator makes, and an
 * unconfigured portal has made neither.
 *
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-a-portal-must-decide-what-is-measured-and-the-collector-must-enforce-it
 */
class TrafficConfigResolver {

	/**
	 * The events a portal gets when it enables measurement without naming any.
	 *
	 * Deliberately just the page view. Anything more is a decision, and a
	 * default that decides for the operator is the thing this feature exists to
	 * avoid.
	 *
	 * @var string[]
	 */
	public const DEFAULT_EVENTS = ['page_view', 'session_start'];

	/**
	 * The event names this app knows how to store, using the GA4 spelling so a
	 * figure here and a figure there mean the same thing.
	 *
	 * @var string[]
	 */
	public const KNOWN_EVENTS = [
		'page_view',
		'session_start',
		'scroll',
		'outbound_click',
		'file_download',
		'search',
		'form_submit',
		'email_open',
		'email_click',
	];

	/**
	 * Events only another app in this instance may write, through the PHP
	 * ingest entry point. A browser never opens a mail, so a browser claiming
	 * to have done so is refused whatever the portal enabled.
	 *
	 * @var string[]
	 */
	public const SERVER_SIDE_EVENTS = ['email_open', 'email_click'];

	/**
	 * Dimensions a portal may enable, beyond the envelope every event carries.
	 *
	 * @var string[]
	 */
	public const KNOWN_DIMENSIONS = [
		'pageReferrer',
		'pageTitle',
		'searchTerm',
		'linkUrl',
		'fileName',
		'region',
		'deviceType',
		'browser',
		'os',
		'language',
		'screen',
		'referrerHost',
		'channel',
		'campaign',
		'source',
		'medium',
		'content',
		'term',
		'blastRef',
		'contactRef',
		'userRef',
	];

	/**
	 * Dimensions a browser client may SUPPLY. Everything else in
	 * KNOWN_DIMENSIONS is derived on the server from the request, or is only
	 * ever written by another app in this instance.
	 *
	 * The split is the privacy boundary: a client that could post `region` or
	 * `userRef` itself could attach to a visitor anything it liked, and the
	 * portal's granularity setting would be decoration.
	 *
	 * @var string[]
	 */
	public const CLIENT_DIMENSIONS = [
		'pageReferrer',
		'pageTitle',
		'searchTerm',
		'linkUrl',
		'fileName',
		'screen',
	];

	/**
	 * Dimensions the ingest service derives from the request and the page
	 * location, with the source (address, user agent, query string) discarded
	 * in the same request.
	 *
	 * @var string[]
	 */
	public const DERIVED_DIMENSIONS = [
		'referrerHost',
		'channel',
		'deviceType',
		'browser',
		'os',
		'language',
		'region',
		'campaign',
		'source',
		'medium',
		'content',
		'term',
	];

	/**
	 * Dimensions carried only by a server-side caller's events: pipelinq's
	 * pseudonymous mail identifiers, and the campaign fields it attributes.
	 *
	 * @var string[]
	 */
	public const SERVER_SIDE_DIMENSIONS = [
		'campaign',
		'source',
		'medium',
		'content',
		'term',
		'blastRef',
		'contactRef',
	];

	/**
	 * The four switches that change what a portal knows about a PERSON rather
	 * than how much traffic it counts. Each is off until an operator turns it
	 * on, and each carries its own warning in the schema.
	 *
	 * @var string[]
	 */
	public const SENSITIVE_SWITCHES = [
		'persistClientId',
		'accountLinking',
		'heatmaps',
		'sessionRecording',
	];

	/**
	 * The dimensions enabled when a portal names none.
	 *
	 * `pageReferrer` is in, because "where did they come from" is the first
	 * question anyone asks and it names a site, not a person. `searchTerm` is
	 * OUT: a search box on a government portal receives names, case numbers and
	 * medical words, and storing that by default is a decision nobody made.
	 *
	 * @var string[]
	 */
	public const DEFAULT_DIMENSIONS = ['pageReferrer', 'pageTitle'];

	/**
	 * GA4's inactivity window, and the number every stakeholder already has in
	 * their head when they say "session".
	 */
	public const DEFAULT_SESSION_TIMEOUT_MINUTES = 30;

	/**
	 * How long raw events live before aggregation replaces them.
	 *
	 * Stated as a default rather than left unbounded: a traffic log that keeps
	 * everything until someone notices is a personal-data liability nobody
	 * chose.
	 */
	public const DEFAULT_RETENTION_DAYS = 90;

	/**
	 * Resolve a portal record's `traffic` block into a complete configuration.
	 *
	 * @param array<string, mixed> $portal The resolved portal record.
	 *
	 * @return array{enabled: bool, kind: string, events: string[], dimensions: string[],
	 *               sessionTimeoutMinutes: int, retentionDays: int,
	 *               consentRequired: bool, preConsentEvents: string[],
	 *               regionGranularity: string, persistClientId: bool,
	 *               sensitive: array<string, bool>} The effective configuration.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-a-portal-must-decide-what-is-measured-and-the-collector-must-enforce-it
	 */
	public function resolve(array $portal): array {
		$traffic = $portal['traffic'] ?? [];
		if (is_array($traffic) === false) {
			$traffic = [];
		}

		$enabled = ($traffic['enabled'] ?? false) === true;

		// An unknown event name is DROPPED from the configuration rather than
		// carried into the collector. A portal cannot enable a measurement this
		// app has no storage for, and silently accepting the name would make
		// the admin UI promise something no aggregate will ever show.
		$events = $this->intersect(
			requested: $traffic['events'] ?? null,
			known: self::KNOWN_EVENTS,
			fallback: self::DEFAULT_EVENTS
		);

		$sensitive = $this->sensitive(value: ($traffic['sensitive'] ?? null));
		$regionGranularity = $this->regionGranularity(value: ($traffic['regionGranularity'] ?? null));

		$dimensions = $this->intersect(
			requested: $traffic['dimensions'] ?? null,
			known: self::KNOWN_DIMENSIONS,
			fallback: self::DEFAULT_DIMENSIONS
		);

		// Two dimensions exist only under a switch that is elsewhere in the
		// configuration, and the switch wins. Listing `userRef` without
		// account linking, or `region` with the granularity set to none,
		// would otherwise store what the operator explicitly turned off.
		$dimensions = array_values(array_filter(
			$dimensions,
			static function (string $dimension) use ($sensitive, $regionGranularity): bool {
				if ($dimension === 'userRef') {
					return $sensitive['accountLinking'];
				}

				if ($dimension === 'region') {
					return $regionGranularity !== 'none';
				}

				return true;
			}
		));

		$consent = $traffic['consent'] ?? [];
		if (is_array($consent) === false) {
			$consent = [];
		}

		// Pre-consent events must themselves be enabled events. Otherwise a
		// portal could admit before consent something it does not collect
		// after it, which is exactly backwards.
		$preConsent = array_values(array_intersect(
			$this->intersect(
				requested: $consent['preConsentEvents'] ?? null,
				known: self::KNOWN_EVENTS,
				fallback: []
			),
			$events
		));

		return [
			'enabled' => $enabled,
			'kind' => $this->kind(value: ($portal['kind'] ?? null)),
			'events' => $events,
			'dimensions' => $dimensions,
			'sessionTimeoutMinutes' => $this->positiveInt(
				value: ($traffic['sessionTimeoutMinutes'] ?? null),
				default: self::DEFAULT_SESSION_TIMEOUT_MINUTES
			),
			'retentionDays' => $this->positiveInt(
				value: ($traffic['retentionDays'] ?? null),
				default: self::DEFAULT_RETENTION_DAYS
			),
			'consentRequired' => ($consent['required'] ?? false) === true,
			'preConsentEvents' => $preConsent,
			'regionGranularity' => $regionGranularity,
			// Mirrored to the top level because it is the one switch the CLIENT
			// acts on: it decides whether anything is written to the browser.
			'persistClientId' => $sensitive['persistClientId'],
			'sensitive' => $sensitive,
		];
	}

	/**
	 * Whether a caller may send this event at all, before the portal's own
	 * list is consulted.
	 *
	 * The mail events are written by another app in this instance about a
	 * message it sent. A browser has no way of knowing that, so a browser
	 * posting one is either a bug or an attempt to inflate a campaign, and
	 * both are refused with the same reason.
	 *
	 * @param string $event      The event name.
	 * @param bool   $serverSide Whether the caller is server-side PHP.
	 *
	 * @return bool True when the caller may send this event.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-mail-events-must-only-be-written-server-side
	 */
	public function allowsCaller(string $event, bool $serverSide): bool {
		if (in_array($event, self::SERVER_SIDE_EVENTS, true) === false) {
			return true;
		}

		return $serverSide;
	}

	/**
	 * Whether one event name may be stored for this configuration.
	 *
	 * @param array<string, mixed> $config       A resolved configuration.
	 * @param string               $event        The event name.
	 * @param bool                 $hasConsent   Whether the visitor has consented.
	 *
	 * @return bool True when the event may be stored.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-measurement-must-honour-the-portals-consent-posture
	 */
	public function acceptsEvent(array $config, string $event, bool $hasConsent): bool {
		if (($config['enabled'] ?? false) !== true) {
			return false;
		}

		if (in_array($event, ($config['events'] ?? []), true) === false) {
			return false;
		}

		if (($config['consentRequired'] ?? false) === true && $hasConsent === false) {
			return in_array($event, ($config['preConsentEvents'] ?? []), true);
		}

		return true;
	}

	/**
	 * Narrow a requested list to what this app knows, falling back when the
	 * portal named nothing.
	 *
	 * An EMPTY array is a real answer — "enable nothing" — and is preserved.
	 * Only a missing or non-list value falls back, because "I did not say" and
	 * "I said none" are different statements and conflating them would make
	 * disabling an event impossible.
	 *
	 * @param mixed    $requested The portal's value.
	 * @param string[] $known     Names this app supports.
	 * @param string[] $fallback  Used when the portal named nothing at all.
	 *
	 * @return string[] The effective list.
	 */
	private function intersect(mixed $requested, array $known, array $fallback): array {
		if (is_array($requested) === false) {
			return $fallback;
		}

		$names = array_values(array_filter(
			$requested,
			static fn ($n): bool => is_string($n) === true && $n !== ''
		));

		return array_values(array_intersect($names, $known));
	}

	/**
	 * A positive integer, or the default.
	 *
	 * @param mixed $value   The configured value.
	 * @param int   $default The shipped default.
	 *
	 * @return int The effective value.
	 */
	private function positiveInt(mixed $value, int $default): int {
		if (is_int($value) === true && $value > 0) {
			return $value;
		}

		if (is_string($value) === true && ctype_digit($value) === true && (int)$value > 0) {
			return (int)$value;
		}

		return $default;
	}

	/**
	 * The four person-affecting switches, each resolved to a strict boolean.
	 *
	 * Anything that is not literally `true` is off. A string "true" from a
	 * hand-edited record or a 1 from a form must not switch on tracking that
	 * requires a consent banner.
	 *
	 * @param mixed $value The configured `sensitive` block.
	 *
	 * @return array<string, bool> Every switch, on or off.
	 */
	private function sensitive(mixed $value): array {
		if (is_array($value) === false) {
			$value = [];
		}

		$out = [];
		foreach (self::SENSITIVE_SWITCHES as $switch) {
			$out[$switch] = ($value[$switch] ?? false) === true;
		}

		return $out;
	}

	/**
	 * What the portal record is: a site this app serves, or an external site
	 * that only reports traffic here.
	 *
	 * @param mixed $value The configured kind.
	 *
	 * @return string One of `site`, `external`.
	 */
	private function kind(mixed $value): string {
		if ($value === 'external') {
			return 'external';
		}

		return 'site';
	}

	/**
	 * The coarsest geography this portal permits.
	 *
	 * Defaults to `country`. A finer default would be a decision about personal
	 * data taken on the operator's behalf.
	 *
	 * @param mixed $value The configured value.
	 *
	 * @return string One of `none`, `country`, `region`.
	 */
	private function regionGranularity(mixed $value): string {
		if (in_array($value, ['none', 'country', 'region'], true) === true) {
			return (string)$value;
		}

		return 'country';
	}
}
