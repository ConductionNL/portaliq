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
				return $this->rows;
			}//end findAll()

			/**
			 * @param array<string,mixed> $object
			 */
			public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->saved = $object;
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
		$response = $this->controller(['subjectRef' => 'guardian-1'], $objectService)->subscribe('https://push.example.org/x', ['p256dh' => 'k']);

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
		$this->assertSame('guardian-1', $objectService->saved['subjectRef']);
		$this->assertTrue($objectService->saved['active']);
	}//end testSubscribeSavesTheSubjectsOwnSubscription()

	public function testUnsubscribeIsSuccessfulEvenWhenNoSubscriptionExisted(): void {
		$objectService = $this->fakeObjectService([]);
		$response = $this->controller(['subjectRef' => 'guardian-1'], $objectService)->unsubscribe('https://push.example.org/x');

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
	}//end testUnsubscribeIsSuccessfulEvenWhenNoSubscriptionExisted()

	public function testUnsubscribeDeactivatesAnExistingSubscription(): void {
		$objectService = $this->fakeObjectService([['id' => 'sub-1', 'subjectRef' => 'guardian-1', 'endpoint' => 'https://push.example.org/x']]);
		$response = $this->controller(['subjectRef' => 'guardian-1'], $objectService)->unsubscribe('https://push.example.org/x');

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
		$this->assertFalse($objectService->saved['active']);
	}//end testUnsubscribeDeactivatesAnExistingSubscription()
}//end class
