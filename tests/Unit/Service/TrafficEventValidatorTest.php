<?php

/**
 * Unit tests for TrafficEventValidator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\TrafficConfigResolver;
use OCA\Portaliq\Service\TrafficEventValidator;
use PHPUnit\Framework\TestCase;

/**
 * The validator stands between an anonymous, publicly writable endpoint and a
 * government portal's traffic table. These tests are about what it refuses and
 * whether the refusal says why.
 */
class TrafficEventValidatorTest extends TestCase {

	/**
	 * The validator under test.
	 *
	 * @var TrafficEventValidator
	 */
	private TrafficEventValidator $validator;

	/**
	 * The configuration resolver the validator asks.
	 *
	 * @var TrafficConfigResolver
	 */
	private TrafficConfigResolver $resolver;


	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->validator = new TrafficEventValidator();
		$this->resolver = new TrafficConfigResolver();
	}//end setUp()


	/**
	 * A fully enabled configuration, for the positive cases.
	 *
	 * @return array<string, mixed> The resolved configuration.
	 */
	private function openConfig(): array {
		return $this->resolver->resolve(
			portal: [
				'traffic' => [
					'enabled' => true,
					'events' => ['page_view', 'search'],
					'dimensions' => ['pageReferrer', 'pageTitle'],
				],
			]
		);
	}//end openConfig()


	/**
	 * A well-formed event is accepted and normalised.
	 *
	 * The positive control. Without it every refusal below is satisfied by a
	 * validator that refuses everything, and a collector that stores nothing
	 * passes its own test suite.
	 *
	 * @return void
	 */
	public function testAWellFormedEventIsAccepted(): void {
		$result = $this->validator->validate(
			event: [
				'name' => 'page_view',
				'clientId' => 'c-1',
				'sessionId' => 's-1',
				'sequence' => 0,
				'pageLocation' => 'https://portaal.example.nl/begrippen',
			],
			config: $this->openConfig(),
			hasConsent: true,
			resolver: $this->resolver
		);

		$this->assertTrue($result['ok']);
		$this->assertSame('page_view', $result['event']['name']);
		$this->assertSame(0, $result['event']['sequence']);
	}//end testAWellFormedEventIsAccepted()


	/**
	 * An event without a usable sequence is refused, not stored unorderable.
	 *
	 * Ordering by arrival is wrong exactly when it matters — a delayed beacon
	 * on a slow connection lands after the next page's — so an event that
	 * cannot be ordered is worse than no event: it silently reverses a journey.
	 *
	 * @return void
	 */
	public function testAnEventWithoutASequenceIsRefused(): void {
		foreach ([null, '3', -1, 1.5] as $bad) {
			$result = $this->validator->validate(
				event: [
					'name' => 'page_view',
					'clientId' => 'c-1',
					'sessionId' => 's-1',
					'sequence' => $bad,
					'pageLocation' => 'https://portaal.example.nl/',
				],
				config: $this->openConfig(),
				hasConsent: true,
				resolver: $this->resolver
			);

			$this->assertFalse($result['ok'], 'sequence ' . var_export($bad, true) . ' must be refused');
			$this->assertSame('missing-sequence', $result['reason']);
		}
	}//end testAnEventWithoutASequenceIsRefused()


	/**
	 * A disabled event and a consent-blocked event give DIFFERENT reasons.
	 *
	 * The fixes are different — one is a configuration change, the other is a
	 * consent banner that never fired — so one reason for both would send the
	 * operator to the wrong place.
	 *
	 * @return void
	 */
	public function testTheRefusalDistinguishesNotEnabledFromNeedsConsent(): void {
		$config = $this->resolver->resolve(
			portal: [
				'traffic' => [
					'enabled' => true,
					'events' => ['page_view'],
					'consent' => ['required' => true, 'preConsentEvents' => []],
				],
			]
		);

		$base = ['clientId' => 'c', 'sessionId' => 's', 'sequence' => 0, 'pageLocation' => 'https://x/'];

		$notEnabled = $this->validator->validate(
			event: ($base + ['name' => 'search']),
			config: $config,
			hasConsent: true,
			resolver: $this->resolver
		);
		$this->assertSame('event-not-enabled', $notEnabled['reason']);

		$needsConsent = $this->validator->validate(
			event: ($base + ['name' => 'page_view']),
			config: $config,
			hasConsent: false,
			resolver: $this->resolver
		);
		$this->assertSame('event-requires-consent', $needsConsent['reason']);
	}//end testTheRefusalDistinguishesNotEnabledFromNeedsConsent()


	/**
	 * A dimension the portal did not enable is STRIPPED; the event survives.
	 *
	 * Refusing the whole event would lose a real page view because a client
	 * sent one field too many — the client is often a cached build of an older
	 * configuration, which is not a reason to stop counting visitors.
	 *
	 * @return void
	 */
	public function testAnUnlistedDimensionIsStrippedNotFatal(): void {
		$config = $this->resolver->resolve(
			portal: [
				'traffic' => [
					'enabled' => true,
					'events' => ['search'],
					'dimensions' => ['pageTitle'],
				],
			]
		);

		$result = $this->validator->validate(
			event: [
				'name' => 'search',
				'clientId' => 'c',
				'sessionId' => 's',
				'sequence' => 2,
				'pageLocation' => 'https://x/zoeken',
				'pageTitle' => 'Zoeken',
				'searchTerm' => 'achternaam van een inwoner',
			],
			config: $config,
			hasConsent: true,
			resolver: $this->resolver
		);

		$this->assertTrue($result['ok']);
		$this->assertSame('Zoeken', $result['event']['pageTitle']);
		$this->assertArrayNotHasKey(
			'searchTerm',
			$result['event'],
			'A dimension the portal did not enable must not reach storage.'
		);
	}//end testAnUnlistedDimensionIsStrippedNotFatal()


	/**
	 * An over-long string is truncated rather than losing the event.
	 *
	 * @return void
	 */
	public function testAnOverLongLocationIsTruncatedNotRefused(): void {
		$long = 'https://portaal.example.nl/?q=' . str_repeat('a', 2000);

		$result = $this->validator->validate(
			event: [
				'name' => 'page_view',
				'clientId' => 'c',
				'sessionId' => 's',
				'sequence' => 0,
				'pageLocation' => $long,
			],
			config: $this->openConfig(),
			hasConsent: true,
			resolver: $this->resolver
		);

		$this->assertTrue($result['ok']);
		$this->assertSame(TrafficEventValidator::MAX_STRING, mb_strlen($result['event']['pageLocation']));
	}//end testAnOverLongLocationIsTruncatedNotRefused()


	/**
	 * A cookieless event carries no correlation ids and is still accepted.
	 *
	 * The default mode stores nothing in the visitor's browser, so the client
	 * has no id to send. Refusing that would make the privacy-preserving
	 * default the one configuration that never records a visitor.
	 *
	 * @return void
	 */
	public function testACookielessEventNeedsNoCorrelationIds(): void {
		$result = $this->validator->validate(
			event: [
				'name' => 'page_view',
				'sequence' => 0,
				'pageLocation' => 'https://x/',
			],
			config: $this->openConfig(),
			hasConsent: true,
			resolver: $this->resolver
		);

		$this->assertTrue($result['ok']);
		$this->assertSame('', $result['event']['clientId']);
		$this->assertSame('', $result['event']['sessionId']);
	}//end testACookielessEventNeedsNoCorrelationIds()


	/**
	 * A client id is kept ONLY when the portal switched persistence on.
	 *
	 * A stale client built against an older configuration keeps sending the
	 * id it once stored. The portal said no, so the server drops it; the
	 * event itself survives, because the visitor is still a visitor.
	 *
	 * @return void
	 */
	public function testAClientIdIsDroppedUnlessThePortalPersistsIds(): void {
		$event = ['name' => 'page_view', 'sequence' => 0, 'pageLocation' => 'https://x/', 'clientId' => 'c-1'];

		$dropped = $this->validator->validate(
			event: $event,
			config: $this->openConfig(),
			hasConsent: true,
			resolver: $this->resolver
		);
		$this->assertTrue($dropped['ok']);
		$this->assertSame('', $dropped['event']['clientId'], 'The portal did not persist ids; none may be stored.');

		$persisting = $this->resolver->resolve(
			portal: ['traffic' => ['enabled' => true, 'sensitive' => ['persistClientId' => true]]]
		);
		$kept = $this->validator->validate(event: $event, config: $persisting, hasConsent: true, resolver: $this->resolver);
		$this->assertSame('c-1', $kept['event']['clientId']);

		// And even a persisting portal keeps nothing before consent.
		$consenting = $this->resolver->resolve(
			portal: [
				'traffic' => [
					'enabled' => true,
					'sensitive' => ['persistClientId' => true],
					'consent' => ['required' => true, 'preConsentEvents' => ['page_view']],
				],
			]
		);
		$before = $this->validator->validate(event: $event, config: $consenting, hasConsent: false, resolver: $this->resolver);
		$this->assertTrue($before['ok']);
		$this->assertSame('', $before['event']['clientId']);
	}//end testAClientIdIsDroppedUnlessThePortalPersistsIds()


	/**
	 * A mail event is refused from a browser and accepted from PHP.
	 *
	 * Both halves. A validator that refused mail events everywhere would pass
	 * the first assertion while breaking the mail integration that is the
	 * only reason the events exist.
	 *
	 * @return void
	 */
	public function testMailEventsAreServerSideOnly(): void {
		$config = $this->resolver->resolve(
			portal: ['traffic' => ['enabled' => true, 'events' => ['email_open'], 'dimensions' => ['blastRef']]]
		);
		$event = ['name' => 'email_open', 'sequence' => 0, 'pageLocation' => 'mailto:blast/1', 'blastRef' => 'b-1'];

		$fromBrowser = $this->validator->validate(event: $event, config: $config, hasConsent: true, resolver: $this->resolver);
		$this->assertFalse($fromBrowser['ok']);
		$this->assertSame('event-server-side-only', $fromBrowser['reason']);

		$fromPhp = $this->validator->validate(
			event: $event,
			config: $config,
			hasConsent: true,
			resolver: $this->resolver,
			context: ['serverSide' => true]
		);
		$this->assertTrue($fromPhp['ok']);
		$this->assertSame('b-1', $fromPhp['event']['blastRef']);
	}//end testMailEventsAreServerSideOnly()


	/**
	 * A derived dimension cannot be supplied by the client.
	 *
	 * `region` is computed from the address and then the address is
	 * discarded. A client that could post it directly would decide what the
	 * portal knows about a visitor, and the granularity setting would be
	 * decoration.
	 *
	 * @return void
	 */
	public function testADerivedDimensionFromTheClientIsIgnored(): void {
		$config = $this->resolver->resolve(
			portal: ['traffic' => ['enabled' => true, 'dimensions' => ['region', 'deviceType', 'pageTitle']]]
		);

		$result = $this->validator->validate(
			event: [
				'name' => 'page_view',
				'sequence' => 0,
				'pageLocation' => 'https://x/',
				'region' => 'NL-NB',
				'deviceType' => 'tablet',
				'pageTitle' => 'Home',
			],
			config: $config,
			hasConsent: true,
			resolver: $this->resolver
		);

		$this->assertTrue($result['ok']);
		$this->assertArrayNotHasKey('region', $result['event']);
		$this->assertArrayNotHasKey('deviceType', $result['event']);
		$this->assertSame('Home', $result['event']['pageTitle'], 'a client dimension still passes');
	}//end testADerivedDimensionFromTheClientIsIgnored()


	/**
	 * The params map is bounded in keys, key shape and value length, and a
	 * non-scalar value is dropped rather than stored.
	 *
	 * @return void
	 */
	public function testParamsAreBounded(): void {
		$params = [];
		for ($i = 0; $i < 30; $i++) {
			$params['k' . $i] = 'v';
		}

		$params['long'] = str_repeat('x', 1000);
		$params['nested'] = ['not' => 'allowed'];
		$params['bad key!'] = 'v';

		$result = $this->validator->validate(
			event: ['name' => 'page_view', 'sequence' => 0, 'pageLocation' => 'https://x/', 'params' => $params],
			config: $this->openConfig(),
			hasConsent: true,
			resolver: $this->resolver
		);

		$this->assertTrue($result['ok']);
		$stored = $result['event']['params'];
		$this->assertLessThanOrEqual(TrafficEventValidator::MAX_PARAMS, count($stored));
		$this->assertArrayNotHasKey('nested', $stored);
		$this->assertArrayNotHasKey('bad key!', $stored);
		foreach ($stored as $value) {
			$this->assertLessThanOrEqual(TrafficEventValidator::MAX_PARAM_VALUE, mb_strlen((string)$value));
		}
	}//end testParamsAreBounded()


	/**
	 * A form event keeps only the whitelisted params: a value typed into a
	 * field cannot reach storage even from a client that sends it
	 * (portal-traffic-outcomes).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-form-analytics-must-never-carry-a-value
	 */
	public function testAFormEventKeepsOnlyIdsAndTimes(): void {
		$config = $this->resolver->resolve(
			portal: ['traffic' => ['enabled' => true, 'events' => ['form_start', 'form_field', 'form_abandon', 'form_submit']]]
		);

		$result = $this->validator->validate(
			event: [
				'name' => 'form_field',
				'sequence' => 3,
				'pageLocation' => 'https://x/campagne',
				'params' => ['formId' => 'aanmelden', 'fieldId' => 'email', 'ms' => 1200, 'value' => 'jan@example.org', 'label' => 'E-mail'],
			],
			config: $config,
			hasConsent: true,
			resolver: $this->resolver
		);

		$this->assertTrue($result['ok']);
		$this->assertSame(['formId' => 'aanmelden', 'fieldId' => 'email', 'ms' => 1200], $result['event']['params']);

		$abandon = $this->validator->validate(
			event: [
				'name' => 'form_abandon',
				'sequence' => 4,
				'pageLocation' => 'https://x/campagne',
				'params' => ['formId' => 'aanmelden', 'lastFieldId' => 'email', 'values' => 'x'],
			],
			config: $config,
			hasConsent: true,
			resolver: $this->resolver
		);
		$this->assertSame(['formId' => 'aanmelden', 'lastFieldId' => 'email'], $abandon['event']['params']);
	}//end testAFormEventKeepsOnlyIdsAndTimes()


	/**
	 * A form event is refused, not stripped, when the portal did not enable
	 * it: form analytics is a decision the operator makes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-form-analytics-must-never-carry-a-value
	 */
	public function testAFormEventIsRefusedUnlessEnabled(): void {
		$result = $this->validator->validate(
			event: ['name' => 'form_field', 'sequence' => 0, 'pageLocation' => 'https://x/', 'params' => ['formId' => 'f', 'fieldId' => 'a', 'ms' => 1]],
			config: $this->openConfig(),
			hasConsent: true,
			resolver: $this->resolver
		);

		$this->assertFalse($result['ok']);
		$this->assertSame('event-not-enabled', $result['reason']);
	}//end testAFormEventIsRefusedUnlessEnabled()


	/**
	 * A custom dimension survives only when the portal declared its id;
	 * the rest of the params are untouched.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-custom-dimensions-must-be-declared-before-they-are-stored
	 */
	public function testAnUndeclaredCustomDimensionIsStripped(): void {
		$config = $this->resolver->resolve(
			portal: ['traffic' => ['enabled' => true, 'customDimensions' => [['id' => 'audience', 'name' => 'Audience', 'scope' => 'session']]]]
		);

		$result = $this->validator->validate(
			event: [
				'name' => 'page_view',
				'sequence' => 0,
				'pageLocation' => 'https://x/',
				'params' => ['cd_audience' => 'inwoner', 'cd_secret' => 'bsn', 'percent' => 90],
			],
			config: $config,
			hasConsent: true,
			resolver: $this->resolver
		);

		$this->assertTrue($result['ok']);
		$this->assertSame(['cd_audience' => 'inwoner', 'percent' => 90], $result['event']['params']);
	}//end testAnUndeclaredCustomDimensionIsStripped()


	/**
	 * The client clock is clamped to the server's, and refused when it is
	 * not a clock problem any more.
	 *
	 * @return void
	 */
	public function testTheTimestampIsClampedToTheServerClock(): void {
		$now = new \DateTimeImmutable('2026-09-04T10:00:00Z');
		$base = ['name' => 'page_view', 'sequence' => 0, 'pageLocation' => 'https://x/'];
		$run = fn (?string $stamp): array => $this->validator->validate(
			event: ($base + ['timestamp' => $stamp]),
			config: $this->openConfig(),
			hasConsent: true,
			resolver: $this->resolver,
			now: $now
		);

		// Slightly ahead: clamped to now, not refused.
		$ahead = $run('2026-09-04T10:02:00Z');
		$this->assertTrue($ahead['ok']);
		$this->assertSame('2026-09-04T10:00:00.000Z', $ahead['event']['occurredAt']);

		// In the past within the window: kept as sent, in UTC.
		$past = $run('2026-09-04T11:30:00+02:00');
		$this->assertSame('2026-09-04T09:30:00.000Z', $past['event']['occurredAt']);

		// Missing or garbage: now.
		$this->assertSame('2026-09-04T10:00:00.000Z', $run(null)['event']['occurredAt']);
		$this->assertSame('2026-09-04T10:00:00.000Z', $run('yesterday-ish')['event']['occurredAt']);

		// Far ahead, or older than a week: refused with a reason.
		$this->assertSame('timestamp-out-of-range', $run('2026-09-05T10:00:00Z')['reason']);
		$this->assertSame('timestamp-out-of-range', $run('2026-08-01T10:00:00Z')['reason']);
	}//end testTheTimestampIsClampedToTheServerClock()


	/**
	 * Nothing is accepted for a portal that never enabled measurement, and the
	 * reason says SO rather than blaming consent.
	 *
	 * This caught a real defect: `enabled` and `events` are independent, so a
	 * portal with measurement off still resolves the default event list, and
	 * asking "is this event enabled" answered yes for `page_view`. The operator
	 * of a portal that was never switched on would have been sent to check a
	 * consent banner.
	 *
	 * @return void
	 */
	public function testADisabledPortalAcceptsNothing(): void {
		$result = $this->validator->validate(
			event: [
				'name' => 'page_view',
				'clientId' => 'c',
				'sessionId' => 's',
				'sequence' => 0,
				'pageLocation' => 'https://x/',
			],
			config: $this->resolver->resolve(portal: []),
			hasConsent: true,
			resolver: $this->resolver
		);

		$this->assertFalse($result['ok']);
		$this->assertSame('measurement-disabled', $result['reason']);
	}//end testADisabledPortalAcceptsNothing()


	/**
	 * A heatmap event is refused as sensitive-off until the portal's
	 * operator accepts that warning, whatever the events list says; with
	 * the switch on it is stored with positions only, and a selector
	 * loses anything that looks like an id.
	 *
	 * @return void
	 */
	public function testHeatmapEventsFollowTheHeatmapsSwitch(): void {
		$event = [
			'name' => 'heat_click',
			'sequence' => 0,
			'pageLocation' => 'https://portaal.example.nl/',
			'params' => ['x' => 0.25, 'y' => '0.5', 'vw' => 1280, 'tag' => 'BUTTON', 'selector' => 'main > form#bsn-123 button.cta[name=jan]', 'text' => 'Jan Jansen'],
		];
		$off = $this->resolver->resolve(portal: ['traffic' => ['enabled' => true, 'events' => ['page_view', 'heat_click']]]);
		$refused = $this->validator->validate(event: $event, config: $off, hasConsent: true, resolver: $this->resolver);
		$this->assertSame(['ok' => false, 'reason' => 'sensitive-off'], $refused);

		$on = $this->resolver->resolve(portal: ['traffic' => ['enabled' => true, 'sensitive' => ['heatmaps' => true]]]);
		$stored = $this->validator->validate(event: $event, config: $on, hasConsent: true, resolver: $this->resolver);
		$this->assertTrue($stored['ok'], 'the switch enables the event without listing it');
		$this->assertSame(
			['x' => 0.25, 'y' => 0.5, 'vw' => 1280, 'tag' => 'button', 'selector' => 'main > form button.cta'],
			$stored['event']['params']
		);

		$scroll = $this->validator->validate(
			event: ['name' => 'heat_scroll', 'sequence' => 1, 'pageLocation' => 'https://portaal.example.nl/', 'params' => ['depth' => 1.2, 'vw' => 800]],
			config: $on,
			hasConsent: true,
			resolver: $this->resolver
		);
		$this->assertSame(['vw' => 800], $scroll['event']['params'], 'a depth past the page is not a depth');
	}//end testHeatmapEventsFollowTheHeatmapsSwitch()


	/**
	 * An experiment tag survives only when it names a running experiment
	 * and one of its variants; half a tag or a stopped one is stripped.
	 *
	 * @return void
	 */
	public function testAnExperimentTagMustNameARunningExperiment(): void {
		$config = $this->resolver->resolve(portal: ['traffic' => [
			'enabled' => true,
			'experiments' => [
				['id' => 'hero', 'status' => 'running', 'page' => '/', 'variants' => [['id' => 'a'], ['id' => 'b']]],
				['id' => 'old', 'status' => 'stopped', 'page' => '/', 'variants' => [['id' => 'a'], ['id' => 'b']]],
			],
		]]);
		$validate = fn (array $params): array => $this->validator->validate(
			event: ['name' => 'page_view', 'sequence' => 0, 'pageLocation' => 'https://portaal.example.nl/', 'params' => $params + ['keep' => 'me']],
			config: $config,
			hasConsent: true,
			resolver: $this->resolver
		)['event']['params'];

		$this->assertSame(['experiment' => 'hero', 'variant' => 'b', 'keep' => 'me'], $validate(['experiment' => 'hero', 'variant' => 'b']));
		$this->assertSame(['keep' => 'me'], $validate(['experiment' => 'hero', 'variant' => 'z']), 'not a variant');
		$this->assertSame(['keep' => 'me'], $validate(['experiment' => 'old', 'variant' => 'a']), 'stopped');
		$this->assertSame(['keep' => 'me'], $validate(['experiment' => 'hero']), 'half a tag');
	}//end testAnExperimentTagMustNameARunningExperiment()
}//end class
