<?php

/**
 * The small parts of the plain site page: its addresses, its dates, and the
 * names it reads for widgets and information categories
 * (site-honest-without-javascript REQ-SHJ-002, REQ-SHJ-004).
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Cms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/site-honest-without-javascript/tasks.md#2.4
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\Cms\PlainAddress;
use OCA\Portaliq\Service\Cms\PlainCategoryNames;
use OCA\Portaliq\Service\Cms\PlainContext;
use OCA\Portaliq\Service\Cms\PlainQuery;
use OCA\Portaliq\Service\Cms\PlainDates;
use OCA\Portaliq\Service\Cms\PlainWidgetLabels;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlainAddress::class)]
#[CoversClass(PlainContext::class)]
#[CoversClass(PlainQuery::class)]
#[CoversClass(PlainDates::class)]
#[CoversClass(PlainWidgetLabels::class)]
#[CoversClass(PlainCategoryNames::class)]
class PlainHelpersTest extends TestCase {

	/**
	 * Plain and full addresses carry the route and the portal; a `/` stays
	 * readable and every other reserved character is encoded.
	 *
	 * @return void
	 */
	public function testTheAddressesCarryTheRouteAndThePortal(): void {
		$named = new PlainAddress('/site', 'demo');
		$host = new PlainAddress('/site', '');

		$this->assertSame('/site/plain', $named->plainBase());
		$this->assertSame('demo', $named->portalSlug());
		$this->assertSame('/site/plain?route=/afval/groen&portal=demo', $named->plain('afval/groen/'));
		$this->assertSame('/site?route=/zoeken&_search=a%26b%20c&_page=2', $host->full('/zoeken', ['_search' => 'a&b c', '_page' => '2']));
		$this->assertSame('/site/plain?route=/', $host->plain(''));
	}//end testTheAddressesCarryTheRouteAndThePortal()

	/**
	 * An authored route goes to its plain version with its own query kept as
	 * written (a dotted name stays dotted) and never overriding the route or
	 * portal; web, mail and phone addresses stay; anything else is dropped.
	 *
	 * @return void
	 */
	public function testAnAuthoredLinkIsHeldToSafeTargets(): void {
		$address = new PlainAddress('/site', 'demo');

		$this->assertSame('/site/plain?route=/zoeken&portal=demo&f.wooCategory=c1&q=a%20b', $address->authored(' /zoeken?f.wooCategory=c1&q=a+b&portal=elders&route=/x '));
		$this->assertSame('https://example.nl/a', $address->authored('https://example.nl/a'));
		$this->assertSame('mailto:info@example.nl', $address->authored('mailto:info@example.nl'));
		$this->assertSame('tel:0123', $address->authored('tel:0123'));
		foreach (['//elders.example', 'javascript:alert(1)', 'data:text/html,x', 'afval', ''] as $link) {
			$this->assertSame('', $address->authored($link), $link);
		}
	}//end testAnAuthoredLinkIsHeldToSafeTargets()

	/**
	 * The context's full page keeps the search and a page beyond the first.
	 *
	 * @return void
	 */
	public function testTheContextNamesItsFullPage(): void {
		$address = new PlainAddress('/site', '');

		$this->assertSame('/site?route=/zoeken&_search=afval&_page=3', (new PlainContext('/zoeken', '', new PlainQuery('afval', 3), $address, 'nl'))->fullUrl());
		$this->assertSame('/site?route=/zoeken', (new PlainContext('/zoeken', 'x', new PlainQuery(), $address, 'nl'))->fullUrl());
	}//end testTheContextNamesItsFullPage()

	/**
	 * The request's record, search and page become address parameters; an
	 * empty one and page 1 are left out, a page below 1 is page 1, and the
	 * context's full page keeps the record.
	 *
	 * @return void
	 */
	public function testTheQueryNamesOnlyWhatWasAsked(): void {
		$this->assertSame(['record' => 'm1', '_search' => 'vvd', '_page' => '2'], (new PlainQuery('vvd', 2, 'm1'))->params());
		$this->assertSame([], (new PlainQuery('', -4, ''))->params());
		$this->assertSame(1, (new PlainQuery('', -4))->page);

		$context = new PlainContext('/raad', '', new PlainQuery(record: 'm1'), new PlainAddress('/site', ''), 'nl');
		$this->assertSame('m1', $context->record);
		$this->assertSame('/site?route=/raad&record=m1', $context->fullUrl());
	}//end testTheQueryNamesOnlyWhatWasAsked()

	/**
	 * A date reads as words in the document language, on the day it was
	 * written; anything that is not a date is ''.
	 *
	 * @return void
	 */
	public function testADateReadsAsWords(): void {
		$dates = new PlainDates();

		$this->assertSame('1 maart 2026', $dates->readable('2026-03-01', 'nl'));
		$this->assertSame('1 maart 2026', $dates->readable('2026-03-01T23:30:00-05:00', 'nl-NL'));
		$this->assertSame('March 1, 2026', $dates->readable('2026-03-01', 'en'));
		foreach (['', 'gisteren', '2026-02-30', '01-03-2026'] as $value) {
			$this->assertSame('', $dates->readable($value, 'nl'), $value);
		}
	}//end testADateReadsAsWords()

	/**
	 * A widget reads by its label, else by its key written as words.
	 *
	 * @return void
	 */
	public function testAWidgetReadsByItsLabel(): void {
		$labels = new PlainWidgetLabels();

		$this->assertSame('Aanvraagformulier', $labels->label('intakeForm'));
		$this->assertSame('Uitklapblokken', $labels->label('nlAccordion'));
		$this->assertSame('Some New Block', $labels->label('someNewBlock'));
		$this->assertSame('My block', $labels->label('my_block'));
		$this->assertSame('Blok', $labels->label(''));
	}//end testAWidgetReadsByItsLabel()

	/**
	 * A category code reads by its name in Dutch or English; an unknown code
	 * reads as itself.
	 *
	 * @return void
	 */
	public function testACategoryReadsByName(): void {
		$names = new PlainCategoryNames();

		$this->assertCount(17, PlainCategoryNames::WOO_CATEGORIES);
		$this->assertSame('Woo-verzoeken en -besluiten', $names->name('infocat014', 'nl'));
		$this->assertSame('Woo requests and decisions', $names->name('infocat014', 'en-GB'));
		$this->assertSame('infocat999', $names->name('infocat999', 'nl'));
	}//end testACategoryReadsByName()
}//end class
