<?php

/**
 * StaffRowWriteGoldenTest
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

use OCA\Portaliq\Controller\EventController;
use OCA\Portaliq\Controller\NewsController;
use OCA\Portaliq\Controller\NewsletterController;
use OCA\Portaliq\Service\NewsletterPreflightService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The staff writes on events, news and newsletters, pinned per store answer:
 * the status and body each answers, and what each asks the store.
 *
 * @covers \OCA\Portaliq\Controller\EventController
 * @covers \OCA\Portaliq\Controller\NewsController
 * @covers \OCA\Portaliq\Controller\NewsletterController
 * @uses   \OCA\Portaliq\Service\PortalObjectWriter
 * @uses   \OCA\Portaliq\Service\ActionAuthService
 * @uses   \OCA\Portaliq\Service\Tenancy\PortalTenantContext
 */
class StaffRowWriteGoldenTest extends TestCase {
	use StaffActionDoubleTrait;

	private const GOLDEN = __DIR__ . '/../../Fixtures/Golden/staff-row-writes.json';

	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * What the store was asked in the current case.
	 *
	 * @var array<int, mixed>
	 */
	private array $asked = [];

	public function testEveryStaffWriteMatchesTheGolden(): void {
		$actual = [];
		foreach ($this->stores() as $storeName => $store) {
			foreach ($this->calls() as $callName => $call) {
				$this->asked = [];
				$response    = $call($this->container(store: $store));
				$data        = $response->getData();
				foreach (['sentAt', 'publishedAt'] as $key) {
					if (is_string($data[$key] ?? null) === true) {
						$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $data[$key]);
						$data[$key] = '(time)';
					}
				}

				$actual[$storeName . ' | ' . $callName] = ['status' => $response->getStatus(), 'data' => $data, 'asked' => $this->clocked(asked: $this->asked)];
			}
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode((string)json_encode($actual), true));
	}

	/**
	 * The store answers, by name: [find answer, save answer]; a Throwable is thrown.
	 *
	 * @return array<string, array{0: mixed, 1: mixed}|null>
	 */
	private function stores(): array {
		$row = ['id' => 'x1', 'title' => 'T', 'status' => 'draft', 'target' => ['groupRefs' => ['g1']], 'organisation' => 'zuid', '@self' => ['id' => 'x1']];

		return [
			'no object service' => null,
			'found and saved' => [$row, 'echo'],
			'not found' => [null, 'echo'],
			'find throws' => [new RuntimeException('find down'), 'echo'],
			'save throws' => [$row, new RuntimeException('save down')],
			'save answers null' => [$row, null],
			'found empty' => [[], 'echo'],
		];
	}

	/**
	 * The staff writes, by name.
	 *
	 * @return array<string, callable(ContainerInterface): \OCP\AppFramework\Http\JSONResponse>
	 */
	private function calls(): array {
		return [
			'event publish' => fn (ContainerInterface $c) => $this->event(container: $c)->publish('x1'),
			'event publish no id' => fn (ContainerInterface $c) => $this->event(container: $c)->publish(''),
			'news publish' => fn (ContainerInterface $c) => $this->news(container: $c)->publish('x1'),
			'news publish no id' => fn (ContainerInterface $c) => $this->news(container: $c)->publish(''),
			'news unpublish' => fn (ContainerInterface $c) => $this->news(container: $c)->unpublish('x1'),
			'news update' => fn (ContainerInterface $c) => $this->news(container: $c)->update('x1', 'Titel', 'Tekst', ['groupRefs' => ['g1']]),
			'newsletter send' => fn (ContainerInterface $c) => $this->newsletter(container: $c)->send('x1'),
			'event publish x2' => fn (ContainerInterface $c) => $this->event(container: $c)->publish('x2'),
			'news unpublish x2' => fn (ContainerInterface $c) => $this->news(container: $c)->unpublish('x2'),
			'newsletter send x2' => fn (ContainerInterface $c) => $this->newsletter(container: $c)->send('x2'),
			'newsletter send no id' => fn (ContainerInterface $c) => $this->newsletter(container: $c)->send(''),
			'event create' => fn (ContainerInterface $c) => $this->event(container: $c)->create(
				title: 'Feest',
				start: '2026-12-01T10:00:00Z',
				target: ['groupRefs' => ['g1']],
				description: 'Omschrijving',
				end: '2026-12-01T12:00:00Z',
				rsvpEnabled: true,
				signupRoles: [['name' => 'Hulp']]
			),
			'event create no target' => fn (ContainerInterface $c) => $this->event(container: $c)->create('Feest', '2026-12-01T10:00:00Z', []),
			'news create' => fn (ContainerInterface $c) => $this->news(container: $c)->create(
				title: 'Titel',
				body: 'Tekst',
				target: ['groupRefs' => ['g1']],
				photoRefs: ['p1'],
				public: true,
				portal: 'zuid',
				audienceLabel: 'Ouders',
				eventRef: 'e1'
			),
			'news create no body' => fn (ContainerInterface $c) => $this->news(container: $c)->create('Titel', '', ['groupRefs' => ['g1']]),
			'newsletter create' => fn (ContainerInterface $c) => $this->newsletter(container: $c)->create('Brief', ['n1', 'n2'], ['groupRefs' => ['g1']]),
			'newsletter create no items' => fn (ContainerInterface $c) => $this->newsletter(container: $c)->create('Brief', [], ['groupRefs' => ['g1']]),
		];
	}

	/**
	 * A container whose object service answers as `$store` says.
	 *
	 * @param array{0: mixed, 1: mixed}|null $store The answers, or null for no service.
	 *
	 * @return ContainerInterface
	 */
	private function container(?array $store): ContainerInterface {
		$asked = &$this->asked;
		$service = null;
		if ($store !== null) {
			$service = new class($store, $asked) {
				public function __construct(private array $store, private array &$asked) {
				}

				public function find(string $id, mixed $register = null, mixed $schema = null, bool $_rbac = true, bool $_multitenancy = true): mixed {
					$this->asked[] = ['find' => [$id, $register, $schema, $_rbac, $_multitenancy]];
					if ($this->store[0] instanceof \Throwable) {
						throw $this->store[0];
					}

					return $this->store[0];
				}

				public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): mixed {
					$this->asked[] = ['saveObject' => [$object, $register, $schema, $uuid, $_rbac, $_multitenancy]];
					if ($this->store[1] instanceof \Throwable) {
						throw $this->store[1];
					}

					return ($this->store[1] === 'echo') ? $object : $this->store[1];
				}
			};
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): mixed => ($id === self::OS && $service !== null) ? $service : throw new RuntimeException('no ' . $id)
		);

		return $container;
	}

	/**
	 * The store calls with their write moments replaced by a marker.
	 *
	 * @param array<int, mixed> $asked The store calls.
	 *
	 * @return array<int, mixed>
	 */
	private function clocked(array $asked): array {
		foreach ($asked as $i => $call) {
			foreach (['sentAt', 'publishedAt'] as $key) {
				if (is_string($call['saveObject'][0][$key] ?? null) === true) {
					$asked[$i]['saveObject'][0][$key] = '(time)';
				}
			}
		}

		return $asked;
	}

	private function session(): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('staff-1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return $session;
	}

	/**
	 * A logger whose error and warning lines land in the store calls.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$asked  = &$this->asked;
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		foreach (['error', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				static function (string $message, array $context = []) use (&$asked, $level): void {
					$asked[] = [$level => [$message, $context]];
				}
			);
		}

		return $logger;
	}

	private function event(ContainerInterface $container): EventController {
		return new EventController(
			request: $this->createMock(originalClassName: IRequest::class),
			userSession: $this->session(),
			container: $container,
			logger: $this->logger(),
			actionAuth: $this->staffActionAuth(action: EventController::ACTION)
		);
	}

	private function news(ContainerInterface $container): NewsController {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1767225600);

		return new NewsController(
			request: $this->createMock(originalClassName: IRequest::class),
			userSession: $this->session(),
			container: $container,
			logger: $this->logger(),
			actionAuth: $this->staffActionAuth(action: NewsController::ACTION),
			timeFactory: $time
		);
	}

	private function newsletter(ContainerInterface $container): NewsletterController {
		$preflight = $this->createMock(NewsletterPreflightService::class);
		$preflight->method('sendIsRefused')->willReturn(false);

		return new NewsletterController(
			request: $this->createMock(originalClassName: IRequest::class),
			userSession: $this->session(),
			container: $container,
			preflight: $preflight,
			logger: $this->logger(),
			actionAuth: $this->staffActionAuth(action: NewsletterController::ACTION)
		);
	}
}
