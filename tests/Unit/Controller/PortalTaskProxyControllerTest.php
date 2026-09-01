<?php

/**
 * Tests for the resident-facing task proxy and the contributions announcement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Controller\ContributionController;
use OCA\Portaliq\Controller\PortalTaskProxyController;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\NotificationDispatchService;
use OCA\Portaliq\Service\PortalActionForwarder;
use OCA\Portaliq\Service\PortalAuditHook;
use OCA\Portaliq\Service\PortalFileReader;
use OCA\Portaliq\Service\PortalFileWriter;
use OCA\Portaliq\Service\PortalInboxReader;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalSchemaReader;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\PortalTaskGateway;
use OCA\Portaliq\Service\SubmissionReceiptService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The proxy's fail-closed edge and refusal mapping (design D-3): no bearer
 * means 401 with ZERO forwards (the mutation check — an unauthenticated or
 * unmatched party sees nothing); the seam's named refusals pass through with
 * their codes; a seam 401 (our assertion refused: a configuration defect)
 * becomes 503; a transport failure becomes 502. Plus the contributions
 * announcement: `tasks.enabled` for authenticated subjects only, and never on
 * the anonymous aggregate.
 *
 * @spec openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-the-task-proxy-is-the-only-path-and-the-assertion-never-reaches-the-browser
 * @spec openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-mijn-taken-lists-details-and-completes-the-partys-open-tasks
 */
class PortalTaskProxyControllerTest extends TestCase {
	private const SUBJECT = ['subjectRef' => 's1', 'audience' => 'client', 'organisation' => 'org-1', 'trust' => 'substantial'];

	/**
	 * No bearer: 401, and the gateway is NEVER consulted — an
	 * unauthenticated caller triggers no forward and learns nothing.
	 */
	public function testNoBearerIsRefusedWithZeroForwards(): void {
		$gateway = $this->createMock(PortalTaskGateway::class);
		$gateway->expects($this->never())->method('listTasks');
		$gateway->expects($this->never())->method('getTask');
		$gateway->expects($this->never())->method('completeTask');

		$controller = $this->controller(subject: null, gateway: $gateway);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->index()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->show('t-1')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->complete('t-1')->getStatus());
	}//end testNoBearerIsRefusedWithZeroForwards()

	/**
	 * A successful list is relayed as-is.
	 */
	public function testAListIsRelayed(): void {
		$gateway = $this->createMock(PortalTaskGateway::class);
		$gateway->method('listTasks')->willReturn(['status' => 200, 'body' => ['results' => [['uuid' => 't-1']], 'total' => 1]]);

		$response = $this->controller(subject: self::SUBJECT, gateway: $gateway)->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $response->getData()['total']);
	}//end testAListIsRelayed()

	/**
	 * The seam's named refusals pass through with their status and code:
	 * an unmatched party keeps the unrevealing 404, a violated upload
	 * constraint stays 400, a terminal task stays 409.
	 */
	public function testNamedRefusalsPassThrough(): void {
		$cases = [
			[404, 'no-such-task'],
			[400, 'upload-constraint'],
			[409, 'task-closed'],
		];
		foreach ($cases as [$status, $code]) {
			$gateway = $this->createMock(PortalTaskGateway::class);
			$gateway->method('getTask')->willReturn(['status' => $status, 'body' => ['error' => 'x', 'code' => $code]]);

			$response = $this->controller(subject: self::SUBJECT, gateway: $gateway)->show('t-1');

			$this->assertSame($status, $response->getStatus());
			$this->assertSame($code, $response->getData()['code']);
		}
	}//end testNamedRefusalsPassThrough()

	/**
	 * A seam 401 refused OUR assertion — a configuration defect, not the
	 * resident's session — and becomes 503 `task-service-unavailable`, never
	 * a relayed "log in again".
	 */
	public function testASeamUnauthorizedBecomesUnavailable(): void {
		$gateway = $this->createMock(PortalTaskGateway::class);
		$gateway->method('listTasks')->willReturn(['status' => 401, 'body' => ['error' => 'No acting portal subject', 'code' => 'portal-subject-invalid']]);

		$response = $this->controller(subject: self::SUBJECT, gateway: $gateway)->index();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('task-service-unavailable', $response->getData()['code']);
	}//end testASeamUnauthorizedBecomesUnavailable()

	/**
	 * Transport failure (gateway null) becomes 502 `task-service-unreachable`.
	 */
	public function testTransportFailureBecomesBadGateway(): void {
		$gateway = $this->createMock(PortalTaskGateway::class);
		$gateway->method('completeTask')->willReturn(null);

		$response = $this->controller(subject: self::SUBJECT, gateway: $gateway)->complete('t-1');

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertSame('task-service-unreachable', $response->getData()['code']);
	}//end testTransportFailureBecomesBadGateway()

	/**
	 * An authenticated contributions aggregate announces the tasks surface
	 * when (and only when) the seam is available.
	 */
	public function testContributionsAnnounceTasksForAuthenticatedSubjects(): void {
		$gateway = $this->createMock(PortalTaskGateway::class);
		$gateway->method('isAvailable')->willReturn(true);

		$response = $this->contributionController(subject: self::SUBJECT, gateway: $gateway)->index();

		$this->assertTrue($response->getData()['tasks']['enabled']);
	}//end testContributionsAnnounceTasksForAuthenticatedSubjects()

	/**
	 * An unavailable seam (openregister absent / secret unconfigured) is
	 * announced as disabled, so the SPA never shows a dead "Mijn taken".
	 */
	public function testAnUnavailableSeamIsAnnouncedDisabled(): void {
		$gateway = $this->createMock(PortalTaskGateway::class);
		$gateway->method('isAvailable')->willReturn(false);

		$response = $this->contributionController(subject: self::SUBJECT, gateway: $gateway)->index();

		$this->assertFalse($response->getData()['tasks']['enabled']);
	}//end testAnUnavailableSeamIsAnnouncedDisabled()

	/**
	 * The ANONYMOUS aggregate never announces tasks — an unauthenticated
	 * visitor sees no task surface at all (mutation check).
	 */
	public function testTheAnonymousAggregateNeverAnnouncesTasks(): void {
		$gateway = $this->createMock(PortalTaskGateway::class);
		$gateway->method('isAvailable')->willReturn(true);

		$response = $this->contributionController(subject: null, gateway: $gateway)->index();

		$this->assertArrayNotHasKey('tasks', $response->getData());
	}//end testTheAnonymousAggregateNeverAnnouncesTasks()

	/**
	 * Build the proxy controller around a session outcome and a gateway.
	 *
	 * @param array<string, mixed>|null $subject The resolved subject, or null (no bearer).
	 * @param PortalTaskGateway $gateway The gateway mock.
	 */
	private function controller(?array $subject, PortalTaskGateway $gateway): PortalTaskProxyController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('');
		$request->method('getParam')->willReturn(null);
		$request->method('getUploadedFile')->willReturn([]);

		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn($subject);

		return new PortalTaskProxyController($request, $session, $gateway);
	}//end controller()

	/**
	 * Build a ContributionController whose registry serves a minimal
	 * aggregate, wired to the task gateway under test.
	 *
	 * @param array<string, mixed>|null $subject The resolved subject, or null (anonymous).
	 * @param PortalTaskGateway $gateway The gateway mock.
	 */
	private function contributionController(?array $subject, PortalTaskGateway $gateway): ContributionController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('');

		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn($subject);

		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('aggregateFor')->willReturn(['contributions' => []]);
		$registry->method('aggregateAnonymous')->willReturn(['contributions' => []]);

		$inboxReader = $this->createMock(PortalInboxReader::class);
		$inboxReader->method('unreadCount')->willReturn(0);

		return new ContributionController(
			$request,
			$registry,
			$session,
			$this->createMock(PortalObjectReader::class),
			$this->createMock(PortalObjectWriter::class),
			$this->createMock(PortalFileWriter::class),
			$this->createMock(PortalFileReader::class),
			$this->createMock(PortalSchemaReader::class),
			$inboxReader,
			$this->createMock(PortalAuditHook::class),
			$this->createMock(PortalActionForwarder::class),
			$this->createMock(AuditTrailService::class),
			$this->createMock(SubmissionReceiptService::class),
			$this->createMock(NotificationDispatchService::class),
			$this->createMock(LoggerInterface::class),
			$gateway
		);
	}//end contributionController()
}//end class
