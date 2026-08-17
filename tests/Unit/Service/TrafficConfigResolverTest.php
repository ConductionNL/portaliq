<?php

/**
 * Unit tests for TrafficConfigResolver.
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
use PHPUnit\Framework\TestCase;

/**
 * The configuration is the gate. These tests are about the cases where a
 * plausible implementation quietly measures more than the operator asked for.
 */
class TrafficConfigResolverTest extends TestCase {

	/**
	 * The resolver under test.
	 *
	 * @var TrafficConfigResolver
	 */
	private TrafficConfigResolver $resolver;


	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->resolver = new TrafficConfigResolver();
	}//end setUp()


	/**
	 * A portal that has never heard of this feature measures NOTHING.
	 *
	 * An upgrade must not start recording a municipality's visitors. Consent
	 * and retention are decisions its operator makes, and an unconfigured
	 * portal has made neither — so "off" is the only honest default.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredPortalMeasuresNothing(): void {
		$config = $this->resolver->resolve(portal: ['slug' => 'open-tilburg']);

		$this->assertFalse($config['enabled']);
		$this->assertFalse(
			$this->resolver->acceptsEvent(config: $config, event: 'page_view', hasConsent: true),
			'An unconfigured portal must refuse even the most ordinary event.'
		);
	}//end testAnUnconfiguredPortalMeasuresNothing()


	/**
	 * "I said none" and "I did not say" are DIFFERENT statements.
	 *
	 * An empty list is a real answer and must be preserved. Treating it as
	 * "unset, use the defaults" would make disabling every event impossible —
	 * the operator would set `events: []`, see page views keep arriving, and
	 * have no way to tell the configuration was ignored.
	 *
	 * @return void
	 */
	public function testAnEmptyEventListMeansNoneNotDefaults(): void {
		$explicitNone = $this->resolver->resolve(
			portal: ['traffic' => ['enabled' => true, 'events' => []]]
		);
		$this->assertSame([], $explicitNone['events']);

		$unset = $this->resolver->resolve(portal: ['traffic' => ['enabled' => true]]);
		$this->assertSame(TrafficConfigResolver::DEFAULT_EVENTS, $unset['events']);
	}//end testAnEmptyEventListMeansNoneNotDefaults()


	/**
	 * An event this app cannot store is dropped from the configuration.
	 *
	 * Keeping the name would make the admin UI promise a measurement no
	 * aggregate will ever show.
	 *
	 * @return void
	 */
	public function testAnUnknownEventNameIsNotConfigurable(): void {
		$config = $this->resolver->resolve(
			portal: ['traffic' => ['enabled' => true, 'events' => ['page_view', 'mind_reading']]]
		);

		$this->assertSame(['page_view'], $config['events']);
		$this->assertFalse(
			$this->resolver->acceptsEvent(config: $config, event: 'mind_reading', hasConsent: true)
		);
	}//end testAnUnknownEventNameIsNotConfigurable()


	/**
	 * A portal enabling only `page_view` gets ONLY `page_view`.
	 *
	 * The positive half is asserted alongside the refusal on purpose: a
	 * resolver that refused everything would satisfy the refusal alone, and
	 * "measures nothing" is not the same success as "measures what was asked".
	 *
	 * @return void
	 */
	public function testTheEnabledSetIsExactlyWhatWasAskedFor(): void {
		$config = $this->resolver->resolve(
			portal: ['traffic' => ['enabled' => true, 'events' => ['page_view']]]
		);

		$this->assertTrue($this->resolver->acceptsEvent(config: $config, event: 'page_view', hasConsent: true));
		$this->assertFalse($this->resolver->acceptsEvent(config: $config, event: 'search', hasConsent: true));
	}//end testTheEnabledSetIsExactlyWhatWasAskedFor()


	/**
	 * Search terms are NOT collected by default.
	 *
	 * A search box on a government portal receives names, case numbers and
	 * medical words. Collecting that because it is interesting is a decision
	 * about personal data, and the default must not make it on the operator's
	 * behalf.
	 *
	 * @return void
	 */
	public function testSearchTermsAreNotADefaultDimension(): void {
		$config = $this->resolver->resolve(portal: ['traffic' => ['enabled' => true]]);

		$this->assertNotContains('searchTerm', $config['dimensions']);
		$this->assertContains('pageReferrer', $config['dimensions']);
	}//end testSearchTermsAreNotADefaultDimension()


	/**
	 * Before consent, only the pre-consent events are accepted.
	 *
	 * @return void
	 */
	public function testConsentGatesEverythingNotExplicitlyExempted(): void {
		$config = $this->resolver->resolve(
			portal: [
				'traffic' => [
					'enabled' => true,
					'events' => ['page_view', 'search'],
					'consent' => ['required' => true, 'preConsentEvents' => ['page_view']],
				],
			]
		);

		// Without consent: the exempted event only.
		$this->assertTrue($this->resolver->acceptsEvent(config: $config, event: 'page_view', hasConsent: false));
		$this->assertFalse($this->resolver->acceptsEvent(config: $config, event: 'search', hasConsent: false));

		// With consent: both. Asserted so a resolver that simply refused
		// everything before consent AND after it cannot pass.
		$this->assertTrue($this->resolver->acceptsEvent(config: $config, event: 'search', hasConsent: true));
	}//end testConsentGatesEverythingNotExplicitlyExempted()


	/**
	 * A pre-consent event that is not itself enabled is discarded.
	 *
	 * Otherwise a portal could admit before consent something it does not
	 * collect after it — backwards, and the kind of configuration that reads
	 * as deliberate.
	 *
	 * @return void
	 */
	public function testAPreConsentEventMustAlsoBeAnEnabledEvent(): void {
		$config = $this->resolver->resolve(
			portal: [
				'traffic' => [
					'enabled' => true,
					'events' => ['page_view'],
					'consent' => ['required' => true, 'preConsentEvents' => ['page_view', 'search']],
				],
			]
		);

		$this->assertSame(['page_view'], $config['preConsentEvents']);
		$this->assertFalse($this->resolver->acceptsEvent(config: $config, event: 'search', hasConsent: false));
	}//end testAPreConsentEventMustAlsoBeAnEnabledEvent()


	/**
	 * Retention and the session window always resolve to a usable number.
	 *
	 * A portal that enables measurement without stating a retention period
	 * gets the shipped default rather than "keep everything until someone
	 * notices".
	 *
	 * @return void
	 */
	public function testRetentionAndSessionWindowAlwaysHaveAValue(): void {
		$config = $this->resolver->resolve(portal: ['traffic' => ['enabled' => true]]);
		$this->assertSame(TrafficConfigResolver::DEFAULT_RETENTION_DAYS, $config['retentionDays']);
		$this->assertSame(
			TrafficConfigResolver::DEFAULT_SESSION_TIMEOUT_MINUTES,
			$config['sessionTimeoutMinutes']
		);

		// A nonsensical value falls back rather than disabling retention: zero
		// days would mean "delete immediately", and a negative number would
		// mean whatever the arithmetic happened to do.
		$broken = $this->resolver->resolve(
			portal: ['traffic' => ['enabled' => true, 'retentionDays' => -5, 'sessionTimeoutMinutes' => 0]]
		);
		$this->assertSame(TrafficConfigResolver::DEFAULT_RETENTION_DAYS, $broken['retentionDays']);
		$this->assertSame(
			TrafficConfigResolver::DEFAULT_SESSION_TIMEOUT_MINUTES,
			$broken['sessionTimeoutMinutes']
		);
	}//end testRetentionAndSessionWindowAlwaysHaveAValue()


	/**
	 * Region granularity defaults to the coarsest useful level.
	 *
	 * @return void
	 */
	public function testRegionGranularityDefaultsToCountry(): void {
		$this->assertSame(
			'country',
			$this->resolver->resolve(portal: ['traffic' => ['enabled' => true]])['regionGranularity']
		);
		$this->assertSame(
			'none',
			$this->resolver->resolve(
				portal: ['traffic' => ['enabled' => true, 'regionGranularity' => 'none']]
			)['regionGranularity']
		);
		// An unrecognised value must not become a finer one by accident.
		$this->assertSame(
			'country',
			$this->resolver->resolve(
				portal: ['traffic' => ['enabled' => true, 'regionGranularity' => 'street']]
			)['regionGranularity']
		);
	}//end testRegionGranularityDefaultsToCountry()


}//end class
