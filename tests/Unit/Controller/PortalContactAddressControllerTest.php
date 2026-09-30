<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalContactAddressController;
use OCA\Portaliq\Service\Identity\PortalContactAddressService;
use OCA\Portaliq\Service\Identity\PortalIdentityMailer;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * identity-profile-page: every address route refuses a caller with no
 * session, a new e-mail address gets its mail, and the secret never comes
 * back in the answer.
 *
 * @spec openspec/changes/identity-profile-page/specs/portal-profile/spec.md#requirement-a-new-e-mail-address-is-confirmed-before-it-is-used-req-ipp-002
 */
class PortalContactAddressControllerTest extends TestCase {

	/**
	 * The service double.
	 *
	 * @var PortalContactAddressService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $addresses;

	/**
	 * The mailer double.
	 *
	 * @var PortalIdentityMailer&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $mailer;

	public function testEveryRouteRefusesACallerWithNoSession(): void {
		$controller = $this->controller(subject: null);
		$this->addresses->expects($this->never())->method($this->anything());

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->add(kind: 'email', value: 'x@example.nl')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->prefer(kind: 'email', value: 'x@example.nl')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->remove(kind: 'email', value: 'x@example.nl')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->channel(channel: 'post')->getStatus());

	}//end testEveryRouteRefusesACallerWithNoSession()

	public function testANewAddressIsMailedAndTheSecretIsNotAnswered(): void {
		$controller = $this->controller(subject: ['subjectRef' => 'subject-1', 'organisation' => 'gemeente-x']);
		$this->addresses->expects($this->once())->method('addAddress')
			->with('subject-1', 'email', 'new@example.nl')
			->willReturn(['refusal' => '', 'value' => 'new@example.nl', 'confirmationToken' => 'secret-1']);
		$this->mailer->expects($this->once())->method('send')
			->with(PortalIdentityMailer::TEMPLATE_EMAIL_CONFIRMATION, 'new@example.nl', 'secret-1', 'gemeente-x')
			->willReturn(true);

		$data = $controller->add(kind: 'email', value: 'new@example.nl')->getData();

		$this->assertTrue($data['confirmationPending']);
		$this->assertTrue($data['confirmationSent']);
		$this->assertStringNotContainsString('secret-1', (string)json_encode($data));

	}//end testANewAddressIsMailedAndTheSecretIsNotAnswered()

	public function testAPhoneNumberSendsNoMail(): void {
		$controller = $this->controller(subject: ['subjectRef' => 'subject-1', 'organisation' => 'gemeente-x']);
		$this->addresses->method('addAddress')->willReturn(['refusal' => '', 'value' => '+31612345678', 'confirmationToken' => '']);
		$this->mailer->expects($this->never())->method('send');

		$this->assertFalse($controller->add(kind: 'phone', value: '0612345678')->getData()['confirmationPending']);

	}//end testAPhoneNumberSendsNoMail()

	public function testARefusalNamesItsReason(): void {
		$controller = $this->controller(subject: ['subjectRef' => 'subject-1', 'organisation' => 'gemeente-x']);
		$this->addresses->method('preferAddress')->willReturn('confirm_first');
		$this->addresses->method('chooseChannel')->willReturn('');

		$refused = $controller->prefer(kind: 'email', value: 'c@example.nl');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $refused->getStatus());
		$this->assertSame(['error' => 'confirm_first'], $refused->getData());
		$this->assertSame(['channel' => 'post'], $controller->channel(channel: 'post')->getData());

	}//end testARefusalNamesItsReason()

	/**
	 * The controller under test.
	 *
	 * @param array<string, mixed>|null $subject What the bearer resolves to.
	 *
	 * @return PortalContactAddressController
	 */
	private function controller(?array $subject): PortalContactAddressController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn($subject === null ? '' : 'Bearer token-1');
		$session = $this->getMockBuilder(PortalSessionService::class)
			->disableOriginalConstructor()
			->onlyMethods(['resolveFromBearer'])
			->getMock();
		$session->method('resolveFromBearer')->willReturn($subject);
		$this->addresses = $this->getMockBuilder(PortalContactAddressService::class)
			->disableOriginalConstructor()
			->onlyMethods(['addAddress', 'preferAddress', 'removeAddress', 'chooseChannel'])
			->getMock();
		$this->mailer = $this->getMockBuilder(PortalIdentityMailer::class)
			->disableOriginalConstructor()
			->onlyMethods(['send'])
			->getMock();

		return new PortalContactAddressController($request, $session, $this->addresses, $this->mailer);
	}//end controller()
}//end class
