<?php

/**
 * Unit tests for LoginProviders.
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

namespace OCA\Portaliq\Tests\Unit\Service\Signin;

use OCA\Portaliq\Service\OidcClaimMapperService;
use OCA\Portaliq\Service\Signin\BrokerLoginRoute;
use OCA\Portaliq\Service\Signin\LoginProviders;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Which sign-in buttons a portal offers, and by which route each one signs in.
 */
#[CoversClass(LoginProviders::class)]
#[UsesClass(BrokerLoginRoute::class)]
#[UsesClass(OidcClaimMapperService::class)]
class LoginProvidersTest extends TestCase {

	/**
	 * The list under test.
	 *
	 * @var LoginProviders
	 */
	private LoginProviders $providers;

	/**
	 * Overrides routing DigiD through a well-formed broker.
	 *
	 * @var array<string,mixed>
	 */
	private array $brokered = [
		'loginRoutes' => ['digid' => 'broker', 'generic' => 'broker'],
		'broker'      => ['startUrl' => 'https://broker.example/start', 'exchangeUrl' => 'https://broker.example/exchange', 'consumerId' => ' portal-1 '],
	];


	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->providers = new LoginProviders();
	}//end setUp()


	/**
	 * The OIDC config of every provider except eIDAS, labelled in capitals.
	 *
	 * @param string $provider The provider.
	 *
	 * @return array<string,string>|null
	 */
	public static function oidc(string $provider): ?array {
		if ($provider === 'eidas') {
			return null;
		}

		return ['label' => strtoupper($provider)];
	}//end oidc()


	/**
	 * Each provider is offered by its route, in a fixed order; an unconfigured one is not offered.
	 *
	 * @return void
	 */
	public function testListOffersEachConfiguredProviderByItsRoute(): void {
		$this->assertSame(
			[
				['provider' => 'digid', 'label' => 'DigiD', 'route' => 'broker'],
				['provider' => 'eherkenning', 'label' => 'EHERKENNING', 'route' => 'oidc'],
				// Yivi (resident-identity-in-forms T05) is a known provider too; configured over OIDC here.
				['provider' => 'yivi', 'label' => 'YIVI', 'route' => 'oidc'],
				['provider' => 'generic', 'label' => 'GENERIC', 'route' => 'oidc'],
			],
			$this->providers->list($this->brokered, 'secret', [self::class, 'oidc'])
		);
	}//end testListOffersEachConfiguredProviderByItsRoute()


	/**
	 * A brokered provider without a secret or a sound broker is not offered at all.
	 *
	 * @return void
	 */
	public function testABrokeredProviderWithoutItsBrokerIsNotOffered(): void {
		$listed = array_column($this->providers->list($this->brokered, '', [self::class, 'oidc']), 'provider');
		$this->assertSame(['eherkenning', 'yivi', 'generic'], $listed);

		$plain = $this->brokered;
		$plain['broker']['startUrl'] = 'http://broker.example/start';
		$this->assertNotContains('digid', array_column($this->providers->list($plain, 'secret', [self::class, 'oidc']), 'provider'));
	}//end testABrokeredProviderWithoutItsBrokerIsNotOffered()


	/**
	 * The broker settings carry the broker, the secret and the preset's label and audience.
	 *
	 * @return void
	 */
	public function testBrokerSettings(): void {
		$this->assertSame(
			[
				'startUrl'    => 'https://broker.example/start',
				'exchangeUrl' => 'https://broker.example/exchange',
				'consumerId'  => 'portal-1',
				'secret'      => 'secret',
				'label'       => 'DigiD',
				'audience'    => 'client',
			],
			$this->providers->brokerSettings($this->brokered, 'secret', 'digid')
		);
		$this->assertNull($this->providers->brokerSettings($this->brokered, 'secret', 'eherkenning'));
		$this->assertNull($this->providers->brokerSettings($this->brokered, 'secret', 'generic'));
	}//end testBrokerSettings()


	/**
	 * Silent sign-in names a provider only when that provider is offered over OIDC.
	 *
	 * @return void
	 */
	public function testSilentProviderIsAnOfferedOidcProvider(): void {
		$offered = [
			['provider' => 'digid', 'route' => 'broker'],
			['provider' => 'generic', 'route' => 'oidc'],
		];

		$this->assertSame('generic', $this->providers->silentProvider(['silentSignIn' => 'generic'], $offered));
		$this->assertSame('', $this->providers->silentProvider(['silentSignIn' => 'digid'], $offered));
		$this->assertSame('', $this->providers->silentProvider(['silentSignIn' => 'eidas'], $offered));
		$this->assertSame('', $this->providers->silentProvider(['silentSignIn' => true], $offered));
		$this->assertSame('', $this->providers->silentProvider([], $offered));
	}//end testSilentProviderIsAnOfferedOidcProvider()
}//end class
