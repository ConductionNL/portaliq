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

	public function testAFooterLinkWithoutADestinationOrLabelIsDropped(): void {
		$footer = (new PortalShell())->footer(portal: ['footer' => [
			'description' => ' Eén loket voor de gemeente ',
			'socials'     => [
				['label' => 'Mastodon', 'href' => 'https://social.example/@gemeente', 'icon' => 'mastodon', 'style' => 'x'],
				['label' => 'LinkedIn'],
				['href' => 'https://linkedin.example'],
				['label' => 'Script', 'href' => 'javascript:alert(1)'],
				'not an entry',
			],
			'legalLinks'  => [['label' => 'Privacy', 'href' => '/privacy'], ['label' => 'Elders', 'href' => '//evil.example']],
			'badges'      => [['label' => 'ISO 27001', 'href' => 'https://cert.example/27001'], ['label' => 'ISO 9001', 'href' => '']],
			'secret'      => 'not served',
		]]);

		$this->assertSame(
			[
				'description' => 'Eén loket voor de gemeente',
				'colophon'    => '',
				'socials'     => [['label' => 'Mastodon', 'href' => 'https://social.example/@gemeente', 'icon' => 'mastodon']],
				'legalLinks'  => [['label' => 'Privacy', 'href' => '/privacy']],
				'badges'      => [['label' => 'ISO 27001', 'href' => 'https://cert.example/27001']],
			],
			$footer
		);
	}//end testAFooterLinkWithoutADestinationOrLabelIsDropped()

	public function testAPortalWithoutAFooterServesTheEmptyShape(): void {
		$this->assertSame(
			['description' => '', 'colophon' => '', 'socials' => [], 'legalLinks' => [], 'badges' => []],
			(new PortalShell())->footer(portal: ['footer' => 'broken'])
		);
	}//end testAPortalWithoutAFooterServesTheEmptyShape()

	public function testThePortalsRegionsKeepAPresentEmptyKeyAndDropStyling(): void {
		$regions = (new PortalShell())->regions(portal: ['regions' => [
			'footer'  => [],
			'hero'    => [['widgetKey' => 'hero', 'props' => ['title' => 'Welkom', 'style' => 'position:absolute', 'class' => 'evil']]],
			'sidebar' => [['widgetKey' => 'markdown']],
		]]);

		$this->assertSame(['hero', 'footer'], array_keys($regions));
		$this->assertSame([], $regions['footer'], 'a portal leaves its footer out on purpose');
		$this->assertSame(['title' => 'Welkom'], $regions['hero'][0]['props']);
		$this->assertSame('hero', $regions['hero'][0]['slot']);
		$this->assertSame([], (new PortalShell())->regions(portal: ['regions' => 'broken']));
	}//end testThePortalsRegionsKeepAPresentEmptyKeyAndDropStyling()
}//end class
