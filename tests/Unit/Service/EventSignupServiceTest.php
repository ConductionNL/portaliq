<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\EventFeedReader;
use OCA\Portaliq\Service\EventSignupService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @spec openspec/changes/events-and-signups/specs/portaliq-cms/spec.md#requirement-a-sign-up-role-enforces-its-capacity-server-side
 */
class EventSignupServiceTest extends TestCase {

	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	private function eventWithRole(int $capacity): array {
		return ['id' => 'e1', 'signupRoles' => [['id' => 'begeleiding', 'label' => 'Begeleiding', 'capacity' => $capacity]]];
	}//end eventWithRole()

	private function feedReader(?array $event, bool $ownChild = true): EventFeedReader {
		$reader = $this->createMock(EventFeedReader::class);
		$reader->method('readOwnEvent')->willReturn($event);
		$reader->method('isOwnChild')->willReturn($ownChild);
		return $reader;
	}//end feedReader()

	public function testReturnsRoleNotFoundForAnUnreachableEventOrUnknownRole(): void {
		$service = new EventSignupService($this->createMock(ContainerInterface::class), $this->feedReader(null), $this->createMock(LoggerInterface::class));
		$this->assertSame(EventSignupService::REASON_ROLE_NOT_FOUND, $service->attemptSignup('g1', 'e1', 'begeleiding'));

		$service2 = new EventSignupService($this->createMock(ContainerInterface::class), $this->feedReader($this->eventWithRole(2)), $this->createMock(LoggerInterface::class));
		$this->assertSame(EventSignupService::REASON_ROLE_NOT_FOUND, $service2->attemptSignup('g1', 'e1', 'unknown-role'));
	}//end testReturnsRoleNotFoundForAnUnreachableEventOrUnknownRole()

	public function testASignupForAChildThatIsNotTheGuardiansOwnIsRefused(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$service = new EventSignupService($container, $this->feedReader($this->eventWithRole(2), false), $this->createMock(LoggerInterface::class));
		$this->assertSame(EventSignupService::REASON_ROLE_NOT_FOUND, $service->attemptSignup('g1', 'e1', 'begeleiding', 'someone-elses-child'));
	}//end testASignupForAChildThatIsNotTheGuardiansOwnIsRefused()

	public function testASignupIsAcceptedWhileCapacityRemains(): void {
		$objectService = new class {
			/**
			 * @var array<string,mixed>
			 */
			public array $saved = [];

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
				return $object;
			}//end saveObject()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id) => ($id === self::OS) ? $objectService : throw new RuntimeException('no service'));

		$service = new EventSignupService($container, $this->feedReader($this->eventWithRole(2)), $this->createMock(LoggerInterface::class));
		$result = $service->attemptSignup('g1', 'e1', 'begeleiding');

		$this->assertNull($result);
		$this->assertSame('begeleiding', $objectService->saved['roleId']);
	}//end testASignupIsAcceptedWhileCapacityRemains()

	public function testASignupIsRefusedOnceTheRoleIsFull(): void {
		$objectService = new class {
			public bool $saveCalled = false;

			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			public function setSchema(string $schema): self {
				return $this;
			}//end setSchema()

			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				// One existing sign-up, capacity is also 1 — full.
				return [['eventRef' => 'e1', 'roleId' => 'begeleiding', 'guardianRef' => 'other']];
			}//end findAll()

			/**
			 * @param array<string,mixed> $object
			 */
			public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->saveCalled = true;
				return $object;
			}//end saveObject()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id) => ($id === self::OS) ? $objectService : throw new RuntimeException('no service'));

		$service = new EventSignupService($container, $this->feedReader($this->eventWithRole(1)), $this->createMock(LoggerInterface::class));
		$result = $service->attemptSignup('g2', 'e1', 'begeleiding');

		$this->assertSame(EventSignupService::REASON_ROLE_FULL, $result);
		$this->assertFalse($objectService->saveCalled, 'a refused signup must never write');
	}//end testASignupIsRefusedOnceTheRoleIsFull()

	/**
	 * The count reads every sign-up of the role, not the first 500 rows of
	 * the schema: with 600 sign-ups and room for 600 the role is full, and
	 * the count asks the store for this event and role only.
	 */
	public function testTheCountReadsEverySignupOfTheRolePastTheFirstPage(): void {
		$objectService = new class {
			public bool $saveCalled = false;

			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $filters = [];

			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			public function setSchema(string $schema): self {
				return $this;
			}//end setSchema()

			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->filters[] = $config['filters'];
				$count = max(0, min(500, 600 - $config['offset']));
				return array_fill(0, $count, ['eventRef' => 'e1', 'roleId' => 'begeleiding', 'guardianRef' => 'other']);
			}//end findAll()

			/**
			 * @param array<string,mixed> $object
			 */
			public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->saveCalled = true;
				return $object;
			}//end saveObject()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id) => ($id === self::OS) ? $objectService : throw new RuntimeException('no service'));

		$service = new EventSignupService($container, $this->feedReader($this->eventWithRole(600)), $this->createMock(LoggerInterface::class));

		$this->assertSame(EventSignupService::REASON_ROLE_FULL, $service->attemptSignup('g2', 'e1', 'begeleiding'));
		$this->assertFalse($objectService->saveCalled);
		$this->assertSame([['eventRef' => 'e1', 'roleId' => 'begeleiding'], ['eventRef' => 'e1', 'roleId' => 'begeleiding']], $objectService->filters);
	}//end testTheCountReadsEverySignupOfTheRolePastTheFirstPage()
}//end class
