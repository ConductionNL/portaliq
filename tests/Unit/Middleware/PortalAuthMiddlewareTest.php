<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Middleware;

use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Auth\PortalUnauthorizedException;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
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
 * portal-page-provisioning adds the anonymous-allowed branch: a no-bearer
 * request to a NON-anonymous-declared route still throws exactly as before
 * (regression), while a no-bearer request matching an anonymous-declared
 * entry passes through.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T02
 * @spec openspec/changes/portal-page-provisioning/tasks.md#5.1
 */
class PortalAuthMiddlewareTest extends TestCase
{

    public function testUnprotectedControllerIsIgnored(): void
    {
        $mw = $this->middleware($this->session(null), $this->registry([]));

        // A non-protected controller passes even with no session, no exception.
        $mw->beforeController(new \stdClass(), 'index');
        $this->assertTrue(true);

    }//end testUnprotectedControllerIsIgnored()

    public function testProtectedControllerWithoutSessionFailsClosed(): void
    {
        // No anonymous entries anywhere — the unconditional bearer-required
        // default for every non-opted-in route.
        $mw = $this->middleware($this->session(null), $this->registry([]));

        $this->expectException(PortalUnauthorizedException::class);
        $mw->beforeController($this->protectedController(), 'index');

    }//end testProtectedControllerWithoutSessionFailsClosed()

    public function testProtectedControllerWithSessionPasses(): void
    {
        $mw = $this->middleware($this->session(['subjectRef' => 's1', 'audience' => 'supplier']), $this->registry([]));

        // No exception thrown when a valid session resolves.
        $mw->beforeController($this->protectedController(), 'index');
        $this->assertTrue(true);

    }//end testProtectedControllerWithSessionPasses()

    public function testAfterExceptionConvertsAuthFailureTo401(): void
    {
        $mw       = $this->middleware($this->session(null), $this->registry([]));
        $response = $mw->afterException($this->protectedController(), 'index', new PortalUnauthorizedException());

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testAfterExceptionConvertsAuthFailureTo401()

    public function testAfterExceptionRethrowsOtherErrors(): void
    {
        $mw = $this->middleware($this->session(null), $this->registry([]));

        $this->expectException(RuntimeException::class);
        $mw->afterException($this->protectedController(), 'index', new RuntimeException('boom'));

    }//end testAfterExceptionRethrowsOtherErrors()

    /**
     * A no-bearer request to a NON-anonymous-declared (register, schema)
     * still throws — the anonymous-allowed branch does not widen access to
     * anything that never opted in (regression, portal-page-provisioning).
     */
    public function testNoBearerCreateToNonAnonymousRouteStillThrows(): void
    {
        $registry = $this->registry(
            [
                'contributions' => [
                    [
                        'actions' => [
                            ['id' => 'openIntake', 'type' => 'create', 'register' => 'openbuild', 'schema' => 'melding', 'anonymous' => true],
                        ],
                    ],
                ],
            ]
        );

        // Same aggregate exists, but the request targets a DIFFERENT
        // (register, schema) than the one anonymous entry declares.
        $mw = $this->middleware($this->session(null), $registry, ['register' => 'openbuild', 'schema' => 'someOtherSchema']);

        $this->expectException(PortalUnauthorizedException::class);
        $mw->beforeController($this->protectedController(), 'create');

    }//end testNoBearerCreateToNonAnonymousRouteStillThrows()

    /**
     * A no-bearer request to a route matching an `anonymous: true`,
     * `type: create` action for the EXACT (register, schema) passes through
     * with no exception — the controller receives `subject() === null` and
     * branches into its own anonymous path.
     */
    public function testNoBearerCreateToAnonymousDeclaredRoutePassesThrough(): void
    {
        $registry = $this->registry(
            [
                'contributions' => [
                    [
                        'actions' => [
                            ['id' => 'openIntake', 'type' => 'create', 'register' => 'openbuild', 'schema' => 'melding', 'anonymous' => true],
                        ],
                    ],
                ],
            ]
        );

        $mw = $this->middleware($this->session(null), $registry, ['register' => 'openbuild', 'schema' => 'melding']);

        // No exception — the request is let through.
        $mw->beforeController($this->protectedController(), 'create');
        $this->assertTrue(true);

    }//end testNoBearerCreateToAnonymousDeclaredRoutePassesThrough()

    /**
     * A `type: update` action flagged `anonymous: true` is NOT part of the
     * anonymous surface for `create()` — only an exact `type: create` match
     * counts (design.md Non-Goals).
     */
    public function testNoBearerCreateIgnoresAnAnonymousUpdateAction(): void
    {
        $registry = $this->registry(
            [
                'contributions' => [
                    [
                        'actions' => [
                            ['id' => 'weird', 'type' => 'update', 'register' => 'openbuild', 'schema' => 'melding', 'anonymous' => true],
                        ],
                    ],
                ],
            ]
        );

        $mw = $this->middleware($this->session(null), $registry, ['register' => 'openbuild', 'schema' => 'melding']);

        $this->expectException(PortalUnauthorizedException::class);
        $mw->beforeController($this->protectedController(), 'create');

    }//end testNoBearerCreateIgnoresAnAnonymousUpdateAction()

    /**
     * index(): ANY anonymous entry existing anywhere is enough to let a
     * no-bearer request through — the anonymous visitor's SPA needs the page
     * layout before it can submit anything.
     */
    public function testNoBearerIndexWithAnonymousEntryPassesThrough(): void
    {
        $registry = $this->registry(
            [
                'contributions' => [
                    ['actions' => [['id' => 'x', 'type' => 'create', 'register' => 'r', 'schema' => 's', 'anonymous' => true]]],
                ],
            ]
        );

        $mw = $this->middleware($this->session(null), $registry);

        $mw->beforeController($this->protectedController(), 'index');
        $this->assertTrue(true);

    }//end testNoBearerIndexWithAnonymousEntryPassesThrough()

    /**
     * index(): with NO anonymous entries anywhere, a no-bearer request still
     * throws — byte-identical to pre-change behaviour.
     */
    public function testNoBearerIndexWithNoAnonymousEntriesStillThrows(): void
    {
        $mw = $this->middleware($this->session(null), $this->registry(['contributions' => []]));

        $this->expectException(PortalUnauthorizedException::class);
        $mw->beforeController($this->protectedController(), 'index');

    }//end testNoBearerIndexWithNoAnonymousEntriesStillThrows()

    /**
     * A no-bearer request to a method OUTSIDE the anonymous-eligible set
     * (`update`, the endpoint `action` forward, …) always throws — this
     * change defines no anonymous surface for it (design.md Non-Goals),
     * regardless of what the anonymous aggregate contains.
     */
    public function testNoBearerNonEligibleMethodAlwaysThrows(): void
    {
        $registry = $this->registry(
            [
                'contributions' => [
                    ['actions' => [['id' => 'x', 'type' => 'create', 'register' => 'r', 'schema' => 's', 'anonymous' => true]]],
                ],
            ]
        );

        $mw = $this->middleware($this->session(null), $registry, ['register' => 'r', 'schema' => 's']);

        $this->expectException(PortalUnauthorizedException::class);
        $mw->beforeController($this->protectedController(), 'update');

    }//end testNoBearerNonEligibleMethodAlwaysThrows()

    private function middleware(PortalSessionService $session, PortalContributionRegistry $registry, array $params=[]): PortalAuthMiddleware
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getHeader')->willReturn('');
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, $default=null) => ($params[$key] ?? $default)
        );
        return new PortalAuthMiddleware($request, $session, $registry);

    }//end middleware()

    private function session(?array $subject): PortalSessionService
    {
        $mock = $this->createMock(PortalSessionService::class);
        $mock->method('resolveFromBearer')->willReturn($subject);
        return $mock;

    }//end session()

    private function registry(array $aggregate): PortalContributionRegistry
    {
        $mock = $this->createMock(PortalContributionRegistry::class);
        $mock->method('aggregateAnonymous')->willReturn($aggregate);
        return $mock;

    }//end registry()

    private function protectedController(): object
    {
        return new class implements PortalProtected {
        };

    }//end protectedController()

}//end class
