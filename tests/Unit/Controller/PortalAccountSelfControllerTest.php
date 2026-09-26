<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalAccountSelfController;
use OCA\Portaliq\Service\Identity\PortalAccessRequestService;
use OCA\Portaliq\Service\Identity\PortalSelfServiceService;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * portal-identity-and-the-organisations-cases, the bearer's own account:
 * every surface refuses a caller with no session, and the confirmation secret
 * for a new address is never readable from the old one.
 *
 * Moved here with PortalAccountSelfController when it was split out of
 * PortalIdentityController.
 *
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
 */
class PortalAccountSelfControllerTest extends TestCase {

	/**
	 * The doubles the controller under test is built from.
	 *
	 * @var array<string, mixed>
	 */
	private array $doubles = [];

	public function testEveryAccountSurfaceRefusesACallerWithNoSession(): void {
		$controller = $this->controller(subject: null);
		$this->doubles['selfService']->expects($this->never())->method('updateDetails');
		$this->doubles['selfService']->expects($this->never())->method('removeAccount');
		$this->doubles['accessRequests']->expects($this->never())->method('request');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->updateDetails(displayName: 'Iemand anders')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->removeAccount()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->requestAccess(reason: 'omdat')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->myAccessRequests()->getStatus());

	}//end testEveryAccountSurfaceRefusesACallerWithNoSession()

	public function testTheConfirmationSecretIsNotReadableFromTheOldSession(): void {
		$controller = $this->controller(subject: ['subjectRef' => 'subject-1', 'organisation' => 'gemeente-x']);
		$this->doubles['selfService']->method('updateDetails')->willReturn(['updated' => true, 'confirmationToken' => 'secret-1']);

		$data = $controller->updateDetails(email: 'nieuw@example.org')->getData();

		$this->assertTrue($data['confirmationPending']);
		$this->assertArrayNotHasKey('confirmationToken', $data);

	}//end testTheConfirmationSecretIsNotReadableFromTheOldSession()

	/**
	 * notification-preferences-per-role: the channel opt-out is forwarded
	 * to the service exactly as given, alongside the other optional fields.
	 *
	 * @return void
	 */
	public function testTheChannelPreferenceIsForwardedToTheService(): void {
		$controller = $this->controller(subject: ['subjectRef' => 'subject-1', 'organisation' => 'gemeente-x']);
		$this->doubles['selfService']->expects($this->once())
			->method('updateDetails')
			->with(
				$this->equalTo(value: 'subject-1'),
				$this->equalTo(value: ''),
				$this->equalTo(value: ''),
				$this->equalTo(value: false)
			)
			->willReturn(['updated' => true, 'confirmationToken' => '']);

		$response = $controller->updateDetails(emailNotifications: false);

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());

	}//end testTheChannelPreferenceIsForwardedToTheService()

	/**
	 * The controller over doubles, all of which can only answer methods the
	 * real classes have.
	 *
	 * @param array<string, mixed>|null $subject The resolved subject.
	 *
	 * @return PortalAccountSelfController
	 */
	private function controller(?array $subject): PortalAccountSelfController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);

		$session = $this->double(PortalSessionService::class, ['resolveFromBearer']);
		$session->method('resolveFromBearer')->willReturn($subject);

		$this->doubles = [
			'selfService' => $this->double(PortalSelfServiceService::class, ['updateDetails', 'confirmEmail', 'removeAccount']),
			'accessRequests' => $this->double(PortalAccessRequestService::class, ['request', 'madeBy']),
		];

		return new PortalAccountSelfController(
			$request,
			$session,
			$this->doubles['selfService'],
			$this->doubles['accessRequests']
		);
	}//end controller()

	/**
	 * A double of one class, limited to the methods it really has.
	 *
	 * @param string $class The class to double.
	 * @param array<int, string> $methods The methods to stub.
	 *
	 * @return mixed
	 */
	private function double(string $class, array $methods): mixed {
		return $this->getMockBuilder($class)
			->disableOriginalConstructor()
			->onlyMethods($methods)
			->getMock();
	}//end double()

}//end class
