<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalIdentityController;
use OCA\Portaliq\Service\CaseTypeReader;
use OCA\Portaliq\Service\Identity\PortalAccessRequestService;
use OCA\Portaliq\Service\Identity\PortalChallengeService;
use OCA\Portaliq\Service\Identity\PortalInvitationService;
use OCA\Portaliq\Service\Identity\PortalReferenceLinkService;
use OCA\Portaliq\Service\Identity\PortalRegistrationPolicyService;
use OCA\Portaliq\Service\Identity\PortalSelfServiceService;
use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * portal-identity-and-the-organisations-cases, the refusals: a portal with
 * registration off offers none, a submission that did not solve the challenge
 * creates nothing, a case type that declares `account` only never offers the
 * reference route, and every account surface refuses a caller with no session.
 *
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
 */
class PortalIdentityControllerTest extends TestCase {

	/**
	 * The doubles the controller under test is built from.
	 *
	 * @var array<string, mixed>
	 */
	private array $doubles = [];

	public function testRegistrationSwitchedOffCreatesNoAccount(): void {
		$controller = $this->controller(site: ['organisation' => 'gemeente-x']);
		$this->doubles['policy']->method('isOffered')->willReturn(false);
		$this->doubles['accounts']->expects($this->never())->method('provision');

		$response = $controller->register(email: 'ans@example.org');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'registration_off'], $response->getData());

	}//end testRegistrationSwitchedOffCreatesNoAccount()

	public function testAnUnsolvedChallengeCreatesNoAccount(): void {
		$controller = $this->controller(site: ['organisation' => 'gemeente-x']);
		$this->doubles['policy']->method('isOffered')->willReturn(true);
		$this->doubles['challenge']->method('accepts')->willReturn(false);
		$this->doubles['accounts']->expects($this->never())->method('provision');

		$response = $controller->register(email: 'ans@example.org', nonce: 'nonce-1', solution: 'nonsense');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'challenge_failed'], $response->getData());

	}//end testAnUnsolvedChallengeCreatesNoAccount()

	public function testAnAddressOutsideTheAllowedDomainsCreatesNoAccount(): void {
		$controller = $this->controller(site: ['organisation' => 'gemeente-x']);
		$this->doubles['policy']->method('isOffered')->willReturn(true);
		$this->doubles['challenge']->method('accepts')->willReturn(true);
		$this->doubles['policy']->method('decide')->willReturn(['accepted' => false, 'reason' => 'domain_not_allowed', 'status' => '']);
		$this->doubles['accounts']->expects($this->never())->method('provision');

		$response = $controller->register(email: 'ans@example.org');

		$this->assertSame(['error' => 'domain_not_allowed'], $response->getData());

	}//end testAnAddressOutsideTheAllowedDomainsCreatesNoAccount()

	public function testAnAcceptedRegistrationWaitsAndSaysSo(): void {
		$controller = $this->controller(site: ['organisation' => 'gemeente-x']);
		$this->doubles['policy']->method('isOffered')->willReturn(true);
		$this->doubles['challenge']->method('accepts')->willReturn(true);
		$this->doubles['policy']->method('decide')->willReturn(['accepted' => true, 'reason' => 'approval', 'status' => 'pending']);
		$this->doubles['accounts']->method('provision')->willReturn(['subjectRef' => 'subject-1', 'isNew' => true, 'status' => 'pending']);

		$data = $controller->register(email: 'ans@example.org')->getData();

		$this->assertSame('pending', $data['status']);
		$this->assertSame('approval', $data['awaiting']);
		// The account cannot sign in yet, so its reference is not handed out.
		$this->assertArrayNotHasKey('subjectRef', $data);

	}//end testAnAcceptedRegistrationWaitsAndSaysSo()

	public function testACaseTypeThatDeclaresAccountOnlyOffersNoReferenceLink(): void {
		$controller = $this->controller(site: ['organisation' => 'gemeente-x']);
		$this->doubles['caseTypes']->method('readCaseType')->willReturn(['portalIdentityKind' => ['account']]);
		$this->doubles['references']->method('admitsReference')->willReturn(false);
		$this->doubles['references']->expects($this->never())->method('issue');

		$response = $controller->requestReferenceLink(register: 'portaliq', schema: 'portalCaseType', caseType: 'vergunning', caseReference: 'ZAAK-1', email: 'ans@example.org');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'route_not_offered'], $response->getData());

	}//end testACaseTypeThatDeclaresAccountOnlyOffersNoReferenceLink()

	public function testTheIssuedSecretNeverTravelsInTheAnswer(): void {
		$controller = $this->controller(site: ['organisation' => 'gemeente-x']);
		$this->doubles['caseTypes']->method('readCaseType')->willReturn(['portalIdentityKind' => ['reference']]);
		$this->doubles['references']->method('admitsReference')->willReturn(true);
		$this->doubles['references']->method('issue')->willReturn(['token' => 'secret-1', 'expiresAt' => '2026-09-19T09:00:00+00:00']);

		$data = $controller->requestReferenceLink(register: 'portaliq', schema: 'portalCaseType', caseType: 'melding', caseReference: 'ZAAK-1', email: 'ans@example.org')->getData();

		$this->assertTrue($data['sent']);
		$this->assertArrayNotHasKey('token', $data);

	}//end testTheIssuedSecretNeverTravelsInTheAnswer()

	public function testEveryAccountSurfaceRefusesACallerWithNoSession(): void {
		$controller = $this->controller(site: ['organisation' => 'gemeente-x'], subject: null);
		$this->doubles['selfService']->expects($this->never())->method('updateDetails');
		$this->doubles['selfService']->expects($this->never())->method('removeAccount');
		$this->doubles['accessRequests']->expects($this->never())->method('request');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->updateDetails(displayName: 'Iemand anders')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->removeAccount()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->requestAccess(reason: 'omdat')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->myAccessRequests()->getStatus());

	}//end testEveryAccountSurfaceRefusesACallerWithNoSession()

	public function testTheConfirmationSecretIsNotReadableFromTheOldSession(): void {
		$controller = $this->controller(site: ['organisation' => 'gemeente-x'], subject: ['subjectRef' => 'subject-1', 'organisation' => 'gemeente-x']);
		$this->doubles['selfService']->method('updateDetails')->willReturn(['updated' => true, 'confirmationToken' => 'secret-1']);

		$data = $controller->updateDetails(email: 'nieuw@example.org')->getData();

		$this->assertTrue($data['confirmationPending']);
		$this->assertArrayNotHasKey('confirmationToken', $data);

	}//end testTheConfirmationSecretIsNotReadableFromTheOldSession()

	/**
	 * The controller over doubles, all of which can only answer methods the
	 * real classes have.
	 *
	 * @param array<string, mixed>|null $site The portal being visited.
	 * @param array<string, mixed>|null $subject The resolved subject.
	 *
	 * @return PortalIdentityController
	 */
	private function controller(?array $site, ?array $subject = null): PortalIdentityController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);

		$portals = $this->double(PortalResolver::class, ['resolve']);
		$portals->method('resolve')->willReturn($site);

		$session = $this->double(PortalSessionService::class, ['resolveFromBearer']);
		$session->method('resolveFromBearer')->willReturn($subject);

		$this->doubles = [
			'challenge' => $this->double(PortalChallengeService::class, ['issue', 'accepts']),
			'references' => $this->double(PortalReferenceLinkService::class, ['admitsReference', 'issue', 'redeem']),
			'policy' => $this->double(PortalRegistrationPolicyService::class, ['isOffered', 'decide']),
			'invitations' => $this->double(PortalInvitationService::class, ['accept']),
			'selfService' => $this->double(PortalSelfServiceService::class, ['updateDetails', 'confirmEmail', 'removeAccount']),
			'accessRequests' => $this->double(PortalAccessRequestService::class, ['request', 'madeBy']),
			'accounts' => $this->double(PortalAccountService::class, ['provision']),
			'caseTypes' => $this->double(CaseTypeReader::class, ['readCaseType']),
		];

		return new PortalIdentityController(
			$request,
			$portals,
			$session,
			$this->doubles['challenge'],
			$this->doubles['references'],
			$this->doubles['policy'],
			$this->doubles['invitations'],
			$this->doubles['selfService'],
			$this->doubles['accessRequests'],
			$this->doubles['accounts'],
			$this->doubles['caseTypes']
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
