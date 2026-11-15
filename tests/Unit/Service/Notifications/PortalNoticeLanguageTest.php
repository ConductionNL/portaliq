<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Notifications;

use OCA\Portaliq\Service\Notifications\PortalNoticeLanguage;
use OCA\Portaliq\Service\PortalResolver;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Which language a notice to an organisation is written in: the portal's first
 * declared locale when there is one, else Dutch, and never a lost notice when
 * the portal cannot be resolved.
 */
#[CoversClass(PortalNoticeLanguage::class)]
class PortalNoticeLanguageTest extends TestCase {
	/**
	 * The organisation's first declared locale is the notice language, trimmed.
	 *
	 * @return void
	 */
	public function testTheFirstDeclaredLocaleIsUsed(): void {
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('resolveByOrganisation')->with('org-1')->willReturn(['locales' => ['  de ', 'en']]);

		$this->assertSame('de', (new PortalNoticeLanguage($this->createMock(IFactory::class), $portals))->languageFor(organisation: 'org-1'));
	}//end testTheFirstDeclaredLocaleIsUsed()

	/**
	 * No locales, a blank first locale, a non-string first locale or no portal
	 * at all all fall back to Dutch.
	 *
	 * @return void
	 */
	public function testEmptyOrUnresolvableFallsBackToDutch(): void {
		$cases = [
			['locales' => []],
			['locales' => ['   ']],
			['locales' => [5]],
			['locales' => 'de'],
			[],
			null,
		];
		foreach ($cases as $i => $resolved) {
			$portals = $this->createMock(PortalResolver::class);
			$portals->method('resolveByOrganisation')->willReturn($resolved);

			$this->assertSame('nl', (new PortalNoticeLanguage($this->createMock(IFactory::class), $portals))->languageFor(organisation: 'org-' . $i), (string) $i);
		}
	}//end testEmptyOrUnresolvableFallsBackToDutch()

	/**
	 * A resolver that throws does not lose the notice — the language is Dutch.
	 *
	 * @return void
	 */
	public function testAThrowingResolverFallsBackToDutch(): void {
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('resolveByOrganisation')->willThrowException(new RuntimeException('no db'));

		$this->assertSame('nl', (new PortalNoticeLanguage($this->createMock(IFactory::class), $portals))->languageFor(organisation: 'org-x'));
	}//end testAThrowingResolverFallsBackToDutch()

	/**
	 * forOrganisation asks the l10n factory for this app in the resolved
	 * language and returns what it gives.
	 *
	 * @return void
	 */
	public function testForOrganisationAsksTheFactoryForThatLanguage(): void {
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('resolveByOrganisation')->willReturn(['locales' => ['fr']]);

		$l10n    = $this->createMock(IL10N::class);
		$factory = $this->createMock(IFactory::class);
		$factory->expects($this->once())->method('get')->with('portaliq', 'fr')->willReturn($l10n);

		$this->assertSame($l10n, (new PortalNoticeLanguage($factory, $portals))->forOrganisation(organisation: 'org-1'));
	}//end testForOrganisationAsksTheFactoryForThatLanguage()
}//end class
