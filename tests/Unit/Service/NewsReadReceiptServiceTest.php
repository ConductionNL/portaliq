<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\NewsFeedReader;
use OCA\Portaliq\Service\NewsReadReceiptService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins idempotent read-receipt recording and the no-existence-oracle refusal
 * for an out-of-audience/non-existent item.
 *
 * @spec openspec/changes/news-and-newsletter-authoring/specs/portaliq-cms/spec.md#requirement-a-newsitem-is-authored-per-school-group-or-child-and-tracks-read-receipts
 */
class NewsReadReceiptServiceTest extends TestCase {

	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	private function container(object $objectService): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objectService) {
				if ($id === self::OS) {
					return $objectService;
				}

				throw new RuntimeException('no service: ' . $id);
			}
		);

		return $container;
	}//end container()

	public function testReturnsFalseForAnOutOfAudienceOrNonExistentItem(): void {
		$feedReader = $this->createMock(NewsFeedReader::class);
		$feedReader->method('readOwnItem')->willReturn(null);

		$service = new NewsReadReceiptService($this->createMock(ContainerInterface::class), $feedReader, $this->createMock(LoggerInterface::class));

		$this->assertFalse($service->markRead('guardian-anna-devries', 'n1'));
	}//end testReturnsFalseForAnOutOfAudienceOrNonExistentItem()

	public function testFirstReadRecordsExactlyOneReceipt(): void {
		$feedReader = $this->createMock(NewsFeedReader::class);
		$feedReader->method('readOwnItem')->willReturn(['id' => 'n1', 'title' => 'X', 'readReceipts' => []]);

		$objectService = new class {
			/**
			 * @var array<string,mixed>
			 */
			public array $saved = [];

			/**
			 * @param array<string,mixed> $object
			 */
			public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->saved = $object;
				return $object;
			}//end saveObject()
		};

		$service = new NewsReadReceiptService($this->container($objectService), $feedReader, $this->createMock(LoggerInterface::class));

		$this->assertTrue($service->markRead('guardian-anna-devries', 'n1'));
		$this->assertCount(1, $objectService->saved['readReceipts']);
		$this->assertSame('guardian-anna-devries', $objectService->saved['readReceipts'][0]['subjectRef']);
	}//end testFirstReadRecordsExactlyOneReceipt()

	public function testASecondReadDoesNotDuplicateTheReceipt(): void {
		$feedReader = $this->createMock(NewsFeedReader::class);
		$feedReader->method('readOwnItem')->willReturn([
			'id' => 'n1',
			'title' => 'X',
			'readReceipts' => [['subjectRef' => 'guardian-anna-devries', 'readAt' => '2026-09-01T00:00:00+00:00']],
		]);

		// saveObject must NEVER be called on the idempotent no-op path.
		$objectService = $this->getMockBuilder(\stdClass::class)->addMethods(['saveObject'])->getMock();
		$objectService->expects($this->never())->method('saveObject');

		$service = new NewsReadReceiptService($this->container($objectService), $feedReader, $this->createMock(LoggerInterface::class));

		$this->assertTrue($service->markRead('guardian-anna-devries', 'n1'));
	}//end testASecondReadDoesNotDuplicateTheReceipt()
}//end class
