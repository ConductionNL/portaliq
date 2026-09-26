<?php

/**
 * NewsControllerTest
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
 * @spec openspec/changes/news-and-newsletter-authoring/design.md#api-design
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\NewsController;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Staff authoring: an invalid target is refused before any write, a valid
 * one is created as a draft, and publish/unpublish flip status without
 * touching other fields.
 *
 * @spec openspec/changes/news-and-newsletter-authoring/design.md#api-design
 */
class NewsControllerTest extends TestCase {

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

	public function testCreateRejectsATargetWithNoDimension(): void {
		$controller = new NewsController($this->createMock(IRequest::class), $this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class));

		$response = $controller->create('Title', 'Body', [], 'staff-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testCreateRejectsATargetWithNoDimension()

	public function testCreateSavesADraftWithAValidTarget(): void {
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
				return array_merge($object, ['id' => 'n1']);
			}//end saveObject()
		};

		$controller = new NewsController($this->createMock(IRequest::class), $this->container($objectService), $this->createMock(LoggerInterface::class));
		$response = $controller->create('Title', 'Body', ['groupRefs' => ['groep-5a']], 'staff-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('draft', $objectService->saved['status']);
		$this->assertSame('staff-1', $objectService->saved['authorRef']);
		$this->assertSame('n1', $response->getData()['id']);
	}//end testCreateSavesADraftWithAValidTarget()

	public function testPublishReturns404ForAMissingId(): void {
		$objectService = new class {
			public function find(string $id, mixed $register = null, mixed $schema = null, bool $_rbac = true, bool $_multitenancy = true): mixed {
				return null;
			}//end find()
		};

		$controller = new NewsController($this->createMock(IRequest::class), $this->container($objectService), $this->createMock(LoggerInterface::class));
		$response = $controller->publish('missing');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testPublishReturns404ForAMissingId()

	public function testPublishFlipsStatusAndPreservesOtherFields(): void {
		$objectService = new class {
			/**
			 * @var array<string,mixed>
			 */
			public array $saved = [];

			public function find(string $id, mixed $register = null, mixed $schema = null, bool $_rbac = true, bool $_multitenancy = true): array {
				return ['id' => $id, 'title' => 'X', 'status' => 'draft'];
			}//end find()

			/**
			 * @param array<string,mixed> $object
			 */
			public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->saved = $object;
				return $object;
			}//end saveObject()
		};

		$controller = new NewsController($this->createMock(IRequest::class), $this->container($objectService), $this->createMock(LoggerInterface::class));
		$response = $controller->publish('n1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('published', $objectService->saved['status']);
		$this->assertSame('X', $objectService->saved['title']);
	}//end testPublishFlipsStatusAndPreservesOtherFields()
}//end class
