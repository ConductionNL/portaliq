<?php

/**
 * Unit tests for TrafficMatch.
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

namespace OCA\Portaliq\Tests\Unit\Service\Traffic;

use OCA\Portaliq\Service\Traffic\TrafficMatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Which tracked events a goal or journey step matches.
 */
#[CoversClass(TrafficMatch::class)]
class TrafficMatchTest extends TestCase {

	/**
	 * The matcher under test.
	 *
	 * @var TrafficMatch
	 */
	private TrafficMatch $match;


	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->match = new TrafficMatch();
	}//end setUp()


	/**
	 * Only known keys with a non-blank string survive, trimmed.
	 *
	 * @return void
	 */
	public function testNormaliseKeepsKnownNonBlankStringKeysTrimmed(): void {
		$this->assertSame(
			['pathPrefix' => '/aanvragen', 'formId' => 'f-1'],
			$this->match->normalise(['pathPrefix' => '  /aanvragen ', 'formId' => 'f-1', 'term' => '   ', 'other' => 'x', 'eventName' => 42])
		);
	}//end testNormaliseKeepsKnownNonBlankStringKeysTrimmed()


	/**
	 * A value is cut at 256 characters.
	 *
	 * @return void
	 */
	public function testNormaliseCutsLongValues(): void {
		$out = $this->match->normalise(['term' => str_repeat('é', 300)]);

		$this->assertSame(256, mb_strlen($out['term']));
	}//end testNormaliseCutsLongValues()


	/**
	 * Nothing usable is null, not an empty match that would match everything.
	 *
	 * @return void
	 */
	public function testNormaliseOfNothingUsableIsNull(): void {
		$this->assertNull($this->match->normalise('pathPrefix'));
		$this->assertNull($this->match->normalise(['other' => 'x']));
		$this->assertNull($this->match->normalise([]));
	}//end testNormaliseOfNothingUsableIsNull()


	/**
	 * A path prefix matches a page view by its path, or the path of its location.
	 *
	 * @return void
	 */
	public function testPathPrefixMatchesPageViewsByPathOrLocation(): void {
		$match = ['pathPrefix' => '/aanvragen'];

		$this->assertTrue($this->match->matches($match, ['name' => 'page_view', 'pagePath' => '/aanvragen/paspoort']));
		$this->assertTrue($this->match->matches($match, ['name' => 'page_view', 'pageLocation' => 'https://gemeente.nl/aanvragen/rijbewijs?x=1']));
		$this->assertFalse($this->match->matches($match, ['name' => 'page_view', 'pagePath' => '/nieuws']));
		$this->assertFalse($this->match->matches($match, ['name' => 'search', 'pagePath' => '/aanvragen']));
	}//end testPathPrefixMatchesPageViewsByPathOrLocation()


	/**
	 * Path equality ignores a trailing slash on either side, except the root.
	 *
	 * @return void
	 */
	public function testPathEqualsIgnoresATrailingSlash(): void {
		$this->assertTrue($this->match->matches(['pathEquals' => '/contact/'], ['name' => 'page_view', 'pagePath' => '/contact']));
		$this->assertTrue($this->match->matches(['pathEquals' => '/contact'], ['name' => 'page_view', 'pagePath' => '/contact/']));
		$this->assertTrue($this->match->matches(['pathEquals' => '/'], ['name' => 'page_view', 'pagePath' => '/']));
		$this->assertFalse($this->match->matches(['pathEquals' => '/contact'], ['name' => 'page_view', 'pagePath' => '/contact/form']));
	}//end testPathEqualsIgnoresATrailingSlash()


	/**
	 * A file extension matches a download by its file name or link, case-insensitively and with or without the dot.
	 *
	 * @return void
	 */
	public function testFileExtensionMatchesDownloads(): void {
		$this->assertTrue($this->match->matches(['fileExtension' => '.PDF'], ['name' => 'file_download', 'fileName' => 'besluit.pdf']));
		$this->assertTrue($this->match->matches(['fileExtension' => 'docx'], ['name' => 'file_download', 'params' => ['link_url' => 'https://x.nl/a/brief.DOCX']]));
		$this->assertFalse($this->match->matches(['fileExtension' => 'pdf'], ['name' => 'file_download', 'fileName' => 'readme']));
		$this->assertFalse($this->match->matches(['fileExtension' => 'pdf'], ['name' => 'page_view', 'fileName' => 'besluit.pdf']));
	}//end testFileExtensionMatchesDownloads()


	/**
	 * A form id matches the submit of that form only.
	 *
	 * @return void
	 */
	public function testFormIdMatchesThatFormsSubmit(): void {
		$this->assertTrue($this->match->matches(['formId' => 'melding'], ['name' => 'form_submit', 'params' => ['formId' => 'melding']]));
		$this->assertFalse($this->match->matches(['formId' => 'melding'], ['name' => 'form_submit', 'params' => ['formId' => 'klacht']]));
	}//end testFormIdMatchesThatFormsSubmit()


	/**
	 * A term matches a search containing it, case-insensitively, from any of its three spellings.
	 *
	 * @return void
	 */
	public function testTermMatchesSearchesContainingIt(): void {
		$match = ['term' => 'Paspoort'];

		$this->assertTrue($this->match->matches($match, ['name' => 'search', 'searchTerm' => 'nieuw paspoort aanvragen']));
		$this->assertTrue($this->match->matches($match, ['name' => 'search', 'params' => ['search_term' => 'PASPOORT']]));
		$this->assertTrue($this->match->matches($match, ['name' => 'search', 'params' => ['searchTerm' => 'paspoortje']]));
		$this->assertFalse($this->match->matches($match, ['name' => 'search', 'searchTerm' => 'rijbewijs']));
	}//end testTermMatchesSearchesContainingIt()


	/**
	 * An event name matches any event of that name.
	 *
	 * @return void
	 */
	public function testEventNameMatchesByName(): void {
		$this->assertTrue($this->match->matches(['eventName' => 'video_start'], ['name' => 'video_start']));
		$this->assertFalse($this->match->matches(['eventName' => 'video_start'], ['name' => 'video_end']));
	}//end testEventNameMatchesByName()


	/**
	 * A step type demands its event name before any key is looked at.
	 *
	 * @return void
	 */
	public function testAStepTypeDemandsItsEvent(): void {
		$this->assertTrue($this->match->matches([], ['name' => 'form_submit'], 'form_submitted'));
		$this->assertFalse($this->match->matches([], ['name' => 'page_view'], 'form_submitted'));
		$this->assertTrue($this->match->matches(['eventName' => 'x'], ['name' => 'x'], 'event'));
		$this->assertTrue($this->match->matches([], ['name' => 'anything'], 'unknown-type'));
	}//end testAStepTypeDemandsItsEvent()


	/**
	 * Every key must hold; an unknown key never does.
	 *
	 * @return void
	 */
	public function testEveryKeyMustHoldAndUnknownKeysFail(): void {
		$event = ['name' => 'page_view', 'pagePath' => '/aanvragen/paspoort'];

		$this->assertTrue($this->match->matches(['pathPrefix' => '/aanvragen', 'pathEquals' => '/aanvragen/paspoort'], $event));
		$this->assertFalse($this->match->matches(['pathPrefix' => '/aanvragen', 'pathEquals' => '/aanvragen'], $event));
		$this->assertFalse($this->match->matches(['bogus' => 'x'], $event));
	}//end testEveryKeyMustHoldAndUnknownKeysFail()
}//end class
