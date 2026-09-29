<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\Cms\PortalShell;
use PHPUnit\Framework\TestCase;

/**
 * portal-theme-blocks-and-contributed-pages REQ-PTB-004 and REQ-PTB-005: the
 * content API projects the portal's header shape, register destination and
 * footer onto named keys, and drops what a visitor cannot follow.
 *
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-header-must-be-a-block-whose-shape-the-portal-chooses-req-ptb-004
 */
class PortalShellTest extends TestCase {

	public function testAnUnknownOrMissingHeaderVariantIsDouble(): void {
		$shell = new PortalShell();

		$this->assertSame('double', $shell->headerVariant(portal: []));
		$this->assertSame('double', $shell->headerVariant(portal: ['headerVariant' => 'x']));
		$this->assertSame('double', $shell->headerVariant(portal: ['headerVariant' => ['single']]));
		$this->assertSame('single', $shell->headerVariant(portal: ['headerVariant' => 'single']));
	}//end testAnUnknownOrMissingHeaderVariantIsDouble()

	public function testTheRegisterDestinationIsServedOnlyWhenDeclared(): void {
		$shell = new PortalShell();

		$this->assertSame(['modes' => ['digid']], $shell->authentication(portal: ['authentication' => ['modes' => ['digid'], 'register' => '  ']]));
		$this->assertSame(
			['modes' => ['digid'], 'register' => '/registreren', 'registerLabel' => 'Account maken'],
			$shell->authentication(portal: ['authentication' => ['modes' => ['digid'], 'register' => '/registreren', 'registerLabel' => 'Account maken', 'oidc' => ['issuer' => 'https://idp.example']]])
		);
		$this->assertSame(['modes' => ['public']], $shell->authentication(portal: []));
	}//end testTheRegisterDestinationIsServedOnlyWhenDeclared()
}//end class
