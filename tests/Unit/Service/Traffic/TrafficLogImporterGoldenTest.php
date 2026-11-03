<?php

/**
 * TrafficLogImporterGoldenTest
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

use OCA\Portaliq\Service\PublishedPortals;
use OCA\Portaliq\Service\Traffic\TrafficLogImporter;
use OCA\Portaliq\Service\Traffic\TrafficLogParser;
use OCA\Portaliq\Service\TrafficIngestService;
use PHPUnit\Framework\TestCase;

/**
 * Every ingest call (portal, events, visitor context) and the outcome of a log import, per log shape.
 *
 * @covers \OCA\Portaliq\Service\Traffic\TrafficLogImporter
 * @covers \OCA\Portaliq\Service\Traffic\TrafficLogImportRun
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficLogParser
 */
class TrafficLogImporterGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../../Fixtures/Golden/traffic-log-importer.json';

	private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';

	private const FIREFOX = 'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0';

	/**
	 * Every scenario's trace matches the recorded one.
	 *
	 * @return void
	 */
	public function testEveryScenarioMatchesTheGolden(): void {
		$actual = [];
		foreach ($this->scenarios() as $name => $scenario) {
			$actual[$name] = $this->trace(scenario: $scenario);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertSame(expected: array_keys($expected), actual: array_keys($actual));
		foreach ($expected as $name => $case) {
			$this->assertSame(expected: $case, actual: $actual[$name], message: $name);
		}
	}//end testEveryScenarioMatchesTheGolden()

	/**
	 * Import one log over a recording ingest double.
	 *
	 * @param array<string, mixed> $scenario The log lines, format, host, slug and ingest answer.
	 *
	 * @return array<string, mixed>
	 */
	private function trace(array $scenario): array {
		$calls   = [];
		$portals = $this->createMock(originalClassName: PublishedPortals::class);
		$portals->method('allPublishedPortals')->willReturn([
			['slug' => 'noord', 'status' => 'published'],
			['status' => 'published'],
			['slug' => 'zuid', 'status' => 'published', 'traffic' => ['enabled' => true]],
			['slug' => 'zuid', 'status' => 'published', 'second' => true],
		]);

		$ingest = $this->createMock(originalClassName: TrafficIngestService::class);
		$ingest->method('ingestForPortal')->willReturnCallback(
			static function (array $portal, array $events, array $context) use (&$calls, $scenario): array {
				$calls[] = ['portal' => $portal, 'events' => $events, 'context' => $context];
				return ($scenario['answer'] ?? ['accepted' => count($events), 'refused' => []]);
			}
		);

		$stream = fopen('php://memory', 'r+');
		fwrite($stream, implode('', $scenario['lines'] ?? []));
		rewind($stream);

		$outcome = (new TrafficLogImporter($portals, new TrafficLogParser(), $ingest))->import(
			slug: ($scenario['slug'] ?? 'zuid'),
			stream: $stream,
			format: ($scenario['format'] ?? 'combined'),
			host: ($scenario['host'] ?? 'https://zuid.example/')
		);

		return ['outcome' => $outcome, 'calls' => $calls];
	}//end trace()

	/**
	 * A combined-format line.
	 *
	 * @param string $ip    The address.
	 * @param string $path  The path.
	 * @param int    $second The second within the minute.
	 * @param string $agent The user agent.
	 *
	 * @return string
	 */
	private static function line(string $ip, string $path, int $second = 0, string $agent = self::CHROME): string {
		return $ip . ' - - [04/Sep/2026:10:15:' . sprintf('%02d', $second) . ' +0000] "GET ' . $path . ' HTTP/1.1" 200 512 "https://www.google.com/" "' . $agent . '"' . "\n";
	}//end line()

	/**
	 * The scenarios by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function scenarios(): array {
		$mixed = [
			self::line('203.0.113.9', '/'),
			self::line('203.0.113.9', '/over-ons', 1),
			self::line('203.0.113.9', '/'),
			self::line('203.0.113.9', '/', 0, self::FIREFOX),
			self::line('198.51.100.4', '/contact'),
			self::line('198.51.100.4', '/js/site.js'),
			"garbage\n",
			self::line('2001:db8::1', '/nieuws?x=1', 2),
		];
		$many = [];
		for ($i = 0; $i < 53; $i++) {
			$many[] = self::line('203.0.113.7', '/p' . $i, ($i % 60));
		}

		$many[] = self::line('198.51.100.4', '/after');
		$exact = array_slice($many, 0, 50);
		$json  = [
			json_encode(['time' => '2026-09-04T10:15:36+00:00', 'remote_addr' => '203.0.113.9', 'request' => 'GET /a HTTP/1.1', 'status' => 200, 'http_user_agent' => self::CHROME, 'http_referer' => '-']) . "\n",
			"{not json\n",
		];

		return [
			'unknown portal'            => ['slug' => 'west', 'lines' => $mixed],
			'empty slug'                => ['slug' => '', 'lines' => $mixed],
			'empty log'                 => [],
			'mixed log'                 => ['lines' => $mixed],
			'refusals summed'           => ['lines' => $mixed, 'answer' => ['accepted' => '1', 'refused' => ['event-not-enabled' => '2', 'bot' => 1]]],
			'host without slash'        => ['lines' => $mixed, 'host' => 'https://zuid.example'],
			'host with slashes'         => ['lines' => [self::line('203.0.113.9', '/')], 'host' => 'https://zuid.example///'],
			'batch overflows'           => ['lines' => $many],
			'batch exactly full'        => ['lines' => $exact],
			'json format'               => ['lines' => $json, 'format' => 'json'],
			'combined line as json'     => ['lines' => [self::line('203.0.113.9', '/')], 'format' => 'json'],
			'first matching portal'     => ['slug' => 'noord', 'lines' => [self::line('203.0.113.9', '/')]],
			'same second other path'    => ['lines' => [self::line('203.0.113.9', '/a', 5), self::line('203.0.113.9', '/b', 5), self::line('203.0.113.9', '/a', 6), self::line('203.0.113.9', '/a', 5)]],
			'odd refusal counts'        => ['lines' => [self::line('203.0.113.9', '/')], 'answer' => ['accepted' => 1, 'refused' => ['bot' => 'many', 'rate' => '3']]],
			'no trailing newline'       => ['lines' => [rtrim(self::line('203.0.113.9', '/x'))]],
		];
	}//end scenarios()
}//end class
