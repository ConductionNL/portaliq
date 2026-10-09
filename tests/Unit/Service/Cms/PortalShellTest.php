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
 * @spec openspec/changes/site-chrome-follows-the-design/specs/site-chrome/spec.md
 */
class PortalShellTest extends TestCase {

	public function testTheHeaderSearchIsOffUntilEnabledAndOpensAnInSitePage(): void {
		$shell = new PortalShell();

		$this->assertSame(['enabled' => false, 'label' => '', 'placeholder' => '', 'route' => '/zoeken'], $shell->headerSearch(portal: []));
		$this->assertSame(
			['enabled' => true, 'label' => '', 'placeholder' => 'Zoek een cursus', 'route' => '/cursussen'],
			$shell->headerSearch(portal: ['headerSearch' => ['enabled' => true, 'placeholder' => ' Zoek een cursus ', 'route' => '/cursussen']])
		);
		// A declared box shows without `enabled`, as lane L3 writes it.
		$this->assertSame(
			['enabled' => true, 'label' => 'Zoeken op de website', 'placeholder' => '', 'route' => '/zoeken'],
			$shell->headerSearch(portal: ['headerSearch' => ['label' => 'Zoeken op de website', 'route' => '/zoeken']])
		);
		// Switched off, or a truthy string, is off; an address elsewhere is not a search page.
		$this->assertFalse($shell->headerSearch(portal: ['headerSearch' => ['enabled' => false, 'label' => 'Zoeken']])['enabled']);
		$this->assertSame(
			['enabled' => false, 'label' => '', 'placeholder' => '', 'route' => '/zoeken'],
			$shell->headerSearch(portal: ['headerSearch' => ['enabled' => 'yes', 'route' => '//evil.example/zoek']])
		);
	}//end testTheHeaderSearchIsOffUntilEnabledAndOpensAnInSitePage()

	public function testTheProjectionServesTheHeaderSearchAndTheAccountLabel(): void {
		$projected = (new PortalShell())->project(portal: [
			'headerSearch' => ['enabled' => true],
			'accountLabel' => ' Mijn Wilgenboom ',
		]);

		$this->assertTrue($projected['headerSearch']['enabled']);
		$this->assertSame('Mijn Wilgenboom', $projected['accountLabel']);
		$this->assertSame('', (new PortalShell())->project(portal: [])['accountLabel']);
		// The card label of the resident menu (resident-menu-badges-and-cards).
		$this->assertSame(['cardLabel' => 'U regelt het voor'], (new PortalShell())->project(portal: ['residentMenu' => ['cardLabel' => ' U regelt het voor ', 'secret' => 'x']])['residentMenu']);
		$this->assertSame([], (new PortalShell())->project(portal: ['residentMenu' => 'x'])['residentMenu']);
	}//end testTheProjectionServesTheHeaderSearchAndTheAccountLabel()

	/**
	 * The portal's own menu groups and the cases display reach the site, well
	 * formed only (zuiddrecht-resident-pages-match-the-boards).
	 *
	 * @return void
	 */
	public function testTheProjectionServesTheMenuGroupsAndTheCasesDisplay(): void {
		$projected = (new PortalShell())->project(portal: [
			'residentMenu' => [
				'cardLabel' => 'U regelt het voor',
				'groups'    => [
					['title' => ' Mijn Zuiddrecht ', 'items' => ['overview', 'inbox', 'bad name', 7, '']],
					['title' => '', 'items' => ['cases']],
					['title' => 'Leeg', 'items' => []],
					'x',
					['title' => 'Vragen en meldingen', 'items' => ['portaliq:meldingen']],
				],
			],
			'myCases'      => ['display' => 'rows'],
		]);
		$this->assertSame(
			expected: [
				'cardLabel' => 'U regelt het voor',
				'groups'    => [
					['title' => 'Mijn Zuiddrecht', 'items' => ['overview', 'inbox']],
					['title' => 'Vragen en meldingen', 'items' => ['portaliq:meldingen']],
				],
			],
			actual: $projected['residentMenu']
		);
		$this->assertSame(expected: ['display' => 'rows'], actual: $projected['myCases']);
		$this->assertSame(expected: [], actual: (new PortalShell())->project(portal: ['myCases' => ['display' => 'cards']])['myCases']);
		$this->assertSame(expected: [], actual: (new PortalShell())->project(portal: [])['myCases']);
	}//end testTheProjectionServesTheMenuGroupsAndTheCasesDisplay()

	/**
	 * The e-mail ask: off, in the portal's words, or the site's own
	 * (mijn-overview-follows-the-boards).
	 *
	 * @return void
	 */
	public function testTheProjectionServesTheContactPrompt(): void {
		$shell = new PortalShell();
		$this->assertSame(['show' => false], $shell->project(portal: ['contactPrompt' => ['show' => false, 'text' => 'x']])['contactPrompt']);
		$this->assertSame(
			['text' => 'Voeg je e-mailadres toe.', 'button' => 'Naar mijn account'],
			$shell->project(portal: ['contactPrompt' => [
				'text'    => ' Voeg je e-mailadres toe. ',
				'button'  => 'Naar mijn account',
				'dismiss' => str_repeat('x', 201),
			]])['contactPrompt']
		);
		$this->assertSame([], $shell->project(portal: [])['contactPrompt']);
		$this->assertSame([], $shell->project(portal: ['contactPrompt' => 'aan'])['contactPrompt']);
	}//end testTheProjectionServesTheContactPrompt()

	/**
	 * The items a portal leaves out of the menu reach the site by name, well
	 * formed only and never `overview` (resident-menu-leave-out).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/resident-menu-leave-out/specs/site-resident-menu/spec.md#requirement-a-portal-may-leave-items-out-of-the-resident-menu
	 */
	public function testTheProjectionServesTheItemsLeftOut(): void {
		$projected = (new PortalShell())->project(portal: [
			'residentMenu' => ['leaveOut' => ['cases', 'tasks', 'access', 'tasks', 'overview', 'bad name', 7]],
		]);
		$this->assertSame(expected: ['leaveOut' => ['cases', 'tasks', 'access']], actual: $projected['residentMenu']);
		$this->assertSame(expected: [], actual: (new PortalShell())->project(portal: ['residentMenu' => ['leaveOut' => 'cases']])['residentMenu']);
	}//end testTheProjectionServesTheItemsLeftOut()

	public function testTheFooterServesItsButtonAndContactColumnOnNamedKeys(): void {
		$footer = (new PortalShell())->footer(portal: ['footer' => [
			'cta'     => ['label' => 'Contact en schooltijden', 'href' => '/contact', 'style' => 'x'],
			'contact' => [
				'title' => 'Contact',
				'lines' => [
					['text' => 'Wilgenlaan 12, Zuiddrecht'],
					['text' => 'E-mail: [e-mailadres]', 'href' => 'mailto:info@example.org'],
					['text' => 'Script', 'href' => 'javascript:alert(1)'],
					['href' => '/leeg'],
				],
			],
		]]);

		$this->assertSame(['label' => 'Contact en schooltijden', 'href' => '/contact'], $footer['cta']);
		$this->assertSame(
			['title' => 'Contact', 'lines' => [
				['text' => 'Wilgenlaan 12, Zuiddrecht'],
				['text' => 'E-mail: [e-mailadres]', 'href' => 'mailto:info@example.org'],
				['text' => 'Script'],
			]],
			$footer['contact']
		);
	}//end testTheFooterServesItsButtonAndContactColumnOnNamedKeys()

	public function testAFooterButtonThatLeadsNowhereIsDropped(): void {
		$footer = (new PortalShell())->footer(portal: ['footer' => ['cta' => ['label' => 'Klik', 'href' => 'javascript:x'], 'contact' => ['title' => 'Contact', 'lines' => []]]]);

		$this->assertNull($footer['cta']);
		$this->assertNull($footer['contact']);
	}//end testAFooterButtonThatLeadsNowhereIsDropped()

	public function testTheSignInCardsAreServedPerModeTheyCanBeOfferedFor(): void {
		$auth = (new PortalShell())->authentication(portal: ['authentication' => [
			'modes'      => ['nextcloud', 'digid'],
			'modeLabels' => [
				'nextcloud' => ['title' => 'Ik ben leerling', 'button' => 'Inloggen met je schoolaccount', 'hint' => '', 'secret' => 'x'],
				'digid'     => ['title' => 'Ik ben ouder of verzorger', 'text' => 'Om uw kind ziek te melden.'],
				'public'    => ['title' => 'Niet een manier om in te loggen'],
				'eidas'     => ['title' => ''],
			],
			'signInPage' => [
				'title'     => 'Inloggen op Mijn Vaartveld',
				'intro'     => 'Kies wie je bent.',
				'notice'    => ['text' => 'Wachtwoord vergeten? Vraag het bij de receptie.'],
				'staffLink' => ['text' => 'Werkt u hier?', 'label' => 'Log in op de werkplek', 'href' => 'javascript:x'],
				'panel'     => ['title' => 'Alles op een plek', 'items' => [['title' => 'Rooster', 'text' => 'Je lessen van vandaag'], ['text' => 'zonder titel']]],
			],
		]]);

		$this->assertSame(
			[
				'nextcloud' => ['title' => 'Ik ben leerling', 'button' => 'Inloggen met je schoolaccount'],
				'digid'     => ['title' => 'Ik ben ouder of verzorger', 'text' => 'Om uw kind ziek te melden.'],
			],
			$auth['modeLabels']
		);
		$this->assertSame(
			[
				'title'  => 'Inloggen op Mijn Vaartveld',
				'intro'  => 'Kies wie je bent.',
				'notice' => ['text' => 'Wachtwoord vergeten? Vraag het bij de receptie.'],
				'panel'  => ['title' => 'Alles op een plek', 'items' => [['title' => 'Rooster', 'text' => 'Je lessen van vandaag']]],
			],
			$auth['signInPage']
		);
	}//end testTheSignInCardsAreServedPerModeTheyCanBeOfferedFor()

	public function testAPortalWithoutSignInTextServesNone(): void {
		$auth = (new PortalShell())->authentication(portal: ['authentication' => ['modes' => ['digid'], 'modeLabels' => 'x', 'signInPage' => ['notice' => ['title' => 'Zonder tekst']]]]);

		$this->assertSame(['modes' => ['digid']], $auth);
	}//end testAPortalWithoutSignInTextServesNone()

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
				'cta'         => null,
				'contact'     => null,
			],
			$footer
		);
	}//end testAFooterLinkWithoutADestinationOrLabelIsDropped()

	public function testAPortalWithoutAFooterServesTheEmptyShape(): void {
		$this->assertSame(
			['description' => '', 'colophon' => '', 'socials' => [], 'legalLinks' => [], 'badges' => [], 'cta' => null, 'contact' => null],
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
