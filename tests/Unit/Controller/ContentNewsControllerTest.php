<?php

/**
 * ContentNewsController tests: the portal's sign-in gate applies, a miss is
 * the shared 404, and only a visitor without a bearer gets a cacheable answer.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-item-shows-on-a-portals-public-website-only-when-staff-put-it-there
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\ContentNewsController;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\PublicNewsReader;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-item-shows-on-a-portals-public-website-only-when-staff-put-it-there
 */
class ContentNewsControllerTest extends TestCase {

	/**
	 * A controller for a portal with these modes and this bearer.
	 *
	 * @param array<int, string>|null   $modes   The portal's modes; null for no portal.
	 * @param string                    $bearer  The Authorization header.
	 * @param array<string, mixed>|null $subject What the bearer resolves to.
	 * @param PublicNewsReader|null     $news    The reader.
	 *
	 * @return ContentNewsController
	 */
	private function controller(?array $modes, string $bearer = '', ?array $subject = null, ?PublicNewsReader $news = null): ContentNewsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => ($name === 'Authorization') ? $bearer : '');

		$resolver = $this->createMock(PortalResolver::class);
		$resolver->method('resolve')->willReturn(
			($modes === null) ? null : ['slug' => 'wilgenboom', 'authentication' => ['modes' => $modes]]
		);

		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn($subject);

		if ($news === null) {
			$news = $this->createMock(PublicNewsReader::class);
			$news->method('listFor')->willReturn([['id' => 'n1', 'title' => 'Nieuws']]);
			$news->method('itemFor')->willReturnCallback(
				static fn (string $portal, string $id): ?array => ($id === 'n1') ? ['id' => 'n1', 'body' => 'Tekst'] : null
			);
		}

		return new ContentNewsController('portaliq', $request, $resolver, $session, $news);
	}//end controller()

	/**
	 * The Cache-Control header the controller set. Read from the response's own
	 * headers, because `getHeaders()` merges in defaults through the server
	 * container, which a unit test has none of.
	 *
	 * @param \OCP\AppFramework\Http\Response $response The response.
	 *
	 * @return string
	 */
	private function cacheControl(\OCP\AppFramework\Http\Response $response): string {
		$headers = \Closure::bind(fn (): ?array => $this->headers, $response, \OCP\AppFramework\Http\Response::class)();

		return (string)($headers['Cache-Control'] ?? '');
	}//end cacheControl()

	public function testAPublicPortalServesItsNewsCacheably(): void {
		$response = $this->controller(['public', 'digid'])->index();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame([['id' => 'n1', 'title' => 'Nieuws']], $response->getData()['items']);
		$this->assertSame('public, max-age=300, must-revalidate', $this->cacheControl($response));
	}//end testAPublicPortalServesItsNewsCacheably()

	public function testTheLimitReachesTheReader(): void {
		$news = $this->createMock(PublicNewsReader::class);
		$news->expects($this->once())->method('listFor')->with('wilgenboom', 3)->willReturn([]);

		$this->controller([], '', null, $news)->index(null, 3);
	}//end testTheLimitReachesTheReader()

	public function testASignInOnlyPortalRefusesAVisitorWithoutASession(): void {
		$response = $this->controller(['digid'])->index();

		$this->assertSame(401, $response->getStatus());
		$this->assertSame(['digid'], $response->getData()['authentication']['modes']);
		$this->assertSame('private, no-store', $this->cacheControl($response));
	}//end testASignInOnlyPortalRefusesAVisitorWithoutASession()

	public function testASignedInVisitorGetsAPrivateAnswer(): void {
		$response = $this->controller(['digid'], 'Bearer x', ['trust' => 'substantial'])->show('n1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('private, no-store', $this->cacheControl($response));
	}//end testASignedInVisitorGetsAPrivateAnswer()

	public function testAnUnknownPortalOrItemIsTheShared404(): void {
		$this->assertSame(404, $this->controller(null)->index()->getStatus());
		$this->assertSame(404, $this->controller([])->show('missing')->getStatus());
	}//end testAnUnknownPortalOrItemIsTheShared404()
}//end class
