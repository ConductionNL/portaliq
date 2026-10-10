<?php

/**
 * Portaliq Portal Plans Controller Test
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
 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t02
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Controller\PortalPlansController;
use OCA\Portaliq\Service\Plans\PortalPlanService;
use OCA\Portaliq\Service\PortalPdfExport;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * 401 without a session, the outcomes as statuses, only the allowed fields
 * reach the service, and the PDF is refused to a stranger.
 *
 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md
 */
class PortalPlansControllerTest extends TestCase {

	/**
	 * The controller over doubles.
	 *
	 * @param array<string, mixed>|null $subject The session's subject.
	 * @param PortalPlanService $plans The plan rules.
	 * @param array<string, mixed> $params What the request carries.
	 * @param PortalPdfExport|null $pdf The renderer.
	 *
	 * @return PortalPlansController
	 */
	private function controller(?array $subject, PortalPlanService $plans, array $params=[], ?PortalPdfExport $pdf=null): PortalPlansController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn($subject);
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('resolve')->willReturn(['slug' => 'zuid']);

		return new PortalPlansController($request, $session, $plans, $portals, $pdf);
	}//end controller()

	/**
	 * Without a session every route answers 401 and the service is not asked.
	 *
	 * @return void
	 */
	public function testWithoutASessionNothingIsAsked(): void {
		$plans = $this->createMock(PortalPlanService::class);
		$plans->expects($this->never())->method($this->anything());
		$controller = $this->controller(subject: null, plans: $plans);
		$this->assertInstanceOf(PortalProtected::class, $controller);
		$responses = [
			$controller->index(), $controller->templates(), $controller->start('t'), $controller->show('1'), $controller->update('1'), $controller->destroy('1'),
			$controller->addParticipants('1'), $controller->removeParticipant('1', 'x'), $controller->addAction('1'), $controller->updateAction('1', 'a'), $controller->pdf('1'),
		];
		foreach ($responses as $response) {
			$this->assertSame(401, $response->getStatus());
		}
	}//end testWithoutASessionNothingIsAsked()

	/**
	 * Outcomes become statuses, and an unknown plan reads the same as someone else's.
	 *
	 * @return void
	 */
	public function testOutcomesBecomeStatuses(): void {
		$plans = $this->createMock(PortalPlanService::class);
		$plans->method('detail')->willReturn(null);
		$plans->method('delete')->willReturnOnConsecutiveCalls('ok', 'forbidden', 'not_found', 'failed');
		$plans->method('start')->willReturnOnConsecutiveCalls(['status' => 'ok', 'id' => 'p1'], ['status' => 'forbidden', 'id' => ''], ['status' => 'invalid', 'id' => '']);
		$controller = $this->controller(subject: ['subjectRef' => 'sanne', 'organisation' => 'o'], plans: $plans);
		$this->assertSame(404, $controller->show('someone-elses')->getStatus());
		$this->assertSame([200, 403, 404, 500], array_map(static fn ($r): int => $r->getStatus(), [$controller->destroy('1'), $controller->destroy('1'), $controller->destroy('1'), $controller->destroy('1')]));
		$created = $controller->start('t1', '', ['c1']);
		$this->assertSame(201, $created->getStatus());
		$this->assertSame(['id' => 'p1'], $created->getData());
		$this->assertSame(403, $controller->start('t1', '', ['nobody'])->getStatus());
		$this->assertSame(400, $controller->start('', '')->getStatus());
	}//end testOutcomesBecomeStatuses()

	/**
	 * Only the allowed fields reach the service: an owner or a plan cannot be set from the request.
	 *
	 * @return void
	 */
	public function testOnlyAllowedFieldsReachTheService(): void {
		$seen = [];
		$plans = $this->createMock(PortalPlanService::class);
		$plans->method('update')->willReturnCallback(function (array $subject, string $id, array $changes) use (&$seen): string {
			$seen['plan'] = $changes;
			return 'ok';
		});
		$plans->method('updateAction')->willReturnCallback(function (array $subject, string $id, string $actionId, array $data) use (&$seen): string {
			$seen['action'] = $data;
			return 'ok';
		});
		$params = ['goal' => 'g', 'owner' => 'mark', 'participants' => ['mark'], 'plan' => 'other', 'status' => 'done', 'id' => 'x', 'portal' => 'zuid'];
		$controller = $this->controller(subject: ['subjectRef' => 'sanne', 'organisation' => 'o'], plans: $plans, params: $params);
		$controller->update('p1');
		$controller->updateAction('p1', 'a1');
		$this->assertSame(['goal' => 'g', 'status' => 'done'], $seen['plan']);
		$this->assertSame(['status' => 'done'], $seen['action']);
	}//end testOnlyAllowedFieldsReachTheService()

	/**
	 * The PDF is refused to a stranger, and answers 503 when OpenRegister cannot render.
	 *
	 * @return void
	 */
	public function testThePdf(): void {
		$plans = $this->createMock(PortalPlanService::class);
		$plans->method('pdfRows')->willReturnOnConsecutiveCalls(null, ['title' => 'Plan', 'columns' => [], 'rows' => []], ['title' => 'Plan', 'columns' => [], 'rows' => []]);
		$subject = ['subjectRef' => 'sanne', 'organisation' => 'o'];
		$this->assertSame(404, $this->controller(subject: $subject, plans: $plans)->pdf('stranger')->getStatus());
		$this->assertSame(503, $this->controller(subject: $subject, plans: $plans)->pdf('p1')->getStatus(), 'no renderer');
		$pdf = $this->createMock(PortalPdfExport::class);
		$pdf->method('available')->willReturn(true);
		$pdf->method('render')->willReturn('%PDF-1.4 plan');
		$response = $this->controller(subject: $subject, plans: $plans, pdf: $pdf)->pdf('p1');
		$this->assertInstanceOf(\OCA\Portaliq\Http\PdfDownloadResponse::class, $response);
		$this->assertSame('plan.pdf', $response->fileName());
	}//end testThePdf()
}//end class
