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

use OCA\Portaliq\Contribution\PortalContributionFilter;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Controller\ContentController;
use OCA\Portaliq\Service\Assistant\PublicAssistantChannel;
use OCA\Portaliq\Service\Cms\PortalShell;
use OCA\Portaliq\Service\CmsReader;
use OCA\Portaliq\Service\PortalNoticeReader;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\TrafficConfigResolver;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

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
	 * Resolves the serving portal.
	 *
	 * @var PortalResolver&MockObject
	 */
	private PortalResolver $resolver;

	/**
	 * Reads portal-scoped content.
	 *
	 * @var CmsReader&MockObject
	 */
	private CmsReader $reader;

	/**
	 * Aggregates the leaf apps' contributions. Nullable so the tests that
	 * predate the contribution bridge construct the controller unchanged.
	 *
	 * @var (PortalContributionRegistry&MockObject)|null
	 */
	private ?PortalContributionRegistry $registry = null;

	/** Set by the gate tests; null means the gate is never consulted. */
	private ?PortalSessionService $session = null;

	/**
	 * The notice reader, when a test sets one.
	 *
	 * @var PortalNoticeReader|null
	 */
	private ?PortalNoticeReader $notices = null;

	/**
	 * The Nextcloud session, when a test signs someone in.
	 *
	 * @var IUserSession|null
	 */
	private ?IUserSession $userSession = null;

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

		$this->resolver = $this->createMock(PortalResolver::class);
		$this->reader = $this->createMock(CmsReader::class);
		// The shell projection is the real one: a stub answering [] would let
		// the site() tests pass without the fields they assert.
		$this->reader->method('shell')->willReturnCallback(
			static fn (array $portal): array => (new PortalShell())->project(portal: $portal)
		);
		$this->request = $this->createMock(IRequest::class);
	}//end setUp()


	/**
	 * Build the controller, optionally with an Authorization header.
	 *
	 * @param string $authorization The Authorization header value.
	 *
	 * @return ContentController The controller.
	 */
	private function controller(string $authorization = '', ?PublicAssistantChannel $assistant = null): ContentController {
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
			reader: $this->reader,
			registry: ($this->registry ?? $this->createMock(PortalContributionRegistry::class)),
			contribFilter: new PortalContributionFilter(),
			logger: $this->createMock(LoggerInterface::class),
			// Resolves nobody unless a test says otherwise. Every pre-existing
			// assertion here uses a portal whose modes include `public`, so the
			// content gate never consults this — which is the point: adding the
			// gate must not change what a public portal serves.
			session: ($this->session ?? $this->createMock(PortalSessionService::class)),
			traffic: new TrafficConfigResolver(),
			urlGenerator: $this->urlGenerator(),
			notices: ($this->notices ?? $this->createMock(PortalNoticeReader::class)),
			userSession: ($this->userSession ?? $this->createMock(IUserSession::class)),
			assistant: $assistant
		);
	}//end controller()


	/**
	 * A URL generator that answers one absolute collector URL.
	 * @return IURLGenerator The double.
	 */
	private function urlGenerator(): IURLGenerator {
		$generator = $this->createMock(IURLGenerator::class);
		$generator->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $params = []): string => ($route === 'core.login.showLoginForm'
				? 'https://portaal.example/index.php/login?redirect_url=' . rawurlencode((string)$params['redirect_url'])
				: 'https://portaal.example/index.php/apps/portaliq/api/traffic')
		);
		$generator->method('linkToRoute')->willReturnCallback(
			static fn (string $route, array $params = []): string => '/index.php/apps/portaliq/portal/api/session/nextcloud?portal=' . $params['portal']
		);

		return $generator;
	}//end urlGenerator()


	/**
	 * A published portal fixture.
	 *
	 * @return array The portal.
	 */
	private function portal(): array {
		return [
			'title' => 'Open Tilburg',
			'slug' => 'open-tilburg',
			'theme' => 'vng',
			'locales' => ['nl', 'en'],
			'authentication' => ['modes' => ['public']],
		];
	}//end portal()


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
	 * operate-maintenance-notice T03 (REQ-OMN-001): the site record carries
	 * the notices active on the site surface of the resolved portal.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-notices/spec.md#requirement-a-notice-shows-on-every-page-during-its-window-req-omn-001
	 */
	public function testSiteCarriesActiveNotices(): void {
		$this->resolver->method('resolve')->willReturn($this->portal());
		$notice        = ['id' => 'n-1', 'message' => 'Onderhoud', 'level' => 'warning', 'linkLabel' => '', 'linkUrl' => '', 'endsAt' => '2026-10-04T02:00:00+00:00'];
		$this->notices = $this->createMock(PortalNoticeReader::class);
		$this->notices->expects($this->once())->method('active')->with('open-tilburg', 'site')->willReturn([$notice]);

		$data = $this->controller()->site()->getData();

		$this->assertSame([$notice], $data['notices']);
	}//end testSiteCarriesActiveNotices()


	/**
	 * A resolved site returns its own presentation, without secrets.
	 *
	 * @return void
	 */
	public function testAResolvedSiteReturnsItsPresentation(): void {
		$this->resolver->method('resolve')->willReturn($this->portal());

		$data = $this->controller()->site()->getData();

		$this->assertSame('Open Tilburg', $data['title']);
		$this->assertSame('vng', $data['theme']);
		$this->assertSame(['public'], $data['authentication']['modes']);
	}//end testAResolvedSiteReturnsItsPresentation()


	/**
	 * The site carries the header's shape and a declared register destination
	 * (portal-theme-blocks-and-contributed-pages REQ-PTB-004).
	 *
	 * @return void
	 */
	public function testTheSiteCarriesTheHeaderShapeAndTheRegisterDestination(): void {
		$portal = $this->portal();
		$portal['headerVariant'] = 'single';
		$portal['authentication']['register'] = '/registreren';
		$this->resolver->method('resolve')->willReturn($portal);

		$data = $this->controller()->site()->getData();

		$this->assertSame('single', $data['headerVariant']);
		$this->assertSame('/registreren', $data['authentication']['register']);
	}//end testTheSiteCarriesTheHeaderShapeAndTheRegisterDestination()


	/**
	 * A social link with a label and no destination is absent from the served
	 * site (portal-theme-blocks-and-contributed-pages REQ-PTB-005).
	 *
	 * @return void
	 */
	public function testASocialLinkWithoutADestinationIsNotServed(): void {
		$portal           = $this->portal();
		$portal['footer'] = ['socials' => [['label' => 'LinkedIn'], ['label' => 'Mastodon', 'href' => 'https://social.example']]];
		$this->resolver->method('resolve')->willReturn($portal);

		$data = $this->controller()->site()->getData();

		$this->assertSame([['label' => 'Mastodon', 'href' => 'https://social.example']], $data['footer']['socials']);
		$this->assertSame('', $data['footer']['colophon']);
	}//end testASocialLinkWithoutADestinationIsNotServed()


	/**
	 * The site serves the portal's regions, an empty map as a JSON object
	 * (portal-theme-blocks-and-contributed-pages REQ-PTB-009).
	 *
	 * @return void
	 */
	public function testTheSiteServesThePortalsRegions(): void {
		$portal            = $this->portal();
		$portal['regions'] = ['hero' => [['widgetKey' => 'hero', 'props' => ['title' => 'Welkom']]]];
		$this->resolver->method('resolve')->willReturn($portal);

		$data = $this->controller()->site()->getData();

		$this->assertSame('Welkom', $data['regions']['hero'][0]['props']['title']);
	}//end testTheSiteServesThePortalsRegions()


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
		$portal = $this->portal();
		$portal['authentication']['oidc'] = ['issuer' => 'https://idp.example', 'clientId' => 'secret-ish'];
		$this->resolver->method('resolve')->willReturn($portal);

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
		$this->resolver->method('resolve')->willReturn($this->portal());
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
		$this->resolver->method('resolve')->willReturn($this->portal());
		$this->reader->method('menus')->willReturn([]);

		$headers = $this->controller(authorization: 'Bearer whatever')->menus()->getHeaders();

		$this->assertStringContainsString('no-store', $headers['Cache-Control']);
		$this->assertStringNotContainsString('public', $headers['Cache-Control']);
	}//end testAnAuthenticatedResponseIsNeverShared()


	/**
	 * A page read by a signed-in Nextcloud user is never cached.
	 *
	 * An editor reads the site with their Nextcloud session and no resident
	 * bearer, so the body is the anonymous one. Served `public, max-age=300`,
	 * the editor's own tab answered from its browser cache for five minutes
	 * after they published (found on :8080, 02 Oct 2026).
	 *
	 * @return void
	 */
	public function testAPageReadBySignedInNextcloudUserIsNeverCached(): void {
		$this->resolver->method('resolve')->willReturn($this->portal());
		$this->reader->method('page')->willReturn(['id' => 'p1', 'route' => '/over']);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($this->createMock(IUser::class));

		$headers = $this->controller()->page(route: '/over')->getHeaders();

		$this->assertSame('private, no-store', $headers['Cache-Control']);
	}//end testAPageReadBySignedInNextcloudUserIsNeverCached()


	/**
	 * A page read by an anonymous visitor stays publicly cacheable.
	 *
	 * @return void
	 */
	public function testAPageReadByAnonymousVisitorStaysCacheable(): void {
		$this->resolver->method('resolve')->willReturn($this->portal());
		$this->reader->method('page')->willReturn(['id' => 'p1', 'route' => '/over']);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);

		$headers = $this->controller()->page(route: '/over')->getHeaders();

		$this->assertSame('public, max-age=300, must-revalidate', $headers['Cache-Control']);
	}//end testAPageReadByAnonymousVisitorStaysCacheable()


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
		$this->resolver->method('resolve')->willReturn($this->portal());
		$this->reader->expects($this->once())
			->method('menus')
			->with(portal: 'open-tilburg', locale: 'nl', audience: 'authenticated')
			->willReturn([]);

		$this->controller(authorization: 'Bearer definitely-not-valid')->menus();
	}//end testAnInvalidBearerIsStillTreatedAsAuthenticated()


	/**
	 * An unknown locale falls back to the site's default.
	 *
	 * @return void
	 */
	public function testAnUnknownLocaleFallsBackToTheSiteDefault(): void {
		$this->resolver->method('resolve')->willReturn($this->portal());
		$this->reader->expects($this->once())
			->method('menus')
			->with(portal: 'open-tilburg', locale: 'nl', audience: 'anonymous')
			->willReturn([]);

		$this->controller()->menus(portal: 'open-tilburg', locale: 'de');
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
		$this->resolver->method('resolve')->willReturn($this->portal());
		$this->reader->expects($this->once())
			->method('menus')
			->with(portal: 'open-tilburg', locale: 'en', audience: 'anonymous')
			->willReturn([]);

		$this->controller()->menus(portal: 'open-tilburg', locale: 'en');
	}//end testASupportedLocaleIsHonoured()


	/**
	 * A route without a leading slash is normalised.
	 *
	 * @return void
	 */
	public function testARouteIsNormalisedToALeadingSlash(): void {
		$this->resolver->method('resolve')->willReturn($this->portal());
		$this->reader->expects($this->once())
			->method('page')
			->with(portal: 'open-tilburg', route: '/over-ons', locale: 'nl', audience: 'anonymous')
			->willReturn(['title' => 'Over ons']);

		$this->controller()->page(route: 'over-ons');
	}//end testARouteIsNormalisedToALeadingSlash()


	/**
	 * A missing page is the same 404 as a missing site.
	 *
	 * @return void
	 */
	public function testAMissingPageIsTheSameNotFound(): void {
		$this->resolver->method('resolve')->willReturn($this->portal());
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
		$this->resolver->method('resolve')->willReturn($this->portal());
		$this->reader->method('page')->willReturn($page);

		$this->assertSame($page, $this->controller()->page(route: '/over-ons')->getData());
	}//end testAFoundPageIsReturnedUnchanged()


	/**
	 * The glossary and page listing are portal-scoped.
	 *
	 * @return void
	 */
	public function testListingsAreScopedToTheResolvedPortal(): void {
		$this->resolver->method('resolve')->willReturn($this->portal());
		$this->reader->expects($this->once())
			->method('glossary')
			->with(portal: 'open-tilburg', locale: 'nl', audience: 'anonymous')
			->willReturn([]);
		$this->reader->expects($this->once())
			->method('pages')
			->with(portal: 'open-tilburg', locale: 'nl', audience: 'anonymous')
			->willReturn([]);

		$this->controller()->glossary();
		$this->controller()->pages();
	}//end testListingsAreScopedToTheResolvedPortal()


	/**
	 * The contribution bridge asks for the ANONYMOUS aggregate, and scopes it
	 * to the resolved portal — both halves asserted from one call.
	 *
	 * @return void
	 */
	public function testContributionsAreAnonymousAndScopedToThePortal(): void {
		$this->resolver->method('resolve')->willReturn($this->portal());
		$this->registry = $this->createMock(PortalContributionRegistry::class);
		$this->registry->expects($this->once())
			->method('aggregateAnonymous')
			->willReturn(
				[
					'contributions' => [
						['app' => 'procest', 'label' => 'Zaken'],
						['app' => 'shillinq', 'portals' => ['open-venray']],
					],
				]
			);

		$data = $this->controller()->contributions()->getData();

		$this->assertSame(
			['procest'],
			array_column($data['contributions'], 'app'),
			'the untargeted contribution is kept; the one aimed at another portal is not'
		);
	}//end testContributionsAreAnonymousAndScopedToThePortal()


	/**
	 * A visitor's OWN aggregate must never come from this endpoint. It is
	 * publicly cacheable, so a subject-scoped response here would be pooled
	 * across visitors at the edge — where this installation's logs never look.
	 *
	 * Asserted by the collaborator called, not by inspecting the body: a
	 * `aggregateFor()` that happened to return nothing today would make a
	 * body-only assertion pass while the wrong call was being made.
	 *
	 * @return void
	 */
	public function testTheSubjectScopedAggregateIsNeverServedPublicly(): void {
		$this->resolver->method('resolve')->willReturn($this->portal());
		$this->registry = $this->createMock(PortalContributionRegistry::class);
		$this->registry->expects($this->never())->method('aggregateFor');
		$this->registry->method('aggregateAnonymous')->willReturn(['contributions' => []]);

		$response = $this->controller()->contributions();

		$this->assertStringContainsString(
			'public',
			(string)$response->getHeaders()['Cache-Control'],
			'and the response really is the publicly cacheable kind, so the rule above matters'
		);
	}//end testTheSubjectScopedAggregateIsNeverServedPublicly()


	/**
	 * A provider that throws costs the visitor a section, not the portal.
	 * ADR-046 reaches third-party code through a duck-typed call; treating
	 * that as fatal would let any leaf app take down every portal's content
	 * API.
	 *
	 * @return void
	 */
	public function testAThrowingRegistryDegradesToAnEmptyList(): void {
		$this->resolver->method('resolve')->willReturn($this->portal());
		$this->registry = $this->createMock(PortalContributionRegistry::class);
		$this->registry->method('aggregateAnonymous')
			->willThrowException(new \RuntimeException('provider exploded'));

		$response = $this->controller()->contributions();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['contributions' => []], $response->getData());
	}//end testAThrowingRegistryDegradesToAnEmptyList()


	/**
	 * An unresolved portal answers with the SAME 404 as every other content
	 * endpoint — the contribution bridge must not become the one route that
	 * tells a caller whether a host is claimed.
	 *
	 * @return void
	 */
	public function testAnUnresolvedPortalGetsTheSharedNotFound(): void {
		$this->resolver->method('resolve')->willReturn(null);

		$response = $this->controller()->contributions();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'not_found'], $response->getData());
	}//end testAnUnresolvedPortalGetsTheSharedNotFound()


	/**
	 * A portal fixture that does NOT allow anonymous visitors.
	 *
	 * @param string|null $minTrust The assurance floor, or null for none.
	 *
	 * @return array The portal.
	 */
	private function privatePortal(?string $minTrust = null): array {
		$portal = $this->portal();
		$portal['slug'] = 'open-venray';
		$portal['authentication'] = ['modes' => ['digid']];
		if ($minTrust !== null) {
			$portal['authentication']['minTrust'] = $minTrust;
		}

		return $portal;
	}//end privatePortal()


	/**
	 * A portal that allows no anonymous mode serves no CONTENT anonymously.
	 *
	 * `authentication.modes` was declared, documented, echoed by `site()` and
	 * enforced NOWHERE. Measured on a live rig before this gate existed: a
	 * portal set to `modes: ['digid']` served its menus, its page list and full
	 * page BODIES to an anonymous caller, every one of them HTTP 200.
	 *
	 * Every content endpoint is asserted, not a representative one — the gate
	 * is five separate call sites and a miss on any single one is the whole
	 * leak.
	 *
	 * @return void
	 */
	public function testAPrivatePortalServesNoContentAnonymously(): void {
		$this->resolver->method('resolve')->willReturn($this->privatePortal());
		$controller = $this->controller();

		$responses = [
			'menus'         => $controller->menus(),
			'pages'         => $controller->pages(),
			'page'          => $controller->page(route: '/over-ons'),
			'glossary'      => $controller->glossary(),
			'contributions' => $controller->contributions(),
		];

		foreach ($responses as $name => $response) {
			$this->assertSame(
				Http::STATUS_UNAUTHORIZED,
				$response->getStatus(),
				"{$name} served a private portal's content to an anonymous caller"
			);
			$this->assertSame('authentication_required', $response->getData()['error']);
			// NEVER publicly cacheable: a refusal pooled at a CDN becomes the
			// answer every later visitor gets, including those who signed in.
			$this->assertStringContainsString(
				'no-store',
				$response->getHeaders()['Cache-Control'],
				"{$name} let a refusal become publicly cacheable"
			);
		}
	}//end testAPrivatePortalServesNoContentAnonymously()


	/**
	 * The DOOR stays public even when the rooms are not.
	 *
	 * A visitor to a DigiD-only portal must still be able to load its title,
	 * theme and above all its MODES, or the renderer has nothing to draw a
	 * sign-in door from. Gating `site()` too would make a private portal
	 * unreachable rather than protected — the same reason a login page is
	 * anonymous.
	 *
	 * @return void
	 */
	public function testAPrivatePortalStillPublishesItsSignInDoor(): void {
		$this->resolver->method('resolve')->willReturn($this->privatePortal());

		$response = $this->controller()->site();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['digid'], $response->getData()['authentication']['modes']);
	}//end testAPrivatePortalStillPublishesItsSignInDoor()


	/**
	 * A portal with NO authentication block keeps serving publicly.
	 *
	 * The schema's own words: absent or unparseable configuration fails closed
	 * to public READ-ONLY. This is what stops the gate from taking every
	 * existing site off the air the day it ships — an outage filed as a
	 * security fix is how a security fix gets reverted.
	 *
	 * @return void
	 */
	public function testAPortalWithoutAuthenticationStaysPublic(): void {
		$portal = $this->portal();
		unset($portal['authentication']);
		$this->resolver->method('resolve')->willReturn($portal);
		$this->reader->method('menus')->willReturn([]);

		$this->assertSame(Http::STATUS_OK, $this->controller()->menus()->getStatus());
	}//end testAPortalWithoutAuthenticationStaysPublic()


	/**
	 * A real session below the portal's assurance floor is refused — with a
	 * DIFFERENT answer from "no session at all".
	 *
	 * 403 rather than 401 on purpose: the caller did authenticate, so telling
	 * them to authenticate again would loop them through the same means that
	 * just succeeded. The level is the problem and the status has to say so.
	 *
	 * @return void
	 */
	public function testASessionBelowTheTrustFloorIsRefusedAsForbidden(): void {
		$this->resolver->method('resolve')->willReturn($this->privatePortal(minTrust: 'high'));
		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn(
			['subjectRef' => 's-1', 'audience' => 'client', 'organisation' => 'org-1', 'trust' => 'low']
		);
		$this->session = $session;

		$response = $this->controller(authorization: 'Bearer real-token')->pages();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('insufficient_trust', $response->getData()['error']);
	}//end testASessionBelowTheTrustFloorIsRefusedAsForbidden()


	/**
	 * A session that MEETS the floor is served.
	 *
	 * The other half of the pair. Without it, a gate that refused everybody
	 * would pass every assertion above while making the portal unusable — the
	 * failure mode that looks exactly like a working security control.
	 *
	 * @return void
	 */
	public function testASessionMeetingTheTrustFloorIsServed(): void {
		$this->resolver->method('resolve')->willReturn($this->privatePortal(minTrust: 'substantial'));
		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn(
			['subjectRef' => 's-1', 'audience' => 'client', 'organisation' => 'org-1', 'trust' => 'high']
		);
		$this->session = $session;
		$this->reader->method('menus')->willReturn([]);

		$response = $this->controller(authorization: 'Bearer real-token')->menus();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		// And it must not be poolable across visitors.
		$this->assertStringContainsString('no-store', $response->getHeaders()['Cache-Control']);
	}//end testASessionMeetingTheTrustFloorIsServed()


	/**
	 * The site record carries the resolved measurement block and the
	 * absolute collector URL (portal-traffic-analytics), so a client on
	 * another origin sends only what the portal asked for and knows where
	 * to post. Defaults are filled in: a portal with no traffic block at all
	 * is served `enabled: false` rather than nothing.
	 *
	 * @return void
	 */
	public function testTheSiteRecordCarriesTheMeasurementConfigurationAndTheCollector(): void {
		$portal = $this->portal();
		$portal['traffic'] = ['enabled' => true, 'events' => ['page_view', 'scroll', 'not-a-thing']];
		$this->resolver->method('resolve')->willReturn($portal);

		$data = $this->controller()->site()->getData();

		$this->assertTrue($data['traffic']['enabled']);
		$this->assertSame(['page_view', 'scroll'], $data['traffic']['events'], 'an unknown event is dropped, not served');
		$this->assertSame(30, $data['traffic']['sessionTimeoutMinutes']);
		$this->assertFalse($data['traffic']['persistClientId'], 'cookieless by default');
		$this->assertArrayHasKey('sensitive', $data['traffic']);
		$this->assertSame('https://portaal.example/index.php/apps/portaliq/api/traffic', $data['collector']);
	}//end testTheSiteRecordCarriesTheMeasurementConfigurationAndTheCollector()


	/**
	 * A portal that never configured measurement is served an explicit
	 * `enabled: false`: the client must be told, not left to guess.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredPortalIsServedAsNotMeasuring(): void {
		$this->resolver->method('resolve')->willReturn($this->portal());

		$data = $this->controller()->site()->getData();

		$this->assertFalse($data['traffic']['enabled']);
	}//end testAnUnconfiguredPortalIsServedAsNotMeasuring()

	/**
	 * REQ-PFS-CONTENT-001: the search reads inside documents unless the
	 * portal switched it off, and the site record says which.
	 *
	 * @return void
	 */
	public function testTheSiteRecordSaysWhetherSearchReadsInsideDocuments(): void {
		$this->resolver->method('resolve')->willReturn($this->portal());
		$this->assertTrue($this->controller()->site()->getData()['searchInsideDocuments'], 'on by default');
	}//end testTheSiteRecordSaysWhetherSearchReadsInsideDocuments()


	/**
	 * The assistant widget is offered only when the portal enabled it AND hermiq's
	 * entry point answers (search-assistant-from-public-content REQ-SAP-006).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/search-assistant-from-public-content/tasks.md#t05
	 */
	public function testTheSiteRecordOffersTheAssistantOnlyWhenEnabledAndAvailable(): void {
		$portal                = $this->portal();
		$portal['assistant']   = ['enabled' => true];
		$this->resolver->method('resolve')->willReturn($portal);
		$available = $this->createMock(PublicAssistantChannel::class);
		$available->method('isAvailable')->willReturn(true);
		$missing = $this->createMock(PublicAssistantChannel::class);
		$missing->method('isAvailable')->willReturn(false);

		$this->assertTrue($this->controller(assistant: $available)->site()->getData()['assistantEnabled']);
		$this->assertFalse($this->controller(assistant: $missing)->site()->getData()['assistantEnabled'], 'hermiq is not there');
		$this->assertFalse($this->controller()->site()->getData()['assistantEnabled'], 'no channel at all');
	}//end testTheSiteRecordOffersTheAssistantOnlyWhenEnabledAndAvailable()


	/**
	 * A portal that did not enable it is served false, even with hermiq there.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/search-assistant-from-public-content/tasks.md#t05
	 */
	public function testAPortalThatDidNotEnableTheAssistantGetsNone(): void {
		$this->resolver->method('resolve')->willReturn($this->portal());
		$available = $this->createMock(PublicAssistantChannel::class);
		$available->method('isAvailable')->willReturn(true);

		$this->assertFalse($this->controller(assistant: $available)->site()->getData()['assistantEnabled']);
	}//end testAPortalThatDidNotEnableTheAssistantGetsNone()


	/**
	 * A portal that set it to false is served false.
	 *
	 * @return void
	 */
	public function testAPortalCanSwitchSearchInsideDocumentsOff(): void {
		$portal = $this->portal();
		$portal['searchInsideDocuments'] = false;
		$this->resolver->method('resolve')->willReturn($portal);

		$this->assertFalse($this->controller()->site()->getData()['searchInsideDocuments']);
	}//end testAPortalCanSwitchSearchInsideDocumentsOff()

	/**
	 * REQ-PWR-001: a portal offering the account route is served the address of
	 * Nextcloud's login page with a way back; one that does not is served none.
	 *
	 * @return void
	 */
	public function testALostPasswordAddressIsServedOnlyWithTheAccountRoute(): void {
		$withAccount = $this->portal();
		$withAccount['authentication'] = ['modes' => ['nextcloud']];
		$this->resolver->method('resolve')->willReturn($withAccount);
		$url = $this->controller()->site()->getData()['lostPasswordUrl'];
		$this->assertStringStartsWith('https://portaal.example/index.php/login?redirect_url=', $url);
		$this->assertStringContainsString(rawurlencode('session/nextcloud?portal=open-tilburg'), $url);
	}//end testALostPasswordAddressIsServedOnlyWithTheAccountRoute()


	/**
	 * A DigiD-only portal gets no address.
	 *
	 * @return void
	 */
	public function testADigidOnlyPortalIsServedNoLostPasswordAddress(): void {
		$digid = $this->portal();
		$digid['authentication'] = ['modes' => ['digid']];
		$this->resolver->method('resolve')->willReturn($digid);

		$this->assertSame('', $this->controller()->site()->getData()['lostPasswordUrl']);
	}//end testADigidOnlyPortalIsServedNoLostPasswordAddress()
}//end class
