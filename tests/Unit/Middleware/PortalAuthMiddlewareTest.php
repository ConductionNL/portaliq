<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Middleware;

use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Auth\PortalUnauthorizedException;
use OCA\Portaliq\Middleware\PortalAuthMiddleware;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves the portal guard fails closed: protected routes require a valid bearer,
 * unprotected routes are untouched, and auth failures become 401.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T02
 */
class PortalAuthMiddlewareTest extends TestCase
{

    public function testUnprotectedControllerIsIgnored(): void
    {
        $mw = $this->middleware($this->session(null));

        // A non-protected controller passes even with no session, no exception.
        $mw->beforeController(new \stdClass(), 'index');
        $this->assertTrue(true);

    }//end testUnprotectedControllerIsIgnored()

    public function testProtectedControllerWithoutSessionFailsClosed(): void
    {
        $mw = $this->middleware($this->session(null));

        $this->expectException(PortalUnauthorizedException::class);
        $mw->beforeController($this->protectedController(), 'index');

    }//end testProtectedControllerWithoutSessionFailsClosed()

    public function testProtectedControllerWithSessionPasses(): void
    {
        $mw = $this->middleware($this->session(['subjectRef' => 's1', 'audience' => 'supplier']));

        // No exception thrown when a valid session resolves.
        $mw->beforeController($this->protectedController(), 'index');
        $this->assertTrue(true);

    }//end testProtectedControllerWithSessionPasses()

    public function testAfterExceptionConvertsAuthFailureTo401(): void
    {
        $mw       = $this->middleware($this->session(null));
        $response = $mw->afterException($this->protectedController(), 'index', new PortalUnauthorizedException());

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testAfterExceptionConvertsAuthFailureTo401()

    public function testAfterExceptionRethrowsOtherErrors(): void
    {
        $mw = $this->middleware($this->session(null));

        $this->expectException(RuntimeException::class);
        $mw->afterException($this->protectedController(), 'index', new RuntimeException('boom'));

    }//end testAfterExceptionRethrowsOtherErrors()

    private function middleware(PortalSessionService $session): PortalAuthMiddleware
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getHeader')->willReturn('');
        return new PortalAuthMiddleware($request, $session);

    }//end middleware()

    private function session(?array $subject): PortalSessionService
    {
        $mock = $this->createMock(PortalSessionService::class);
        $mock->method('resolveFromBearer')->willReturn($subject);
        return $mock;

    }//end session()

    private function protectedController(): object
    {
        return new class implements PortalProtected {
        };

    }//end protectedController()

}//end class
