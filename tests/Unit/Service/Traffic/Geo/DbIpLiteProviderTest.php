<?php

/**
 * Unit tests for DbIpLiteProvider.
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

namespace OCA\Portaliq\Tests\Unit\Service\Traffic\Geo;

use OCA\Portaliq\Service\Traffic\Geo\DbIpLiteProvider;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The month's URL, the fallback to last month, and the decompression.
 */
class DbIpLiteProviderTest extends TestCase {

	/**
	 * The URLs the fake client saw.
	 *
	 * @var array<int, string>
	 */
	private array $requested = [];

	/**
	 * Temporary files to remove.
	 *
	 * @var array<int, string>
	 */
	private array $files = [];


	/**
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->files as $file) {
			@unlink($file);
		}

		parent::tearDown();
	}//end tearDown()


	/**
	 * A provider over a fake client that answers per URL.
	 *
	 * @param array<string, array{status: int, body?: string}> $answers URL => what to answer.
	 *
	 * @return DbIpLiteProvider The provider.
	 */
	private function provider(array $answers): DbIpLiteProvider {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url, array $options) use ($answers): IResponse {
				$this->requested[] = $url;
				$answer = $answers[$url] ?? ['status' => 404];
				if (isset($answer['body']) === true) {
					file_put_contents($options['sink'], $answer['body']);
				}

				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn($answer['status']);

				return $response;
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(
			function (): string {
				$path = (string)tempnam(sys_get_temp_dir(), 'dbip');
				$this->files[] = $path;

				return $path;
			}
		);

		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturn((int)gmmktime(12, 0, 0, 9, 4, 2026));

		return new DbIpLiteProvider($clients, $temp, $clock);
	}//end provider()


	/**
	 * This month first, last month second, both on the documented URL.
	 *
	 * @return void
	 */
	public function testTheUrlsAreThisMonthThenLastMonth(): void {
		$this->assertSame(
			[
				'https://download.db-ip.com/free/dbip-city-lite-2026-09.mmdb.gz',
				'https://download.db-ip.com/free/dbip-city-lite-2026-08.mmdb.gz',
			],
			$this->provider([])->urls()
		);
		$this->assertSame('dbip', $this->provider([])->providerId());
		$this->assertStringContainsString('CC BY 4.0', $this->provider([])->attribution());
		$this->assertStringContainsString('db-ip.com', $this->provider([])->attribution());
	}//end testTheUrlsAreThisMonthThenLastMonth()


	/**
	 * A 404 for this month falls back to last month; the gzip is
	 * decompressed to the target; the URL that answered is reported.
	 *
	 * @return void
	 */
	public function testThisMonthMissingFallsBackToLastMonth(): void {
		$provider = $this->provider([
			'https://download.db-ip.com/free/dbip-city-lite-2026-08.mmdb.gz' => ['status' => 200, 'body' => (string)gzencode('august database')],
		]);
		$target = (string)tempnam(sys_get_temp_dir(), 'out');
		$this->files[] = $target;

		$source = $provider->fetch(targetPath: $target);

		$this->assertSame('https://download.db-ip.com/free/dbip-city-lite-2026-08.mmdb.gz', $source);
		$this->assertSame('august database', file_get_contents($target));
		$this->assertCount(2, $this->requested);
	}//end testThisMonthMissingFallsBackToLastMonth()


	/**
	 * Neither month answering is a refusal that names both.
	 *
	 * @return void
	 */
	public function testNoMonthAnsweringIsARefusal(): void {
		$target = (string)tempnam(sys_get_temp_dir(), 'out');
		$this->files[] = $target;

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('2026-08');
		$this->provider([])->fetch(targetPath: $target);
	}//end testNoMonthAnsweringIsARefusal()
}//end class
