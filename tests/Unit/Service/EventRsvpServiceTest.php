<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\EventFeedReader;
use OCA\Portaliq\Service\EventRsvpService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @spec openspec/changes/events-and-signups/specs/portaliq-cms/spec.md#requirement-an-event-is-authored-per-school-group-or-child-with-guardian-rsvp
 */
class EventRsvpServiceTest extends TestCase {

	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	private function feedReader(?array $event): EventFeedReader {
		$reader = $this->createMock(EventFeedReader::class);
		$reader->method('readOwnEvent')->willReturn($event);
		return $reader;
	}//end feedReader()

	public function testReturnsFalseWhenTheEventIsNotInAudienceOrRsvpDisabled(): void {
		$service = new EventRsvpService($this->createMock(ContainerInterface::class), $this->feedReader(null), $this->createMock(LoggerInterface::class));
		$this->assertFalse($service->rsvp('g1', 'e1', 'child-1', 'yes'));

		$disabled = $this->feedReader(['id' => 'e1', 'rsvpEnabled' => false]);
		$service2 = new EventRsvpService($this->createMock(ContainerInterface::class), $disabled, $this->createMock(LoggerInterface::class));
		$this->assertFalse($service2->rsvp('g1', 'e1', 'child-1', 'yes'));
	}//end testReturnsFalseWhenTheEventIsNotInAudienceOrRsvpDisabled()

	public function testRejectsAnInvalidResponseValue(): void {
		$service = new EventRsvpService($this->createMock(ContainerInterface::class), $this->feedReader(['id' => 'e1', 'rsvpEnabled' => true]), $this->createMock(LoggerInterface::class));
		$this->assertFalse($service->rsvp('g1', 'e1', 'child-1', 'HACKED'));
	}//end testRejectsAnInvalidResponseValue()

	public function testFirstRsvpCreatesANewRecord(): void {
		$objectService = new class {
			/**
			 * @var array<string,mixed>
			 */
			public array $saved = [];

			public ?string $uuid = 'unset';

			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			public function setSchema(string $schema): self {
				return $this;
			}//end setSchema()

			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				return [];
			}//end findAll()

			/**
			 * @param array<string,mixed> $object
			 */
			public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->saved = $object;
				$this->uuid = $uuid;
				return $object;
			}//end saveObject()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id) => ($id === self::OS) ? $objectService : throw new RuntimeException('no service'));

		$service = new EventRsvpService($container, $this->feedReader(['id' => 'e1', 'rsvpEnabled' => true]), $this->createMock(LoggerInterface::class));
		$this->assertTrue($service->rsvp('g1', 'e1', 'child-1', 'maybe'));

		$this->assertSame('maybe', $objectService->saved['response']);
		$this->assertNull($objectService->uuid, 'a first RSVP has no existing id to update');
	}//end testFirstRsvpCreatesANewRecord()

	public function testASecondRsvpUpdatesRatherThanDuplicates(): void {
		$objectService = new class {
			/**
			 * @var array<string,mixed>
			 */
			public array $saved = [];

			public ?string $uuid = 'unset';

			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			public function setSchema(string $schema): self {
				return $this;
			}//end setSchema()

			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				return [['id' => 'rsvp-1', 'eventRef' => 'e1', 'guardianRef' => 'g1', 'childRef' => 'child-1', 'response' => 'maybe']];
			}//end findAll()

			/**
			 * @param array<string,mixed> $object
			 */
			public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->saved = $object;
				$this->uuid = $uuid;
				return $object;
			}//end saveObject()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id) => ($id === self::OS) ? $objectService : throw new RuntimeException('no service'));

		$service = new EventRsvpService($container, $this->feedReader(['id' => 'e1', 'rsvpEnabled' => true]), $this->createMock(LoggerInterface::class));
		$this->assertTrue($service->rsvp('g1', 'e1', 'child-1', 'yes'));

		$this->assertSame('yes', $objectService->saved['response']);
		$this->assertSame('rsvp-1', $objectService->uuid, 'the existing id must be reused so OR updates rather than creates');
	}//end testASecondRsvpUpdatesRatherThanDuplicates()
}//end class
