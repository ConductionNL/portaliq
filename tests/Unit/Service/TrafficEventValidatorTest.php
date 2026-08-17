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
	 * An event with no correlation ids cannot be part of a journey.
	 *
	 * @return void
	 */
	public function testMissingCorrelationIdsAreRefused(): void {
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

		$this->assertFalse($result['ok']);
		$this->assertSame('missing-correlation-id', $result['reason']);
	}//end testMissingCorrelationIdsAreRefused()


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


}//end class
