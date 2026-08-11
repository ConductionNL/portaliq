<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\HealthController;
use OCA\Portaliq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * `GET /api/health` (ADR-006, REQ-OBS-002).
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * `HealthController` had no test at all. Two of its three branches are ones a
 * browser cannot reach on a live instance — "OpenRegister is absent" and "the
 * probe throws" — so the `openspec/specs/observability/spec.md` scenarios that
 * describe them carry an `@e2e exclude` whose stated reason is *this file*.
 * An exclusion reason is a claim, and a claim that names a test which does not
 * exist is worth less than no claim at all; these are the tests that make it
 * true. The third branch (healthy → 200, public, no auth) IS reachable and is
 * asserted end-to-end in `tests/e2e/app-shell-and-admin.spec.ts`.
 *
 * @spec openspec/specs/observability/spec.md#REQ-OBS-002
 */
class HealthControllerTest extends TestCase
{

    /**
     * Build a controller whose dependency probe answers `$available`, or
     * throws when `$throw` is given.
     *
     * @param bool|null            $available What `isOpenRegisterAvailable()` returns.
     * @param \Throwable|null      $throw     Thrown instead of returning, when set.
     * @param LoggerInterface|null $logger    Logger to observe, when set.
     *
     * @return HealthController
     */
    private function controller(?bool $available=null, ?\Throwable $throw=null, ?LoggerInterface $logger=null): HealthController
    {
        $settingsService = $this->createMock(SettingsService::class);
        $probe           = $settingsService->method('isOpenRegisterAvailable');
        if ($throw !== null) {
            $probe->willThrowException($throw);
        } else {
            $probe->willReturn((bool) $available);
        }

        return new HealthController(
            $this->createMock(IRequest::class),
            $settingsService,
            ($logger ?? $this->createMock(LoggerInterface::class))
        );

    }//end controller()

    /**
     * Healthy: 200 + `status: ok` + the dependency reported true.
     *
     * The end-to-end twin of this assertion lives in
     * `tests/e2e/app-shell-and-admin.spec.ts`; it is kept here as well because
     * it is the CONTROL for the two failure branches below — without it, a
     * change that made every answer 503 would still leave those two green.
     *
     * @return void
     */
    public function testDependenciesPresentIs200AndOk(): void
    {
        $response = $this->controller(available: true)->index();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $body = $response->getData();
        $this->assertSame('ok', $body['status']);
        $this->assertSame('portaliq', $body['app']);
        $this->assertTrue($body['dependencies']['openregister']);

    }//end testDependenciesPresentIs200AndOk()

    /**
     * A missing required dependency is 503 + `degraded`, not 200 + a warning.
     *
     * The status code is the half that matters operationally: the consumers of
     * this endpoint are load-balancer readiness probes and blackbox exporters,
     * which route on the code and never parse the body. A degraded instance
     * answering 200 keeps taking traffic.
     *
     * @return void
     */
    public function testAMissingDependencyIs503AndDegraded(): void
    {
        $response = $this->controller(available: false)->index();

        $this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
        $body = $response->getData();
        $this->assertSame('degraded', $body['status']);
        $this->assertFalse($body['dependencies']['openregister']);

    }//end testAMissingDependencyIs503AndDegraded()

    /**
     * A throwing probe is logged server-side WITH the exception, and answered
     * with a static generic message that leaks nothing (ADR-005).
     *
     * Both halves are asserted. Logging alone would let a leaking body through;
     * asserting the body alone would let a silent swallow through, and a health
     * endpoint that fails silently is the one failure nobody is paged for.
     *
     * @return void
     */
    public function testAThrowingDependencyProbeIsLoggedAndReportedGenerically(): void
    {
        $secret = 'DSN=pgsql://portaliq:hunter2@db/nextcloud';
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('health check failed'),
                $this->callback(
                    static function (array $context): bool {
                        return isset($context['exception']) === true
                            && $context['exception'] instanceof \Throwable;
                    }
                )
            );

        $response = $this->controller(throw: new RuntimeException($secret), logger: $logger)->index();

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $body = $response->getData();
        $this->assertSame('error', $body['status']);
        $this->assertSame('Health check failed', $body['message']);
        // ADR-005: nothing about the failure's internals may reach the caller.
        $this->assertStringNotContainsString('hunter2', json_encode($body));
        $this->assertStringNotContainsString('pgsql', json_encode($body));
        $this->assertArrayNotHasKey('dependencies', $body);

    }//end testAThrowingDependencyProbeIsLoggedAndReportedGenerically()
}//end class
