<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\SessionController;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * portal-controller-http-test-coverage: the three HTTP-facing behaviours
 * `supplier-portal` T02 describes as DONE but never turned into a regression
 * test — `GET /portal/api/session` resolve (both branches), the dev-login
 * gate (open vs 404-closed, per `src/portal/App.jsx`'s own comment about the
 * production posture), and logout's real revocation
 * (portal-auth-edge-session-hardening) rather than a static `{ok: true}`.
 *
 * @spec openspec/changes/portal-controller-http-test-coverage/tasks.md#2.1
 * @spec openspec/changes/portal-controller-http-test-coverage/tasks.md#2.2
 * @spec openspec/changes/portal-controller-http-test-coverage/tasks.md#2.3
 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T03
 */
class SessionControllerTest extends TestCase
{

    private const SUBJECT = [
        'subjectRef'   => 's1',
        'audience'     => 'supplier',
        'organisation' => 'org-1',
        'trust'        => 'low',
        'roles'        => [],
        'jti'          => 'jti-1',
    ];

    public function testIndexReturnsSubjectShapeForAValidBearer(): void
    {
        $session = $this->createMock(PortalSessionService::class);
        $session->method('resolveFromBearer')->willReturn(self::SUBJECT);

        $response = $this->controller(session: $session)->index();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['authenticated']);
        $this->assertSame('s1', $data['subjectRef']);
        $this->assertSame('supplier', $data['audience']);
        $this->assertSame('org-1', $data['organisation']);
        $this->assertSame('low', $data['trust']);

    }//end testIndexReturnsSubjectShapeForAValidBearer()

    public function testIndexReturns401ForAnInvalidBearer(): void
    {
        $session = $this->createMock(PortalSessionService::class);
        $session->method('resolveFromBearer')->willReturn(null);

        $response = $this->controller(session: $session)->index();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertFalse($response->getData()['authenticated']);

    }//end testIndexReturns401ForAnInvalidBearer()

    public function testDevLoginReturns404WhenGateIsClosed(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueBool')->willReturn(false);
        $config->method('getAppValue')->willReturn('no');

        $session = $this->createMock(PortalSessionService::class);
        $session->expects($this->never())->method('issueSession');

        $response = $this->controller(session: $session, config: $config)->devLogin();

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        // Marked for Nextcloud's bruteforce throttler (portal-session-hardening-v2,
        // T05) — probing for a debug-only endpoint on a closed instance is the
        // abuse pattern BruteForceProtection exists to slow down.
        $this->assertTrue($response->isThrottled());

    }//end testDevLoginReturns404WhenGateIsClosed()

    public function testDevLoginMintsATokenWhenDebugModeIsOn(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueBool')->willReturn(true);

        $session = $this->createMock(PortalSessionService::class);
        $session->method('issueSession')->willReturn(['token' => 'signed.jwt.token', 'jti' => 'jti-1']);

        $response = $this->controller(session: $session, config: $config)->devLogin(
            subjectRef: 'dev-supplier',
            audience: 'supplier',
            organisation: 'dev-org'
        );

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('signed.jwt.token', $data['token']);
        $this->assertSame('Bearer', $data['tokenType']);

    }//end testDevLoginMintsATokenWhenDebugModeIsOn()

    public function testDevLoginMintsATokenWhenTheAppFlagIsExplicitlyEnabled(): void
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueBool')->willReturn(false);
        $config->method('getAppValue')->willReturn('yes');

        $session = $this->createMock(PortalSessionService::class);
        $session->method('issueSession')->willReturn(['token' => 't', 'jti' => 'j']);

        $response = $this->controller(session: $session, config: $config)->devLogin();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());

    }//end testDevLoginMintsATokenWhenTheAppFlagIsExplicitlyEnabled()

    public function testDevLoginReturns503WhenNoDedicatedSecretIsConfigured(): void
    {
        // portal-auth-edge-session-hardening: the edge fails closed rather
        // than falling back to a system/shared secret.
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueBool')->willReturn(true);

        $session = $this->createMock(PortalSessionService::class);
        $session->method('issueSession')->willReturn(null);

        $response = $this->controller(session: $session, config: $config)->devLogin();

        $this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());

    }//end testDevLoginReturns503WhenNoDedicatedSecretIsConfigured()

    public function testLogoutRevokesTheCallersOwnSessionAndAlwaysReturnsOk(): void
    {
        $session = $this->createMock(PortalSessionService::class);
        $session->method('resolveFromBearer')->willReturn(self::SUBJECT);
        $session->expects($this->once())->method('revoke')->with('jti-1');

        $response = $this->controller(session: $session)->logout();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($response->getData()['ok']);

    }//end testLogoutRevokesTheCallersOwnSessionAndAlwaysReturnsOk()

    public function testLogoutOnAnAlreadyInvalidBearerIsNotAnError(): void
    {
        $session = $this->createMock(PortalSessionService::class);
        $session->method('resolveFromBearer')->willReturn(null);
        $session->expects($this->never())->method('revoke');

        $response = $this->controller(session: $session)->logout();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($response->getData()['ok']);

    }//end testLogoutOnAnAlreadyInvalidBearerIsNotAnError()

    public function testRefreshReturnsANewBearerForAValidSession(): void
    {
        $session = $this->createMock(PortalSessionService::class);
        $session->method('refreshSession')->willReturn(['token' => 'new.signed.jwt', 'jti' => 'jti-2']);

        $response = $this->controller(session: $session)->refresh();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('new.signed.jwt', $data['token']);
        $this->assertSame('Bearer', $data['tokenType']);

    }//end testRefreshReturnsANewBearerForAValidSession()

    public function testRefreshReturns401OnAnyRejection(): void
    {
        // Fail-closed: revoked, expired, malformed, past-the-cap, or
        // unconfigured all collapse to the SAME null → the SAME generic 401.
        $session = $this->createMock(PortalSessionService::class);
        $session->method('refreshSession')->willReturn(null);

        $response = $this->controller(session: $session)->refresh();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testRefreshReturns401OnAnyRejection()

    private function controller(PortalSessionService $session, ?IConfig $config=null): SessionController
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getHeader')->willReturnMap([['Authorization', 'Bearer some-token']]);

        return new SessionController(
            $request,
            $session,
            ($config ?? $this->createMock(IConfig::class))
        );

    }//end controller()

}//end class
