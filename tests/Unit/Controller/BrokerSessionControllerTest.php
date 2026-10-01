<?php
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Portaliq\Tests\Unit\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/portaliq
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\BrokerSessionController;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\Signin\BrokerLogin;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * T04 and T08: the broker start and callback endpoints.
 *
 * @spec openspec/specs/portal-broker-envelope-login/spec.md
 */
class BrokerSessionControllerTest extends TestCase {


	/**
	 * The controller over a login double.
	 *
	 * @param BrokerLogin         $login   The login.
	 * @param PortalResolver|null $portals The portal resolver.
	 *
	 * @return BrokerSessionController
	 */
	private function controller(BrokerLogin $login, ?PortalResolver $portals = null): BrokerSessionController {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(
			static fn (string $name): string => $name === 'portaliq.portalPage.site' ? '/apps/portaliq/site' : '/apps/portaliq/portal'
		);
		$urls->method('linkToRouteAbsolute')->willReturn('https://portal.example/apps/portaliq/portal/api/session/broker/callback');
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://portal.example' . $path);

		return new BrokerSessionController(
			$this->createMock(IRequest::class),
			$login,
			$urls,
			($portals ?? $this->createMock(PortalResolver::class))
		);
	}//end controller()


	/**
	 * A login double answering the given start and completion.
	 *
	 * @param string|null                $start    What start() answers.
	 * @param array<string, string>|null $complete What complete() answers.
	 *
	 * @return BrokerLogin
	 */
	private function login(?string $start = null, ?array $complete = null): BrokerLogin {
		$login = $this->getMockBuilder(BrokerLogin::class)->disableOriginalConstructor()->onlyMethods(['start', 'complete'])->getMock();
		$login->method('start')->willReturn($start);
		$login->method('complete')->willReturn($complete);
		return $login;
	}//end login()


	/**
	 * T04: the start resolves the organisation from the portal the public site
	 * names, and redirects to integriq.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-the-broker-start-binds-the-login-to-one-organisation-and-one-provider-req-bel-002
	 */
	public function testStartResolvesOrganisationFromPortal(): void {
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('resolve')->willReturn(['slug' => 'venray', 'organisation' => 'gemeente-x']);
		$login = $this->getMockBuilder(BrokerLogin::class)->disableOriginalConstructor()->onlyMethods(['start', 'complete'])->getMock();
		$login->expects($this->once())->method('start')
			->with('gemeente-x', 'digid', '/apps/portaliq/portal', 'https://portal.example/apps/portaliq/portal/api/session/broker/callback')
			->willReturn('https://integriq.example/idp/start?state=s');

		$response = $this->controller(login: $login, portals: $portals)->start(provider: 'digid', portal: 'venray');

		$this->assertSame(Http::STATUS_FOUND, $response->getStatus());
		$this->assertSame('https://integriq.example/idp/start?state=s', $response->getRedirectURL());
	}//end testStartResolvesOrganisationFromPortal()


	/**
	 * A login started on the public site returns to the page it came from,
	 * and an address outside the site route to the portal.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
	 */
	public function testStartKeepsTheSitePageToReturnTo(): void {
		$cases = [
			'/apps/portaliq/site?portal=venray&route=/mijn' => '/apps/portaliq/site?portal=venray&route=/mijn',
			'//evil.example/x' => '/apps/portaliq/portal',
		];
		foreach ($cases as $returnTo => $kept) {
			$login = $this->getMockBuilder(BrokerLogin::class)->disableOriginalConstructor()->onlyMethods(['start', 'complete'])->getMock();
			$login->expects($this->once())->method('start')
				->with('gemeente-x', 'digid', $kept, $this->anything())
				->willReturn('https://integriq.example/idp/start?state=s');

			$this->controller(login: $login)->start(org: 'gemeente-x', provider: 'digid', returnTo: $returnTo);
		}
	}//end testStartKeepsTheSitePageToReturnTo()


	/**
	 * T08: a refused start, a refused callback and a callback with nothing in
	 * it all land on the same place, with no reason.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-a-failed-login-returns-to-the-login-screen-without-a-reason-req-bel-006
	 */
	public function testEveryFailureLandsOnTheSameFragment(): void {
		$controller = $this->controller(login: $this->login());
		$answers = [
			$controller->start(org: 'gemeente-x', provider: 'digid'),
			$controller->start(org: 'unknown', provider: 'nope'),
			$controller->callback(state: 's', code: 'c'),
			$controller->callback(),
		];

		foreach ($answers as $response) {
			$this->assertSame(Http::STATUS_FOUND, $response->getStatus());
			$this->assertSame('https://portal.example/apps/portaliq/portal#signin=failed', $response->getRedirectURL());
		}
	}//end testEveryFailureLandsOnTheSameFragment()


	/**
	 * T07: a completed login lands on the page it started from, with the
	 * bearer in the fragment only.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-the-envelope-becomes-an-ordinary-portal-session-req-bel-005
	 */
	public function testACompletedLoginCarriesTheBearerInTheFragment(): void {
		$response = $this->controller(login: $this->login(complete: ['token' => 'a.b c', 'returnTo' => '/apps/portaliq/portal']))->callback(state: 's', code: 'c');

		$this->assertSame('https://portal.example/apps/portaliq/portal#token=a.b%20c', $response->getRedirectURL());
	}//end testACompletedLoginCarriesTheBearerInTheFragment()
}//end class
