<?php

/**
 * TrafficConfigResolverGoldenTest
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
 * The resolved traffic configuration, key order included, per portal traffic block.
 *
 * @covers \OCA\Portaliq\Service\TrafficConfigResolver
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficOutcomeDefinitions
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficSegments
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficReportDefinitions
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficExperimentDefinitions
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficMatch
 */
class TrafficConfigResolverGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../Fixtures/Golden/traffic-config-resolver.json';

	/**
	 * Every portal's configuration matches the recorded one.
	 *
	 * @return void
	 */
	public function testEveryPortalMatchesTheGolden(): void {
		$resolver = new TrafficConfigResolver();
		$actual   = [];
		foreach ($this->portals() as $name => $portal) {
			$actual[$name] = $resolver->resolve(portal: $portal);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertSame(expected: array_keys($expected), actual: array_keys($actual));
		foreach ($expected as $name => $case) {
			$this->assertSame(expected: $case, actual: json_decode((string)json_encode($actual[$name]), true), message: $name);
		}
	}//end testEveryPortalMatchesTheGolden()

	/**
	 * The portals by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function portals(): array {
		$full = [
			'enabled'               => true,
			'events'                => ['page_view', 'search', 'heat_click', 'heat_scroll', 'form_start', 'bogus', 'search'],
			'dimensions'            => ['pageReferrer', 'searchTerm', 'region', 'deviceType', 'userRef', 'nope'],
			'sensitive'             => ['persistClientId' => true, 'accountLinking' => true, 'heatmaps' => true, 'sessionRecording' => 'yes'],
			'regionGranularity'     => 'region',
			'sessionTimeoutMinutes' => '45',
			'retentionDays'         => 30,
			'consent'               => ['required' => true, 'preConsentEvents' => ['page_view', 'search', 'scroll', 'bogus']],
			'excludedPaths'         => ['melden', '/melden/', ' /privé ', '', 7, '/melden'],
			'goals'                 => [['id' => 'contact', 'type' => 'page_reached', 'match' => ['pathEquals' => '/contact'], 'value' => '2.5']],
			'funnels'               => [['id' => 'f', 'name' => 'F', 'steps' => [['name' => 'a', 'match' => ['pathPrefix' => '/a']]]]],
			'customDimensions'      => [['id' => 'audience', 'name' => 'Audience', 'scope' => 'session'], ['id' => 'bad id']],
			'segments'              => [
				['id' => 'converted', 'name' => 'Converted', 'conditions' => [['dimension' => 'goal:contact', 'operator' => 'is', 'value' => 'true']]],
				['id' => 'mobile', 'name' => 'Mobile', 'conditions' => [['dimension' => 'deviceType', 'operator' => 'is', 'value' => 'mobile']]],
			],
			'rollupOf'              => ['noord', 'zuid', 'noord'],
			'reports'               => [['id' => 'd', 'name' => 'Daily', 'cadence' => 'daily', 'recipients' => ['admin']]],
			'alerts'                => [
				[
					'id'         => 'busy',
					'name'       => 'Busy',
					'metric'     => 'pageViews',
					'comparison' => 'above',
					'threshold'  => 50,
					'period'     => 'day',
					'recipients' => ['admin'],
				],
				['id' => 'goal', 'metric' => 'goal:contact', 'comparison' => 'below', 'threshold' => 1, 'recipients' => ['admin']],
			],
			'experiments'           => [
				['id' => 'hero', 'status' => 'running', 'page' => '/', 'goal' => 'contact', 'variants' => [['id' => 'a'], ['id' => 'b', 'pageRoute' => '/b']]],
				['id' => 'draft', 'status' => 'draft', 'page' => '/', 'variants' => [['id' => 'a'], ['id' => 'b']]],
			],
		];

		return [
			'no traffic block'          => ['slug' => 'zuid'],
			'traffic not an array'      => ['slug' => 'zuid', 'traffic' => 'on'],
			'enabled only'              => ['slug' => 'zuid', 'traffic' => ['enabled' => true]],
			'enabled truthy'            => ['slug' => 'zuid', 'traffic' => ['enabled' => 1]],
			'external kind'             => ['slug' => 'zuid', 'kind' => 'external', 'traffic' => ['enabled' => true]],
			'other kind'                => ['slug' => 'zuid', 'kind' => 'app'],
			'full block'                => ['slug' => 'zuid', 'kind' => 'site', 'traffic' => $full],
			'full block no slug'        => ['traffic' => $full],
			'heat without switch'       => ['slug' => 'zuid', 'traffic' => ['sensitive' => [], 'events' => ['heat_click', 'page_view']] + $full],
			'empty event list'          => ['slug' => 'zuid', 'traffic' => ['events' => []]],
			'only unknown events'       => ['slug' => 'zuid', 'traffic' => ['events' => ['bogus']]],
			'events not a list'         => ['slug' => 'zuid', 'traffic' => ['events' => 'page_view']],
			'region off'                => ['slug' => 'zuid', 'traffic' => ['regionGranularity' => 'none', 'dimensions' => ['region', 'browser']]],
			'region unknown'            => ['slug' => 'zuid', 'traffic' => ['regionGranularity' => 'city', 'dimensions' => ['region']]],
			'country region'            => ['slug' => 'zuid', 'traffic' => ['regionGranularity' => 'country', 'dimensions' => ['region', 'userRef']]],
			'account linking off'       => ['slug' => 'zuid', 'traffic' => ['dimensions' => ['userRef', 'os'], 'sensitive' => ['accountLinking' => false]]],
			'sensitive not an array'    => ['slug' => 'zuid', 'traffic' => ['sensitive' => true]],
			'bad numbers'               => ['slug' => 'zuid', 'traffic' => ['sessionTimeoutMinutes' => 0, 'retentionDays' => '-5']],
			'float numbers'             => ['slug' => 'zuid', 'traffic' => ['sessionTimeoutMinutes' => 12.5, 'retentionDays' => '07']],
			'consent not an array'      => ['slug' => 'zuid', 'traffic' => ['consent' => 'required']],
			'consent truthy'            => ['slug' => 'zuid', 'traffic' => ['consent' => ['required' => 'yes', 'preConsentEvents' => ['session_start']]]],
			'pre-consent not enabled'   => [
				'slug'    => 'zuid',
				'traffic' => ['events' => ['page_view'], 'consent' => ['preConsentEvents' => ['scroll', 'page_view']]],
			],
			'excluded not a list'       => ['slug' => 'zuid', 'traffic' => ['excludedPaths' => '/melden']],
			'rollup includes itself'    => ['slug' => 'zuid', 'traffic' => ['rollupOf' => ['zuid', 'west']]],
		];
	}//end portals()
}//end class
