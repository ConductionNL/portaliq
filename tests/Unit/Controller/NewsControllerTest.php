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
use OCA\Portaliq\Service\NewsAudienceOptions;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
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

	private function authenticatedUserSession(): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('staff-directie-1');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		return $userSession;
	}//end authenticatedUserSession()

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

	public function testCreateRefusesAnUnauthenticatedCaller(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$controller = new NewsController($this->createMock(IRequest::class), $userSession, $this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class));

		$this->expectException(\OCP\AppFramework\OCS\OCSForbiddenException::class);
		$controller->create('Title', 'Body', ['groupRefs' => ['groep-5a']]);
	}//end testCreateRefusesAnUnauthenticatedCaller()

	public function testCreateRejectsATargetWithNoDimension(): void {
		$controller = new NewsController($this->createMock(IRequest::class), $this->authenticatedUserSession(), $this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class));

		$response = $controller->create('Title', 'Body', []);

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

		$controller = new NewsController($this->createMock(IRequest::class), $this->authenticatedUserSession(), $this->container($objectService), $this->createMock(LoggerInterface::class));
		$response = $controller->create('Title', 'Body', ['groupRefs' => ['groep-5a']]);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('draft', $objectService->saved['status']);
		$this->assertSame('staff-directie-1', $objectService->saved['authorRef']);
		$this->assertSame('n1', $response->getData()['id']);
	}//end testCreateSavesADraftWithAValidTarget()

	public function testPublishReturns404ForAMissingId(): void {
		$objectService = new class {
			public function find(string $id, mixed $register = null, mixed $schema = null, bool $_rbac = true, bool $_multitenancy = true): mixed {
				return null;
			}//end find()
		};

		$controller = new NewsController($this->createMock(IRequest::class), $this->authenticatedUserSession(), $this->container($objectService), $this->createMock(LoggerInterface::class));
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

		$controller = new NewsController($this->createMock(IRequest::class), $this->authenticatedUserSession(), $this->container($objectService), $this->createMock(LoggerInterface::class));
		$response = $controller->publish('n1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('published', $objectService->saved['status']);
		$this->assertSame('X', $objectService->saved['title']);
	}//end testPublishFlipsStatusAndPreservesOtherFields()

	public function testUnpublishReturns404ForAMissingId(): void {
		$objectService = new class {
			public function find(string $id, mixed $register = null, mixed $schema = null, bool $_rbac = true, bool $_multitenancy = true): mixed {
				return null;
			}//end find()
		};

		$controller = new NewsController($this->createMock(IRequest::class), $this->authenticatedUserSession(), $this->container($objectService), $this->createMock(LoggerInterface::class));
		$response = $controller->unpublish('missing');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testUnpublishReturns404ForAMissingId()

	public function testUnpublishRevertsToADraftAndPreservesOtherFields(): void {
		$objectService = new class {
			/**
			 * @var array<string,mixed>
			 */
			public array $saved = [];

			public function find(string $id, mixed $register = null, mixed $schema = null, bool $_rbac = true, bool $_multitenancy = true): array {
				return ['id' => $id, 'title' => 'X', 'status' => 'published'];
			}//end find()

			/**
			 * @param array<string,mixed> $object
			 */
			public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->saved = $object;
				return $object;
			}//end saveObject()
		};

		$controller = new NewsController($this->createMock(IRequest::class), $this->authenticatedUserSession(), $this->container($objectService), $this->createMock(LoggerInterface::class));
		$response = $controller->unpublish('n1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('draft', $objectService->saved['status']);
		$this->assertSame('X', $objectService->saved['title']);
	}//end testUnpublishRevertsToADraftAndPreservesOtherFields()
	/**
	 * An edit changes the title, body and audience and nothing else; a bad
	 * target is refused before any read.
	 *
	 * @spec openspec/changes/staff-news-screen/tasks.md#T1
	 */
	public function testUpdateChangesTheTextAndAudienceOnly(): void {
		$objectService = new class {
			/**
			 * @var array<string,mixed>
			 */
			public array $saved = [];

			public function find(string $id, mixed $register = null, mixed $schema = null, bool $_rbac = true, bool $_multitenancy = true): array {
				return ['id' => $id, 'title' => 'Old', 'body' => 'Old body', 'target' => ['schoolRef' => 's'], 'status' => 'published', 'authorRef' => 'po-leerkracht-09'];
			}//end find()

			/**
			 * @param array<string,mixed> $object
			 */
			public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->saved = $object;
				return $object;
			}//end saveObject()
		};

		$controller = new NewsController($this->createMock(IRequest::class), $this->authenticatedUserSession(), $this->container($objectService), $this->createMock(LoggerInterface::class));

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->update('n1', 'New', 'New body', [])->getStatus());
		$this->assertSame([], $objectService->saved);

		$response = $controller->update('n1', 'New', 'New body', ['groupRefs' => ['groep-7']]);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('New', $objectService->saved['title']);
		$this->assertSame('New body', $objectService->saved['body']);
		$this->assertSame(['groupRefs' => ['groep-7']], $objectService->saved['target']);
		$this->assertSame('published', $objectService->saved['status']);
		$this->assertSame('po-leerkracht-09', $objectService->saved['authorRef']);
	}//end testUpdateChangesTheTextAndAudienceOnly()

	/**
	 * Editing and the audience choices need a signed-in Nextcloud user, like
	 * every other authoring endpoint.
	 *
	 * @spec openspec/changes/staff-news-screen/tasks.md#T1
	 */
	public function testUpdateAndAudiencesRefuseAnUnauthenticatedCaller(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$controller = new NewsController($this->createMock(IRequest::class), $userSession, $this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class));

		$refused = 0;
		foreach ([fn () => $controller->update('n1', 'T', 'B', ['schoolRef' => 's']), fn () => $controller->audiences()] as $call) {
			try {
				$call();
			} catch (\OCP\AppFramework\OCS\OCSForbiddenException $e) {
				$refused++;
			}
		}

		$this->assertSame(2, $refused);
	}//end testUpdateAndAudiencesRefuseAnUnauthenticatedCaller()

	/**
	 * The audience choices come from NewsAudienceOptions.
	 *
	 * @spec openspec/changes/staff-news-screen/tasks.md#T2
	 */
	public function testAudiencesListsTheSchoolAndGroupChoices(): void {
		$options = $this->createMock(NewsAudienceOptions::class);
		$options->method('options')->willReturn(['schools' => [], 'groups' => [['id' => 'g7', 'label' => 'Groep 7']]]);
		$controller = new NewsController($this->createMock(IRequest::class), $this->authenticatedUserSession(), $this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class), $options);

		$this->assertSame(['schools' => [], 'groups' => [['id' => 'g7', 'label' => 'Groep 7']]], $controller->audiences()->getData());
	}//end testAudiencesListsTheSchoolAndGroupChoices()
}//end class
