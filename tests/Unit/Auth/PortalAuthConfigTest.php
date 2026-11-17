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
	}//end testAMalformedBlockOffersNoWayIn()

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
	}//end testTheClosedSetIsTheSchemasEnum()
}//end class
