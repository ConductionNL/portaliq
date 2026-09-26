<?php

/**
 * EventControllerTest
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/events-and-signups/design.md#api-design
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\EventController;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @spec openspec/changes/events-and-signups/design.md#api-design
 */
class EventControllerTest extends TestCase {

	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	private function container(object $objectService): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id) => ($id === self::OS) ? $objectService : throw new RuntimeException('no service'));
		return $container;
	}//end container()

	public function testCreateRejectsATargetWithNoDimension(): void {
		$controller = new EventController($this->createMock(IRequest::class), $this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class));
		$response = $controller->create('Title', '2026-11-12T09:00:00+00:00', []);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testCreateRejectsATargetWithNoDimension()

	public function testCreateSavesADraftWithAValidTarget(): void {
		$objectService = new class {
			/**
			 * @var array<string,mixed>
			 */
			public array $saved = [];

			public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->saved = $object;
				return array_merge($object, ['id' => 'e1']);
			}//end saveObject()
		};

		$controller = new EventController($this->createMock(IRequest::class), $this->container($objectService), $this->createMock(LoggerInterface::class));
		$response = $controller->create('Schoolreisje', '2026-11-12T09:00:00+00:00', ['groupRefs' => ['groep-5a']], rsvpEnabled: true);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('draft', $objectService->saved['status']);
		$this->assertTrue($objectService->saved['rsvpEnabled']);
	}//end testCreateSavesADraftWithAValidTarget()

	public function testPublishReturns404ForAMissingId(): void {
		$objectService = new class {
			public function find(string $id, mixed $register = null, mixed $schema = null, bool $_rbac = true, bool $_multitenancy = true): mixed {
				return null;
			}//end find()
		};

		$controller = new EventController($this->createMock(IRequest::class), $this->container($objectService), $this->createMock(LoggerInterface::class));
		$response = $controller->publish('missing');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testPublishReturns404ForAMissingId()

	public function testPublishFlipsStatus(): void {
		$objectService = new class {
			/**
			 * @var array<string,mixed>
			 */
			public array $saved = [];

			public function find(string $id, mixed $register = null, mixed $schema = null, bool $_rbac = true, bool $_multitenancy = true): array {
				return ['id' => $id, 'title' => 'X', 'status' => 'draft'];
			}//end find()

			public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->saved = $object;
				return $object;
			}//end saveObject()
		};

		$controller = new EventController($this->createMock(IRequest::class), $this->container($objectService), $this->createMock(LoggerInterface::class));
		$response = $controller->publish('e1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('published', $objectService->saved['status']);
	}//end testPublishFlipsStatus()
}//end class
