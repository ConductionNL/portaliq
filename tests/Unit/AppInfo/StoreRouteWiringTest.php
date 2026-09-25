<?php

/**
 * Tests for the store plane's route half (WOO-559).
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

use PHPUnit\Framework\TestCase;

/**
 * The store plane has two halves and the failure mode of missing either is
 * quiet. `src/manifest.json` declares a `type: "store"` page whose shared
 * component calls `/apps/portaliq/api/store/items`; OpenRegister's
 * `AppHost\Routes::standard()` supplies those routes to apps that adopt its
 * table, and Portaliq builds its own table instead.
 *
 * Measured against the dev instance on 2026-09-09, before this change:
 *   GET /index.php/apps/portaliq/api/store/items -> 200 text/html
 *
 * Not a 404: the route fell through to the SPA catch-all (`/{path}`), which
 * answered the JSON caller with the app shell, and the page reported "The
 * store registry did not answer" for a registry it never reached. This test
 * pins the ROUTE half — the two entries exist, carry the engine's url + verb
 * + slug bound, and sit ahead of the catch-all. The CONTROLLER half (the DI
 * alias) is pinned by StorePlaneRegistrarTest; a route without it is a
 * dispatch-time 500, not a 404.
 *
 * @coversNothing
 */
final class StoreRouteWiringTest extends TestCase {

	/**
	 * The engine's store surface: route name => [url, verb, requirements|null].
	 *
	 * Verbatim from OpenRegister's `AppHost\Routes::canonicalRoutes()`, which
	 * is the table the shared store page was written against. The slug bound
	 * is the same regex the engine's controller re-checks in the body, so a
	 * drift here would let the router accept what the controller rejects.
	 *
	 * @var array<string, array{0: string, 1: string, 2: array<string, string>|null}>
	 */
	private const STORE_ROUTES = [
		'store#search' => ['/api/store/items', 'GET', null],
		'store#install' => ['/api/store/items/{slug}/install', 'POST', ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],
	];

	/**
	 * The SPA catch-all every declared API route has to precede.
	 */
	private const CATCH_ALL = 'dashboard#catchAll';

	/**
	 * Load and return the declared route entries.
	 *
	 * Evaluated, not grepped: a string match is satisfied by the route name
	 * inside a comment and never sees the url/verb it is paired with.
	 *
	 * @return array<int, array<string, mixed>> The declared route entries.
	 */
	private function declaredRoutes(): array {
		$routesFile = __DIR__ . '/../../../appinfo/routes.php';
		$this->assertFileExists($routesFile, 'appinfo/routes.php must exist');

		$declaration = require $routesFile;

		$this->assertIsArray($declaration, 'appinfo/routes.php must return an array');
		$this->assertArrayHasKey('routes', $declaration, "appinfo/routes.php must return a 'routes' key");
		$this->assertIsArray($declaration['routes'], "'routes' must be an array");

		return $declaration['routes'];
	}//end declaredRoutes()

	/**
	 * The position of the first route with the given name, or null.
	 *
	 * @param array<int, array<string, mixed>> $routes The declared routes.
	 * @param string                           $name   The route name.
	 *
	 * @return int|null
	 */
	private function positionOf(array $routes, string $name): ?int {
		foreach ($routes as $index => $route) {
			if (($route['name'] ?? null) === $name) {
				return (int)$index;
			}
		}

		return null;
	}//end positionOf()

	/**
	 * Positive control for every assertion below.
	 *
	 * All of them search the evaluated table. If that table were empty — a
	 * moved file, a refactor that changed the return shape — an "everything is
	 * present" result would be manufactured for free.
	 *
	 * @return void
	 */
	public function testRouteTableIsNonEmptyAndWellShaped(): void {
		$routes = $this->declaredRoutes();

		$this->assertGreaterThan(
			0,
			count($routes),
			'The evaluated route table is empty — every route assertion below would pass vacuously.'
		);

		$inspected = 0;
		foreach ($routes as $route) {
			$this->assertIsArray($route, 'Every route entry must be an array');
			$this->assertArrayHasKey('name', $route, 'Every route entry must declare a name');
			$this->assertArrayHasKey('url', $route, 'Every route entry must declare a url');
			$this->assertArrayHasKey('verb', $route, 'Every route entry must declare a verb');
			$inspected++;
		}

		$this->assertGreaterThan(0, $inspected, 'No route entry was inspected.');
		$this->assertNotNull(
			$this->positionOf($routes, self::CATCH_ALL),
			'The SPA catch-all is missing; the ordering assertion below would compare against nothing.'
		);

	}//end testRouteTableIsNonEmptyAndWellShaped()

	/**
	 * Both engine store routes are declared with the engine's url, verb and bound.
	 *
	 * Asserted per entry, so a table that carries `store#search` and forgets
	 * `store#install` (the shape a "search works, install 405s" bug takes) is
	 * reported by name.
	 *
	 * @return void
	 */
	public function testStoreRoutesAreDeclaredWithTheEngineUrlVerbAndSlugBound(): void {
		$routes = $this->declaredRoutes();
		$inspected = 0;
		$missing = [];

		foreach (self::STORE_ROUTES as $name => $expected) {
			[$url, $verb, $requirements] = $expected;
			$inspected++;

			$found = false;
			foreach ($routes as $route) {
				if (($route['name'] ?? null) !== $name) {
					continue;
				}

				if (($route['url'] ?? null) !== $url || ($route['verb'] ?? null) !== $verb) {
					continue;
				}

				if ($requirements !== null && ($route['requirements'] ?? null) !== $requirements) {
					continue;
				}

				$found = true;
				break;
			}

			if ($found === false) {
				$missing[] = sprintf('%s (%s %s)', $name, $verb, $url);
			}
		}//end foreach

		$this->assertGreaterThan(0, $inspected, 'No store route was inspected — the expectation table is empty.');
		$this->assertSame(
			[],
			$missing,
			sprintf(
				'appinfo/routes.php does not declare these engine store route(s) with the '
				. "engine's url/verb/slug bound. Without them the store page's call falls "
				. "through to the SPA catch-all and receives HTML 200.\n  - %s",
				implode("\n  - ", $missing)
			)
		);

	}//end testStoreRoutesAreDeclaredWithTheEngineUrlVerbAndSlugBound()

	/**
	 * The store routes sit AHEAD of the SPA catch-all.
	 *
	 * Symfony matches in insertion order and the catch-all's `.+` matches
	 * slashes, so an entry declared after it is unreachable — declared, and
	 * still answered with HTML. Position is part of the contract, not a style.
	 *
	 * @return void
	 */
	public function testStoreRoutesAreDeclaredAheadOfTheSpaCatchAll(): void {
		$routes = $this->declaredRoutes();
		$catchAll = $this->positionOf($routes, self::CATCH_ALL);
		$this->assertNotNull($catchAll, 'The SPA catch-all must be declared.');

		$inspected = 0;
		foreach (array_keys(self::STORE_ROUTES) as $name) {
			$position = $this->positionOf($routes, $name);
			$this->assertNotNull($position, sprintf('%s must be declared.', $name));
			$this->assertLessThan(
				$catchAll,
				$position,
				sprintf(
					'%s is declared at position %d, AFTER the SPA catch-all at %d: the '
					. 'catch-all matches first and the route is dead.',
					$name,
					$position,
					$catchAll
				)
			);
			$inspected++;
		}

		$this->assertSame(count(self::STORE_ROUTES), $inspected, 'Every store route must have been positioned.');

	}//end testStoreRoutesAreDeclaredAheadOfTheSpaCatchAll()

	/**
	 * Portaliq ships NO StoreController of its own.
	 *
	 * The engine's alias is registered `unlessLeafDefinesIt`: a leaf class of
	 * that name silently wins, and the manifest's promise ("openregister hosts
	 * the store plane, so this app writes NO store controller") stops being
	 * true without any test noticing. Asserted on the file AND the autoloader,
	 * because a class can also arrive through a classmap.
	 *
	 * @return void
	 */
	public function testPortaliqShipsNoStoreControllerOfItsOwn(): void {
		$leafFile = __DIR__ . '/../../../lib/Controller/StoreController.php';

		$this->assertFileDoesNotExist(
			$leafFile,
			'lib/Controller/StoreController.php exists — it shadows the engine alias registered by '
			. 'StorePlaneRegistrar (ADR-114 Decision 4: a leaf app declares its store, it does not implement one).'
		);
		$this->assertFalse(
			class_exists('OCA\\Portaliq\\Controller\\StoreController'),
			'OCA\\Portaliq\\Controller\\StoreController is autoloadable as a real class; the engine alias '
			. 'would be skipped for it.'
		);

	}//end testPortaliqShipsNoStoreControllerOfItsOwn()

	/**
	 * The SPA catch-all refuses `api/…` paths, so an UNDECLARED API route 404s.
	 *
	 * This is the property whose absence made the bug quiet: `.+` matched
	 * `api/store/items`, the app shell went back with HTTP 200, and the JSON
	 * caller reported a registry that did not answer. Evaluated against
	 * representative paths rather than compared as a regex string, so an
	 * equivalent spelling stays green and a weaker one does not. The SPA's own
	 * deep links are the positive control: a lookahead that also refused them
	 * would break the admin UI while making this assertion pass.
	 *
	 * @return void
	 */
	public function testSpaCatchAllDoesNotSwallowApiPaths(): void {
		$routes = $this->declaredRoutes();
		$position = $this->positionOf($routes, self::CATCH_ALL);
		$this->assertNotNull($position, 'The SPA catch-all must be declared.');

		$requirement = $routes[$position]['requirements']['path'] ?? null;
		$this->assertIsString($requirement, 'The SPA catch-all must bound {path} with a requirement.');

		// Symfony anchors a requirement around the whole placeholder.
		$pattern = '#^(?:' . $requirement . ')$#';

		$swallowed = [];
		foreach (['api/store/items', 'api/store/items/some-item/install', 'api/undeclared'] as $apiPath) {
			if (preg_match($pattern, $apiPath) === 1) {
				$swallowed[] = $apiPath;
			}
		}

		$this->assertSame(
			[],
			$swallowed,
			sprintf(
				'The SPA catch-all (%s) still matches these api/ paths; an undeclared API route is '
				. "answered with the app shell and HTTP 200 instead of a 404.\n  - %s",
				$requirement,
				implode("\n  - ", $swallowed)
			)
		);

		$lost = [];
		foreach (['store', 'portals/42', 'api-keys', 'settings/general'] as $spaPath) {
			if (preg_match($pattern, $spaPath) !== 1) {
				$lost[] = $spaPath;
			}
		}

		$this->assertSame(
			[],
			$lost,
			sprintf(
				"These SPA deep links no longer reach the catch-all (%s); the admin UI would 404 on reload.\n  - %s",
				$requirement,
				implode("\n  - ", $lost)
			)
		);

	}//end testSpaCatchAllDoesNotSwallowApiPaths()

}//end class
