<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\ContributionController;
use OCA\Portaliq\Controller\SessionController;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * portal-session-hardening-v2 T05/T06/T12: the public session, collection,
 * create/update, action, and download endpoints each declare an anon
 * rate-limit posture with a sane (non-zero) default; `dev-login` additionally
 * carries `BruteForceProtection` — the tightest surface, per design.md.
 *
 * A static/attribute check rather than a live throttling test: the actual
 * enforcement is Nextcloud core's RateLimitingMiddleware, exercised at the
 * framework level, not by portaliq's own code.
 *
 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T05
 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T06
 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T12
 * @spec openspec/specs/supplier-portal/spec.md#public-portal-endpoints-are-rate-limited
 */
class RateLimitAttributesTest extends TestCase
{

    /**
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function rateLimitedMethodProvider(): array
    {
        return [
            'session index'          => [SessionController::class, 'index'],
            'session devLogin'       => [SessionController::class, 'devLogin'],
            'session logout'         => [SessionController::class, 'logout'],
            'session refresh'        => [SessionController::class, 'refresh'],
            'session oidcStart'      => [SessionController::class, 'oidcStart'],
            'session oidcCallback'   => [SessionController::class, 'oidcCallback'],
            'contribution collection' => [ContributionController::class, 'collection'],
            'contribution create'    => [ContributionController::class, 'create'],
            'contribution update'    => [ContributionController::class, 'update'],
            'contribution action'    => [ContributionController::class, 'action'],
            'contribution downloadFile' => [ContributionController::class, 'downloadFile'],
        ];

    }//end rateLimitedMethodProvider()

    /**
     * @dataProvider rateLimitedMethodProvider
     *
     * @param class-string $class  The controller class.
     * @param string       $method The method name.
     */
    public function testMethodDeclaresAnAnonRateLimitWithASaneDefault(string $class, string $method): void
    {
        $reflection = new ReflectionClass($class);
        $attributes = $reflection->getMethod($method)->getAttributes(AnonRateLimit::class);

        $this->assertNotEmpty($attributes, "{$class}::{$method}() must declare #[AnonRateLimit]");

        /** @var AnonRateLimit $instance */
        $instance = $attributes[0]->newInstance();
        $this->assertGreaterThan(0, $instance->getLimit(), 'limit must be a sane positive default');
        $this->assertGreaterThan(0, $instance->getPeriod(), 'period must be a sane positive default');

    }//end testMethodDeclaresAnAnonRateLimitWithASaneDefault()

    public function testDevLoginCarriesTheTightestLimitAndBruteForceProtection(): void
    {
        $reflection = new ReflectionClass(SessionController::class);
        $method     = $reflection->getMethod('devLogin');

        $bruteForce = $method->getAttributes(BruteForceProtection::class);
        $this->assertNotEmpty($bruteForce, 'devLogin() must declare #[BruteForceProtection] — the debug-only mint must not become a brute-force oracle');

        /** @var AnonRateLimit $devLoginLimit */
        $devLoginLimit = $method->getAttributes(AnonRateLimit::class)[0]->newInstance();

        foreach (['index', 'logout', 'refresh'] as $other) {
            /** @var AnonRateLimit $otherLimit */
            $otherLimit = $reflection->getMethod($other)->getAttributes(AnonRateLimit::class)[0]->newInstance();
            $this->assertLessThanOrEqual(
                $otherLimit->getLimit(),
                $devLoginLimit->getLimit(),
                "devLogin()'s limit must be at least as tight as {$other}()'s"
            );
        }

    }//end testDevLoginCarriesTheTightestLimitAndBruteForceProtection()
}//end class
