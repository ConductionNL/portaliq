<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\Cms\PlainPublicationReader;
use OCA\Portaliq\Service\InstanceLoopback;
use OCA\Portaliq\Service\InternalBaseUrl;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The plain page reads publications on the server, anonymously, through the
 * real InstanceLoopback (site-honest-without-javascript REQ-SHJ-003..005).
 * Only the HTTP client underneath is a double.
 *
 * @covers \OCA\Portaliq\Service\Cms\PlainPublicationReader
 *
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-publications-can-be-searched-without-javascript-req-shj-003
 */
class PlainPublicationReaderTest extends TestCase {

	private const ENDPOINT = '/index.php/apps/opencatalogi/api/federation/publications';

	/** @var list<array{url: string, options: array<string, mixed>}> */
	private array $calls = [];

	/**
	 * @return void
	 */
	public function testTheCallCarriesNoCredentials(): void {
		$reader = $this->reader(answers: [self::ENDPOINT => [200, ['results' => [], 'total' => 0]]]);

		$reader->search(endpoint: self::ENDPOINT, query: 'afval', page: 2, pageSize: 10);

		$this->assertCount(1, $this->calls);
		$this->assertSame('https://cloud.example'.self::ENDPOINT.'?_limit=10&_page=2&_search=afval', $this->calls[0]['url']);
		$options = $this->calls[0]['options'];
		$this->assertSame(['Accept' => 'application/json'], $options['headers']);
		$this->assertArrayNotHasKey('cookies', $options);
		$this->assertArrayNotHasKey('auth', $options);
		$this->assertSame(5, $options['timeout']);
	}//end testTheCallCarriesNoCredentials()

	/**
	 * @return void
	 */
	public function testAForeignEndpointIsNeverCalled(): void {
		$reader = $this->reader(answers: []);

		foreach (['https://elders.example/api/publications', '//elders.example/x', '/index.php/../x', 'relative/path', '/x?y=1'] as $endpoint) {
			$this->assertSame('unavailable', $reader->search(endpoint: $endpoint, query: '', page: 1, pageSize: 10)['state'], $endpoint);
			$this->assertSame('unavailable', $reader->publication(endpoint: $endpoint, id: 'p1')['state'], $endpoint);
		}

		$this->assertSame([], $this->calls);
	}//end testAForeignEndpointIsNeverCalled()

	/**
	 * @return void
	 */
	public function testAnAbsentAppIsUnavailableNotEmpty(): void {
		$reader = $this->reader(answers: [self::ENDPOINT => [200, ['results' => [], 'total' => 0]]], enabled: false);

		$this->assertSame(['state' => 'unavailable', 'total' => 0, 'results' => []], $reader->search(endpoint: self::ENDPOINT, query: 'afval', page: 1, pageSize: 10));
		$this->assertSame([], $this->calls);
	}//end testAnAbsentAppIsUnavailableNotEmpty()

	/**
	 * @return void
	 */
	public function testAServerErrorIsUnavailable(): void {
		$reader = $this->reader(answers: [self::ENDPOINT => [500, ['error' => 'x']], self::ENDPOINT.'/p1' => [502, []]]);

		$this->assertSame('unavailable', $reader->search(endpoint: self::ENDPOINT, query: '', page: 1, pageSize: 10)['state']);
		$this->assertSame('unavailable', $reader->publication(endpoint: self::ENDPOINT, id: 'p1')['state']);

		$throwing = $this->reader(answers: [], throws: true);
		$this->assertSame('unavailable', $throwing->search(endpoint: self::ENDPOINT, query: '', page: 1, pageSize: 10)['state']);
	}//end testAServerErrorIsUnavailable()

	/**
	 * @return void
	 */
	public function testMissingAndForbiddenAreBothNotFound(): void {
		$reader = $this->reader(
			answers: [
				self::ENDPOINT.'/missing'  => [404, ['error' => 'not found']],
				self::ENDPOINT.'/withheld' => [403, ['error' => 'forbidden']],
				self::ENDPOINT.'/other'    => [200, ['id' => 'someone-else', 'name' => 'Ander']],
			]
		);

		$this->assertSame(['state' => 'not-found'], $reader->publication(endpoint: self::ENDPOINT, id: 'missing'));
		$this->assertSame(['state' => 'not-found'], $reader->publication(endpoint: self::ENDPOINT, id: 'withheld'));
		$this->assertSame(['state' => 'not-found'], $reader->publication(endpoint: self::ENDPOINT, id: 'other'));
		$this->assertSame(['state' => 'not-found'], $reader->publication(endpoint: self::ENDPOINT, id: '../x'));
	}//end testMissingAndForbiddenAreBothNotFound()

	/**
	 * @return void
	 */
	public function testFieldsAreReadTheWayTheBlockReadsThem(): void {
		$reader = $this->reader(
			answers: [
				self::ENDPOINT                    => [
					200,
					[
						'total'   => 2,
						'results' => [
							['name' => 'Woo-besluit afvalinzameling 2026', 'publicationDate' => '2026-03-01', '@self' => ['id' => 'p1', 'summary' => 'Het besluit.']],
							['id' => 'p2', 'description' => 'Zonder self', '@self' => ['title' => 'Titel uit self', 'published' => '2026-01-02']],
						],
					],
				],
				self::ENDPOINT.'/p1'              => [200, ['id' => 'p1', 'name' => 'Woo-besluit afvalinzameling 2026', 'themes' => ['t1', ['id' => 't2']]]],
				self::ENDPOINT.'/p1/attachments'  => [
					200,
					[
						'results' => [
							['title' => 'besluit.pdf', 'size' => 120000, 'downloadUrl' => '/index.php/apps/openregister/download/1'],
							['name' => 'bijlage', 'extension' => 'odt', 'accessUrl' => 'https://cloud.example/a', 'size' => 10],
							['title' => 'kwaad', 'downloadUrl' => 'javascript:alert(1)'],
						],
					],
				],
				PlainPublicationReader::THEMES_ENDPOINT.'/t1' => [200, ['title' => 'Afval']],
				PlainPublicationReader::THEMES_ENDPOINT.'/t2' => [404, []],
			]
		);

		$search = $reader->search(endpoint: self::ENDPOINT, query: '', page: 1, pageSize: 10);
		$this->assertSame(
			[
				'state'   => 'ok',
				'total'   => 2,
				'results' => [
					['id' => 'p1', 'title' => 'Woo-besluit afvalinzameling 2026', 'date' => '2026-03-01', 'summary' => 'Het besluit.'],
					['id' => 'p2', 'title' => 'Titel uit self', 'date' => '2026-01-02', 'summary' => 'Zonder self'],
				],
			],
			$search
		);

		$detail = $reader->publication(endpoint: self::ENDPOINT, id: 'p1');
		$this->assertSame('ok', $detail['state']);
		$this->assertSame('Woo-besluit afvalinzameling 2026', $detail['publication']['name']);
		$this->assertSame(['Afval'], $detail['themes']);
		$this->assertSame(
			[
				['name' => 'besluit.pdf', 'type' => 'PDF', 'size' => 120000, 'href' => '/index.php/apps/openregister/download/1'],
				['name' => 'bijlage', 'type' => 'ODT', 'size' => 10, 'href' => 'https://cloud.example/a'],
				['name' => 'kwaad', 'type' => '', 'size' => 0, 'href' => ''],
			],
			$detail['documents']
		);
	}//end testFieldsAreReadTheWayTheBlockReadsThem()

	/**
	 * A reader over the real loopback; the client answers by path.
	 *
	 * @param array<string, array{0: int, 1: mixed}> $answers Status and body by path (query ignored).
	 * @param bool                                   $enabled Whether opencatalogi is on.
	 * @param bool                                   $throws  Whether the transport fails.
	 *
	 * @return PlainPublicationReader
	 */
	private function reader(array $answers, bool $enabled=true, bool $throws=false): PlainPublicationReader {
		$this->calls = [];
		$client      = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url, array $options) use ($answers, $throws): IResponse {
				$this->calls[] = ['url' => $url, 'options' => $options];
				if ($throws === true) {
					throw new \RuntimeException('timeout', 28);
				}

				$path              = (string)parse_url($url, PHP_URL_PATH);
				[$status, $body]   = ($answers[$path] ?? [404, []]);
				$response          = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn($status);
				$response->method('getBody')->willReturn(json_encode($body));
				return $response;
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://cloud.example'.$path);
		$base = $this->createMock(InternalBaseUrl::class);
		$base->method('configured')->willReturn('');

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(static fn (string $app): bool => $app === 'opencatalogi' && $enabled);

		return new PlainPublicationReader(new InstanceLoopback($clients, $urls, $base, new NullLogger()), $apps, new NullLogger());
	}//end reader()
}//end class
