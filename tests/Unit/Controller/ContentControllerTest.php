<?php

/**
 * ContentControllerTest
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

use OCA\Portaliq\Controller\ContentController;
use OCA\Portaliq\Service\CmsReader;
use OCA\Portaliq\Service\WebsiteResolver;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The public content API's boundary behaviour.
 *
 * Two things here are security properties rather than features, and both look
 * completely normal when they break:
 *
 *   - every miss returns the SAME body, so the API is not an oracle for which
 *     sites exist or which routes are drafted;
 *   - the caching headers differ by audience, so a CDN cannot pool one
 *     visitor's response across everybody.
 */
class ContentControllerTest extends TestCase {

	/**
	 * Resolves the serving website.
	 *
	 * @var WebsiteResolver&MockObject
	 */
	private WebsiteResolver $resolver;

	/**
	 * Reads website-scoped content.
	 *
	 * @var CmsReader&MockObject
	 */
	private CmsReader $reader;

	/**
	 * The incoming request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;


	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->resolver = $this->createMock(WebsiteResolver::class);
		$this->reader = $this->createMock(CmsReader::class);
		$this->request = $this->createMock(IRequest::class);
	}//end setUp()


	/**
	 * Build the controller, optionally with an Authorization header.
	 *
	 * @param string $authorization The Authorization header value.
	 *
	 * @return ContentController The controller.
	 */
	private function controller(string $authorization = ''): ContentController {
		$this->request->method('getHeader')->willReturnCallback(
			static function (string $name) use ($authorization): string {
				if ($name === 'Authorization') {
					return $authorization;
				}

				return '';
			}
		);

		return new ContentController(
			appName: 'portaliq',
			request: $this->request,
			resolver: $this->resolver,
			reader: $this->reader
		);
	}//end controller()


	/**
	 * A published website fixture.
	 *
	 * @return array The website.
	 */
	private function website(): array {
		return [
			'title' => 'Open Tilburg',
			'slug' => 'open-tilburg',
			'theme' => 'vng',
			'locales' => ['nl', 'en'],
			'authentication' => ['modes' => ['public']],
		];
	}//end website()


	/**
	 * An unresolved site is a 404 and nothing else.
	 *
	 * @return void
	 */
	public function testAnUnresolvedSiteIsNotFound(): void {
		$this->resolver->method('resolve')->willReturn(null);

		$response = $this->controller()->site();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'not_found'], $response->getData());
	}//end testAnUnresolvedSiteIsNotFound()


	/**
	 * Every miss shares one body.
	 *
	 * A different body per reason would tell a caller which sites exist and
	 * which routes are drafted — the two things this API must not disclose.
	 *
	 * @return void
	 */
	public function testEveryMissReturnsAnIdenticalBody(): void {
		$this->resolver->method('resolve')->willReturn(null);
		$controller = $this->controller();

		$bodies = [
			$controller->site()->getData(),
			$controller->menus()->getData(),
			$controller->pages()->getData(),
			$controller->page(route: '/anything')->getData(),
			$controller->glossary()->getData(),
		];

		foreach ($bodies as $body) {
			$this->assertSame($bodies[0], $body);
		}
	}//end testEveryMissReturnsAnIdenticalBody()


	/**
	 * A resolved site returns its own presentation, without secrets.
	 *
	 * @return void
	 */
	public function testAResolvedSiteReturnsItsPresentation(): void {
		$this->resolver->method('resolve')->willReturn($this->website());

		$data = $this->controller()->site()->getData();

		$this->assertSame('Open Tilburg', $data['title']);
		$this->assertSame('vng', $data['theme']);
		$this->assertSame(['public'], $data['authentication']['modes']);
	}//end testAResolvedSiteReturnsItsPresentation()


	/**
	 * Only the authentication MODES are exposed.
	 *
	 * Provider configuration belongs in the credential broker; a public
	 * endpoint that echoes it back is the kind of leak nobody notices because
	 * the response still looks like an ordinary site record.
	 *
	 * @return void
	 */
	public function testProviderConfigurationIsNotExposed(): void {
		$website = $this->website();
		$website['authentication']['oidc'] = ['issuer' => 'https://idp.example', 'clientId' => 'secret-ish'];
		$this->resolver->method('resolve')->willReturn($website);

		$data = $this->controller()->site()->getData();

		$this->assertSame(['modes' => ['public']], $data['authentication']);
		$this->assertStringNotContainsString('idp.example', json_encode($data));
	}//end testProviderConfigurationIsNotExposed()


	/**
	 * An anonymous response is publicly cacheable.
	 *
	 * @return void
	 */
	public function testAnAnonymousResponseIsPubliclyCacheable(): void {
		$this->resolver->method('resolve')->willReturn($this->website());
		$this->reader->method('menus')->willReturn([]);

		$headers = $this->controller()->menus()->getHeaders();

		$this->assertStringContainsString('public', $headers['Cache-Control']);
	}//end testAnAnonymousResponseIsPubliclyCacheable()


	/**
	 * An authenticated response is never shared.
	 *
	 * This is the assertion that stands between a CDN and a cross-visitor
	 * leak, and the leak would happen at the edge where this instance's logs
	 * never see it.
	 *
	 * @return void
	 */
	public function testAnAuthenticatedResponseIsNeverShared(): void {
		$this->resolver->method('resolve')->willReturn($this->website());
		$this->reader->method('menus')->willReturn([]);

		$headers = $this->controller(authorization: 'Bearer whatever')->menus()->getHeaders();

		$this->assertStringContainsString('no-store', $headers['Cache-Control']);
		$this->assertStringNotContainsString('public', $headers['Cache-Control']);
	}//end testAnAuthenticatedResponseIsNeverShared()


	/**
	 * The audience is decided BEFORE the bearer is validated.
	 *
	 * An invalid token must not read from the anonymous cache slot and then be
	 * rejected — that is exactly how a per-visitor response ends up cached
	 * under the anonymous key.
	 *
	 * @return void
	 */
	public function testAnInvalidBearerIsStillTreatedAsAuthenticated(): void {
		$this->resolver->method('resolve')->willReturn($this->website());
		$this->reader->expects($this->once())
			->method('menus')
			->with(website: 'open-tilburg', locale: 'nl', audience: 'authenticated')
			->willReturn([]);

		$this->controller(authorization: 'Bearer definitely-not-valid')->menus();
	}//end testAnInvalidBearerIsStillTreatedAsAuthenticated()


	/**
	 * An unknown locale falls back to the site's default.
	 *
	 * @return void
	 */
	public function testAnUnknownLocaleFallsBackToTheSiteDefault(): void {
		$this->resolver->method('resolve')->willReturn($this->website());
		$this->reader->expects($this->once())
			->method('menus')
			->with(website: 'open-tilburg', locale: 'nl', audience: 'anonymous')
			->willReturn([]);

		$this->controller()->menus(site: 'open-tilburg', locale: 'de');
	}//end testAnUnknownLocaleFallsBackToTheSiteDefault()


	/**
	 * A supported locale is honoured.
	 *
	 * Without this, the fallback test above would also pass for a controller
	 * that ignored the locale entirely.
	 *
	 * @return void
	 */
	public function testASupportedLocaleIsHonoured(): void {
		$this->resolver->method('resolve')->willReturn($this->website());
		$this->reader->expects($this->once())
			->method('menus')
			->with(website: 'open-tilburg', locale: 'en', audience: 'anonymous')
			->willReturn([]);

		$this->controller()->menus(site: 'open-tilburg', locale: 'en');
	}//end testASupportedLocaleIsHonoured()


	/**
	 * A route without a leading slash is normalised.
	 *
	 * @return void
	 */
	public function testARouteIsNormalisedToALeadingSlash(): void {
		$this->resolver->method('resolve')->willReturn($this->website());
		$this->reader->expects($this->once())
			->method('page')
			->with(website: 'open-tilburg', route: '/over-ons', locale: 'nl', audience: 'anonymous')
			->willReturn(['title' => 'Over ons']);

		$this->controller()->page(route: 'over-ons');
	}//end testARouteIsNormalisedToALeadingSlash()


	/**
	 * A missing page is the same 404 as a missing site.
	 *
	 * @return void
	 */
	public function testAMissingPageIsTheSameNotFound(): void {
		$this->resolver->method('resolve')->willReturn($this->website());
		$this->reader->method('page')->willReturn(null);

		$response = $this->controller()->page(route: '/nope');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'not_found'], $response->getData());
	}//end testAMissingPageIsTheSameNotFound()


	/**
	 * A found page is returned as the reader shaped it.
	 *
	 * @return void
	 */
	public function testAFoundPageIsReturnedUnchanged(): void {
		$page = ['title' => 'Over ons', 'body' => ['type' => 'markdown', 'markdown' => '## Hoi']];
		$this->resolver->method('resolve')->willReturn($this->website());
		$this->reader->method('page')->willReturn($page);

		$this->assertSame($page, $this->controller()->page(route: '/over-ons')->getData());
	}//end testAFoundPageIsReturnedUnchanged()


	/**
	 * The glossary and page listing are website-scoped.
	 *
	 * @return void
	 */
	public function testListingsAreScopedToTheResolvedWebsite(): void {
		$this->resolver->method('resolve')->willReturn($this->website());
		$this->reader->expects($this->once())
			->method('glossary')
			->with(website: 'open-tilburg', locale: 'nl', audience: 'anonymous')
			->willReturn([]);
		$this->reader->expects($this->once())
			->method('pages')
			->with(website: 'open-tilburg', locale: 'nl', audience: 'anonymous')
			->willReturn([]);

		$this->controller()->glossary();
		$this->controller()->pages();
	}//end testListingsAreScopedToTheResolvedWebsite()


}//end class
