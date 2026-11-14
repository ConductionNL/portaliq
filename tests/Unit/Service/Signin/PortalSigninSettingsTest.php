<?php
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Portaliq\Tests\Unit\Service\Signin
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/portaliq
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Signin;

use OCA\Portaliq\Service\OidcClaimMapperService;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalOrganisationConfigService;
use OCA\Portaliq\Service\Signin\OrganisationLoginConfig;
use OCA\Portaliq\Service\Signin\PortalSigninSettings;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * T11: the Sign-in widget's settings over the real organisation config, with
 * app config held in memory.
 *
 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
 */
class PortalSigninSettingsTest extends TestCase {

	/**
	 * The app config values.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * The app config key prefix whose writes fail, or '' when every write lands.
	 *
	 * @var string
	 */
	private string $refusedPrefix = '';

	/**
	 * The organisation config the last settings() built.
	 *
	 * @var OrganisationLoginConfig|null
	 */
	private ?OrganisationLoginConfig $orgConfig = null;

	/**
	 * The portal every call is about.
	 */
	private const PORTAL = ['slug' => 'venray', 'organisation' => 'gemeente-x'];


	/**
	 * The settings over in-memory app config and one organisation.
	 *
	 * @return PortalSigninSettings
	 */
	private function settings(): PortalSigninSettings {
		$organisations = new class {
			/**
			 * @param string $slug The slug.
			 *
			 * @return object
			 */
			public function findBySlug(string $slug): object {
				if ($slug !== 'gemeente-x') {
					throw new \RuntimeException('not found');
				}

				return new class {
					public function getUuid(): string {
						return 'org-uuid-1';
					}

					public function getName(): string {
						return 'Gemeente X';
					}
				};
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($organisations);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default));
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				if ($this->refusedPrefix !== '' && str_starts_with($key, $this->refusedPrefix) === true) {
					return false;
				}

				$this->config[$key] = $value;
				return true;
			}
		);

		$this->orgConfig = new OrganisationLoginConfig(
			new PortalOrganisationConfigService($container, $appConfig, $this->createMock(LoggerInterface::class), new OidcClaimMapperService()),
			$appConfig
		);

		return new PortalSigninSettings(
			$this->createMock(PortalObjectReader::class),
			$this->orgConfig
		);
	}//end settings()


	/**
	 * Every provider starts on the OIDC route, with no broker set.
	 *
	 * @return void
	 */
	public function testANewOrganisationKeepsTheOidcRoute(): void {
		$view = $this->settings()->view(portal: self::PORTAL);

		$this->assertSame([['provider' => 'digid', 'route' => 'oidc'], ['provider' => 'eherkenning', 'route' => 'oidc'], ['provider' => 'eidas', 'route' => 'oidc']], $view['providers']);
		$this->assertSame(['startUrl' => '', 'exchangeUrl' => '', 'consumerId' => ''], $view['broker']);
		$this->assertFalse($view['hasSecret']);
		$this->assertNull($this->settings()->view(portal: ['slug' => 'x', 'organisation' => 'unknown']));
	}//end testANewOrganisationKeepsTheOidcRoute()


	/**
	 * T11's check: DigiD cannot be switched to the broker without a start
	 * address; nothing is written.
	 *
	 * @return void
	 */
	public function testABrokerRouteWithoutAStartAddressIsRefused(): void {
		$result = $this->settings()->save(
			portal: self::PORTAL,
			routes: ['digid' => 'broker'],
			broker: ['exchangeUrl' => 'https://integriq.example/api/idp/envelope/exchange', 'consumerId' => 'portaliq-venray'],
			secret: 's3cret'
		);

		$this->assertSame(['error' => 'broker_incomplete'], $result);
		$this->assertSame([], $this->config);
	}//end testABrokerRouteWithoutAStartAddressIsRefused()


	/**
	 * A plain http broker address counts as missing: the envelope is trusted
	 * on the back channel alone, so it must be https.
	 *
	 * @return void
	 */
	public function testABrokerRouteWithAPlainHttpAddressIsRefused(): void {
		$result = $this->settings()->save(
			portal: self::PORTAL,
			routes: ['digid' => 'broker'],
			broker: ['startUrl' => 'https://integriq.example/idp/start', 'exchangeUrl' => 'http://integriq.example/api/idp/envelope/exchange', 'consumerId' => 'portaliq-venray'],
			secret: 's3cret'
		);

		$this->assertSame(['error' => 'broker_incomplete'], $result);
		$this->assertSame([], $this->config);
	}//end testABrokerRouteWithAPlainHttpAddressIsRefused()


	/**
	 * A complete broker saves; the secret goes to its own entry and never
	 * comes back; an empty secret on a later save keeps it.
	 *
	 * @return void
	 */
	public function testACompleteBrokerIsSavedAndTheSecretIsWriteOnly(): void {
		$broker = ['startUrl' => 'https://integriq.example/idp/start', 'exchangeUrl' => 'https://integriq.example/api/idp/envelope/exchange', 'consumerId' => 'portaliq-venray', 'extra' => 'dropped'];
		$view = $this->settings()->save(portal: self::PORTAL, routes: ['digid' => 'broker', 'generic' => 'broker', 'eidas' => 'nonsense'], broker: $broker, secret: 's3cret');

		$this->assertSame('broker', $view['providers'][0]['route']);
		$this->assertSame('oidc', $view['providers'][2]['route']);
		$this->assertTrue($view['hasSecret']);
		$this->assertStringNotContainsString('s3cret', (string)json_encode($view));
		$this->assertSame('s3cret', $this->config['broker_secret_org-uuid-1']);
		$stored = json_decode($this->config['org_presentation_org-uuid-1'], true);
		$this->assertSame(['digid' => 'broker'], $stored['loginRoutes']);
		$this->assertArrayNotHasKey('extra', $stored['broker']);
		$this->assertStringNotContainsString('s3cret', $this->config['org_presentation_org-uuid-1']);

		$again = $this->settings()->save(portal: self::PORTAL, routes: ['digid' => 'broker', 'eherkenning' => 'broker'], broker: $broker, secret: '');
		$this->assertSame('broker', $again['providers'][1]['route']);
		$this->assertSame('s3cret', $this->config['broker_secret_org-uuid-1'], 'an empty secret keeps the stored one');
	}//end testACompleteBrokerIsSavedAndTheSecretIsWriteOnly()


	/**
	 * A portal with no organisation, or one that does not exist, saves
	 * nothing.
	 *
	 * @return void
	 */
	public function testAPortalWithoutAKnownOrganisationSavesNothing(): void {
		$broker = ['startUrl' => 'https://integriq.example/idp/start', 'exchangeUrl' => 'https://integriq.example/api/idp/envelope/exchange', 'consumerId' => 'portaliq-venray'];

		foreach (['', '  ', 'unknown'] as $organisation) {
			$result = $this->settings()->save(portal: ['slug' => 'venray', 'organisation' => $organisation], routes: [], broker: $broker, secret: 's3cret');
			$this->assertSame(['error' => 'no_organisation'], $result, '"' . $organisation . '"');
		}

		$this->assertSame([], $this->config);
	}//end testAPortalWithoutAKnownOrganisationSavesNothing()


	/**
	 * A presentation that cannot be stored is a failed save, and the secret
	 * is then not written either.
	 *
	 * @return void
	 */
	public function testAPresentationThatCannotBeStoredFailsTheSave(): void {
		$this->refusedPrefix = 'org_presentation_';
		$broker = ['startUrl' => 'https://integriq.example/idp/start', 'exchangeUrl' => 'https://integriq.example/api/idp/envelope/exchange', 'consumerId' => 'portaliq-venray'];

		$result = $this->settings()->save(portal: self::PORTAL, routes: ['digid' => 'broker'], broker: $broker, secret: 's3cret');

		$this->assertSame(['error' => 'save_failed'], $result);
		$this->assertSame([], $this->config);
	}//end testAPresentationThatCannotBeStoredFailsTheSave()


	/**
	 * A secret that cannot be stored is a failed save, even though the routes
	 * were written.
	 *
	 * @return void
	 */
	public function testASecretThatCannotBeStoredFailsTheSave(): void {
		$this->refusedPrefix = 'broker_secret_';
		$broker = ['startUrl' => 'https://integriq.example/idp/start', 'exchangeUrl' => 'https://integriq.example/api/idp/envelope/exchange', 'consumerId' => 'portaliq-venray'];

		$result = $this->settings()->save(portal: self::PORTAL, routes: ['digid' => 'broker'], broker: $broker, secret: ' s3cret ');

		$this->assertSame(['error' => 'save_failed'], $result);
		$this->assertArrayHasKey('org_presentation_org-uuid-1', $this->config);
		$this->assertArrayNotHasKey('broker_secret_org-uuid-1', $this->config);
	}//end testASecretThatCannotBeStoredFailsTheSave()


	/**
	 * The organisation config refuses a write without an organisation and
	 * resolves no broker for a blank organisation.
	 *
	 * @return void
	 */
	public function testTheOrganisationConfigRefusesAnEmptyOrganisation(): void {
		$this->settings();

		$this->assertFalse($this->orgConfig->writePresentation(organisationUuid: '', overrides: ['loginRoutes' => []]));
		$this->assertFalse($this->orgConfig->setBrokerSecret(organisationUuid: '', secret: 's3cret'));
		$this->assertNull($this->orgConfig->resolveBrokerConfig(orgSlug: ' ', provider: 'digid'));
		$this->assertNull($this->orgConfig->resolveBrokerConfig(orgSlug: 'unknown', provider: 'digid'));
		$this->assertSame([], $this->config);
	}//end testTheOrganisationConfigRefusesAnEmptyOrganisation()
}//end class
