<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\StaffSubmissionReader;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * submission-row-actions 1.3: a submission is read with the caller's rights,
 * so one they may not read is indistinguishable from one that does not exist.
 *
 * @covers \OCA\Portaliq\Service\StaffSubmissionReader
 *
 * @spec openspec/changes/submission-row-actions/specs/supplier-portal/spec.md#requirement-an-employee-can-register-a-failed-receipt-again
 */
class StaffSubmissionReaderTest extends TestCase {


	/**
	 * The read asks OpenRegister for that id in the submission schema, with
	 * RBAC and multitenancy on, and hands back the serialised row.
	 *
	 * @return void
	 */
	public function testTheRecordIsReadAsTheCaller(): void {
		$objects = new class {
			/** @var array<string, mixed> */
			public array $asked = [];

			/**
			 * @param mixed ...$args The call.
			 *
			 * @return object
			 */
			public function find(...$args): object {
				$this->asked = $args;
				return new class implements \JsonSerializable {
					/**
					 * @return array<string, mixed>
					 */
					public function jsonSerialize(): array {
						return ['id' => 'sub-1', 'deliveryStatus' => 'failed'];
					}
				};
			}
		};

		$row = $this->reader(objects: $objects)->find(id: 'sub-1');

		$this->assertSame(['id' => 'sub-1', 'deliveryStatus' => 'failed'], $row);
		$this->assertSame(['id' => 'sub-1', 'register' => 'portaliq', 'schema' => 'portalSubmission', '_rbac' => true, '_multitenancy' => true], $objects->asked);
	}//end testTheRecordIsReadAsTheCaller()


	/**
	 * A refusal or a missing record answers null, the same as not existing.
	 *
	 * @return void
	 */
	public function testARefusedOrMissingReadIsNull(): void {
		$refusing = new class {
			/**
			 * @param mixed ...$args The call.
			 *
			 * @return object
			 */
			public function find(...$args): object {
				throw new RuntimeException('Forbidden');
			}
		};
		$missing = new class {
			/**
			 * @param mixed ...$args The call.
			 *
			 * @return null
			 */
			public function find(...$args) {
				return null;
			}
		};

		$this->assertNull($this->reader(objects: $refusing)->find(id: 'sub-1'));
		$this->assertNull($this->reader(objects: $missing)->find(id: 'sub-1'));
	}//end testARefusedOrMissingReadIsNull()


	/**
	 * A blank id, or no OpenRegister, reads nothing.
	 *
	 * @return void
	 */
	public function testABlankIdOrNoOpenRegisterReadsNothing(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$this->assertNull((new StaffSubmissionReader($container, new NullLogger()))->find(id: '  '));

		$absent = $this->createMock(ContainerInterface::class);
		$absent->method('get')->willThrowException(new RuntimeException('no app'));
		$this->assertNull((new StaffSubmissionReader($absent, new NullLogger()))->find(id: 'sub-1'));
	}//end testABlankIdOrNoOpenRegisterReadsNothing()


	/**
	 * The reader over a stand-in ObjectService.
	 *
	 * @param object $objects The stand-in.
	 *
	 * @return StaffSubmissionReader
	 */
	private function reader(object $objects): StaffSubmissionReader {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\\OpenRegister\\Service\\ObjectService')->willReturn($objects);

		return new StaffSubmissionReader($container, new NullLogger());
	}//end reader()
}//end class
