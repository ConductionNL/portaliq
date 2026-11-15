<?php

/**
 * Unit tests for PortalSignInText.
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

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\Cms\PortalSignInText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The public half of a portal's sign-in configuration: its texts, never anything else.
 */
#[CoversClass(PortalSignInText::class)]
class PortalSignInTextTest extends TestCase {

	/**
	 * The projection under test.
	 *
	 * @var PortalSignInText
	 */
	private PortalSignInText $text;


	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->text = new PortalSignInText();
	}//end setUp()


	/**
	 * Mode cards keep their known text keys for known modes only, trimmed, and blank ones vanish.
	 *
	 * @return void
	 */
	public function testModeLabelsKeepKnownModesAndKeys(): void {
		$this->assertSame(
			['modeLabels' => ['digid' => ['title' => 'DigiD', 'button' => 'Inloggen', 'hint' => '2']]],
			$this->text->project(
				[
					'clientSecret' => 'never',
					'modeLabels'   => [
						'digid'   => ['title' => ' DigiD ', 'text' => '  ', 'button' => 'Inloggen', 'hint' => 2, 'secret' => 'x', 'icon' => ['a']],
						'ldap'    => ['title' => 'LDAP'],
						'eidas'   => 'eIDAS',
						'oidc'    => ['title' => ''],
					],
				]
			)
		);
	}//end testModeLabelsKeepKnownModesAndKeys()


	/**
	 * Nothing public is an empty projection.
	 *
	 * @return void
	 */
	public function testNothingPublicProjectsToNothing(): void {
		$this->assertSame([], $this->text->project([]));
		$this->assertSame([], $this->text->project(['modeLabels' => 'x', 'signInPage' => 'y']));
		$this->assertSame([], $this->text->project(['signInPage' => ['notice' => ['title' => 'No text']]]));
	}//end testNothingPublicProjectsToNothing()


	/**
	 * The page keeps its title and intro, a notice with text, and a staff link only with a label and a followable address.
	 *
	 * @return void
	 */
	public function testSignInPageTexts(): void {
		$page = $this->text->project(
			[
				'signInPage' => [
					'title'     => 'Inloggen',
					'intro'     => 'Kies hoe',
					'notice'    => ['title' => 'Let op', 'text' => 'Onderhoud vanavond'],
					'staffLink' => ['text' => 'Medewerker?', 'label' => 'Hier', 'href' => '/login'],
				],
			]
		)['signInPage'];

		$this->assertSame('Inloggen', $page['title']);
		$this->assertSame('Kies hoe', $page['intro']);
		$this->assertSame(['title' => 'Let op', 'text' => 'Onderhoud vanavond'], $page['notice']);
		$this->assertSame(['text' => 'Medewerker?', 'label' => 'Hier', 'href' => '/login'], $page['staffLink']);
	}//end testSignInPageTexts()


	/**
	 * A staff link to a protocol-relative, script or unlabelled address is dropped.
	 *
	 * @return void
	 */
	public function testAStaffLinkMustBeFollowableAndLabelled(): void {
		$link = fn (array $staff): bool => isset($this->text->project(['signInPage' => ['title' => 't', 'staffLink' => $staff]])['signInPage']['staffLink']);

		$this->assertTrue($link(['label' => 'x', 'href' => 'https://intranet.example']));
		$this->assertTrue($link(['label' => 'x', 'href' => 'mailto:it@example.nl']));
		$this->assertTrue($link(['label' => 'x', 'href' => 'TEL:+3112']));
		$this->assertFalse($link(['label' => 'x', 'href' => '//evil.example']));
		$this->assertFalse($link(['label' => 'x', 'href' => 'javascript:alert(1)']));
		$this->assertFalse($link(['href' => '/login']));
	}//end testAStaffLinkMustBeFollowableAndLabelled()


	/**
	 * A panel keeps its titled items; a panel with neither title nor items is dropped.
	 *
	 * @return void
	 */
	public function testPanelKeepsTitledItems(): void {
		$page = $this->text->project(
			[
				'signInPage' => [
					'panel' => ['items' => [['title' => 'Snel', 'text' => 'In 1 minuut', 'icon' => 'clock'], ['text' => 'no title'], 'x']],
				],
			]
		)['signInPage'];

		$this->assertSame(['title' => '', 'items' => [['title' => 'Snel', 'text' => 'In 1 minuut', 'icon' => 'clock']]], $page['panel']);
		$this->assertSame([], $this->text->project(['signInPage' => ['panel' => ['items' => [['text' => 'x']]]]]));
	}//end testPanelKeepsTitledItems()
}//end class
