<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalAccountSelfController;
use OCA\Portaliq\Service\Identity\PortalAccessRequestService;
use OCA\Portaliq\Service\Identity\PortalIdentityMailer;
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
	 * portaliq#795. The confirmation secret used to be minted and dropped, so
	 * a new address could never be confirmed. It now goes to the NEW address,
	 * inside the mailed link, and the answer says only that it went.
	 *
	 * @return void
	 */
	public function testTheConfirmationSecretIsMailedToTheNewAddress(): void {
		$controller = $this->controller(subject: ['subjectRef' => 'subject-1', 'organisation' => 'gemeente-x']);
		$this->doubles['selfService']->method('updateDetails')->willReturn(['updated' => true, 'confirmationToken' => 'secret-1']);
		$this->doubles['mailer']->expects($this->once())
			->method('send')
			->with(
				$this->equalTo(PortalIdentityMailer::TEMPLATE_EMAIL_CONFIRMATION),
				$this->equalTo('nieuw@example.org'),
				$this->equalTo('secret-1'),
				$this->equalTo('gemeente-x')
			)
			->willReturn(true);

		$data = $controller->updateDetails(email: 'nieuw@example.org')->getData();

		$this->assertSame(['updated' => true, 'confirmationPending' => true, 'confirmationSent' => true], $data);
		$this->assertStringNotContainsString('secret-1', (string)json_encode($data));

	}//end testTheConfirmationSecretIsMailedToTheNewAddress()

	/**
	 * portaliq#795. A confirmation mail that did not leave is said so, so the
	 * page can ask to try again; the secret never falls back into the answer.
	 *
	 * @return void
	 */
	public function testAFailedConfirmationMailIsReportedWithoutTheSecret(): void {
		$controller = $this->controller(subject: ['subjectRef' => 'subject-1', 'organisation' => 'gemeente-x']);
		$this->doubles['selfService']->method('updateDetails')->willReturn(['updated' => true, 'confirmationToken' => 'secret-1']);
		$this->doubles['mailer']->method('send')->willReturn(false);

		$data = $controller->updateDetails(email: 'nieuw@example.org')->getData();

		$this->assertSame(['updated' => true, 'confirmationPending' => true, 'confirmationSent' => false], $data);

	}//end testAFailedConfirmationMailIsReportedWithoutTheSecret()

	/**
	 * A change that parks no new address mails nothing.
	 *
	 * @return void
	 */
	public function testANameChangeSendsNoMail(): void {
		$controller = $this->controller(subject: ['subjectRef' => 'subject-1', 'organisation' => 'gemeente-x']);
		$this->doubles['selfService']->method('updateDetails')->willReturn(['updated' => true, 'confirmationToken' => '']);
		$this->doubles['mailer']->expects($this->never())->method('send');

		$data = $controller->updateDetails(displayName: 'Anna')->getData();

		$this->assertFalse($data['confirmationPending']);

	}//end testANameChangeSendsNoMail()

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
	 * The message language reaches the service as given (translated-message-notice).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-a-guardian-chooses-the-language-messages-are-shown-in
	 */
	public function testTheMessageLanguageIsForwardedToTheService(): void {
		$controller = $this->controller(subject: ['subjectRef' => 'subject-1', 'organisation' => 'school-x']);
		$this->doubles['selfService']->expects($this->once())
			->method('updateDetails')
			->with('subject-1', '', '', null, 'tr')
			->willReturn(['updated' => true, 'confirmationToken' => '']);

		$this->assertSame(Http::STATUS_OK, $controller->updateDetails(messageLanguage: 'tr')->getStatus());

	}//end testTheMessageLanguageIsForwardedToTheService()

	/**
	 * The details come from the bearer's own subject only; no subject is 401,
	 * no account is 404.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-a-guardian-chooses-the-language-messages-are-shown-in
	 */
	public function testDetailsAreTheBearersOwn(): void {
		$details = ['displayName' => 'Ans', 'email' => 'a@example.org', 'emailNotifications' => true, 'messageLanguage' => 'ar'];

		$controller = $this->controller(subject: ['subjectRef' => 'subject-1', 'organisation' => 'school-x']);
		$this->doubles['selfService']->expects($this->once())->method('details')->with('subject-1')->willReturn($details);
		$response = $controller->details();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($details, $response->getData());

		$missing = $this->controller(subject: ['subjectRef' => 'subject-2', 'organisation' => 'school-x']);
		$this->doubles['selfService']->method('details')->willReturn(null);
		$this->assertSame(Http::STATUS_NOT_FOUND, $missing->details()->getStatus());

		$anonymous = $this->controller(subject: null);
		$this->doubles['selfService']->expects($this->never())->method('details');
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $anonymous->details()->getStatus());

	}//end testDetailsAreTheBearersOwn()

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
			'selfService' => $this->double(PortalSelfServiceService::class, ['updateDetails', 'confirmEmail', 'removeAccount', 'details']),
			'accessRequests' => $this->double(PortalAccessRequestService::class, ['request', 'madeBy']),
			'mailer' => $this->double(PortalIdentityMailer::class, ['send']),
		];

		return new PortalAccountSelfController(
			$request,
			$session,
			$this->doubles['selfService'],
			$this->doubles['accessRequests'],
			$this->doubles['mailer']
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
