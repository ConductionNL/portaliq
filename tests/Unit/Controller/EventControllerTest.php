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
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @spec openspec/changes/events-and-signups/design.md#api-design
 */
class EventControllerTest extends TestCase {
	use StaffActionDoubleTrait;

	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	private function container(object $objectService): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id) => ($id === self::OS) ? $objectService : throw new RuntimeException('no service'));
		return $container;
	}//end container()

	private function authenticatedUserSession(): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('staff-directie-1');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		return $userSession;
	}//end authenticatedUserSession()

	public function testCreateRefusesAnUnauthenticatedCaller(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$controller = new EventController($this->createMock(IRequest::class), $userSession, $this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class), $this->staffActionAuth(EventController::ACTION));
		$this->expectException(\OCP\AppFramework\OCS\OCSForbiddenException::class);
		$controller->create('Title', '2026-11-12T09:00:00+00:00', ['groupRefs' => ['groep-5a']]);
	}//end testCreateRefusesAnUnauthenticatedCaller()

	public function testCreateRejectsATargetWithNoDimension(): void {
		$controller = new EventController($this->createMock(IRequest::class), $this->authenticatedUserSession(), $this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class), $this->staffActionAuth(EventController::ACTION));
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

		$controller = new EventController($this->createMock(IRequest::class), $this->authenticatedUserSession(), $this->container($objectService), $this->createMock(LoggerInterface::class), $this->staffActionAuth(EventController::ACTION));
		$response = $controller->create('Schoolreisje', '2026-11-12T09:00:00+00:00', ['groupRefs' => ['groep-5a']], rsvpEnabled: true);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('draft', $objectService->saved['status']);
		$this->assertTrue($objectService->saved['rsvpEnabled']);
		$this->assertSame('staff-directie-1', $objectService->saved['authorRef']);
	}//end testCreateSavesADraftWithAValidTarget()

	public function testPublishReturns404ForAMissingId(): void {
		$objectService = new class {
			public function find(string $id, mixed $register = null, mixed $schema = null, bool $_rbac = true, bool $_multitenancy = true): mixed {
				return null;
			}//end find()
		};

		$controller = new EventController($this->createMock(IRequest::class), $this->authenticatedUserSession(), $this->container($objectService), $this->createMock(LoggerInterface::class), $this->staffActionAuth(EventController::ACTION));
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

		$controller = new EventController($this->createMock(IRequest::class), $this->authenticatedUserSession(), $this->container($objectService), $this->createMock(LoggerInterface::class), $this->staffActionAuth(EventController::ACTION));
		$response = $controller->publish('e1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('published', $objectService->saved['status']);
	}//end testPublishFlipsStatus()

	/**
	 * A signed-in user without portal.manage-event is refused by every staff method,
	 * before anything is read or written (#1094).
	 *
	 * @return void
	 */
	public function testEveryStaffMethodRefusesAUserWithoutTheAction(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$controller = new EventController($this->createMock(IRequest::class), $this->authenticatedUserSession(), $container, $this->createMock(LoggerInterface::class), $this->staffActionAuth(EventController::ACTION, false));

		$refused = 0;
		$calls = [
			fn () => $controller->create('Title', '2026-11-12T09:00:00+00:00', ['groupRefs' => ['groep-5a']]),
			fn () => $controller->publish('e1'),
			fn () => $controller->index(),
		];
		foreach ($calls as $call) {
			try {
				$call();
			} catch (\OCP\AppFramework\OCS\OCSForbiddenException $e) {
				$refused++;
			}
		}

		$this->assertSame(count($calls), $refused);
	}//end testEveryStaffMethodRefusesAUserWithoutTheAction()

	/**
	 * An object service double whose findAll answers the given rows, or throws.
	 *
	 * @param array<int, mixed>|null $rows The rows; null to answer a non-array.
	 * @param bool                   $throws Whether findAll throws.
	 *
	 * @return object
	 */
	private function listingService(?array $rows, bool $throws = false): object {
		return new class($rows, $throws) {
			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $configs = [];

			/**
			 * @var array<int, string>
			 */
			public array $scope = [];

			/**
			 * @param array<int, mixed>|null $rows   The rows.
			 * @param bool                   $throws Whether findAll throws.
			 */
			public function __construct(private ?array $rows, private bool $throws) {
			}//end __construct()

			public function setRegister(string $register): void {
				$this->scope[] = 'register:' . $register;
			}//end setRegister()

			public function setSchema(string $schema): void {
				$this->scope[] = 'schema:' . $schema;
			}//end setSchema()

			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): mixed {
				if ($this->throws === true) {
					throw new RuntimeException('store down');
				}

				$this->configs[] = $config;
				return $this->rows;
			}//end findAll()
		};
	}//end listingService()

	/**
	 * Staff get every event with an id, latest start first, as id, title, start and status.
	 *
	 * @spec openspec/changes/event-sign-up-by-a-pupil-with-seats/tasks.md#task-4
	 */
	public function testIndexListsEventsLatestStartFirst(): void {
		$serialisable = new class implements \JsonSerializable {
			public function jsonSerialize(): array {
				return ['@self' => ['id' => 'e3'], 'title' => 'Sportdag', 'start' => '2026-06-01T09:00:00+00:00', 'status' => 'draft'];
			}//end jsonSerialize()
		};
		$objectService = $this->listingService(
			[
				['id' => 'e1', 'title' => 'Informatieavond', 'start' => '2026-11-03T19:30:00+00:00', 'status' => 'published', 'target' => ['groupRefs' => ['3h']]],
				['title' => 'No id'],
				$serialisable,
				['uuid' => 'e2', 'title' => 'Kerstviering', 'start' => '2026-12-18T18:00:00+00:00'],
			]
		);

		$controller = new EventController($this->createMock(IRequest::class), $this->authenticatedUserSession(), $this->container($objectService), $this->createMock(LoggerInterface::class), $this->staffActionAuth(EventController::ACTION));
		$response = $controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'events' => [
					['id' => 'e2', 'title' => 'Kerstviering', 'start' => '2026-12-18T18:00:00+00:00', 'status' => ''],
					['id' => 'e1', 'title' => 'Informatieavond', 'start' => '2026-11-03T19:30:00+00:00', 'status' => 'published'],
					['id' => 'e3', 'title' => 'Sportdag', 'start' => '2026-06-01T09:00:00+00:00', 'status' => 'draft'],
				],
			],
			$response->getData()
		);
		$this->assertSame(['register:portaliq', 'schema:schoolEvent'], $objectService->scope);
		$this->assertSame(0, $objectService->configs[0]['offset']);
	}//end testIndexListsEventsLatestStartFirst()

	/**
	 * A store that cannot be read answers 500, and the failure is logged.
	 *
	 * @spec openspec/changes/event-sign-up-by-a-pupil-with-seats/tasks.md#task-4
	 */
	public function testIndexAnswers500WhenTheEventsCannotBeRead(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Portaliq: event list failed', ['reason' => 'store down']);

		$controller = new EventController($this->createMock(IRequest::class), $this->authenticatedUserSession(), $this->container($this->listingService([], true)), $logger, $this->staffActionAuth(EventController::ACTION));
		$response = $controller->index();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'server_error'], $response->getData());
	}//end testIndexAnswers500WhenTheEventsCannotBeRead()

	/**
	 * A non-list answer from the store, or no store at all, answers 500.
	 *
	 * @spec openspec/changes/event-sign-up-by-a-pupil-with-seats/tasks.md#task-4
	 */
	public function testIndexAnswers500WithoutAUsableStore(): void {
		$withNonList = new EventController($this->createMock(IRequest::class), $this->authenticatedUserSession(), $this->container($this->listingService(null)), $this->createMock(LoggerInterface::class), $this->staffActionAuth(EventController::ACTION));
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $withNonList->index()->getStatus());

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no openregister'));
		$withoutStore = new EventController($this->createMock(IRequest::class), $this->authenticatedUserSession(), $container, $this->createMock(LoggerInterface::class), $this->staffActionAuth(EventController::ACTION));
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $withoutStore->index()->getStatus());
	}//end testIndexAnswers500WithoutAUsableStore()

	/**
	 * A caller without a Nextcloud session is refused before anything is read.
	 *
	 * @spec openspec/changes/event-sign-up-by-a-pupil-with-seats/tasks.md#task-4
	 */
	public function testIndexRefusesAnUnauthenticatedCaller(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$controller = new EventController($this->createMock(IRequest::class), $userSession, $container, $this->createMock(LoggerInterface::class), $this->staffActionAuth(EventController::ACTION));
		$this->expectException(\OCP\AppFramework\OCS\OCSForbiddenException::class);
		$controller->index();
	}//end testIndexRefusesAnUnauthenticatedCaller()
}//end class
