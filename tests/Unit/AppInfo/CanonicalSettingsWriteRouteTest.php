<?php

/**
 * Tests for the canonical settings-write route contract.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\AppInfo;

use OCA\Portaliq\Controller\SettingsController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * OpenRegister's AppHost dialect (`AppHost\Routes::standard()`, mirrored by
 * `GenericSettingsControllerBase`) makes `PUT /api/settings` (`settings#update`)
 * the canonical settings write and `POST /api/settings` (`settings#create`) the
 * retained legacy alias.
 *
 * Portaliq does NOT call `Routes::standard()`; it builds `appinfo/routes.php`
 * itself. That changes the failure mode compared to leaf apps which do adopt the
 * AppHost table:
 *
 *   - Leaf adopts the AppHost table, method missing -> the router matches the
 *     URL, the dispatcher reflects a method that is not there, HTTP 500.
 *   - Leaf owns its route table, route missing      -> the URL matches for the
 *     other verbs only, and the router rejects the verb, HTTP 405.
 *
 * Measured against the dev instance on 2026-08-08, before this change:
 *   GET  /apps/portaliq/api/settings -> 200
 *   POST /apps/portaliq/api/settings -> 412 (CSRF middleware; route exists)
 *   PUT  /apps/portaliq/api/settings -> 405 (no route for the verb at all)
 *
 * So portaliq was missing BOTH halves of the canonical write — the route and
 * the method. Adding the method without the route would ship an unreachable
 * capability (gate-14 route-reachability / orphaned-write-capability); adding
 * the route without the method would turn the 405 into a 500. This test pins
 * both halves, and asserts the ITEM (each individual route entry and each
 * individual method) rather than the container (the file or the class merely
 * existing).
 *
 * @covers \OCA\Portaliq\Controller\SettingsController
 */
class CanonicalSettingsWriteRouteTest extends TestCase
{

    /**
     * The canonical settings surface: route name => [url, verb, method].
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const CANONICAL_SETTINGS_ROUTES = [
        'settings#index'  => ['/api/settings', 'GET', 'index'],
        'settings#create' => ['/api/settings', 'POST', 'create'],
        'settings#update' => ['/api/settings', 'PUT', 'update'],
        'settings#load'   => ['/api/settings/load', 'POST', 'load'],
    ];


    /**
     * Load and return the declared route entries.
     *
     * The route file is evaluated, not grepped: a string match would be
     * satisfied by the route name appearing inside a comment, and would not
     * see the url/verb it is actually paired with.
     *
     * @return array<int, array<string, mixed>> The declared route entries.
     */
    private function declaredRoutes(): array
    {
        $routesFile = __DIR__.'/../../../appinfo/routes.php';
        $this->assertFileExists($routesFile, 'appinfo/routes.php must exist');

        $declaration = require $routesFile;

        $this->assertIsArray($declaration, 'appinfo/routes.php must return an array');
        $this->assertArrayHasKey('routes', $declaration, "appinfo/routes.php must return a 'routes' key");
        $this->assertIsArray($declaration['routes'], "'routes' must be an array");

        return $declaration['routes'];

    }//end declaredRoutes()


    /**
     * Positive control for the two tests below.
     *
     * Both of them look for entries inside the evaluated route table. If that
     * table were empty — a broken path, a refactor that moved the declaration,
     * a file that no longer returns what we think it does — a "nothing is
     * missing" result would be manufactured for free. Read a green from the
     * assertions below only together with a green here.
     *
     * @return void
     */
    public function testRouteTableIsNonEmptyAndWellShaped(): void
    {
        $routes = $this->declaredRoutes();

        $this->assertGreaterThan(
            0,
            count($routes),
            'The evaluated route table is empty — every route assertion below would pass vacuously.'
        );

        $named = 0;
        foreach ($routes as $route) {
            $this->assertIsArray($route, 'Every route entry must be an array');
            $this->assertArrayHasKey('name', $route, 'Every route entry must declare a name');
            $this->assertArrayHasKey('url', $route, 'Every route entry must declare a url');
            $this->assertArrayHasKey('verb', $route, 'Every route entry must declare a verb');
            $named++;
        }

        $this->assertGreaterThan(0, $named, 'No route entry was inspected.');

    }//end testRouteTableIsNonEmptyAndWellShaped()


    /**
     * Every canonical settings route must be declared with the right url+verb.
     *
     * `settings#update` on `PUT /api/settings` is the entry whose absence
     * produced the measured 405. The legacy `settings#create` POST entry is
     * asserted alongside it so this change stays a strict addition: a future
     * edit that "converges" by deleting the POST route breaks here.
     *
     * @return void
     */
    public function testCanonicalSettingsRoutesAreDeclaredWithTheCorrectUrlAndVerb(): void
    {
        $routes   = $this->declaredRoutes();
        $inspected = 0;
        $missing   = [];

        foreach (self::CANONICAL_SETTINGS_ROUTES as $name => $expected) {
            [$url, $verb] = $expected;
            $inspected++;

            $found = false;
            foreach ($routes as $route) {
                if (($route['name'] ?? null) !== $name) {
                    continue;
                }

                if (($route['url'] ?? null) === $url && ($route['verb'] ?? null) === $verb) {
                    $found = true;
                    break;
                }
            }

            if ($found === false) {
                $missing[] = sprintf('%s (%s %s)', $name, $verb, $url);
            }
        }//end foreach

        $this->assertGreaterThan(
            0,
            $inspected,
            'No canonical route was inspected — the expectation table is empty, so an empty '
            .'missing-list means nothing.'
        );

        $this->assertSame(
            [],
            $missing,
            sprintf(
                "appinfo/routes.php does not declare these canonical settings route(s). "
                ."A missing verb on an otherwise-matching url is a 405, not a 404.\n  - %s",
                implode("\n  - ", $missing)
            )
        );

    }//end testCanonicalSettingsRoutesAreDeclaredWithTheCorrectUrlAndVerb()


    /**
     * Every method those routes target must exist and be publicly dispatchable.
     *
     * Asserted per method, never merely that SettingsController exists — a
     * class-exists assertion is satisfied by a controller with none of the
     * methods on it.
     *
     * @return void
     */
    public function testSettingsControllerImplementsEveryRoutedMethodAsPublic(): void
    {
        $reflection = new ReflectionClass(SettingsController::class);

        $inspected = 0;
        $missing   = [];

        foreach (self::CANONICAL_SETTINGS_ROUTES as $name => $expected) {
            $method = $expected[2];
            $inspected++;

            if ($reflection->hasMethod($method) === false) {
                $missing[] = sprintf('SettingsController::%s() (routed as %s)', $method, $name);
                continue;
            }

            $this->assertTrue(
                $reflection->getMethod($method)->isPublic(),
                sprintf(
                    'SettingsController::%s() must be public to be dispatchable by the '
                    .'Nextcloud AppFramework.',
                    $method
                )
            );
        }//end foreach

        $this->assertGreaterThan(
            0,
            $inspected,
            'No routed method was inspected — the empty missing-list means nothing.'
        );

        $this->assertSame(
            [],
            $missing,
            sprintf(
                "appinfo/routes.php routes to these method(s), but SettingsController does not "
                ."implement them. The router will match the url and the dispatcher will then "
                ."fail reflecting the method.\n  - %s",
                implode("\n  - ", $missing)
            )
        );

    }//end testSettingsControllerImplementsEveryRoutedMethodAsPublic()


    /**
     * The write methods must keep the admin-required default posture.
     *
     * Nextcloud's SecurityMiddleware requires an admin session unless the
     * DISPATCHED method declares `#[NoAdminRequired]` or `#[PublicPage]`. Both
     * settings writes deliberately declare neither, which is what REQ-CFG-002
     * mandates. This test is the guard against the failure docudesk nearly
     * shipped: copying `#[NoAdminRequired]` onto a write because a sibling READ
     * method carried it.
     *
     * @return void
     */
    public function testSettingsWritesDoNotRelaxTheAdminRequiredDefault(): void
    {
        $reflection = new ReflectionClass(SettingsController::class);

        $inspected = 0;

        foreach (['update', 'create'] as $method) {
            $this->assertTrue($reflection->hasMethod($method), sprintf('%s() must exist', $method));

            $handle     = $reflection->getMethod($method);
            $attributes = [];
            foreach ($handle->getAttributes() as $attribute) {
                $attributes[] = $attribute->getName();
            }

            $docComment = (string) $handle->getDocComment();
            $inspected++;

            $this->assertNotContains(
                'OCP\AppFramework\Http\Attribute\NoAdminRequired',
                $attributes,
                sprintf(
                    'SettingsController::%s() is an instance-wide config WRITE and must not '
                    .'carry #[NoAdminRequired].',
                    $method
                )
            );

            $this->assertNotContains(
                'OCP\AppFramework\Http\Attribute\PublicPage',
                $attributes,
                sprintf('SettingsController::%s() must not be a public page.', $method)
            );

            $this->assertStringNotContainsString(
                '@NoAdminRequired',
                $docComment,
                sprintf(
                    'SettingsController::%s() must not carry the legacy @NoAdminRequired '
                    .'annotation either — the middleware honours it exactly like the attribute.',
                    $method
                )
            );
        }//end foreach

        $this->assertSame(
            2,
            $inspected,
            'Both write methods must have been inspected; a lower count means the loop '
            .'silently skipped one and the assertions above proved nothing.'
        );

    }//end testSettingsWritesDoNotRelaxTheAdminRequiredDefault()


}//end class
