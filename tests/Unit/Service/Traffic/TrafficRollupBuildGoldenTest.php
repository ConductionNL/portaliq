<?php

/**
 * TrafficRollupBuildGoldenTest
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

namespace OCA\Portaliq\Tests\Unit\Service\Traffic;

use OCA\Portaliq\Service\Traffic\TrafficExperimentDefinitions;
use OCA\Portaliq\Service\Traffic\TrafficOutcomeDefinitions;
use OCA\Portaliq\Service\Traffic\TrafficRollup;
use OCA\Portaliq\Service\Traffic\TrafficSessioniser;
use PHPUnit\Framework\TestCase;

/**
 * The whole daily record a roll-up builds, field by field and in field
 * order, for one day of mixed events under each set of portal options.
 *
 * @covers \OCA\Portaliq\Service\Traffic\TrafficRollup
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficSessioniser
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficJourneyStats
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficDimensionCounts
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficGoals
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficFunnels
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficFormStats
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficErrorStats
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficExperiments
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficHeatmapStats
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficExperimentDefinitions
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficOutcomeDefinitions
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficMatch
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficPagePath
 */
class TrafficRollupBuildGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../../Fixtures/Golden/traffic-rollup-build.json';

	/**
	 * Every built record matches the recorded one, in field order.
	 *
	 * @return void
	 */
	public function testEveryRecordMatchesTheGolden(): void {
		$sessions = (new TrafficSessioniser())->sessions(events: $this->events(), timeoutMinutes: 30);
		$actual   = [];
		foreach ($this->optionSets() as $name => $options) {
			$actual[$name] = (new TrafficRollup())->build(
				portal: 'open-tilburg',
				date: '2026-09-04',
				sessions: $sessions,
				aggregatedAt: '2026-09-05T00:15:00Z',
				options: $options
			);
		}

		$actual['no sessions'] = (new TrafficRollup())->build(portal: 'p', date: '2026-09-04', sessions: [], aggregatedAt: 'x');

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		foreach ($expected as $case => $record) {
			$this->assertSame(
				expected: $record,
				actual: json_decode((string)json_encode($actual[$case] ?? null, JSON_PRESERVE_ZERO_FRACTION), true),
				message: $case
			);
		}

		$this->assertSame(expected: array_keys($expected), actual: array_keys($actual));
	}//end testEveryRecordMatchesTheGolden()

	/**
	 * The portal option sets, by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function optionSets(): array {
		$outcomes = new TrafficOutcomeDefinitions();
		$goals    = $outcomes->goals(
			value: [['id' => 'contact', 'name' => 'Contact', 'type' => 'page_reached', 'match' => ['pathEquals' => '/contact'], 'value' => 10.0]]
		);

		return [
			'no options' => [],
			'persisted ids, linked accounts' => ['persistClientId' => true, 'accountLinking' => true],
			'outcomes, experiments, heatmaps' => [
				'goals' => $goals,
				'funnels' => [[
					'id' => 'f',
					'name' => 'F',
					'steps' => [['name' => 'Home', 'match' => ['pathEquals' => '/']], ['name' => 'Contact', 'match' => ['pathEquals' => '/contact']]],
				]],
				'customDimensions' => [['id' => 'audience', 'name' => 'Audience', 'scope' => 'session']],
				'experiments' => (new TrafficExperimentDefinitions())->definitions(
					value: [['id' => 'hero', 'status' => 'running', 'page' => '/', 'goal' => 'contact', 'variants' => [['id' => 'a'], ['id' => 'b']]]]
				),
				'heatmaps' => true,
			],
		];
	}//end optionSets()

	/**
	 * One day of mixed events.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function events(): array {
		$tag = ['experiment' => 'hero', 'variant' => 'b', 'cd_audience' => 'inwoner'];

		return [
			$this->event(
				at: '10:00:00',
				path: '/',
				visitor: 'h1',
				name: 'session_start',
				extra: ['clientId' => 'cid-1', 'userRef' => 'u-1', 'params' => ['visitorType' => 'returning']]
			),
			$this->event(at: '10:00:01', path: '/', visitor: 'h1', name: 'page_view', extra: [
				'referrerHost' => 'www.google.nl', 'channel' => 'organic search', 'deviceType' => 'mobile', 'browser' => 'Safari',
				'os' => 'iOS', 'language' => 'nl', 'region' => 'NL', 'campaign' => 'woo', 'source' => 'nieuwsbrief', 'medium' => 'email',
				'params' => $tag,
			]),
			$this->event(at: '10:00:05', path: '/zoeken', visitor: 'h1', name: 'search', extra: ['searchTerm' => 'parkeren', 'params' => ['results' => 0]]),
			$this->event(at: '10:00:06', path: '/zoeken', visitor: 'h1', name: 'search', extra: ['params' => ['search_term' => 'afval', 'results' => 4]]),
			$this->event(at: '10:00:07', path: '/zoeken', visitor: 'h1', name: 'search', extra: ['searchTerm' => 'bouwen']),
			$this->event(at: '10:00:08', path: '/', visitor: 'h1', name: 'heat_click', extra: ['params' => ['x' => 0.5, 'y' => 0.5]]),
			$this->event(at: '10:00:09', path: '/', visitor: 'h1', name: 'heat_scroll', extra: ['params' => ['depth' => 0.95]]),
			$this->event(at: '10:00:12', path: '/docs', visitor: 'h1', name: 'file_download', extra: ['fileName' => 'besluit.pdf']),
			$this->event(at: '10:00:15', path: '/docs', visitor: 'h1', name: 'outbound_click', extra: ['linkUrl' => 'https://rijksoverheid.nl/']),
			$this->event(at: '10:00:20', path: '/contact', visitor: 'h1', name: 'page_view', extra: ['params' => $tag]),
			$this->event(at: '10:00:25', path: '/contact', visitor: 'h1', name: 'form_start', extra: ['params' => ['formId' => 'contact']]),
			$this->event(
				at: '10:00:30',
				path: '/contact',
				visitor: 'h1',
				name: 'form_abandon',
				extra: ['params' => ['formId' => 'contact', 'lastFieldId' => 'email']]
			),
			$this->event(
				at: '10:00:40',
				path: '/contact',
				visitor: 'h1',
				name: 'js_error',
				extra: ['params' => ['message' => 'x is undefined', 'source' => 'app.js']]
			),
			$this->event(at: '11:00:00', path: '/', visitor: 'h2', name: 'session_start', extra: ['clientId' => 'cid-2', 'params' => ['first' => true]]),
			$this->event(
				at: '11:00:01',
				path: '/',
				visitor: 'h2',
				name: 'page_view',
				extra: ['deviceType' => 'desktop', 'channel' => 'direct', 'params' => ['cd_audience' => 'ondernemer']]
			),
			$this->event(at: '11:00:05', path: '/', visitor: 'h2', name: 'page_not_found', extra: ['params' => ['path' => '/oud']]),
			$this->event(at: '09:00:00', path: '/', visitor: 'contact-a', name: 'email_open', extra: ['pageLocation' => 'mailto:blast/1']),
			$this->event(at: '09:01:00', path: '/', visitor: 'contact-a', name: 'email_click', extra: ['pageLocation' => 'https://open-tilburg.nl/woo']),
		];
	}//end events()

	/**
	 * One event on 2026-09-04.
	 *
	 * @param string               $at      The UTC time of day.
	 * @param string               $path    The page path.
	 * @param string               $visitor The visitor hash.
	 * @param string               $name    The event name.
	 * @param array<string, mixed> $extra   More fields.
	 *
	 * @return array<string, mixed>
	 */
	private function event(string $at, string $path, string $visitor, string $name, array $extra = []): array {
		return $extra + [
			'name' => $name,
			'occurredAt' => '2026-09-04T' . $at . '.000Z',
			'sequence' => 0,
			'pagePath' => $path,
			'pageLocation' => 'https://open-tilburg.nl' . $path,
			'visitorHash' => $visitor,
		];
	}//end event()
}//end class
