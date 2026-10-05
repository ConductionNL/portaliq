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
		$session = $this->getMockBuilder(PortalSessionService::class)->disableOriginalConstructor()->onlyMethods(['resolveFromBearer'])->getMock();
		$session->method('resolveFromBearer')->willReturn($subject);
		$this->invitations = $this->getMockBuilder(WaitingAccountInvitation::class)->disableOriginalConstructor()->onlyMethods(['redeem'])->getMock();

		$this->logger = $this->createMock(LoggerInterface::class);

		return new PortalAccountClaimController($this->createMock(IRequest::class), $session, $this->invitations, $this->logger);
	}//end controller()
}//end class
