<?php

/**
 * PlainPublicationReader: the anonymous, same-instance read of opencatalogi's
 * public publication endpoint for the plain site page
 * (site-honest-without-javascript REQ-SHJ-003, REQ-SHJ-004, REQ-SHJ-005).
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Cms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/site-honest-without-javascript/tasks.md#3.1
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\Cms\PlainPublicationReader;
use OCA\Portaliq\Service\Cms\PlainPublicationRows;
use OCA\Portaliq\Service\InstanceLoopback;
use OCP\App\IAppManager;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(PlainPublicationReader::class)]
#[CoversClass(PlainPublicationRows::class)]
class PlainPublicationReaderTest extends TestCase {

	/**
	 * The endpoint the blocks read by default.
	 *
	 * @var string
	 */
	private const ENDPOINT = '/index.php/apps/opencatalogi/api/federation/publications';

	/**
	 * The calls the loopback double received: method, path and options.
	 *
	 * @var list<array{method: string, path: string, options: array}>
	 */
	private array $calls = [];

	/**
	 * The search goes out with no cookie, no Authorization header, a five
	 * second timeout and the blocks' own parameters.
	 *
	 * @return void
	 */
	public function testTheCallCarriesNoCredentials(): void {
		$reader = $this->reader(answers: [['status' => 200, 'body' => ['results' => [], 'total' => 0]]]);

		$reader->search(self::ENDPOINT, 'afval container', 2, 10);

		$this->assertCount(1, $this->calls);
		$call = $this->calls[0];
		$this->assertSame('GET', $call['method']);
		$this->assertSame(self::ENDPOINT . '?_limit=10&_page=2&_search=afval%20container', $call['path']);
		$this->assertSame(['Accept' => 'application/json'], $call['options']['headers']);
		$this->assertArrayNotHasKey('cookies', $call['options']);
		$this->assertArrayNotHasKey('auth', $call['options']);
		$this->assertSame(5, $call['options']['timeout']);
		$this->assertSame(5, $call['options']['connect_timeout']);
		$this->assertFalse($call['options']['http_errors']);
		foreach (array_keys($call['options']['headers']) as $header) {
			$this->assertNotContains(strtolower($header), ['cookie', 'authorization']);
		}
	}//end testTheCallCarriesNoCredentials()

	/**
	 * An absolute URL, a protocol-relative one and a path that climbs out are
	 * never called: they read as unavailable.
	 *
	 * @return void
	 */
	public function testAForeignEndpointIsNeverCalled(): void {
		$reader = $this->reader(answers: []);

		foreach (['https://elders.example/api/publications', '//elders.example/api', '/index.php/../../etc', 'javascript:alert(1)', ''] as $endpoint) {
			$this->assertFalse($reader->isLocal($endpoint), $endpoint);
			$this->assertSame(['state' => 'unavailable', 'total' => 0, 'results' => []], $reader->search($endpoint, 'afval', 1, 10));
			$this->assertSame(['state' => 'unavailable'], $reader->publication($endpoint, 'p1'));
		}

		$this->assertSame([], $this->calls);
		$this->assertTrue($reader->isLocal(self::ENDPOINT));
	}//end testAForeignEndpointIsNeverCalled()

	/**
	 * Without opencatalogi nothing is called and the answer is unavailable,
	 * never an empty result with a total of zero.
	 *
	 * @return void
	 */
	public function testAnAbsentAppIsUnavailableNotEmpty(): void {
		$reader = $this->reader(answers: [], catalogue: false);

		$this->assertSame('unavailable', $reader->search(self::ENDPOINT, 'afval', 1, 10)['state']);
		$this->assertSame(['state' => 'unavailable'], $reader->publication(self::ENDPOINT, 'p1'));
		$this->assertSame([], $this->calls);
	}//end testAnAbsentAppIsUnavailableNotEmpty()

	/**
	 * A 500, a transport error and a body that is not JSON are unavailable.
	 *
	 * @return void
	 */
	public function testAServerErrorIsUnavailable(): void {
		$reader = $this->reader(answers: [
			['status' => 500, 'body' => ['error' => 'Failed']],
			new RuntimeException('cURL error 28: timed out'),
			['status' => 200, 'raw' => '<html>'],
			['status' => 503, 'body' => []],
		]);

		$this->assertSame('unavailable', $reader->search(self::ENDPOINT, 'a', 1, 10)['state']);
		$this->assertSame('unavailable', $reader->search(self::ENDPOINT, 'a', 1, 10)['state']);
		$this->assertSame('unavailable', $reader->search(self::ENDPOINT, 'a', 1, 10)['state']);
		$this->assertSame(['state' => 'unavailable'], $reader->publication(self::ENDPOINT, 'p1'));
	}//end testAServerErrorIsUnavailable()

	/**
	 * A 404, a 403 and an answer that is another publication all read as
	 * not-found, with nothing that tells them apart.
	 *
	 * @return void
	 */
	public function testMissingAndForbiddenAreBothNotFound(): void {
		$reader = $this->reader(answers: [
			['status' => 404, 'body' => ['error' => 'Not found']],
			['status' => 403, 'body' => ['error' => 'Forbidden']],
			['status' => 200, 'body' => ['id' => 'other', 'title' => 'Iets anders']],
		]);

		$missing = $reader->publication(self::ENDPOINT, 'p1');
		$forbidden = $reader->publication(self::ENDPOINT, 'p1');
		$other = $reader->publication(self::ENDPOINT, 'p1');

		$this->assertSame(['state' => 'not-found'], $missing);
		$this->assertSame($missing, $forbidden);
		$this->assertSame($missing, $other);
		$this->assertSame(self::ENDPOINT . '/p1', $this->calls[0]['path']);
	}//end testMissingAndForbiddenAreBothNotFound()

	/**
	 * Rows, publication and files are read with the blocks' field names.
	 *
	 * @return void
	 */
	public function testFieldsAreReadTheWayTheBlockReadsThem(): void {
		$reader = $this->reader(answers: [
			['status' => 200, 'body' => ['total' => 25, 'results' => [
				['name' => 'Woo-besluit afvalinzameling 2026', 'publicationDate' => '2026-03-01', '@self' => ['id' => 'p1', 'summary' => 'Over de inzameling.']],
				['@self' => ['id' => 'p2', 'name' => 'Uit @self', 'published' => '2026-01-02T10:00:00+00:00'], 'description' => 'Een beschrijving'],
				'not a row',
			]]],
			['status' => 200, 'body' => ['id' => 'p 1', 'title' => 'Woo-besluit', '@self' => ['id' => 'p 1']]],
			['status' => 200, 'body' => ['results' => [
				['title' => 'besluit.pdf', 'size' => 2400000, 'downloadUrl' => '/index.php/apps/opencatalogi/api/files/1/download'],
				['name' => 'bijlage', 'extension' => 'docx', 'size' => '1200', 'accessUrl' => 'https://example.nl/bijlage'],
				['title' => 'kwaad', 'downloadUrl' => 'javascript:alert(1)'],
			]]],
		]);

		$found = $reader->search(self::ENDPOINT, '', 1, 10);
		$this->assertSame('ok', $found['state']);
		$this->assertSame(25, $found['total']);
		$this->assertSame([
			['id' => 'p1', 'title' => 'Woo-besluit afvalinzameling 2026', 'date' => '2026-03-01', 'summary' => 'Over de inzameling.'],
			['id' => 'p2', 'title' => 'Uit @self', 'date' => '2026-01-02T10:00:00+00:00', 'summary' => 'Een beschrijving'],
		], $found['results']);
		$this->assertSame(self::ENDPOINT . '?_limit=10&_page=1', $this->calls[0]['path']);

		$detail = $reader->publication(self::ENDPOINT, 'p 1');
		$this->assertSame('ok', $detail['state']);
		$this->assertSame('Woo-besluit', $detail['publication']['title']);
		$this->assertSame(self::ENDPOINT . '/p%201', $this->calls[1]['path']);
		$this->assertSame(self::ENDPOINT . '/p%201/attachments', $this->calls[2]['path']);
		$this->assertSame([
			['name' => 'besluit.pdf', 'type' => 'PDF', 'size' => 2400000, 'href' => '/index.php/apps/opencatalogi/api/files/1/download'],
			['name' => 'bijlage', 'type' => 'DOCX', 'size' => 1200, 'href' => 'https://example.nl/bijlage'],
			['name' => 'kwaad', 'type' => '', 'size' => 0, 'href' => ''],
		], $detail['documents']);
	}//end testFieldsAreReadTheWayTheBlockReadsThem()

	/**
	 * A theme's name comes from its title, else its name; a failed read is ''.
	 *
	 * @return void
	 */
	public function testAThemeIsReadByName(): void {
		$reader = $this->reader(answers: [
			['status' => 200, 'body' => ['title' => ' Afval ']],
			['status' => 200, 'body' => ['name' => 'Wonen']],
			['status' => 404, 'body' => []],
		]);

		$this->assertSame('Afval', $reader->themeName('/index.php/apps/opencatalogi/api/themes', 't1'));
		$this->assertSame('Wonen', $reader->themeName('/index.php/apps/opencatalogi/api/themes', 't2'));
		$this->assertSame('', $reader->themeName('/index.php/apps/opencatalogi/api/themes', 't3'));
		$this->assertSame('/index.php/apps/opencatalogi/api/themes/t1', $this->calls[0]['path']);
	}//end testAThemeIsReadByName()

	/**
	 * A reader over a loopback double that answers in order and records each
	 * call, against the real InstanceLoopback signature.
	 *
	 * @param list<array{status: int, body?: mixed, raw?: string}|\Throwable> $answers   The answers, in order.
	 * @param bool                                                            $catalogue Whether opencatalogi is enabled.
	 *
	 * @return PlainPublicationReader
	 */
	private function reader(array $answers, bool $catalogue = true): PlainPublicationReader {
		$loopback = $this->createMock(InstanceLoopback::class);
		$loopback->method('request')->willReturnCallback(
			function (string $method, string $path, array $options = []) use (&$answers): IResponse {
				$this->calls[] = ['method' => $method, 'path' => $path, 'options' => $options];
				$answer = array_shift($answers);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				$this->assertIsArray($answer, 'an unexpected call to ' . $path);
				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn($answer['status']);
				$response->method('getBody')->willReturn($answer['raw'] ?? json_encode($answer['body'] ?? null));

				return $response;
			}
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(static fn (string $app): bool => ($app === 'opencatalogi' && $catalogue));

		return new PlainPublicationReader($loopback, $apps);
	}//end reader()
}//end class
