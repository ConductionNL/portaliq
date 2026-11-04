<?php

/**
 * ContentNewsCatalogueGoldenTest
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\ContentCatalogueController;
use OCA\Portaliq\Controller\ContentNewsController;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\PublicCatalogue;
use OCA\Portaliq\Service\PublicNewsReader;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The public news and catalogue routes answer every portal and caller as
 * recorded: which portal they resolve, who may read, the refusal that names
 * the way in, and whether the answer may be cached.
 *
 * @covers \OCA\Portaliq\Controller\ContentNewsController
 * @covers \OCA\Portaliq\Controller\ContentCatalogueController
 * @covers \OCA\Portaliq\Controller\PublicContentGate
 * @covers \OCA\Portaliq\Controller\BearerCachedJson
 * @uses   \OCA\Portaliq\Service\PortalSessionService
 * @uses   \OCA\Portaliq\Service\PublicCatalogueQuery
 * @uses   \OCA\Portaliq\Service\DerivedCatalogueFacets
 */
class ContentNewsCatalogueGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../Fixtures/Golden/content-news-catalogue.json';

	/**
	 * Each portal situation: the resolved portal (null for none) and the
	 * subject behind a bearer (null for none).
	 */
	private const SITUATIONS = [
		'unresolved' => [null, null],
		'open' => [['slug' => 'p-open'], null],
		'publicMode' => [['slug' => 'p-pub', 'authentication' => ['modes' => ['digid', 'public']]], null],
		'signInNoBearer' => [['slug' => 'p-in', 'authentication' => ['modes' => ['digid', 7], 'minTrust' => 'substantial']], null],
		'signInLowTrust' => [['slug' => 'p-in', 'authentication' => ['modes' => ['digid'], 'minTrust' => 'substantial']], ['trust' => 'low']],
		'signInEnoughTrust' => [['slug' => 'p-in', 'authentication' => ['modes' => ['digid'], 'minTrust' => 'substantial']], ['trust' => 'high']],
		'signInDefaultTrust' => [['slug' => 'p-in', 'authentication' => ['modes' => ['eherkenning']]], ['trust' => 'low']],
	];

	/**
	 * What the collaborators were asked so far.
	 *
	 * @var list<mixed>
	 */
	private array $log = [];

	/**
	 * Every route answers as recorded.
	 *
	 * @return void
	 */
	public function testEveryRouteAnswersAsRecorded(): void {
		$actual = [];
		foreach (self::SITUATIONS as $name => [$portal, $subject]) {
			foreach (['anonymous' => '', 'bearer' => 'Bearer t-1'] as $who => $header) {
				$news = fn (): ContentNewsController => $this->news(portal: $portal, subject: $subject, header: $header);
				$catalogue = fn (): ContentCatalogueController => $this->catalogue(portal: $portal, subject: $subject, header: $header);
				$actual[$name][$who] = [
					'news.index' => $this->answer(response: fn (): JSONResponse => $news()->index(portal: 'asked', limit: 2)),
					'news.show' => $this->answer(response: fn (): JSONResponse => $news()->show(id: 'n-1', portal: 'asked')),
					'news.showMissing' => $this->answer(response: fn (): JSONResponse => $news()->show(id: 'missing')),
					'catalogue.kinds' => $this->answer(response: fn (): JSONResponse => $catalogue()->kinds(portal: 'asked')),
					'catalogue.detail' => $this->answer(response: fn (): JSONResponse => $catalogue()->detail(portal: 'asked', app: 'a', kind: 'k', slug: 's')),
					'catalogue.detailMissing' => $this->answer(response: fn (): JSONResponse => $catalogue()->detail(slug: 'missing')),
					'catalogue.index' => $this->answer(response: fn (): JSONResponse => $catalogue()->index(portal: 'asked')),
				];
			}
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode((string)json_encode($actual), true));
	}//end testEveryRouteAnswersAsRecorded()

	/**
	 * Status, body, cache header and the collaborator log of one response.
	 *
	 * @param callable $response Makes the response.
	 *
	 * @return array<string, mixed>
	 */
	private function answer(callable $response): array {
		$this->log = [];
		$made = $response();

		return ['status' => $made->getStatus(), 'data' => $made->getData(), 'cache' => ((new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($made)['Cache-Control'] ?? null), 'log' => $this->log];
	}//end answer()

	/**
	 * The request, resolver and session for one situation.
	 *
	 * @param array<string, mixed>|null $portal  The resolved portal.
	 * @param array<string, mixed>|null $subject The subject behind the bearer.
	 * @param string                    $header  The Authorization header.
	 *
	 * @return array{0: IRequest, 1: PortalResolver, 2: PortalSessionService}
	 */
	private function shared(?array $portal, ?array $subject, string $header): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null): mixed {
				if ($key === 'search') {
					return 'zwem';
				}

				return $default;
			}
		);
		$request->method('getHeader')->willReturnCallback(function (string $name) use ($header): string {
			$this->log[] = ['getHeader' => $name];
			return ($name === 'Authorization') ? $header : '';
		});
		$resolver = $this->createMock(PortalResolver::class);
		$resolver->method('resolve')->willReturnCallback(function (IRequest $request, ?string $portalSlug = null) use ($portal): ?array {
			$this->log[] = ['resolve' => $portalSlug];
			return $portal;
		});
		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturnCallback(function (?string $authorizationHeader) use ($subject): ?array {
			$this->log[] = ['resolveFromBearer' => $authorizationHeader];
			if ($authorizationHeader === '') {
				return null;
			}

			return $subject;
		});

		return [$request, $resolver, $session];
	}//end shared()

	/**
	 * The news controller for one situation.
	 *
	 * @param array<string, mixed>|null $portal  The resolved portal.
	 * @param array<string, mixed>|null $subject The subject behind the bearer.
	 * @param string                    $header  The Authorization header.
	 *
	 * @return ContentNewsController
	 */
	private function news(?array $portal, ?array $subject, string $header): ContentNewsController {
		[$request, $resolver, $session] = $this->shared(portal: $portal, subject: $subject, header: $header);
		$reader = $this->createMock(PublicNewsReader::class);
		$reader->method('listFor')->willReturnCallback(function (string $portal, int $limit): array {
			$this->log[] = ['listFor' => [$portal, $limit]];
			return [['id' => 'n-1']];
		});
		$reader->method('itemFor')->willReturnCallback(function (string $portal, string $id): ?array {
			$this->log[] = ['itemFor' => [$portal, $id]];
			return ($id === 'missing') ? null : ['id' => $id];
		});

		return new ContentNewsController('portaliq', $request, $resolver, $session, $reader);
	}//end news()

	/**
	 * The catalogue controller for one situation.
	 *
	 * @param array<string, mixed>|null $portal  The resolved portal.
	 * @param array<string, mixed>|null $subject The subject behind the bearer.
	 * @param string                    $header  The Authorization header.
	 *
	 * @return ContentCatalogueController
	 */
	private function catalogue(?array $portal, ?array $subject, string $header): ContentCatalogueController {
		[$request, $resolver, $session] = $this->shared(portal: $portal, subject: $subject, header: $header);
		$catalogue = $this->createMock(PublicCatalogue::class);
		$catalogue->method('kindsFor')->willReturnCallback(function (string $portal): array {
			$this->log[] = ['kindsFor' => $portal];
			return [['kind' => 'course']];
		});
		$catalogue->method('detailFor')->willReturnCallback(function (string $portal, string $appId, string $kind, string $slug): ?array {
			$this->log[] = ['detailFor' => [$portal, $appId, $kind, $slug]];
			return ($slug === 'missing') ? null : ['slug' => $slug];
		});
		$catalogue->method('itemsFor')->willReturn([]);
		$catalogue->method('visitorValuesFor')->willReturn([]);

		return new ContentCatalogueController('portaliq', $request, $resolver, $session, $catalogue);
	}//end catalogue()
}//end class
