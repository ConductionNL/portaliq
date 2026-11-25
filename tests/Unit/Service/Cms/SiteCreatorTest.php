<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\Cms\SiteCreator;
use PHPUnit\Framework\TestCase;

/**
 * The head's DCTERMS.creator names the organisation and its kind (REQ-PIA-003).
 *
 * @covers \OCA\Portaliq\Service\Cms\SiteCreator
 *
 * @spec openspec/specs/portaliq-cms/spec.md
 */
final class SiteCreatorTest extends TestCase {

	public function testTheOrganisationIsNamedWithItsType(): void {
		self::assertSame(
			'Hoogheemraadschap Noord (waterschap)',
			SiteCreator::content(portal: ['title' => 'Mijn Noord', 'organisationTypeLabel' => ' waterschap '], organisation: ['id' => 'o1', 'name' => 'Hoogheemraadschap Noord'])
		);
	}//end testTheOrganisationIsNamedWithItsType()

	public function testWithoutAnOrganisationThePortalTitleStands(): void {
		self::assertSame('Mijn Noord (waterschap)', SiteCreator::content(portal: ['title' => 'Mijn Noord', 'organisationTypeLabel' => 'waterschap'], organisation: null));
		self::assertSame('Mijn Noord', SiteCreator::content(portal: ['title' => 'Mijn Noord'], organisation: ['id' => 'o1', 'name' => '  ']));
	}//end testWithoutAnOrganisationThePortalTitleStands()

	public function testNoNameMeansNoCreator(): void {
		self::assertSame('', SiteCreator::content(portal: ['organisationTypeLabel' => 'waterschap'], organisation: null));
	}//end testNoNameMeansNoCreator()

	/**
	 * The footer names the kind with its Dutch article, and never a municipality it is not.
	 *
	 * @return void
	 */
	public function testTheFooterLineNamesTheKindWithItsArticle(): void {
		self::assertSame(
			expected: 'Een website van het waterschap',
			actual: (new SiteCreator())->kindLine(portal: ['organisationTypeLabel' => ' Waterschap '], locale: 'nl')
		);
		self::assertSame(
			expected: 'Een website van het ministerie',
			actual: (new SiteCreator())->kindLine(portal: ['organisationTypeLabel' => 'ministerie'], locale: 'nl-NL')
		);
		self::assertSame(
			expected: 'Een website van de provincie',
			actual: (new SiteCreator())->kindLine(portal: ['organisationTypeLabel' => 'provincie'], locale: 'nl')
		);
	}//end testTheFooterLineNamesTheKindWithItsArticle()

	/**
	 * No type, or a page in another language, has no footer line.
	 *
	 * @return void
	 */
	public function testNoTypeOrAnotherLanguageHasNoFooterLine(): void {
		self::assertSame(
			expected: '',
			actual: (new SiteCreator())->kindLine(portal: ['title' => 'Open Tilburg'], locale: 'nl')
		);
		self::assertSame(
			expected: '',
			actual: (new SiteCreator())->kindLine(portal: ['organisationTypeLabel' => '  '], locale: 'nl')
		);
		self::assertSame(
			expected: '',
			actual: (new SiteCreator())->kindLine(portal: ['organisationTypeLabel' => 'waterschap'], locale: 'en')
		);
	}//end testNoTypeOrAnotherLanguageHasNoFooterLine()
}//end class
