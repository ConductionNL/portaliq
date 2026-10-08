<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalDraftController;
use OCA\Portaliq\Service\Intake\PortalDraftStore;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Tests\Unit\Service\Identity\PortalIdentityStoreTrait;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * site-multi-step-forms T8 (REQ-SMF-021): the draft routes take the subject
 * from the bearer only, so one resident never reads or deletes another's.
 *
 * @spec openspec/changes/site-multi-step-forms/tasks.md#T8
 */
#[CoversClass(PortalDraftController::class)]
class PortalDraftControllerTest extends TestCase {
	use PortalIdentityStoreTrait;

	protected function setUp(): void {
		$this->rows = [];
	}//end setUp()

	public function testADraftIsOnlyItsOwnersToRead(): void {
		$this->controller('sanne')->save('dossiq', 'woo', ['a' => '1'], 'step-2', 30);

		$this->assertSame(Http::STATUS_OK, $this->controller('sanne')->show('dossiq', 'woo')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller('ahmed')->show('dossiq', 'woo')->getStatus());
	}//end testADraftIsOnlyItsOwnersToRead()

	public function testSignedOutIsRefusedOnEveryRoute(): void {
		$controller = $this->controller('');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->show('dossiq', 'woo')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->save('dossiq', 'woo', [], '', 30)->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->discard('dossiq', 'woo')->getStatus());
		$this->assertSame([], $this->storedRows('portalDraft'));
	}//end testSignedOutIsRefusedOnEveryRoute()

	public function testSendingDeletesTheDraft(): void {
		$this->controller('sanne')->save('dossiq', 'woo', ['a' => '1'], 'step-2', 30);
		$this->controller('ahmed')->discard('dossiq', 'woo');
		$this->assertCount(1, $this->storedRows('portalDraft'));

		$this->controller('sanne')->discard('dossiq', 'woo');
		$this->assertSame([], $this->storedRows('portalDraft'));
	}//end testSendingDeletesTheDraft()

	private function controller(string $subjectRef): PortalDraftController {
		$session = $this->getMockBuilder(PortalSessionService::class)
			->disableOriginalConstructor()
			->onlyMethods(['resolveFromBearer'])
			->getMock();
		$session->method('resolveFromBearer')->willReturn($subjectRef === '' ? null : ['subjectRef' => $subjectRef]);

		$request = $this->createMock(IRequest::class);
		$store = new PortalDraftStore($this->fakeReader(), $this->fakeWriter());

		return new PortalDraftController($request, $session, $store);
	}//end controller()
}//end class
