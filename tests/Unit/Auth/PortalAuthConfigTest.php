<?php

/**
 * Tests for PortalAuthConfig: a portal's authentication block read against
 * the closed set of modes, failing closed to no way in.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Auth
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

namespace OCA\Portaliq\Tests\Unit\Auth;

use OCA\Portaliq\Auth\PortalAuthConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Portaliq\Auth\PortalAuthConfig
 *
 * @spec openspec/changes/portal-scoping-and-auth/specs/portal-scoping-and-auth/spec.md#requirement-each-portal-must-declare-its-authentication-failing-closed
 */
class PortalAuthConfigTest extends TestCase {

	/**
	 * A well-formed block offers exactly its modes, once each, in order, with its floor.
	 *
	 * @return void
	 */
	public function testAWellFormedBlockOffersExactlyItsModes(): void {
		$config = PortalAuthConfig::fromPortal(
			portal: ['authentication' => ['modes' => ['digid', 'nextcloud', 'digid'], 'minTrust' => 'substantial']]
		);

		$this->assertFalse($config->isMalformed());
		$this->assertSame(['digid', 'nextcloud'], $config->modes());
		$this->assertTrue($config->offers(mode: 'digid'));
		$this->assertTrue($config->offers(mode: 'nextcloud'));
		$this->assertFalse($config->offers(mode: 'email-link'));
		$this->assertSame('substantial', $config->minTrust());
	}//end testAWellFormedBlockOffersExactlyItsModes()

	/**
	 * No portal, no block or a block without modes is a public portal that
	 * offers no sign-in, and is not malformed.
	 *
	 * @return void
	 */
	public function testAnAbsentBlockIsPublicAndOffersNoSignIn(): void {
		foreach ([null, [], ['authentication' => []], ['authentication' => ['minTrust' => 'low']]] as $portal) {
			$config = PortalAuthConfig::fromPortal(portal: $portal);
			$this->assertFalse($config->isMalformed());
			$this->assertSame([], $config->modes());
			$this->assertFalse($config->offers(mode: 'nextcloud'));
		}
	}//end testAnAbsentBlockIsPublicAndOffersNoSignIn()

	/**
	 * Malformed blocks.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function malformedBlocks(): array {
		return [
			'not an object' => ['nextcloud'],
			'modes not a list' => [['modes' => 'nextcloud']],
			'modes keyed' => [['modes' => ['a' => 'nextcloud']]],
			'a mode outside the set' => [['modes' => ['nextcloud', 'google']]],
			'a mode that is not a string' => [['modes' => ['nextcloud', 1]]],
			'a floor outside the levels' => [['modes' => ['digid'], 'minTrust' => 'hgh']],
			'oidc not an object' => [['modes' => ['oidc'], 'oidc' => 'https://idp.example']],
			'oidc a list' => [['modes' => ['oidc'], 'oidc' => ['https://idp.example']]],
			'an issuer that is not a string' => [['modes' => ['oidc'], 'oidc' => ['issuer' => 7]]],
			'an empty issuer' => [['modes' => ['oidc'], 'oidc' => ['issuer' => ' / ']]],
			'an empty client id' => [['modes' => ['oidc'], 'oidc' => ['clientId' => '']]],
		];
	}//end malformedBlocks()

	/**
	 * A malformed block offers no way in at all, not even the modes it spells right.
	 *
	 * @param mixed $block The stored authentication block.
	 *
	 * @return void
	 */
	#[DataProvider('malformedBlocks')]
	public function testAMalformedBlockOffersNoWayIn(mixed $block): void {
		$config = PortalAuthConfig::fromPortal(portal: ['authentication' => $block]);

		$this->assertTrue($config->isMalformed());
		$this->assertSame([], $config->modes());
		$this->assertNull($config->minTrust());
		foreach (PortalAuthConfig::MODES as $mode) {
			$this->assertFalse($config->offers(mode: $mode), $mode);
		}

		$this->assertFalse($config->acceptsOidcProvider(config: ['issuer' => 'https://idp.example', 'clientId' => 'rp']));
	}//end testAMalformedBlockOffersNoWayIn()

	/**
	 * A portal that names its OIDC provider accepts only that provider: the
	 * issuer must match (a trailing slash aside) and, when named, the client id.
	 *
	 * @return void
	 */
	public function testAPortalThatNamesItsProviderAcceptsOnlyThatProvider(): void {
		$config = PortalAuthConfig::fromPortal(
			portal: ['authentication' => ['modes' => ['oidc'], 'oidc' => ['issuer' => 'https://idp-a.example/', 'clientId' => 'rp-a']]]
		);

		$this->assertFalse($config->isMalformed());
		$this->assertTrue($config->namesOidcProvider());
		$this->assertTrue($config->acceptsOidcProvider(config: ['issuer' => 'https://idp-a.example', 'clientId' => 'rp-a']));
		$this->assertFalse($config->acceptsOidcProvider(config: ['issuer' => 'https://idp-b.example', 'clientId' => 'rp-a']));
		$this->assertFalse($config->acceptsOidcProvider(config: ['issuer' => 'https://idp-a.example', 'clientId' => 'rp-b']));
		$this->assertFalse($config->acceptsOidcProvider(config: ['clientId' => 'rp-a']));
	}//end testAPortalThatNamesItsProviderAcceptsOnlyThatProvider()

	/**
	 * A portal that names only an issuer accepts any client of that issuer.
	 *
	 * @return void
	 */
	public function testAnIssuerAloneDoesNotPinTheClient(): void {
		$config = PortalAuthConfig::fromPortal(portal: ['authentication' => ['modes' => ['oidc'], 'oidc' => ['issuer' => 'https://idp-a.example']]]);

		$this->assertTrue($config->acceptsOidcProvider(config: ['issuer' => 'https://idp-a.example/', 'clientId' => 'any']));
		$this->assertFalse($config->acceptsOidcProvider(config: ['issuer' => 'https://idp-a.example.evil', 'clientId' => 'any']));
	}//end testAnIssuerAloneDoesNotPinTheClient()

	/**
	 * A portal that names no provider (no `oidc`, or an empty one) accepts
	 * the organisation's provider, as before.
	 *
	 * @return void
	 */
	public function testAPortalThatNamesNoProviderAcceptsTheOrganisations(): void {
		foreach ([['modes' => ['oidc']], ['modes' => ['oidc'], 'oidc' => []], ['modes' => ['oidc'], 'oidc' => ['scopes' => ['openid']]]] as $block) {
			$config = PortalAuthConfig::fromPortal(portal: ['authentication' => $block]);
			$this->assertFalse($config->isMalformed());
			$this->assertFalse($config->namesOidcProvider());
			$this->assertTrue($config->acceptsOidcProvider(config: ['issuer' => 'https://idp-b.example', 'clientId' => 'rp']));
		}
	}//end testAPortalThatNamesNoProviderAcceptsTheOrganisations()

	/**
	 * The closed set is the portal schema's own enum, so neither can drift.
	 *
	 * @return void
	 */
	public function testTheClosedSetIsTheSchemasEnum(): void {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/portaliq_register.json'), true);
		$auth     = $register['components']['schemas']['portal']['properties']['authentication']['properties'];

		$this->assertSame($auth['modes']['items']['enum'], PortalAuthConfig::MODES);
		$this->assertSame($auth['minTrust']['enum'], PortalAuthConfig::TRUST_LEVELS);
		$this->assertSame('string', $auth['oidc']['properties']['issuer']['type']);
		$this->assertSame('string', $auth['oidc']['properties']['clientId']['type']);
	}//end testTheClosedSetIsTheSchemasEnum()
}//end class
