<?php

/**
 * Golden test for ContentCatalogueController::index, driven the way the framework dispatcher does.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\ContentCatalogueController;
use OCA\Portaliq\Service\DerivedCatalogueFacets;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\PublicCatalogue;
use OCA\Portaliq\Service\PublicCatalogueQuery;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;
use TypeError;

/**
 * What a request with given query values gets, and what the resolver, the
 * session and the catalogue were asked, in order. Each call goes through
 * dispatch(), which fills the method's parameters from the request as the
 * framework dispatcher does, so a value the method reads itself and a value
 * the dispatcher hands over are the same request. Items are recorded by id.
 */
#[CoversClass(ContentCatalogueController::class)]
#[UsesClass(PublicCatalogueQuery::class)]
#[UsesClass(DerivedCatalogueFacets::class)]
#[UsesClass(PortalSessionService::class)]
#[UsesClass(\OCA\Portaliq\Service\CatalogueScope::class)]
#[UsesClass(\OCA\Portaliq\Service\CatalogueScopeFilter::class)]
class ContentCatalogueIndexGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../Fixtures/Golden/content-catalogue-index.json';

	/**
	 * @return void
	 */
	public function testEveryRequestMatchesTheGolden(): void {
		$actual = [];
		foreach ($this->worlds() as $name => $world) {
			$actual[$name] = $this->trace(world: $world);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertSame(expected: array_keys($expected), actual: array_keys($actual));
		foreach ($expected as $name => $case) {
			$this->assertSame(expected: $case, actual: $actual[$name], message: $name);
		}
	}//end testEveryRequestMatchesTheGolden()

	/**
	 * One request.
	 *
	 * @param array<string, mixed> $world `params`, `auth`, `subject`, `portal`, `visitor`.
	 *
	 * @return array<string, mixed> The answer and the calls.
	 */
	private function trace(array $world): array {
		$log     = [];
		$params  = ($world['params'] ?? []);
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($params): mixed {
				if (array_key_exists($key, $params) === true) {
					return $params[$key];
				}

				return $default;
			}
		);
		$request->method('getHeader')->willReturnCallback(
			static function (string $name) use ($world): string {
				if ($name === 'Authorization') {
					return ($world['auth'] ?? '');
				}

				return '';
			}
		);

		$resolver = $this->createMock(originalClassName: PortalResolver::class);
		$resolver->method('resolve')->willReturnCallback(
			static function ($asked, ?string $portalSlug = null) use (&$log, $world): ?array {
				$log[] = ['resolver.resolve', $portalSlug];
				if (array_key_exists('portal', $world) === true) {
					return $world['portal'];
				}

				return ['slug' => 'zuid'];
			}
		);
		$session = $this->createMock(originalClassName: PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturnCallback(
			static function (string $header) use (&$log, $world): ?array {
				$log[] = ['session.resolveFromBearer', $header];
				return ($world['subject'] ?? null);
			}
		);
		$catalogue = $this->createMock(originalClassName: PublicCatalogue::class);
		$catalogue->method('itemsFor')->willReturnCallback(
			function (string $portal) use (&$log, $world): array {
				$log[] = ['catalogue.itemsFor', $portal];
				return array_merge($this->items(), ($world['more'] ?? []));
			}
		);
		$catalogue->method('visitorValuesFor')->willReturnCallback(
			static function (string $portal, string $appId, array $subject) use (&$log, $world): array {
				$log[] = ['catalogue.visitorValuesFor', $portal, $appId, $subject];
				return ($world['visitor'] ?? []);
			}
		);

		$controller = new ContentCatalogueController(appName: 'portaliq', request: $request, resolver: $resolver, session: $session, catalogue: $catalogue);

		return $this->answer(controller: $controller, params: $params, log: $log);
	}//end trace()

	/**
	 * Dispatch and shape the answer.
	 *
	 * @param ContentCatalogueController $controller The controller.
	 * @param array<string, mixed>       $params     The request's values.
	 * @param array<int, mixed>          $log        The call log, read after the call.
	 *
	 * @return array<string, mixed> The answer.
	 */
	private function answer(ContentCatalogueController $controller, array $params, array &$log): array {
		try {
			$response = $this->dispatch(controller: $controller, params: $params);
		} catch (TypeError) {
			return ['threw' => 'TypeError', 'calls' => $log];
		}

		$data = $response->getData();
		if (isset($data['items']) === true) {
			$data['items'] = array_column($data['items'], 'id');
		}

		return ['status' => $response->getStatus(), 'cache' => $response->getHeaders()['Cache-Control'] ?? null, 'data' => $data, 'calls' => $log];
	}//end answer()

	/**
	 * Fill index()'s parameters from the request like the framework dispatcher: the
	 * value or the default, int and bool cast by settype unless null, the rest as is.
	 *
	 * @param ContentCatalogueController $controller The controller.
	 * @param array<string, mixed>       $params     The request's values.
	 *
	 * @return \OCP\AppFramework\Http\JSONResponse The response.
	 */
	private function dispatch(ContentCatalogueController $controller, array $params): \OCP\AppFramework\Http\JSONResponse {
		$arguments = [];
		foreach ((new ReflectionMethod($controller, 'index'))->getParameters() as $parameter) {
			$value = $parameter->getDefaultValue();
			if (array_key_exists($parameter->getName(), $params) === true) {
				$value = $params[$parameter->getName()];
			}

			$type = $parameter->getType();
			if ($value !== null && $type instanceof ReflectionNamedType && $type->getName() === 'int') {
				settype($value, 'integer');
			}

			$arguments[] = $value;
		}

		return $controller->index(...$arguments);
	}//end dispatch()

	/**
	 * The catalogue a request sees.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function items(): array {
		$today = date('Y-m-d');

		return [
			[
				'id' => 'a:1',
				'type' => 'news',
				'title' => 'Zwembad open',
				'kind' => 'Bericht',
				'category' => 'c1',
				'date' => $today,
				'audience' => 'Inwoners',
				'facets' => ['Group' => ['g1'], 'Plain' => ['p']],
			],
			[
				'id' => 'a:2',
				'type' => 'event',
				'title' => 'Avondmarkt',
				'kind' => 'Markt',
				'category' => 'c2',
				'date' => '2099-05-01',
				'endDate' => '2099-05-02',
				'facets' => ['Group' => ['g2']],
			],
			[
				'id' => 'b:3',
				'type' => 'event',
				'title' => 'Beurs',
				'kind' => 'Beurs',
				'category' => 'c1',
				'date' => '2001-01-01',
				'facets' => ['Plain' => ['p', 'q']],
			],
			[
				'id' => 'myapp:4',
				'type' => 'page',
				'title' => 'Aanvraag zwemles',
				'kind' => 'Dienst',
				'summary' => 'zwem',
				'category' => 'c3',
				'facets' => ['Group' => ['visitor', 'g1']],
			],
			['id' => 'myapp:5', 'type' => 'page', 'title' => 'Apotheek', 'category' => 'c3', 'date' => '2050-01-01'],
		];
	}//end items()

	/**
	 * The requests.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function worlds(): array {
		$group  = json_encode(['Group' => ['visitor', 'other'], 'Empty' => ['visitor'], 'Plain' => ['p']]);
		$anne   = ['subjectRef' => 'anne'];
		$bearer = 'Bearer t-1';

		return [
			'nothing asked'                 => [],
			'unknown portal'                => ['portal' => null, 'params' => ['portal' => 'nope', 'search' => 'zwem']],
			'portal named'                  => ['params' => ['portal' => 'asked']],
			'sign-in portal, anonymous'     => [
				'portal' => ['slug' => 'zuid', 'authentication' => ['modes' => ['digid'], 'public' => false, 'access' => 'signedIn']],
				'params' => ['search' => 'zwem'],
			],
			'search'                        => ['params' => ['search' => 'zwem']],
			'search cut at 200'             => ['params' => ['search' => str_repeat(' ', 200) . 'zwem']],
			'search just inside 200'        => ['params' => ['search' => str_repeat(' ', 196) . 'zwem']],
			'types trimmed'                 => ['params' => ['types' => ' event, ,page ']],
			'types empty'                   => ['params' => ['types' => ', ,']],
			'filters'                       => ['params' => ['filters' => json_encode(['Plain' => ['p']])]],
			'filters junk'                  => ['params' => ['filters' => 'not json']],
			'filters scalar json'           => ['params' => ['filters' => '3']],
			'sort title'                    => ['params' => ['sort' => 'title']],
			'sort date'                     => ['params' => ['sort' => 'date']],
			'sort dateDesc'                 => ['params' => ['sort' => 'dateDesc']],
			'sort bogus'                    => ['params' => ['sort' => 'bogus']],
			'page and limit'                => ['params' => ['page' => '2', 'limit' => '2']],
			'page and limit as text'        => ['params' => ['page' => 'x', 'limit' => '2abc']],
			'page as array'                 => ['params' => ['page' => ['2'], 'limit' => []]],
			'limit null'                    => ['params' => ['limit' => null]],
			'limit only'                    => ['params' => ['limit' => '2']],
			'page only'                     => [
				'params' => ['page' => '2'],
				'more' => array_map(static fn (int $n): array => ['id' => 'more:' . $n, 'type' => 'page', 'title' => 'Item ' . $n], range(1, 8)),
			],
			'upcoming on'                   => ['params' => ['upcoming' => '1']],
			'upcoming other value'          => ['params' => ['upcoming' => 'true']],
			'app'                           => ['params' => ['app' => 'myapp']],
			'app malformed'                 => ['params' => ['app' => 'Bad App!']],
			'app too long'                  => ['params' => ['app' => 'a' . str_repeat('b', 64)]],
			'categories'                    => ['params' => ['categories' => 'c1,,c2 ']],
			'range schoolYear'              => ['params' => ['range' => 'schoolYear']],
			'range other'                   => ['params' => ['range' => 'calendarYear']],
			'facetsBy'                      => ['params' => ['facetsBy' => json_encode(['kind' => 'Soort', 'news' => 'Nieuws', 'audience' => 'Voor wie'])]],
			'facetsBy junk'                 => ['params' => ['facetsBy' => 'x']],
			'visitor signed in'             => [
				'params' => ['filters' => $group, 'app' => 'myapp'],
				'auth' => $bearer,
				'subject' => $anne,
				'visitor' => ['Group' => ['g1']],
			],
			'visitor signed in, no values'  => ['params' => ['filters' => $group, 'app' => 'myapp'], 'auth' => $bearer, 'subject' => $anne],
			'visitor narrows'               => [
				'params' => ['filters' => json_encode(['Group' => ['visitor']]), 'app' => 'myapp'],
				'auth' => $bearer,
				'subject' => $anne,
				'visitor' => ['Group' => ['g1']],
			],
			'visitor anonymous'             => ['params' => ['filters' => $group, 'app' => 'myapp']],
			'visitor without app'           => ['params' => ['filters' => $group], 'auth' => $bearer, 'subject' => $anne],
			'visitor with malformed app'    => ['params' => ['filters' => $group, 'app' => 'Bad App!'], 'auth' => $bearer, 'subject' => $anne],
			'forged subject in the query'   => [
				'params' => ['filters' => $group, 'app' => 'myapp', 'subject' => 'mallory', 'subjectRef' => 'mallory', 'userId' => 'mallory'],
				'auth' => $bearer,
				'subject' => $anne,
				'visitor' => ['Group' => ['g1']],
			],
			'search as array'               => ['params' => ['search' => ['zwem']]],
			'types as array'                => ['params' => ['types' => ['event']]],
			'filters as array'              => ['params' => ['filters' => ['Plain' => ['p']]]],
			'sort as number'                => ['params' => ['sort' => 3]],
			'upcoming as array'             => ['params' => ['upcoming' => ['1']]],
			'app as array'                  => ['params' => ['app' => ['myapp']]],
			'categories as array'           => ['params' => ['categories' => ['c1']]],
			'range as array'                => ['params' => ['range' => ['schoolYear']]],
			'facetsBy as array'             => ['params' => ['facetsBy' => ['kind' => 'x']]],
			'portal as array'               => ['params' => ['portal' => ['asked']]],
			'search null'                   => ['params' => ['search' => null]],
			'everything together'           => [
				'params' => [
					'portal' => 'asked', 'search' => 'a', 'types' => 'event,page', 'filters' => '{}', 'sort' => 'title', 'page' => '1',
					'limit' => '1', 'upcoming' => '1', 'app' => 'myapp', 'categories' => 'c3', 'range' => '', 'facetsBy' => '{}',
				],
			],
		];
	}//end worlds()
}//end class
