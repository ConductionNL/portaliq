<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Controller\PortalAccountClaimController;
use OCA\Portaliq\Service\Identity\WaitingAccountInvitation;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionParameter;
use RuntimeException;

/**
 * invitation-secret-joins-the-signed-in-account REQ-PIS-008: the redeem
 * route takes a signed-in session at trust level substantial or higher, the
 * account that receives is always the bearer's own, and wrong, expired and
 * used secrets get one and the same answer.
 *
 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
 */
class PortalAccountClaimControllerTest extends TestCase {

	/**
	 * The invitation service double.
	 *
	 * @var mixed
	 */
	private mixed $invitations = null;

	/**
	 * The logger double.
	 *
	 * @var mixed
	 */
	private mixed $logger = null;

	/**
	 * The session double of the last controller built.
	 *
	 * @var mixed
	 */
	private mixed $session = null;

	public function testWithoutASessionNothingIsRedeemed(): void {
		$controller = $this->controller(subject: null);
		$this->invitations->expects($this->never())->method('redeem');

		$response = $controller->redeem(secret: 'secret-abc');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertInstanceOf(PortalProtected::class, $controller, 'The bearer middleware guards the route too.');

	}//end testWithoutASessionNothingIsRedeemed()

	public function testASessionBelowSubstantialIsRefusedBeforeTheSecretIsLookedAt(): void {
		foreach (['low', '', 'unknown-level'] as $trust) {
			$controller = $this->controller(subject: $this->subject(trust: $trust));
			$this->invitations->expects($this->never())->method('redeem');

			$response = $controller->redeem(secret: 'secret-abc');

			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus(), $trust);
			$this->assertSame(['error' => 'trust_too_low'], $response->getData(), $trust);
		}

	}//end testASessionBelowSubstantialIsRefusedBeforeTheSecretIsLookedAt()

	public function testSubstantialAndHighMayRedeemForTheBearersOwnAccount(): void {
		foreach (['substantial', 'high'] as $trust) {
			$subject    = $this->subject(trust: $trust);
			$controller = $this->controller(subject: $subject);
			$this->invitations->expects($this->once())->method('redeem')->with($subject, 'secret-abc')->willReturn(WaitingAccountInvitation::CLAIMED);

			$response = $controller->redeem(secret: 'secret-abc');

			$this->assertSame(Http::STATUS_OK, $response->getStatus(), $trust);
			$this->assertSame(['claimed' => true], $response->getData(), $trust);
		}

	}//end testSubstantialAndHighMayRedeemForTheBearersOwnAccount()

	/**
	 * invitation-joins-an-unbound-account: the claim moved the account from
	 * the sign-in route's audience into the invitation's. The session is
	 * reissued for the audience read from the account, and the new bearer
	 * goes back with the answer.
	 *
	 * @return void
	 */
	public function testAClaimThatMovedTheAudienceHandsBackABearerForIt(): void {
		$subject    = ['audience' => 'client'] + $this->subject(trust: 'substantial');
		$controller = $this->controller(subject: $subject);
		$this->invitations->method('redeem')->willReturn(WaitingAccountInvitation::CLAIMED);
		$this->invitations->method('audienceOf')->with($subject)->willReturn('parent');
		$this->session->expects($this->once())->method('reissueForAudience')->with('Bearer old', 'parent')
			->willReturn(['token' => 'new-bearer', 'jti' => 'jti-2', 'expiresAt' => 1700, 'hardExpiresAt' => 2700, 'idleTimeout' => 900]);

		$response = $controller->redeem(secret: 'secret-abc');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['claimed' => true, 'audience' => 'parent', 'token' => 'new-bearer', 'expiresAt' => 1700], $response->getData());

	}//end testAClaimThatMovedTheAudienceHandsBackABearerForIt()

	/**
	 * A claim within the session's own audience, a refused claim and a
	 * reissue that fails or throws hand back no bearer; the claim still
	 * answers as it did.
	 *
	 * @return void
	 */
	public function testNoNewAudienceNoBearer(): void {
		$subject    = ['audience' => 'parent'] + $this->subject(trust: 'substantial');
		$controller = $this->controller(subject: $subject);
		$this->invitations->method('redeem')->willReturn(WaitingAccountInvitation::CLAIMED);
		$this->invitations->method('audienceOf')->willReturn('parent');
		$this->session->expects($this->never())->method('reissueForAudience');
		$this->assertSame(['claimed' => true], $controller->redeem(secret: 'secret-abc')->getData());

		$controller = $this->controller(subject: $subject);
		$this->invitations->method('redeem')->willReturn(WaitingAccountInvitation::NOT_VALID);
		$this->invitations->expects($this->never())->method('audienceOf');
		$this->session->expects($this->never())->method('reissueForAudience');
		$this->assertSame(['error' => 'invitation_not_valid'], $controller->redeem(secret: 'secret-abc')->getData());

		$controller = $this->controller(subject: ['audience' => 'client'] + $subject);
		$this->invitations->method('redeem')->willReturn(WaitingAccountInvitation::CLAIMED);
		$this->invitations->method('audienceOf')->willReturn('parent');
		$this->session->method('reissueForAudience')->willReturn(null);
		$this->assertSame(['claimed' => true], $controller->redeem(secret: 'secret-abc')->getData());

		$controller = $this->controller(subject: ['audience' => 'client'] + $subject);
		$this->invitations->method('redeem')->willReturn(WaitingAccountInvitation::CLAIMED);
		$this->invitations->method('audienceOf')->willReturn('parent');
		$this->session->method('reissueForAudience')->willThrowException(new RuntimeException('store down'));
		$this->logger->expects($this->once())->method('warning');
		$this->assertSame(['claimed' => true], $controller->redeem(secret: 'secret-abc')->getData());

	}//end testNoNewAudienceNoBearer()

	public function testEveryDeadSecretGetsTheSameAnswer(): void {
		$controller = $this->controller(subject: $this->subject(trust: 'substantial'));
		$this->invitations->method('redeem')->willReturn(WaitingAccountInvitation::NOT_VALID);

		$wrong = $controller->redeem(secret: 'wrong');
		$empty = $controller->redeem();

		$this->assertSame(Http::STATUS_FORBIDDEN, $wrong->getStatus());
		$this->assertSame(['error' => 'invitation_not_valid'], $wrong->getData());
		$this->assertSame($wrong->getStatus(), $empty->getStatus());
		$this->assertSame($wrong->getData(), $empty->getData());

	}//end testEveryDeadSecretGetsTheSameAnswer()

	public function testALockedCallerIsToldToWait(): void {
		$controller = $this->controller(subject: $this->subject(trust: 'substantial'));
		$this->invitations->method('redeem')->willReturn(WaitingAccountInvitation::LOCKED);

		$response = $controller->redeem(secret: 'secret-abc');

		$this->assertSame(Http::STATUS_TOO_MANY_REQUESTS, $response->getStatus());
		$this->assertSame(['error' => 'too_many_attempts'], $response->getData());

	}//end testALockedCallerIsToldToWait()

	/**
	 * Security review L6 and M1: an account that cannot receive is told so,
	 * a conflicting claim has its own answer, and a request that could not
	 * finish says to try again. None of them is the dead-secret answer.
	 *
	 * @return void
	 */
	public function testEachRefusalAboutTheCallerHasItsOwnAnswer(): void {
		$cases = [
			WaitingAccountInvitation::CANNOT_RECEIVE => [Http::STATUS_FORBIDDEN, 'account_cannot_receive'],
			WaitingAccountInvitation::CONFLICT => [Http::STATUS_CONFLICT, 'invitation_conflict'],
			WaitingAccountInvitation::BUSY => [Http::STATUS_SERVICE_UNAVAILABLE, 'try_again'],
		];
		foreach ($cases as $result => [$status, $error]) {
			$controller = $this->controller(subject: $this->subject(trust: 'substantial'));
			$this->invitations->method('redeem')->willReturn($result);

			$response = $controller->redeem(secret: 'secret-abc');

			$this->assertSame($status, $response->getStatus(), $result);
			$this->assertSame(['error' => $error], $response->getData(), $result);
		}

	}//end testEachRefusalAboutTheCallerHasItsOwnAnswer()

	/**
	 * Security review L3: a failure below the controller is caught, logged by
	 * its class only, and answered with try_again; the secret never reaches
	 * the log. The secret is marked sensitive, so a trace redacts it.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedWithoutTheSecret(): void {
		$controller = $this->controller(subject: $this->subject(trust: 'substantial'));
		$this->invitations->method('redeem')->willThrowException(new RuntimeException('store said no to secret-abc'));
		$this->logger->expects($this->once())->method('error')->with(
			'Portal invitation redeem failed',
			$this->callback(static fn (array $context): bool => $context === ['exception' => RuntimeException::class])
		);

		$response = $controller->redeem(secret: 'secret-abc');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertStringNotContainsString('secret-abc', (string)json_encode($response->getData()));
		$parameter = new ReflectionParameter([PortalAccountClaimController::class, 'redeem'], 'secret');
		$this->assertCount(1, $parameter->getAttributes(\SensitiveParameter::class));

	}//end testAFailureIsLoggedWithoutTheSecret()

	public function testTheRouteIsRateLimitedPerAddress(): void {
		$limits = (new ReflectionMethod(PortalAccountClaimController::class, 'redeem'))->getAttributes(AnonRateLimit::class);

		$this->assertCount(1, $limits);
		$this->assertSame(['limit' => 10, 'period' => 60], $limits[0]->getArguments());

	}//end testTheRouteIsRateLimitedPerAddress()

	public function testTheRouteIsRegistered(): void {
		$routes = (require __DIR__ . '/../../../appinfo/routes.php')['routes'];
		$found  = array_values(array_filter($routes, static fn (array $route): bool => $route['name'] === 'portalAccountClaim#redeem'));

		$this->assertSame([['name' => 'portalAccountClaim#redeem', 'url' => '/portal/api/identity/invitation/redeem', 'verb' => 'POST']], $found);

	}//end testTheRouteIsRegistered()

	/**
	 * A session at one trust level.
	 *
	 * @param string $trust The trust level.
	 *
	 * @return array<string, mixed>
	 */
	private function subject(string $trust): array {
		return ['subjectRef' => 'subject-1', 'organisation' => 'gemeente-x', 'trust' => $trust, 'jti' => 'jti-1'];
	}//end subject()

	/**
	 * The controller over doubles that can only answer methods the real
	 * classes have.
	 *
	 * @param array<string, mixed>|null $subject The resolved subject.
	 *
	 * @return PortalAccountClaimController
	 */
	private function controller(?array $subject): PortalAccountClaimController {
		$this->session = $this->getMockBuilder(PortalSessionService::class)->disableOriginalConstructor()->onlyMethods(['resolveFromBearer', 'reissueForAudience'])->getMock();
		$this->session->method('resolveFromBearer')->willReturn($subject);
		$this->invitations = $this->getMockBuilder(WaitingAccountInvitation::class)->disableOriginalConstructor()->onlyMethods(['redeem', 'audienceOf'])->getMock();

		$this->logger = $this->createMock(LoggerInterface::class);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => $name === 'Authorization' ? 'Bearer old' : '');

		return new PortalAccountClaimController($request, $this->session, $this->invitations, $this->logger);
	}//end controller()
}//end class
