<?php

/**
 * The help details and section help texts a portal serves
 * (help-texts-and-form-help).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/help-texts-and-form-help/specs/site-help-texts/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\Cms\PortalHelp;
use OCA\Portaliq\Service\Cms\PortalShell;
use PHPUnit\Framework\TestCase;

class PortalHelpTest extends TestCase {

	public function testOnlyPlainDetailsSurvive(): void {
		$out = (new PortalHelp())->details([
			'intro' => ' Komt u er niet uit? ',
			'phone' => '14 020',
			'email' => 'info@zuiddrecht.example',
			'image' => 'javascript:alert(1)',
			'hours' => ['ma'],
			'secret' => 'x',
		]);

		$this->assertSame(['intro' => 'Komt u er niet uit?', 'phone' => '14 020', 'email' => 'info@zuiddrecht.example'], $out);
		$this->assertSame('/media/1', (new PortalHelp())->details(['image' => '/media/1'])['image']);
		$this->assertSame('https://x.example/a.png', (new PortalHelp())->details(['image' => 'https://x.example/a.png'])['image']);
		$this->assertArrayNotHasKey('email', (new PortalHelp())->details(['email' => 'not an address']));
		$this->assertSame([], (new PortalHelp())->details('junk'));
		$this->assertSame([], (new PortalHelp())->details(null));
	}

	public function testSectionTextsAreKeyedByTheKnownParts(): void {
		$out = (new PortalHelp())->sections(['tasks' => ' Hier staat wat de gemeente van u nodig heeft. ', 'unknown' => 'x', 'cases' => '', 'messages' => 5]);

		$this->assertSame(['tasks' => 'Hier staat wat de gemeente van u nodig heeft.'], $out);
		$this->assertSame([], (new PortalHelp())->sections('x'));
	}

	public function testTheShellServesBoth(): void {
		$shell = (new PortalShell())->project(['help' => ['phone' => '14 020'], 'sectionHelp' => ['tasks' => 'Hulp']]);
		$none  = (new PortalShell())->project([]);

		$this->assertSame(['phone' => '14 020'], $shell['help']);
		$this->assertSame(['tasks' => 'Hulp'], $shell['sectionHelp']);
		$this->assertSame([], $none['help']);
		$this->assertSame([], $none['sectionHelp']);
	}
}
