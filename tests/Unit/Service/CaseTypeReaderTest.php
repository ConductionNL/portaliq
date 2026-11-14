<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\CaseTypeReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Reading case types from OpenRegister without RBAC or multitenancy, and
 * reading nothing when OpenRegister is absent, refuses or answers oddly.
 */
#[CoversClass(CaseTypeReader::class)]
class CaseTypeReaderTest extends TestCase {

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	/**
	 * The reader over a container that serves $service as the ObjectService.
	 */
	private function reader(mixed $service): CaseTypeReader {
		$container = $this->createMock(ContainerInterface::class);
		if ($service instanceof \Throwable) {
			$container->method('get')->willThrowException($service);
		} else {
			$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($service);
		}
		return new CaseTypeReader($container, $this->logger);
	}

	public function testReadsOneCaseTypeWithoutRbacOrMultitenancy(): void {
		$service = new class {
			public array $calls = [];

			public function find(...$args): array {
				$this->calls[] = $args;
				return ['id' => 'ct-1', 'title' => 'Parking permit'];
			}
		};

		$this->assertSame(['id' => 'ct-1', 'title' => 'Parking permit'], $this->reader($service)->readCaseType('reg', 'caseType', 'ct-1'));
		$this->assertSame([['id' => 'ct-1', 'register' => 'reg', 'schema' => 'caseType', '_rbac' => false, '_multitenancy' => false]], $service->calls);
	}

	public function testAnEntityIsReadThroughJsonSerialize(): void {
		$service = new class {
			public function find(...$args): object {
				return new class implements \JsonSerializable {
					public function jsonSerialize(): array {
						return ['id' => 'ct-2'];
					}
				};
			}
		};

		$this->assertSame(['id' => 'ct-2'], $this->reader($service)->readCaseType('reg', 'caseType', 'ct-2'));
	}

	public function testAnUnreadableAnswerIsNull(): void {
		$service = new class {
			public function find(...$args): object {
				return new \stdClass();
			}
		};

		$this->assertNull($this->reader($service)->readCaseType('reg', 'caseType', 'ct-3'));
	}

	public function testMissingCoordinatesAskNothing(): void {
		$service = new class {
			public function find(...$args): array {
				throw new \LogicException('must not be asked');
			}
		};
		$reader = $this->reader($service);

		$this->assertNull($reader->readCaseType('', 'caseType', 'ct-1'));
		$this->assertNull($reader->readCaseType('reg', '', 'ct-1'));
		$this->assertNull($reader->readCaseType('reg', 'caseType', ''));
		$this->assertSame([], $reader->listCaseTypes('', 'caseType'));
		$this->assertSame([], $reader->listCaseTypes('reg', ''));
	}

	public function testWithoutOpenRegisterNothingIsRead(): void {
		$this->assertNull($this->reader(new RuntimeException('no openregister'))->readCaseType('reg', 'caseType', 'ct-1'));
		$this->assertSame([], $this->reader(new RuntimeException('no openregister'))->listCaseTypes('reg', 'caseType'));
		$this->assertNull($this->reader('not an object')->readCaseType('reg', 'caseType', 'ct-1'));
	}

	public function testAFailedReadIsLoggedAndNull(): void {
		$service = new class {
			public function find(...$args): array {
				throw new RuntimeException('gone');
			}
		};
		$this->logger->expects($this->once())->method('warning')
			->with('Portaliq: case type read failed', ['schema' => 'caseType', 'reason' => 'gone']);

		$this->assertNull($this->reader($service)->readCaseType('reg', 'caseType', 'ct-1'));
	}

	public function testListsCaseTypesSkippingUnreadableRows(): void {
		$service = new class {
			public array $calls = [];

			public function setRegister(string $register): void {
				$this->calls[] = ['setRegister', $register];
			}

			public function setSchema(string $schema): void {
				$this->calls[] = ['setSchema', $schema];
			}

			public function findAll(...$args): array {
				$this->calls[] = ['findAll', $args];
				return [['id' => 'a'], new \stdClass(), ['id' => 'b']];
			}
		};

		$this->assertSame([['id' => 'a'], ['id' => 'b']], $this->reader($service)->listCaseTypes('reg', 'caseType', 25));
		$this->assertSame(
			[
				['setRegister', 'reg'],
				['setSchema', 'caseType'],
				['findAll', ['config' => ['filters' => [], 'limit' => 25, 'offset' => 0], '_rbac' => false, '_multitenancy' => false]],
			],
			$service->calls
		);
	}

	public function testAFailedListIsLoggedAndEmpty(): void {
		$service = new class {
			public function setRegister(string $register): void {
			}

			public function setSchema(string $schema): void {
				throw new RuntimeException('no schema');
			}
		};
		$this->logger->expects($this->once())->method('warning')
			->with('Portaliq: case type list failed', ['schema' => 'caseType', 'reason' => 'no schema']);

		$this->assertSame([], $this->reader($service)->listCaseTypes('reg', 'caseType'));
	}
}
