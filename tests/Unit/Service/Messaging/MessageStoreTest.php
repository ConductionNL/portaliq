<?php

/**
 * Unit tests for MessageStore.
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

/**
 * The messaging read hands its filters and ids to the store.
 */
class MessageStoreTest extends TestCase {
	public function testFindAllHandsTheFiltersAndIdsToTheStore(): void {
		$objectService = new class {
			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $configs = [];

			public function setRegister(string $register): self {
				return $this;
			}

			public function setSchema(string $schema): self {
				return $this;
			}

			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->configs[] = $config;
				return [['id' => 'thread-1']];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$store = new MessageStore($container, $this->createMock(LoggerInterface::class));

		$this->assertSame([['id' => 'thread-1']], $store->findAll('messageThread', [], ['thread-1']));
		$store->findAll('guardianMessage', ['threadRef' => 'thread-1']);

		$this->assertSame(['thread-1'], $objectService->configs[0]['ids']);
		$this->assertSame(['threadRef' => 'thread-1'], $objectService->configs[1]['filters']);
		$this->assertArrayNotHasKey('ids', $objectService->configs[1]);
	}//end testFindAllHandsTheFiltersAndIdsToTheStore()
}//end class
