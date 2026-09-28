<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalIdentityController;
use OCA\Portaliq\Service\CaseTypeReader;
use OCA\Portaliq\Service\Identity\PortalChallengeService;
use OCA\Portaliq\Service\Identity\PortalIdentityMailer;
use OCA\Portaliq\Service\Identity\PortalReferenceCaseService;
use OCA\Portaliq\Service\Identity\PortalInvitationService;
use OCA\Portaliq\Service\Identity\PortalReferenceLinkService;
use OCA\Portaliq\Service\Identity\PortalRegistrationPolicyService;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
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
 * reference route. The bearer's own account surfaces moved to
 * PortalAccountSelfControllerTest with the controller they belong to.
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

	/**
	 * What PortalReferenceCaseService::linkableCase() answers.
	 *
	 * @var array{register: string, schema: string}|null
	 */
	private ?array $linkable = ['register' => 'dossiq', 'schema' => 'case'];

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

	/**
	 * gate-9, the wiring half. The signature is the credential this endpoint
	 * authenticates on, so it has to reach the service that checks it. A
	 * controller that read it off the request and dropped it would pass every
	 * test above, and refuse nobody.
	 *
	 * @return void
	 */
	public function testTheSignatureTheCallerSentReachesTheChallenge(): void {
		$controller = $this->controller(site: ['organisation' => 'gemeente-x']);
		$this->doubles['policy']->method('isOffered')->willReturn(true);
		$this->doubles['challenge']->expects($this->once())
			->method('accepts')
			->with(
				$this->equalTo(['organisation' => 'gemeente-x']),
				$this->equalTo('registration'),
				$this->anything(),
				$this->equalTo('nonce-1'),
				$this->equalTo('2016'),
				$this->equalTo(1758300000),
				$this->equalTo('signature-1')
			)
			->willReturn(false);

		$response = $controller->register(
			email: 'ans@example.org',
			nonce: 'nonce-1',
			solution: '2016',
			expiresAt: 1758300000,
			signature: 'signature-1'
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testTheSignatureTheCallerSentReachesTheChallenge()

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

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'route_not_offered'], $response->getData());

	}//end testACaseTypeThatDeclaresAccountOnlyOffersNoReferenceLink()

	/**
	 * gate-7. The register, schema and case type are caller-supplied on an
	 * anonymous route, and the read behind them runs with RBAC and
	 * multitenancy off. A triple the portal has published no binding for is
	 * refused before anything is read, so naming another tenant's register
	 * reaches nothing.
	 *
	 * @return void
	 */
	public function testACaseTypeThePortalNeverDeclaredIsNeverRead(): void {
		$controller = $this->controller(site: ['organisation' => 'gemeente-x', 'slug' => 'gemeente-x'], inScope: false);
		$this->doubles['caseTypes']->expects($this->never())->method('readCaseType');
		$this->doubles['references']->expects($this->never())->method('issue');

		$response = $controller->requestReferenceLink(register: 'andere-gemeente', schema: 'zaak', caseType: 'vergunning', caseReference: 'ZAAK-1', email: 'ans@example.org');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'route_not_offered'], $response->getData());

	}//end testACaseTypeThePortalNeverDeclaredIsNeverRead()

	/**
	 * The three refusals are deliberately one answer. Out of the portal's
	 * scope and `account` only must be indistinguishable, or the status code
	 * tells an anonymous caller which registers hold which case types.
	 *
	 * @return void
	 */
	public function testOutOfScopeAndAccountOnlyAnswerTheSame(): void {
		$outside = $this->controller(site: ['organisation' => 'gemeente-x', 'slug' => 'gemeente-x'], inScope: false)
			->requestReferenceLink(register: 'andere-gemeente', schema: 'zaak', caseType: 'vergunning', caseReference: 'ZAAK-1', email: 'ans@example.org');

		$declared = $this->controller(site: ['organisation' => 'gemeente-x', 'slug' => 'gemeente-x']);
		$this->doubles['caseTypes']->method('readCaseType')->willReturn(['portalIdentityKind' => ['account']]);
		$this->doubles['references']->method('admitsReference')->willReturn(false);
		$accountOnly = $declared->requestReferenceLink(register: 'portaliq', schema: 'portalCaseType', caseType: 'vergunning', caseReference: 'ZAAK-1', email: 'ans@example.org');

		$this->assertSame($accountOnly->getStatus(), $outside->getStatus());
		$this->assertSame($accountOnly->getData(), $outside->getData());

	}//end testOutOfScopeAndAccountOnlyAnswerTheSame()

	/**
	 * portaliq#795. The link used to be issued and then dropped: the answer
	 * said `sent: true` and no mail left. The secret now goes to the address
	 * it was asked for, inside the mailed link, and never into the answer.
	 *
	 * @return void
	 */
	public function testTheReferenceLinkIsMailedToTheAddressAndNeverAnswered(): void {
		$site = ['organisation' => 'gemeente-x', 'slug' => 'gemeente-x', 'title' => 'Gemeente X'];
		$controller = $this->controller(site: $site);
		$this->doubles['caseTypes']->method('readCaseType')->willReturn(['portalIdentityKind' => ['reference']]);
		$this->doubles['references']->method('admitsReference')->willReturn(true);
		$this->doubles['references']->method('issue')->willReturn(['token' => 'secret-1', 'expiresAt' => '2026-09-19T09:00:00+00:00']);
		$this->doubles['mailer']->expects($this->once())
			->method('send')
			->with(
				$this->equalTo(PortalIdentityMailer::TEMPLATE_REFERENCE_LINK),
				$this->equalTo('ans@example.org'),
				$this->equalTo('secret-1'),
				$this->equalTo('gemeente-x'),
				$this->equalTo($site)
			)
			->willReturn(true);

		$data = $controller->requestReferenceLink(register: 'portaliq', schema: 'portalCaseType', caseType: 'melding', caseReference: 'ZAAK-1', email: 'ans@example.org')->getData();

		$this->assertTrue($data['sent']);
		$this->assertStringNotContainsString('secret-1', (string)json_encode($data));

	}//end testTheReferenceLinkIsMailedToTheAddressAndNeverAnswered()

	/**
	 * portaliq#795. A mail that did not leave answers exactly like one that
	 * did: a different answer would tell an anonymous caller something about
	 * the case, and the secret never falls back into the answer.
	 *
	 * @return void
	 */
	public function testAFailedReferenceMailAnswersTheSameAndStillHidesTheSecret(): void {
		$controller = $this->controller(site: ['organisation' => 'gemeente-x']);
		$this->doubles['caseTypes']->method('readCaseType')->willReturn(['portalIdentityKind' => ['reference']]);
		$this->doubles['references']->method('admitsReference')->willReturn(true);
		$this->doubles['references']->method('issue')->willReturn(['token' => 'secret-1', 'expiresAt' => '2026-09-19T09:00:00+00:00']);
		$this->doubles['mailer']->method('send')->willReturn(false);

		$response = $controller->requestReferenceLink(register: 'portaliq', schema: 'portalCaseType', caseType: 'melding', caseReference: 'ZAAK-1', email: 'ans@example.org');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['sent' => true, 'expiresAt' => '2026-09-19T09:00:00+00:00'], $response->getData());

	}//end testAFailedReferenceMailAnswersTheSameAndStillHidesTheSecret()

	public function testTheIssuedSecretNeverTravelsInTheAnswer(): void {
		$controller = $this->controller(site: ['organisation' => 'gemeente-x']);
		$this->doubles['caseTypes']->method('readCaseType')->willReturn(['portalIdentityKind' => ['reference']]);
		$this->doubles['references']->method('admitsReference')->willReturn(true);
		$this->doubles['references']->method('issue')->willReturn(['token' => 'secret-1', 'expiresAt' => '2026-09-19T09:00:00+00:00']);

		$data = $controller->requestReferenceLink(register: 'portaliq', schema: 'portalCaseType', caseType: 'melding', caseReference: 'ZAAK-1', email: 'ans@example.org')->getData();

		$this->assertTrue($data['sent']);
		$this->assertArrayNotHasKey('token', $data);

	}//end testTheIssuedSecretNeverTravelsInTheAnswer()


	/**
	 * gate-25, on the route `GET /portal/api/identity/challenge`. The
	 * endpoint answers 404 for a host no portal claims, before it issues
	 * anything, so an unknown host cannot mint a nonce.
	 *
	 * @return void
	 */
	public function testTheChallengeIsNotIssuedForAHostNoPortalClaims(): void {
		$controller = $this->controller(site: null);
		$this->doubles['challenge']->expects($this->never())->method('issue');

		$response = $controller->challenge(surface: 'form');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'portal_not_found'], $response->getData());

	}//end testTheChallengeIsNotIssuedForAHostNoPortalClaims()

	/**
	 * gate-25, the other side of the same route: a known portal gets the
	 * challenge its own service issued, for the surface that was asked for.
	 *
	 * @return void
	 */
	public function testTheChallengeIsIssuedForTheSurfaceThatWasAsked(): void {
		$controller = $this->controller(site: ['organisation' => 'gemeente-x']);
		$this->doubles['challenge']->expects($this->once())
			->method('issue')
			->with(
				$this->equalTo(['organisation' => 'gemeente-x']),
				$this->equalTo('registration')
			)
			->willReturn(['nonce' => 'nonce-1', 'difficulty' => 12]);

		$response = $controller->challenge(surface: 'registration');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['nonce' => 'nonce-1', 'difficulty' => 12], $response->getData());

	}//end testTheChallengeIsIssuedForTheSurfaceThatWasAsked()

	/**
	 * gate-25, on `POST /portal/api/identity/reference-link/redeem`. Used,
	 * expired and unknown are deliberately one answer, so the refusal says
	 * only `link_not_valid` and never which of the three it was.
	 *
	 * @return void
	 */
	public function testARedeemedLinkIsRefusedWithoutSayingWhy(): void {
		$controller = $this->controller(site: ['organisation' => 'gemeente-x']);
		$this->doubles['references']->method('redeem')->willReturn(null);

		$response = $controller->redeemReferenceLink(token: 'secret-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'link_not_valid'], $response->getData());

	}//end testARedeemedLinkIsRefusedWithoutSayingWhy()

	/**
	 * gate-25, the admitting side: a live link answers with the case the
	 * service resolved, and the token is never echoed back.
	 *
	 * @return void
	 */
	public function testALiveLinkAnswersWithTheCaseAndNeverTheToken(): void {
		$controller = $this->controller(site: ['organisation' => 'gemeente-x']);
		$this->doubles['references']->expects($this->once())
			->method('redeem')
			->with($this->equalTo('secret-1'))
			->willReturn(['caseReference' => 'ZAAK-1', 'organisation' => 'gemeente-x', 'register' => 'dossiq', 'schema' => 'zaak', 'linkId' => 'link-1']);
		$this->doubles['sessions']->method('issueReferenceSession')->willReturn(['token' => 'bearer-1', 'jti' => 'jti-1', 'expiresAt' => '2026-09-28T10:30:00+00:00']);

		$data = $controller->redeemReferenceLink(token: 'secret-1')->getData();

		$this->assertSame('ZAAK-1', $data['caseReference']);
		$this->assertArrayNotHasKey('token', $data);
		$this->assertArrayNotHasKey('tokenHash', $data);
		$this->assertArrayNotHasKey('linkId', $data);

	}//end testALiveLinkAnswersWithTheCaseAndNeverTheToken()

	/**
	 * portaliq#796, identity-ways-in-screens D2. A redeemed link used to hand
	 * back the case number and nothing that could read the case. It now opens
	 * a short session scoped to that one case, minted for the link's case,
	 * register and schema, and its bearer is in the answer.
	 *
	 * @return void
	 */
	public function testARedeemedLinkOpensASessionScopedToItsOneCase(): void {
		$controller = $this->controller(site: ['organisation' => 'gemeente-x']);
		$this->doubles['references']->method('redeem')->willReturn(
			['caseReference' => 'Z-2026-0042', 'organisation' => 'gemeente-x', 'register' => 'dossiq', 'schema' => 'case', 'linkId' => 'link-1']
		);
		$this->doubles['sessions']->expects($this->once())
			->method('issueReferenceSession')
			->with(
				$this->equalTo('link-1'),
				$this->equalTo('Z-2026-0042'),
				$this->equalTo('gemeente-x'),
				$this->equalTo('dossiq'),
				$this->equalTo('case')
			)
			->willReturn(['token' => 'bearer-1', 'jti' => 'jti-1', 'expiresAt' => '2026-09-28T10:30:00+00:00']);

		$response = $controller->redeemReferenceLink(token: 'secret-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['caseReference' => 'Z-2026-0042', 'organisation' => 'gemeente-x', 'bearer' => 'bearer-1', 'expiresAt' => '2026-09-28T10:30:00+00:00'],
			$response->getData()
		);

	}//end testARedeemedLinkOpensASessionScopedToItsOneCase()

	/**
	 * portaliq#796. The address must be the one recorded on the case. A
	 * mismatch issues no link and sends no mail, and answers exactly like a
	 * link that was sent, so the endpoint is no oracle for case numbers.
	 *
	 * @return void
	 */
	public function testAnAddressThatIsNotTheCasesIssuesNothingAndAnswersTheSame(): void {
		$sent = $this->controller(site: ['organisation' => 'gemeente-x', 'slug' => 'gemeente-x']);
		$this->doubles['caseTypes']->method('readCaseType')->willReturn(['portalIdentityKind' => ['reference']]);
		$this->doubles['references']->method('admitsReference')->willReturn(true);
		$this->doubles['references']->method('issue')->willReturn(['token' => 'secret-1', 'expiresAt' => '2026-09-29T09:00:00+00:00']);
		$this->doubles['mailer']->method('send')->willReturn(true);
		$matched = $sent->requestReferenceLink(register: 'dossiq', schema: 'caseType', caseType: 'melding', caseReference: 'Z-2026-0042', email: 'anna@example.nl');

		$this->linkable = null;
		$refused = $this->controller(site: ['organisation' => 'gemeente-x', 'slug' => 'gemeente-x']);
		$this->doubles['caseTypes']->method('readCaseType')->willReturn(['portalIdentityKind' => ['reference']]);
		$this->doubles['references']->method('admitsReference')->willReturn(true);
		$this->doubles['references']->method('nominalExpiry')->willReturn('2026-09-29T09:00:00+00:00');
		$this->doubles['references']->expects($this->never())->method('issue');
		$this->doubles['mailer']->expects($this->never())->method('send');
		$mismatched = $refused->requestReferenceLink(register: 'dossiq', schema: 'caseType', caseType: 'melding', caseReference: 'Z-2026-0042', email: 'mallory@example.nl');

		$this->assertSame($matched->getStatus(), $mismatched->getStatus());
		$this->assertSame($matched->getData(), $mismatched->getData());

	}//end testAnAddressThatIsNotTheCasesIssuesNothingAndAnswersTheSame()

	/**
	 * portaliq#796. The link row records which case collection it opens, so
	 * the session it becomes reads there and nowhere else.
	 *
	 * @return void
	 */
	public function testAnIssuedLinkRecordsTheCaseCollectionItOpens(): void {
		$controller = $this->controller(site: ['organisation' => 'gemeente-x', 'slug' => 'gemeente-x']);
		$this->doubles['caseTypes']->method('readCaseType')->willReturn(['portalIdentityKind' => ['reference']]);
		$this->doubles['references']->method('admitsReference')->willReturn(true);
		$this->doubles['references']->expects($this->once())
			->method('issue')
			->with(
				$this->anything(),
				$this->equalTo('Z-2026-0042'),
				$this->equalTo('anna@example.nl'),
				$this->equalTo('gemeente-x'),
				$this->equalTo('dossiq'),
				$this->equalTo('case')
			)
			->willReturn(['token' => 'secret-1', 'expiresAt' => '2026-09-29T09:00:00+00:00']);

		$controller->requestReferenceLink(register: 'dossiq', schema: 'caseType', caseType: 'melding', caseReference: 'Z-2026-0042', email: 'anna@example.nl');

	}//end testAnIssuedLinkRecordsTheCaseCollectionItOpens()

	/**
	 * portaliq#796. The reference session reads its one case, read only, and
	 * nothing without it.
	 *
	 * @return void
	 */
	public function testAReferenceSessionReadsItsCaseReadOnly(): void {
		$controller = $this->controller(site: null);
		$reference = ['subjectRef' => 'reference:abc', 'organisation' => 'gemeente-x', 'caseReference' => 'Z-2026-0042', 'register' => 'dossiq', 'schema' => 'case', 'jti' => 'jti-1'];
		$this->doubles['sessions']->method('resolveReferenceFromBearer')->willReturn($reference);
		$this->doubles['referenceCases']->expects($this->once())
			->method('read')
			->with($this->equalTo($reference))
			->willReturn(['identifier' => 'Z-2026-0042', 'status' => 'in behandeling']);

		$data = $controller->referenceCase()->getData();

		$this->assertSame('Z-2026-0042', $data['case']['identifier']);
		$this->assertTrue($data['readOnly']);

	}//end testAReferenceSessionReadsItsCaseReadOnly()

	public function testWithoutAReferenceSessionNoCaseIsRead(): void {
		$controller = $this->controller(site: null);
		$this->doubles['sessions']->method('resolveReferenceFromBearer')->willReturn(null);
		$this->doubles['referenceCases']->expects($this->never())->method('read');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->referenceCase()->getStatus());

	}//end testWithoutAReferenceSessionNoCaseIsRead()

	/**
	 * gate-25, on `POST /portal/api/identity/invitation/accept`. An
	 * invitation that is spent, expired or unknown is one refusal, and no
	 * account is created.
	 *
	 * @return void
	 */
	public function testAnInvitationThatIsNotValidCreatesNoAccount(): void {
		$controller = $this->controller(site: ['organisation' => 'gemeente-x']);
		$this->doubles['invitations']->method('accept')->willReturn(null);
		$this->doubles['accounts']->expects($this->never())->method('provision');

		$response = $controller->acceptInvitation(token: 'secret-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'invitation_not_valid'], $response->getData());

	}//end testAnInvitationThatIsNotValidCreatesNoAccount()

	/**
	 * gate-25, the accepting side. The answer says only that it landed: the
	 * account the service created is not handed to the browser, because the
	 * caller still has no session at this point.
	 *
	 * @return void
	 */
	public function testAnAcceptedInvitationSaysOnlyThatItLanded(): void {
		$controller = $this->controller(site: ['organisation' => 'gemeente-x']);
		$this->doubles['invitations']->expects($this->once())
			->method('accept')
			->with($this->equalTo('secret-1'))
			->willReturn(['account' => ['subjectRef' => 'subject-1'], 'tokenHash' => 'hash-1']);

		$response = $controller->acceptInvitation(token: 'secret-1');
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['accepted' => true], $data);
		$this->assertArrayNotHasKey('subjectRef', $data);

	}//end testAnAcceptedInvitationSaysOnlyThatItLanded()


	/**
	 * The controller over doubles, all of which can only answer methods the
	 * real classes have.
	 *
	 * @param array<string, mixed>|null $site The portal being visited.
	 * @param bool $inScope Whether the portal declares the case type asked for.
	 *                     True by default, so a test that is not about the
	 *                     portal's scope still reaches what it is about.
	 *
	 * @return PortalIdentityController
	 */
	private function controller(?array $site, bool $inScope = true): PortalIdentityController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);

		$portals = $this->double(PortalResolver::class, ['resolve']);
		$portals->method('resolve')->willReturn($site);

		$this->doubles = [
			'challenge' => $this->double(PortalChallengeService::class, ['issue', 'accepts']),
			'references' => $this->double(PortalReferenceLinkService::class, ['admitsReference', 'issue', 'redeem', 'nominalExpiry']),
			'policy' => $this->double(PortalRegistrationPolicyService::class, ['isOffered', 'decide']),
			'invitations' => $this->double(PortalInvitationService::class, ['accept']),
			'accounts' => $this->double(PortalAccountService::class, ['provision']),
			'caseTypes' => $this->double(CaseTypeReader::class, ['readCaseType']),
			'bindings' => $this->double(PortalFormBindingResolver::class, ['caseTypeIsInPortalScope']),
			'mailer' => $this->double(PortalIdentityMailer::class, ['send']),
			'referenceCases' => $this->double(PortalReferenceCaseService::class, ['linkableCase', 'read']),
			'sessions' => $this->double(PortalSessionService::class, ['issueReferenceSession', 'resolveReferenceFromBearer']),
		];

		// The case behind a reference link is found and its address matches,
		// unless a test says otherwise.
		$this->doubles['referenceCases']->method('linkableCase')->willReturnCallback(
			fn (): ?array => $this->linkable
		);

		$this->doubles['bindings']->method('caseTypeIsInPortalScope')->willReturn($inScope);

		return new PortalIdentityController(
			$request,
			$portals,
			$this->doubles['challenge'],
			$this->doubles['references'],
			$this->doubles['policy'],
			$this->doubles['invitations'],
			$this->doubles['accounts'],
			$this->doubles['caseTypes'],
			$this->doubles['bindings'],
			$this->doubles['mailer'],
			$this->doubles['referenceCases'],
			$this->doubles['sessions']
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
