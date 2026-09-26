<?php

/**
 * NewsletterControllerTest
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
 * @spec openspec/changes/news-and-newsletter-authoring/specs/portaliq-cms/spec.md#requirement-a-newsletter-send-is-preceded-by-a-recipient-count-preflight
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\NewsletterController;
use OCA\Portaliq\Service\NewsletterPreflightService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The preflight and send paths MUST call the identical
 * NewsletterPreflightService, and a send to an empty resolved audience MUST
 * be refused before any write.
 *
 * @spec openspec/changes/news-and-newsletter-authoring/specs/portaliq-cms/spec.md#requirement-a-newsletter-send-is-preceded-by-a-recipient-count-preflight
 */
class NewsletterControllerTest extends TestCase {

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

	private function fakeObjectServiceReturning(array $newsletter): object {
		return new class($newsletter) {
			/**
			 * @var array<string,mixed>
			 */
			public array $saved = [];

			/**
			 * @param array<string,mixed> $newsletter
			 */
			public function __construct(
				private array $newsletter,
			) {
			}//end __construct()

			public function find(string $id, mixed $register = null, mixed $schema = null, bool $_rbac = true, bool $_multitenancy = true): array {
				return $this->newsletter;
			}//end find()

			/**
			 * @param array<string,mixed> $object
			 */
			public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->saved = $object;
				return $object;
			}//end saveObject()
		};
	}//end fakeObjectServiceReturning()

	public function testCreateRefusesAnUnauthenticatedCaller(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$controller = new NewsletterController($this->createMock(IRequest::class), $userSession, $this->createMock(ContainerInterface::class), $this->createMock(NewsletterPreflightService::class), $this->createMock(LoggerInterface::class));

		$this->expectException(OCSForbiddenException::class);
		$controller->create('Title', ['n1'], ['groupRefs' => ['groep-5a']]);
	}//end testCreateRefusesAnUnauthenticatedCaller()

	public function testPreflightReportsTheExactCount(): void {
		$objectService = $this->fakeObjectServiceReturning(['id' => 'nl1', 'target' => ['groupRefs' => ['groep-5a']]]);
		$preflight = $this->createMock(NewsletterPreflightService::class);
		$preflight->expects($this->once())->method('countRecipients')->with(['groupRefs' => ['groep-5a']])->willReturn(3);

		$controller = new NewsletterController($this->createMock(IRequest::class), $this->authenticatedUserSession(), $this->container($objectService), $preflight, $this->createMock(LoggerInterface::class));
		$response = $controller->preflight('nl1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(3, $response->getData()['recipientCount']);
	}//end testPreflightReportsTheExactCount()

	public function testSendIsRefusedForAnEmptyResolvedAudience(): void {
		$objectService = $this->fakeObjectServiceReturning(['id' => 'nl1', 'target' => ['groupRefs' => ['empty-group']], 'sentAt' => null]);
		$preflight = $this->createMock(NewsletterPreflightService::class);
		$preflight->method('sendIsRefused')->willReturn(true);

		$controller = new NewsletterController($this->createMock(IRequest::class), $this->authenticatedUserSession(), $this->container($objectService), $preflight, $this->createMock(LoggerInterface::class));
		$response = $controller->send('nl1');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame([], $objectService->saved, 'the save must never be attempted on a refused send');
	}//end testSendIsRefusedForAnEmptyResolvedAudience()

	public function testSendStampsSentAtWhenTheAudienceIsNonEmpty(): void {
		$objectService = $this->fakeObjectServiceReturning(['id' => 'nl1', 'target' => ['groupRefs' => ['groep-5a']], 'sentAt' => null]);
		$preflight = $this->createMock(NewsletterPreflightService::class);
		$preflight->method('sendIsRefused')->willReturn(false);

		$controller = new NewsletterController($this->createMock(IRequest::class), $this->authenticatedUserSession(), $this->container($objectService), $preflight, $this->createMock(LoggerInterface::class));
		$response = $controller->send('nl1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertNotNull($objectService->saved['sentAt']);
	}//end testSendStampsSentAtWhenTheAudienceIsNonEmpty()
}//end class
