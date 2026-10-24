<?php

/**
 * HostRootRedirectBootTest
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\AppInfo;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\HostRootRedirect;
use OCA\Portaliq\Service\HostRootRedirectSend;
use OCA\Portaliq\Service\PortalRegisterContext;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\SiteAddressing;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\IAppContainer;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * The bare address of a portal host, asserted from the caller that runs in
 * production: `Application::boot()`, which core calls for every app before it
 * answers `/` (portal-host-root-redirect).
 *
 * Behind boot sit the REAL redirect decision, the real addressing and the
 * real portal resolver, over the portals an instance would hold. Only the
 * request changes between cases, and only the send is replaced, because in
 * production it ends the process. What the send receives is the whole chain:
 * request shape, host, verified domain, landing page, address.
 *
 * @spec openspec/changes/portal-host-root-redirect/specs/portal-settings/spec.md#requirement-any-other-host-must-keep-nextclouds-own-root-req-hrr-002
 */
class HostRootRedirectBootTest extends TestCase {

	/**
	 * Every location the send was asked to redirect to.
	 *
	 * @var string[]
	 */
	private array $sent = [];


	/**
	 * The bare address of a verified domain leads to the site's home, on the
	 * long address, for `/`, `/index.php` and `/index.php/` alike (all three
	 * reach core as an empty path info through index.php), and for HEAD.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-host-root-redirect/specs/portal-settings/spec.md#requirement-the-bare-address-of-a-portal-host-must-lead-to-its-site-req-hrr-001
	 */
	public function testTheBareAddressOfAPortalHostLeadsToTheSite(): void {
		$this->boot(host: 'www.gemeente.example');
		$this->boot(host: 'www.gemeente.example', script: 'index.php');
		$this->boot(host: 'www.gemeente.example', method: 'HEAD');
		$this->boot(host: 'school.example');

		$this->assertSame(
			[
				'/index.php/apps/portaliq/site',
				'/index.php/apps/portaliq/site',
				'/index.php/apps/portaliq/site',
				'/index.php/apps/portaliq/site',
			],
			$this->sent
		);
	}//end testTheBareAddressOfAPortalHostLeadsToTheSite()


	/**
	 * A domain with a landing page leads there, on the long address. A
	 * landing route that would lead off the site leads home instead.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-host-root-redirect/specs/portal-settings/spec.md#requirement-the-bare-address-of-a-portal-host-must-lead-to-its-site-req-hrr-001
	 */
	public function testADomainWithALandingPageLeadsThere(): void {
		$this->boot(host: 'mijn.gemeente.example');
		$this->boot(host: 'MIJN.gemeente.example:443');
		$this->boot(host: 'evil.gemeente.example');

		$this->assertSame(
			[
				'/index.php/apps/portaliq/site/mijn',
				'/index.php/apps/portaliq/site/mijn',
				'/index.php/apps/portaliq/site',
			],
			$this->sent
		);
	}//end testADomainWithALandingPageLeadsThere()


	/**
	 * An unknown host, an unverified domain (whatever it declares), the
	 * workplace host and an empty host get no redirect: core answers them.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-host-root-redirect/specs/portal-settings/spec.md#requirement-any-other-host-must-keep-nextclouds-own-root-req-hrr-002
	 */
	public function testAnyOtherHostKeepsNextcloudsOwnRoot(): void {
		foreach (['nobody.example', 'claimed.example', 'cloud.example', ''] as $host) {
			$this->boot(host: $host);
		}

		$this->assertSame([], $this->sent);
	}//end testAnyOtherHostKeepsNextcloudsOwnRoot()


	/**
	 * A forged `Host` or `X-Forwarded-Host` header selects nothing: the host
	 * Nextcloud resolved is the workplace, so core answers.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-host-root-redirect/specs/portal-settings/spec.md#requirement-any-other-host-must-keep-nextclouds-own-root-req-hrr-002
	 */
	public function testAForgedHostHeaderGetsNoRedirect(): void {
		$this->boot(host: 'cloud.example', forgedHost: 'www.gemeente.example');

		$this->assertSame([], $this->sent);
	}//end testAForgedHostHeaderGetsNoRedirect()


	/**
	 * On a portal host, everything but the bare address as a page is left to
	 * core: `/login` (an employee still signs in there), any other path, a
	 * POST, another entry script, and a JSON or XML client.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-host-root-redirect/specs/portal-settings/spec.md#requirement-an-employee-must-still-reach-the-sign-in-of-nextcloud-on-a-portal-host-req-hrr-003
	 */
	public function testOnlyTheBareAddressIsRedirected(): void {
		$this->boot(host: 'www.gemeente.example', path: '/login');
		$this->boot(host: 'www.gemeente.example', path: '/apps/files');
		$this->boot(host: 'www.gemeente.example', path: '/apps/portaliq/site/afval');
		$this->boot(host: 'www.gemeente.example', method: 'POST');
		$this->boot(host: 'www.gemeente.example', script: '/cron.php');
		$this->boot(host: 'www.gemeente.example', script: '/status.php');
		$this->boot(host: 'www.gemeente.example', script: '/remote.php');
		$this->boot(host: 'www.gemeente.example', script: 'occ');
		$this->boot(host: 'www.gemeente.example', accept: 'application/json');
		$this->boot(host: 'www.gemeente.example', path: null);

		$this->assertSame([], $this->sent);
	}//end testOnlyTheBareAddressIsRedirected()


	/**
	 * When the portals cannot be read, the answer is plain Nextcloud, never a
	 * guessed portal.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-host-root-redirect/specs/portal-settings/spec.md#requirement-any-other-host-must-keep-nextclouds-own-root-req-hrr-002
	 */
	public function testAPortalReadThatFailsGetsNoRedirect(): void {
		$this->boot(host: 'www.gemeente.example', readFails: true);

		$this->assertSame([], $this->sent);
	}//end testAPortalReadThatFailsGetsNoRedirect()


	/**
	 * The portals of the instance. The gemeente portal declares root serving
	 * on its main domain: the redirect still names the long address.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function portals(): array {
		return [
			[
				'slug'    => 'school',
				'domains' => [['hostname' => 'school.example', 'verified' => true]],
			],
			[
				'slug'    => 'gemeente',
				'domains' => [
					['hostname' => 'www.gemeente.example', 'verified' => true, 'servedAtRoot' => true],
					['hostname' => 'mijn.gemeente.example', 'verified' => true, 'landingRoute' => '/mijn'],
					['hostname' => 'evil.gemeente.example', 'verified' => true, 'landingRoute' => '//evil.example'],
					['hostname' => 'claimed.example', 'verified' => false, 'servedAtRoot' => true, 'landingRoute' => '/mijn'],
				],
			],
		];
	}//end portals()


	/**
	 * Boot the app for one request.
	 *
	 * @param string      $host       The host Nextcloud resolved for the request.
	 * @param string|null $path       The raw path info, or null when reading it throws.
	 * @param string      $method     The request method.
	 * @param string      $script     The script name.
	 * @param string      $accept     The Accept header.
	 * @param string      $forgedHost A host a client put in its headers, or ''.
	 * @param bool        $readFails  Whether reading the portals throws.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 */
	private function boot(
		string $host,
		?string $path = '',
		string $method = 'GET',
		string $script = '/index.php',
		string $accept = 'text/html',
		string $forgedHost = '',
		bool $readFails = false,
	): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getServerHost')->willReturn($host);
		$request->method('getServerProtocol')->willReturn('https');
		$request->method('getInsecureServerHost')->willReturn($forgedHost);
		$request->method('getMethod')->willReturn($method);
		$request->method('getScriptName')->willReturn($script);
		if ($path === null) {
			$request->method('getRawPathInfo')->willThrowException(new RuntimeException('cannot be processed'));
		} else {
			$request->method('getRawPathInfo')->willReturn($path);
		}

		$request->method('getHeader')->willReturnCallback(
			static function (string $name) use ($accept, $forgedHost): string {
				$name = strtolower($name);
				if ($name === 'accept') {
					return $accept;
				}

				return (in_array($name, ['host', 'x-forwarded-host'], true) === true ? $forgedHost : '');
			}
		);

		$objects = new class($this->portals(), $readFails) {
			/**
			 * @param array<int, array<string, mixed>> $rows  The published portals.
			 * @param bool                             $fails Whether the read throws.
			 */
			public function __construct(private readonly array $rows, private readonly bool $fails) {
			}

			/**
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				if ($this->fails === true) {
					throw new RuntimeException('OpenRegister is down');
				}

				return $this->rows;
			}
		};
		$orContainer = $this->createMock(ContainerInterface::class);
		$orContainer->method('get')->willReturn($objects);
		$context = $this->createMock(PortalRegisterContext::class);
		$context->method('apply')->willReturn(true);
		$resolver = new PortalResolver($orContainer, $this->createMock(LoggerInterface::class), $context);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturnCallback(
			static function (string $name, array $params = []): string {
				if ($name === 'portaliq.portalPage.sitePath') {
					return '/index.php/apps/portaliq/site/' . $params['path'];
				}

				return '/index.php/apps/portaliq/site';
			}
		);

		$send = $this->createMock(HostRootRedirectSend::class);
		// The real send never returns (it ends the process), so the stand-in
		// records the location and throws, as a `never` method must.
		$send->method('redirect')->willReturnCallback(
			function (string $location): never {
				$this->sent[] = $location;
				throw new RedirectSentForTest($location);
			}
		);

		$redirect     = new HostRootRedirect(new SiteAddressing($resolver, $urlGenerator), $send);
		// IAppContainer, not the bare ContainerInterface: Nextcloud 32 to 34
		// declare getAppContainer() as returning IAppContainer, and 35 as
		// ContainerInterface, which IAppContainer extends. One stand-in fits all.
		$appContainer = $this->createMock(IAppContainer::class);
		$appContainer->method('get')->willReturnMap(
			[
				[HostRootRedirect::class, $redirect],
				[IRequest::class, $request],
			]
		);
		$bootContext = $this->createMock(IBootContext::class);
		$bootContext->method('getAppContainer')->willReturn($appContainer);

		// Constructed without its constructor, which needs a running server;
		// boot() reads nothing the constructor sets.
		$app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
		try {
			$app->boot($bootContext);
		} catch (RedirectSentForTest) {
			// The request ended with the redirect recorded in $this->sent.
		}
	}//end boot()
}//end class

/**
 * Thrown by the stand-in send where the real one ends the process.
 */
class RedirectSentForTest extends RuntimeException {
}//end class
