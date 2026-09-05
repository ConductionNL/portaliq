<?php

/**
 * Unit tests for TrafficLogImporter.
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

use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\Traffic\TrafficLogImporter;
use OCA\Portaliq\Service\Traffic\TrafficLogParser;
use OCA\Portaliq\Service\TrafficIngestService;
use PHPUnit\Framework\TestCase;

/**
 * The importer batches per visitor, skips what the parser skips, drops a
 * repeated line, and hands the address and agent as context with the
 * old-timestamp allowance.
 */
class TrafficLogImporterTest extends TestCase {

	private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';

	/**
	 * What the ingest double received: `[events, context]` per call.
	 *
	 * @var array<int, array{0: array<int, array<string, mixed>>, 1: array<string, mixed>}>
	 */
	private array $calls = [];

	/**
	 * A combined line.
	 *
	 * @param string $ip   The address.
	 * @param string $path The path.
	 * @param string $time The time.
	 *
	 * @return string The line.
	 */
	private function line(string $ip, string $path, string $time = '04/Sep/2026:10:15:36 +0000'): string {
		return $ip . ' - - [' . $time . '] "GET ' . $path . ' HTTP/1.1" 200 512 "-" "' . self::CHROME . '"' . "\n";
	}//end line()


	/**
	 * The importer over one portal and a capturing ingest double.
	 *
	 * @return TrafficLogImporter The importer.
	 */
	private function importer(): TrafficLogImporter {
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('allPublishedPortals')->willReturn([['slug' => 'open-tilburg', 'status' => 'published', 'traffic' => ['enabled' => true]]]);

		$ingest = $this->createMock(TrafficIngestService::class);
		$ingest->method('ingestForPortal')->willReturnCallback(
			function (array $portal, array $events, array $context): array {
				$this->calls[] = [$events, $context];

				return ['accepted' => count($events) - 1, 'refused' => ['event-not-enabled' => 1]];
			}
		);

		return new TrafficLogImporter($portals, new TrafficLogParser(), $ingest);
	}//end importer()


	/**
	 * @return void
	 */
	public function testLinesBecomeViewsPerVisitorWithDuplicatesDropped(): void {
		$stream = fopen('php://memory', 'r+');
		fwrite($stream, $this->line('203.0.113.9', '/'));
		fwrite($stream, $this->line('203.0.113.9', '/over-ons', '04/Sep/2026:10:16:00 +0000'));
		fwrite($stream, $this->line('203.0.113.9', '/'));
		fwrite($stream, $this->line('198.51.100.4', '/contact'));
		fwrite($stream, $this->line('198.51.100.4', '/js/site.js'));
		fwrite($stream, "garbage\n");
		rewind($stream);

		$outcome = $this->importer()->import(slug: 'open-tilburg', stream: $stream, format: 'combined', host: 'https://example.org/');

		$this->assertSame(['lines' => 6, 'views' => 3, 'skipped' => 2, 'duplicates' => 1, 'accepted' => 1, 'refused' => ['event-not-enabled' => 2]], $outcome);
		$this->assertCount(2, $this->calls, 'one ingest call per visitor');
		[$events, $context] = $this->calls[0];
		$this->assertSame('203.0.113.9', $context['ip']);
		$this->assertSame(self::CHROME, $context['userAgent']);
		$this->assertTrue($context['allowOld']);
		$this->assertFalse($context['serverSide']);
		$this->assertSame(['https://example.org/', 'https://example.org/over-ons'], array_column($events, 'pageLocation'));
		$this->assertSame([0, 1], array_column($events, 'sequence'));
		$this->assertSame('2026-09-04T10:15:36.000Z', $events[0]['timestamp']);
		$this->assertSame('page_view', $events[0]['name']);
		$this->assertSame('/contact', parse_url($this->calls[1][0][0]['pageLocation'], PHP_URL_PATH));
	}//end testLinesBecomeViewsPerVisitorWithDuplicatesDropped()


	/**
	 * @return void
	 */
	public function testAnUnknownPortalImportsNothing(): void {
		$stream = fopen('php://memory', 'r+');
		fwrite($stream, $this->line('203.0.113.9', '/'));
		rewind($stream);

		$this->assertNull($this->importer()->import(slug: 'nope', stream: $stream, format: 'combined', host: 'https://x'));
		$this->assertSame([], $this->calls);
	}//end testAnUnknownPortalImportsNothing()
}//end class
