<?php

/**
 * StaffAuthoringGoldenTest
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
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use DateTime;
use DateTimeImmutable;
use OCA\Portaliq\Controller\ActivityController;
use OCA\Portaliq\Controller\EventController;
use OCA\Portaliq\Controller\NewsController;
use OCA\Portaliq\Controller\NewsletterController;
use OCA\Portaliq\Kernel\Contracts\ActionAuthServiceInterface;
use OCA\Portaliq\Service\ActionAuthService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * The staff authoring routes for events, news, newsletters and activities
 * answer an anonymous caller, an ordinary user and staff holding the
 * controller's action exactly as recorded, and hand OpenRegister and the
 * services the same values, the staff member's uid included.
 *
 * @covers \OCA\Portaliq\Controller\EventController
 * @covers \OCA\Portaliq\Controller\NewsController
 * @covers \OCA\Portaliq\Controller\NewsletterController
 * @covers \OCA\Portaliq\Controller\ActivityController
 * @uses   \OCA\Portaliq\Service\PortalObjectWriter
 * @uses   \OCA\Portaliq\Service\ObjectServiceLookup
 * @uses   \OCA\Portaliq\Service\ObjectRowNormaliser
 * @uses   \OCA\Portaliq\Service\Tenancy\PortalTenantContext
 */
class StaffAuthoringGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../Fixtures/Golden/staff-authoring.json';

	/**
	 * Every route, with the arguments it is called with.
	 */
	private const CALLS = [
		EventController::class => [
			'create' => ['title' => 'Sports day', 'start' => '2026-11-10T09:00', 'target' => ['schoolRef' => 's-1'], 'description' => 'd', 'end' => '2026-11-10T12:00', 'rsvpEnabled' => true, 'signupRoles' => [['role' => 'help']]],
			'create-untargeted' => ['title' => 'Sports day', 'start' => '2026-11-10T09:00', 'target' => ['groupRefs' => []]],
			'create-blank-school' => ['title' => 'Sports day', 'start' => '2026-11-10T09:00', 'target' => ['schoolRef' => '']],
			'create-arrayrow' => ['title' => 'array-row', 'start' => '2026-11-10T09:00', 'target' => ['schoolRef' => 's-1']],
			'create-stringrow' => ['title' => 'string-row', 'start' => '2026-11-10T09:00', 'target' => ['schoolRef' => 's-1']],
			'create-nullrow' => ['title' => 'null-row', 'start' => '2026-11-10T09:00', 'target' => ['schoolRef' => 's-1']],
			'create-groups' => ['title' => 'Trip', 'start' => '2026-11-11', 'target' => ['groupRefs' => ['g-1']]],
			'create-children' => ['title' => 'Trip', 'start' => '2026-11-11', 'target' => ['childRefs' => ['c-1'], 'schoolRef' => '']],
			'publish' => ['id' => 'e-1'],
			'publish-missing' => ['id' => 'missing'],
			'publish-empty' => ['id' => ''],
		],
		NewsController::class => [
			'create' => ['title' => 'News', 'body' => 'b', 'target' => ['schoolRef' => 's-1'], 'photoRefs' => ['p-1'], 'public' => true, 'portal' => 'p', 'audienceLabel' => 'All', 'eventRef' => 'e-1'],
			'create-untargeted' => ['title' => 'News', 'body' => 'b', 'target' => []],
			'create-children' => ['title' => 'News', 'body' => 'b', 'target' => ['childRefs' => ['c-1']]],
			'create-blank-school' => ['title' => 'News', 'body' => 'b', 'target' => ['schoolRef' => '']],
			'create-arrayrow' => ['title' => 'array-row', 'body' => 'b', 'target' => ['groupRefs' => ['g-1']]],
			'create-stringrow' => ['title' => 'string-row', 'body' => 'b', 'target' => ['groupRefs' => ['g-1']]],
			'create-nullrow' => ['title' => 'null-row', 'body' => 'b', 'target' => ['groupRefs' => ['g-1']]],
			'update' => ['id' => 'n-1', 'title' => 'News 2', 'body' => 'b2', 'target' => ['groupRefs' => ['g-1']], 'public' => false, 'eventRef' => ''],
			'update-missing' => ['id' => 'missing', 'title' => 'News 2', 'body' => 'b2', 'target' => ['groupRefs' => ['g-1']]],
			'audiences' => [],
			'publish' => ['id' => 'n-1'],
			'unpublish' => ['id' => 'n-1'],
			'publish-missing' => ['id' => 'missing'],
		],
		NewsletterController::class => [
			'create' => ['title' => 'Letter', 'itemRefs' => ['n-1'], 'target' => ['schoolRef' => 's-1']],
			'create-empty' => ['title' => '', 'itemRefs' => [], 'target' => []],
			'create-arrayrow' => ['title' => 'array-row', 'itemRefs' => ['n-1'], 'target' => ['schoolRef' => 's-1']],
			'create-stringrow' => ['title' => 'string-row', 'itemRefs' => ['n-1'], 'target' => ['schoolRef' => 's-1']],
			'create-nullrow' => ['title' => 'null-row', 'itemRefs' => ['n-1'], 'target' => ['schoolRef' => 's-1']],
			'preflight' => ['id' => 'l-1'],
			'preflight-missing' => ['id' => 'missing'],
			'send' => ['id' => 'l-1'],
			'send-missing' => ['id' => 'missing'],
		],
		ActivityController::class => [
			'create' => ['title' => 'Chess', 'kind' => 'club', 'target' => ['schoolRef' => 's-1'], 'termStart' => '2026-11-01', 'capacity' => 10],
			'open' => ['id' => 'a-1'],
			'close' => ['id' => 'a-1'],
			'supervisors' => ['id' => 'a-1', 'supervisorRefs' => ['u-1']],
			'roster' => ['id' => 'a-1'],
			'attendance' => ['id' => 'a-1', 'sessionId' => 's-1', 'childRef' => 'c-1', 'status' => 'present'],
			'contributions' => ['id' => 'a-1'],
		],
	];

	/**
	 * Everything the controllers did, in order.
	 *
	 * @var list<mixed>
	 */
	private array $log = [];

	/**
	 * Every route answers as recorded.
	 *
	 * @return void
	 */
	public function testEveryStaffRouteAnswersAsRecorded(): void {
		$actual = [];
		foreach (self::CALLS as $class => $calls) {
			foreach ($calls as $key => $args) {
				$method = explode('-', $key)[0];
				foreach (['anonymous' => null, 'ordinary' => 'resident-bob', 'staff' => 'staff-anna'] as $who => $uid) {
					$this->log = [];
					$controller = $this->controller(class: $class, uid: $uid);
					try {
						$response = $controller->{$method}(...$args);
						$answer = ['status' => $response->getStatus(), 'data' => $response->getData()];
					} catch (Throwable $e) {
						$answer = ['throws' => get_class($e), 'message' => $e->getMessage()];
					}

					$actual[(new ReflectionClass($class))->getShortName()][$key][$who] = ['answer' => $answer, 'log' => $this->log];
				}
			}
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode((string)json_encode($actual), true));
	}//end testEveryStaffRouteAnswersAsRecorded()

	/**
	 * A controller whose session holds the given uid, or nobody.
	 *
	 * @param class-string $class The controller.
	 * @param string|null  $uid   The signed-in uid.
	 *
	 * @return object
	 */
	private function controller(string $class, ?string $uid): object {
		$action = (string)(new ReflectionClass($class))->getConstant('ACTION');
		$args = [];
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $param) {
			$type = (string)$param->getType();
			$type = ltrim($type, '?');
			$args[$param->getName()] = match ($type) {
				IUserSession::class => $this->session(uid: $uid),
				ActionAuthService::class, ActionAuthServiceInterface::class => $this->auth(action: $action),
				ContainerInterface::class => $this->container(),
				ITimeFactory::class => $this->recorder(class: ITimeFactory::class, label: 'time', answers: ['getTime' => 1793606400, 'now' => new DateTimeImmutable('2026-11-02T10:00:00+00:00'), 'getDateTime' => new DateTime('2026-11-02T10:00:00+00:00')]),
				default => $this->recorder(class: $type, label: (new ReflectionClass($type))->getShortName()),
			};
		}

		return new $class(...$args);
	}//end controller()

	/**
	 * The session for the uid.
	 *
	 * @param string|null $uid The uid, or null.
	 *
	 * @return IUserSession
	 */
	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return $session;
	}//end session()

	/**
	 * An action check that passes only for staff-anna holding exactly the action.
	 *
	 * @param string $action The controller's action.
	 *
	 * @return ActionAuthService
	 */
	private function auth(string $action): ActionAuthService {
		$auth = $this->createMock(ActionAuthService::class);
		$auth->method('requireAction')->willReturnCallback(function (IUser $user, string $asked) use ($action): void {
			$this->log[] = ['requireAction' => [$user->getUID(), $asked]];
			if ($user->getUID() !== 'staff-anna' || $asked !== $action) {
				throw new OCSForbiddenException('no');
			}
		});

		return $auth;
	}//end auth()

	/**
	 * A container whose ObjectService logs every call by name.
	 *
	 * @return ContainerInterface
	 */
	private function container(): ContainerInterface {
		$test = $this;
		$objects = new class ($test) {
			public function __construct(private StaffAuthoringGoldenTest $test) {
			}

			public function __call(string $name, array $args): mixed {
				$this->test->note([$name => $args]);
				if (in_array('missing', $args, true) === true) {
					throw new DoesNotExistException('Object not found');
				}

				if (in_array($name, ['findAll', 'searchObjects'], true) === true) {
					return [];
				}

				if (str_contains((string)json_encode($args), 'array-row') === true) {
					return ['id' => 'x-2', 'called' => $name, 'shape' => 'array'];
				}

				$data = ['id' => 'x-1', 'called' => $name, 'args' => $args];
				if (str_contains((string)json_encode($args), 'string-row') === true) {
					$data = 'x-3';
				}

				if (str_contains((string)json_encode($args), 'null-row') === true) {
					$data = null;
				}

				return new class ($data) implements \JsonSerializable {
					public function __construct(private mixed $data) {
					}

					public function jsonSerialize(): mixed {
						return $this->data;
					}
				};
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id) use ($objects): object {
			$this->log[] = ['container.get' => $id];
			return $objects;
		});

		return $container;
	}//end container()

	/**
	 * Record one event.
	 *
	 * @param mixed $event The event.
	 *
	 * @return void
	 */
	public function note(mixed $event): void {
		$this->log[] = json_decode((string)json_encode($event), true);
	}//end note()

	/**
	 * A double that logs every call by name and answers from `$answers`,
	 * itself for a fluent call, or an empty value.
	 *
	 * @param string               $class   What to double.
	 * @param string               $label   Its name in the log.
	 * @param array<string, mixed> $answers Answers by method.
	 *
	 * @return MockObject
	 */
	private function recorder(string $class, string $label, array $answers = []): MockObject {
		$mock = $this->createMock($class);
		foreach ((new ReflectionClass($class))->getMethods() as $method) {
			if ($method->isPublic() === false || $method->isStatic() === true || $method->isConstructor() === true || $method->isFinal() === true) {
				continue;
			}

			$name = $method->getName();
			$names = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $method->getParameters());
			$mock->method($name)->willReturnCallback(function (mixed ...$args) use ($mock, $method, $name, $names, $label, $answers): mixed {
				$named = [];
				foreach ($args as $i => $value) {
					$named[$names[$i] ?? (string)$i] = is_object($value) ? (($value instanceof MockObject) ? 'double' : get_class($value)) : $value;
				}

				$this->log[] = [$label . '.' . $name => $named];
				if (array_key_exists($name, $answers) === true) {
					return $answers[$name];
				}

				return $this->emptyAnswer(mock: $mock, method: $method);
			});
		}

		return $mock;
	}//end recorder()

	/**
	 * The empty value a method's return type allows.
	 *
	 * @param MockObject       $mock   The double, for fluent calls.
	 * @param ReflectionMethod $method The method.
	 *
	 * @return mixed
	 */
	private function emptyAnswer(MockObject $mock, ReflectionMethod $method): mixed {
		$type = $method->getReturnType();
		if ($type === null || $type->allowsNull() === true || ($type instanceof ReflectionNamedType && in_array($type->getName(), ['void', 'mixed'], true))) {
			return null;
		}

		$name = ($type instanceof ReflectionNamedType) ? $type->getName() : 'mixed';
		$empty = ['string' => '', 'array' => [], 'bool' => true, 'int' => 0, 'float' => 0.0];
		if (array_key_exists($name, $empty) === true) {
			return $empty[$name];
		}

		if ($mock instanceof $name) {
			return $mock;
		}

		return $this->createStub($name);
	}//end emptyAnswer()
}//end class
