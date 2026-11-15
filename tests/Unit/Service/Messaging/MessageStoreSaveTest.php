<?php

/**
 * Unit tests for MessageStore::save() and rowId().
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Messaging
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Messaging;

use OCA\Portaliq\Service\Messaging\MessageStore;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A messaging save goes to the portaliq register with the app's authority and
 * answers the saved row's id, or null when it cannot.
 *
 * @covers \OCA\Portaliq\Service\Messaging\MessageStore
 * @covers \OCA\Portaliq\Service\ObjectServiceLookup
 * @covers \OCA\Portaliq\Service\ObjectRowNormaliser
 */
class MessageStoreSaveTest extends TestCase {

	/**
	 * A store over an object service double, or over none.
	 *
	 * @param object|null $objectService The double, or null for a container without it.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return MessageStore The store.
	 */
	private function store(?object $objectService, ?LoggerInterface $logger = null): MessageStore {
		$container = $this->createMock(ContainerInterface::class);
		if ($objectService === null) {
			$container->method('get')->willThrowException(new RuntimeException('not installed'));
		} else {
			$container->method('get')->with('OCA\\OpenRegister\\Service\\ObjectService')->willReturn($objectService);
		}

		return new MessageStore($container, $logger ?? $this->createMock(LoggerInterface::class));
	}//end store()

	/**
	 * An object service double that records its saves and answers $answer.
	 *
	 * @param mixed $answer What saveObject returns, or a Throwable to throw.
	 *
	 * @return object The double.
	 */
	private function objectService(mixed $answer): object {
		return new class ($answer) {
			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $saves = [];

			public function __construct(private mixed $answer) {
			}

			public function saveObject(array $object, string $register, string $schema, ?string $uuid, bool $_rbac, bool $_multitenancy): mixed {
				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid, '_rbac' => $_rbac, '_multitenancy' => $_multitenancy];
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}
		};
	}//end objectService()

	public function testASaveCreatesInThePortaliqRegisterAndAnswersTheId(): void {
		$service = $this->objectService(['id' => 'msg-1', 'body' => 'Hallo']);

		$this->assertSame('msg-1', $this->store($service)->save('guardianMessage', ['body' => 'Hallo']));
		$this->assertSame(
			[['object' => ['body' => 'Hallo'], 'register' => 'portaliq', 'schema' => 'guardianMessage', 'uuid' => null, '_rbac' => false, '_multitenancy' => false]],
			$service->saves
		);
	}//end testASaveCreatesInThePortaliqRegisterAndAnswersTheId()

	public function testASaveWithAnIdUpdatesThatRowAndReadsASerialisedAnswer(): void {
		$saved   = new class implements \JsonSerializable {
			public function jsonSerialize(): array {
				return ['@self' => ['uuid' => 'thread-7']];
			}
		};
		$service = $this->objectService($saved);

		$this->assertSame('thread-7', $this->store($service)->save('messageThread', ['subject' => 'Rooster'], 'thread-7'));
		$this->assertSame('thread-7', $service->saves[0]['uuid']);
	}//end testASaveWithAnIdUpdatesThatRowAndReadsASerialisedAnswer()

	public function testAFailedSaveIsLoggedAndAnswersNull(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Portaliq: messaging save failed',
			['schema' => 'guardianMessage', 'reason' => 'validation failed']
		);

		$this->assertNull($this->store($this->objectService(new RuntimeException('validation failed')), $logger)->save('guardianMessage', ['body' => 'x']));
	}//end testAFailedSaveIsLoggedAndAnswersNull()

	public function testAnAnswerThatIsNotARowAnswersNull(): void {
		$this->assertNull($this->store($this->objectService('saved'))->save('guardianMessage', ['body' => 'x']));
	}//end testAnAnswerThatIsNotARowAnswersNull()

	public function testWithoutOpenRegisterNothingIsSavedOrRead(): void {
		$store = $this->store(null);

		$this->assertNull($store->save('guardianMessage', ['body' => 'x']));
		$this->assertSame([], $store->findAll('guardianMessage'));
	}//end testWithoutOpenRegisterNothingIsSavedOrRead()

	public function testTheRowIdComesFromIdUuidOrTheEnvelope(): void {
		$store = $this->store(null);

		$this->assertSame('a', $store->rowId(['id' => 'a', 'uuid' => 'b']));
		$this->assertSame('b', $store->rowId(['uuid' => 'b']));
		$this->assertSame('c', $store->rowId(['@self' => ['id' => 'c']]));
		$this->assertSame('d', $store->rowId(['@self' => ['uuid' => 'd']]));
		$this->assertNull($store->rowId(['@self' => 'not an envelope']));
		$this->assertNull($store->rowId([]));
	}//end testTheRowIdComesFromIdUuidOrTheEnvelope()
}//end class
