<?php

/**
 * PushSubscriptionControllerTest
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
 * @spec openspec/changes/push-notifications-quiet-hours/design.md#api-design
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PushSubscriptionController;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @spec openspec/changes/push-notifications-quiet-hours/design.md#api-design
 */
class PushSubscriptionControllerTest extends TestCase {

	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	private function controller(?array $subject, object $objectService): PushSubscriptionController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer token');

		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn($subject);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id) => ($id === self::OS) ? $objectService : throw new RuntimeException('no service'));

		return new PushSubscriptionController($request, $session, $container, $this->createMock(LoggerInterface::class));
	}//end controller()

	private function fakeObjectService(array $existingRows = []): object {
		return new class($existingRows) {
			/**
			 * @var array<string,mixed>
			 */
			public array $saved = [];

			public ?string $uuid = null;

			public function __construct(
				private array $rows,
			) {
			}//end __construct()

			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			public function setSchema(string $schema): self {
				return $this;
			}//end setSchema()

			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				$rows = $this->rows;

				// The store answers the filters as OpenRegister does: a row
				// matches only when every filtered property equals the value.
				$rows = array_values(array_filter($rows, static function (array $row) use ($config): bool {
					foreach (($config['filters'] ?? []) as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							return false;
						}
					}

					return true;
				}));

				return $rows;
			}//end findAll()

			/**
			 * @param array<string,mixed> $object
			 */
			public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->saved = $object;
				$this->uuid = $uuid;
				return $object;
			}//end saveObject()
		};
	}//end fakeObjectService()

	public function testSubscribeFailsClosedWithoutAResolvedSubject(): void {
		$objectService = $this->fakeObjectService();
		$response = $this->controller(null, $objectService)->subscribe('https://push.example.org/x');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testSubscribeFailsClosedWithoutAResolvedSubject()

	public function testSubscribeSavesTheSubjectsOwnSubscription(): void {
		$objectService = $this->fakeObjectService();
		$response = $this->controller(['subjectRef' => 'guardian-1'], $objectService)->subscribe('https://fcm.googleapis.com/fcm/send/abc', ['p256dh' => 'k']);

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
		$this->assertSame('guardian-1', $objectService->saved['subjectRef']);
		$this->assertTrue($objectService->saved['active']);
	}//end testSubscribeSavesTheSubjectsOwnSubscription()

	public function testSubscribeRefusesAnEndpointOutsideTheKnownPushServices(): void {
		$objectService = $this->fakeObjectService();
		$controller = $this->controller(['subjectRef' => 'guardian-1'], $objectService);

		foreach (['', 'http://fcm.googleapis.com/fcm/send/abc', 'https://push.example.org/x', 'https://fcm.googleapis.com.evil.example/x', 'https://evilfcm.googleapis.com.example/x'] as $endpoint) {
			$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->subscribe($endpoint)->getStatus(), $endpoint);
		}

		$this->assertSame([], $objectService->saved);
		$this->assertSame(Http::STATUS_NO_CONTENT, $controller->subscribe('https://web.push.apple.com/QGx')->getStatus());
	}//end testSubscribeRefusesAnEndpointOutsideTheKnownPushServices()

	public function testUnsubscribeIsSuccessfulEvenWhenNoSubscriptionExisted(): void {
		$objectService = $this->fakeObjectService([]);
		$response = $this->controller(['subjectRef' => 'guardian-1'], $objectService)->unsubscribe('https://push.example.org/x');

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
	}//end testUnsubscribeIsSuccessfulEvenWhenNoSubscriptionExisted()

	public function testUnsubscribeDeactivatesAnExistingSubscription(): void {
		$objectService = $this->fakeObjectService([
			['id' => 'sub-other', 'subjectRef' => 'guardian-1', 'endpoint' => 'https://push.example.org/other'],
			['id' => 'sub-1', 'subjectRef' => 'guardian-1', 'endpoint' => 'https://push.example.org/x'],
		]);
		$response = $this->controller(['subjectRef' => 'guardian-1'], $objectService)->unsubscribe('https://push.example.org/x');

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
		$this->assertFalse($objectService->saved['active']);
		$this->assertSame('sub-1', $objectService->uuid, 'the subscription of this endpoint is the one switched off');
	}//end testUnsubscribeDeactivatesAnExistingSubscription()
}//end class
