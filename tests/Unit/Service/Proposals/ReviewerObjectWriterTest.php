<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Proposals;

use OCA\Portaliq\Service\Proposals\ReviewerObjectWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Accepting a proposal writes as the reviewer: OpenRegister's RBAC and
 * multitenancy stay on, and a refusal is a false, never an exception.
 */
#[CoversClass(ReviewerObjectWriter::class)]
class ReviewerObjectWriterTest extends TestCase {

	/**
	 * The writer over $service.
	 *
	 * @param mixed                $service The ObjectService double, a Throwable to throw on lookup.
	 * @param LoggerInterface|null $logger  The logger.
	 *
	 * @return ReviewerObjectWriter
	 */
	private function writer(mixed $service, ?LoggerInterface $logger = null): ReviewerObjectWriter {
		$container = $this->createMock(ContainerInterface::class);
		if ($service instanceof \Throwable) {
			$container->method('get')->willThrowException($service);
		} else {
			$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($service);
		}
		return new ReviewerObjectWriter($container, $logger ?? $this->createMock(LoggerInterface::class));
	}//end writer()

	/**
	 * An ObjectService double that records saves and answers $answer.
	 *
	 * @param mixed $answer What saveObject() returns.
	 *
	 * @return object
	 */
	private static function service(mixed $answer): object {
		return new class ($answer) {
			public array $saved = [];

			public function __construct(private mixed $answer) {
			}

			public function saveObject(...$args): mixed {
				$this->saved[] = $args;
				return $this->answer;
			}
		};
	}//end service()

	public function testTheValuesAreWrittenAsTheReviewerWithRbacAndMultitenancyOn(): void {
		$service = self::service(['id' => 'o-1']);

		$this->assertTrue($this->writer($service)->write('reg', 'page', 'o-1', ['title' => 'New']));
		$this->assertSame(
			[['object' => ['title' => 'New'], 'register' => 'reg', 'schema' => 'page', 'uuid' => 'o-1', '_rbac' => true, '_multitenancy' => true]],
			$service->saved
		);
	}//end testTheValuesAreWrittenAsTheReviewerWithRbacAndMultitenancyOn()

	public function testANullSaveIsNotWritten(): void {
		$this->assertFalse($this->writer(self::service(null))->write('reg', 'page', 'o-1', ['title' => 'New']));
	}//end testANullSaveIsNotWritten()

	public function testMissingCoordinatesOrValuesWriteNothing(): void {
		$service = self::service(['id' => 'o-1']);
		$writer = $this->writer($service);

		$this->assertFalse($writer->write('', 'page', 'o-1', ['a' => 1]));
		$this->assertFalse($writer->write('reg', '', 'o-1', ['a' => 1]));
		$this->assertFalse($writer->write('reg', 'page', '', ['a' => 1]));
		$this->assertFalse($writer->write('reg', 'page', 'o-1', []));
		$this->assertSame([], $service->saved);
	}//end testMissingCoordinatesOrValuesWriteNothing()

	public function testWithoutOpenRegisterNothingIsWritten(): void {
		$this->assertFalse($this->writer(new RuntimeException('no openregister'))->write('reg', 'page', 'o-1', ['a' => 1]));
		$this->assertFalse($this->writer('not a service')->write('reg', 'page', 'o-1', ['a' => 1]));
	}//end testWithoutOpenRegisterNothingIsWritten()

	public function testARefusalIsLoggedAndFalse(): void {
		$service = new class {
			public function saveObject(...$args): mixed {
				throw new RuntimeException('not allowed');
			}
		};
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('Portaliq: accepting a proposal was refused by OpenRegister: not allowed');

		$this->assertFalse($this->writer($service, $logger)->write('reg', 'page', 'o-1', ['a' => 1]));
	}//end testARefusalIsLoggedAndFalse()
}//end class
