<?php

/**
 * Unit tests for TrafficLogParser.
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

use OCA\Portaliq\Service\Traffic\TrafficLogParser;
use PHPUnit\Framework\TestCase;

/**
 * Sample lines: a page view in both formats, and every reason to skip.
 */
class TrafficLogParserTest extends TestCase {

	private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';

	/**
	 * A combined line.
	 *
	 * @param string $request The request line.
	 * @param string $status  The status.
	 * @param string $agent   The user agent.
	 * @param string $ref     The referrer.
	 *
	 * @return string The line.
	 */
	private function line(string $request = 'GET /over-ons?utm_source=x HTTP/1.1', string $status = '200', string $agent = self::CHROME, string $ref = 'https://www.google.nl/'): string {
		return '203.0.113.9 - - [04/Sep/2026:10:15:36 +0200] "' . $request . '" ' . $status . ' 5123 "' . $ref . '" "' . $agent . '"';
	}//end line()


	/**
	 * A successful GET of a page is a page view, in UTC, query stripped.
	 *
	 * @return void
	 */
	public function testACombinedPageLineIsAPageView(): void {
		$view = (new TrafficLogParser())->parse(line: $this->line(), format: 'combined');

		$this->assertSame([
			'ip' => '203.0.113.9',
			'userAgent' => self::CHROME,
			'timestamp' => '2026-09-04T08:15:36.000Z',
			'path' => '/over-ons',
			'referrer' => 'https://www.google.nl/',
		], $view);
	}//end testACombinedPageLineIsAPageView()


	/**
	 * @return array<string, array{0: string}>
	 */
	public static function skippedProvider(): array {
		return [
			'an asset' => ['GET /js/site.js HTTP/1.1|200|' . self::CHROME],
			'an image with a query' => ['GET /img/logo.png?v=2 HTTP/1.1|200|' . self::CHROME],
			'a not found' => ['GET /oud HTTP/1.1|404|' . self::CHROME],
			'a redirect' => ['GET /oud HTTP/1.1|301|' . self::CHROME],
			'a post' => ['POST /api/traffic HTTP/1.1|204|' . self::CHROME],
			'a bot' => ['GET / HTTP/1.1|200|Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
			'garbage' => ['not a log line at all'],
		];
	}//end skippedProvider()


	/**
	 * @dataProvider skippedProvider
	 *
	 * @param string $spec `request|status|agent`, or a whole bad line.
	 *
	 * @return void
	 */
	public function testLinesThatAreNotPageViewsAreSkipped(string $spec): void {
		$parts = explode('|', $spec);
		$line = (count($parts) === 3) ? $this->line($parts[0], $parts[1], $parts[2]) : $spec;

		$this->assertNull((new TrafficLogParser())->parse(line: $line, format: 'combined'));
	}//end testLinesThatAreNotPageViewsAreSkipped()


	/**
	 * A JSON line under Nginx's names, a dash referrer read as none, and
	 * a directory path with a dot in a parent segment kept as a page.
	 *
	 * @return void
	 */
	public function testAJsonLineIsAPageView(): void {
		$line = json_encode([
			'remote_addr' => '198.51.100.4',
			'time_iso8601' => '2026-09-04T10:15:36+02:00',
			'request' => 'GET /v1.2/pagina HTTP/2.0',
			'status' => '200',
			'http_referer' => '-',
			'http_user_agent' => self::CHROME,
		]);

		$view = (new TrafficLogParser())->parse(line: (string)$line, format: 'json');

		$this->assertSame('198.51.100.4', $view['ip']);
		$this->assertSame('2026-09-04T08:15:36.000Z', $view['timestamp']);
		$this->assertSame('/v1.2/pagina', $view['path']);
		$this->assertSame('', $view['referrer']);
	}//end testAJsonLineIsAPageView()


	/**
	 * A JSON line under a shipper's names, with method and path apart.
	 *
	 * @return void
	 */
	public function testAJsonLineWithSeparateMethodAndPath(): void {
		$line = json_encode([
			'ip' => '198.51.100.4',
			'timestamp' => '2026-09-04T08:00:00Z',
			'method' => 'get',
			'path' => 'https://example.org/contact?x=1',
			'status' => 200,
			'ua' => self::CHROME,
		]);

		$view = (new TrafficLogParser())->parse(line: (string)$line, format: 'json');

		$this->assertSame('/contact', $view['path']);
		$this->assertSame('2026-09-04T08:00:00.000Z', $view['timestamp']);
		$this->assertNull((new TrafficLogParser())->parse(line: '{"bad json', format: 'json'));
	}//end testAJsonLineWithSeparateMethodAndPath()
}//end class
