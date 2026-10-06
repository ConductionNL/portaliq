<?php
/**
 * SiteLocale: the visitor's language held to the portal's declared locales.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Site
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

namespace OCA\Portaliq\Tests\Unit\Service\Site;

use OCA\Portaliq\Service\Site\SiteLocale;
use PHPUnit\Framework\TestCase;

/**
 * site-matches-the-zuiddrecht-boards: the document language follows the portal.
 */
class SiteLocaleTest extends TestCase {


	/**
	 * A portal that declares only Dutch serves Dutch to a browser that asks for English.
	 *
	 * @return void
	 */
	public function testADutchOnlyPortalServesDutch(): void {
		$this->assertSame(expected: 'nl', actual: SiteLocale::forPortal(portal: ['locales' => ['nl']], locale: 'en-US'));

	}//end testADutchOnlyPortalServesDutch()


	/**
	 * A declared language is served as the browser asked for it, region included.
	 *
	 * @return void
	 */
	public function testADeclaredLanguageIsServedAsAsked(): void {
		$this->assertSame(expected: 'en-US', actual: SiteLocale::forPortal(portal: ['locales' => ['nl', 'en']], locale: 'en-US'));
		$this->assertSame(expected: 'nl', actual: SiteLocale::forPortal(portal: ['locales' => ['NL ', '']], locale: 'nl'));

	}//end testADeclaredLanguageIsServedAsAsked()


	/**
	 * No portal, or no declared locales: the browser's language, as before.
	 *
	 * @return void
	 */
	public function testAnUndeclaredPortalServesWhatWasAsked(): void {
		$this->assertSame(expected: 'en-US', actual: SiteLocale::forPortal(portal: null, locale: 'en-US'));
		$this->assertSame(expected: 'de', actual: SiteLocale::forPortal(portal: ['locales' => []], locale: 'de'));
		$this->assertSame(expected: 'nl', actual: SiteLocale::forPortal(portal: ['locales' => 'nl'], locale: 'de'), message: 'one declared string counts as a list');

	}//end testAnUndeclaredPortalServesWhatWasAsked()
}//end class
