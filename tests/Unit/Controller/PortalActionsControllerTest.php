<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Controller\PortalActionsController;
use OCA\Portaliq\Service\Actions\PortalActionService;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * 401 without a session, the outcomes as statuses, and only the action
 * fields reach the service.
 *
 * @spec openspec/changes/personal-action-list/tasks.md#t02
 */
class PortalActionsControllerTest extends TestCase {

	private function controller(?array $subject, PortalActionService $actions, array $params=[]): PortalActionsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn($subject);

		return new PortalActionsController($request, $session, $actions);
	}

	public function testWithoutASessionNothingIsAsked(): void {
		$actions = $this->createMock(PortalActionService::class);
		$actions->expects($this->never())->method($this->anything());
		$controller = $this->controller(subject: null, actions: $actions);
		$this->assertInstanceOf(PortalProtected::class, $controller);
		foreach ([$controller->index(), $controller->create(), $controller->update('a'), $controller->destroy('a'), $controller->history('a'), $controller->attach('a'), $controller->file('a')] as $response) {
			$this->assertSame(401, $response->getStatus());
		}
	}

	public function testOutcomesBecomeStatuses(): void {
		$actions = $this->createMock(PortalActionService::class);
		$actions->method('delete')->willReturnOnConsecutiveCalls('ok', 'forbidden', 'not_found', 'invalid');
		$actions->method('history')->willReturn(null);
		$actions->method('file')->willReturn(null);
		$actions->method('create')->willReturn(['status' => 'ok', 'id' => 'a1']);
		$controller = $this->controller(subject: ['subjectRef' => 'sanne'], actions: $actions);

		$this->assertSame([200, 403, 404, 400], array_map(static fn ($r): int => $r->getStatus(), [$controller->destroy('a'), $controller->destroy('a'), $controller->destroy('a'), $controller->destroy('a')]));
		$this->assertSame(404, $controller->history('someone-elses')->getStatus());
		$this->assertSame(404, $controller->file('someone-elses')->getStatus());
		$created = $controller->create();
		$this->assertSame([201, ['id' => 'a1']], [$created->getStatus(), $created->getData()]);
	}

	public function testOnlyTheActionFieldsReachTheService(): void {
		$actions = $this->createMock(PortalActionService::class);
		$actions->expects($this->once())->method('update')
			->with(['subjectRef' => 'mark'], 'a1', ['status' => 'doing'], 'p1')
			->willReturn('ok');
		$controller = $this->controller(subject: ['subjectRef' => 'mark'], actions: $actions, params: ['status' => 'doing', 'owner' => 'mark', 'plan' => 'p1', 'editedBy' => 'x', 'id' => 'a1']);

		$this->assertSame(200, $controller->update('a1', 'p1')->getStatus());
	}
}
