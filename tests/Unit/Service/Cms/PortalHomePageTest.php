<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\Cms\PortalHomePage;
use OCA\Portaliq\Service\PortalObjectReader;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * portaliq-cms: a portal with no published page at its root is a
 * configuration error, and an absent page and a draft are different errors.
 *
 * Both URLs of a route-less portal serve the byte-identical SPA shell, so
 * nothing downstream of this classification can tell the two apart. These
 * assertions are where the distinction is pinned.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-without-a-published-home-page-must-be-reported-as-a-configuration-error
 */
class PortalHomePageTest extends TestCase {
	private const DRAFT = [
		'id'     => 'page-draft',
		'title'  => 'Welkom',
		'route'  => '/',
		'status' => 'draft',
		'portal' => 'wilgenboom',
	];

	private const PUBLISHED = [
		'@self'  => ['uuid' => 'page-live'],
		'title'  => 'Welkom',
		'route'  => '/',
		'status' => 'published',
		'portal' => 'wilgenboom',
	];

	public function testNoPageAtTheRootIsTheMissingState(): void {
		$verdict = $this->service(rows: [])->verdict(slug: 'wilgenboom');

		$this->assertSame('missing', $verdict['state']);
		$this->assertSame('/', $verdict['route']);
		$this->assertNull($verdict['pageId']);
		$this->assertNull($verdict['pageTitle']);
	}//end testNoPageAtTheRootIsTheMissingState()

	public function testADraftAtTheRootIsADraftNotAnAbsence(): void {
		$verdict = $this->service(rows: [self::DRAFT])->verdict(slug: 'wilgenboom');

		$this->assertSame('draft', $verdict['state']);
		$this->assertSame('page-draft', $verdict['pageId'], 'the report must name the page so it can be opened and published');
		$this->assertSame('Welkom', $verdict['pageTitle']);
	}//end testADraftAtTheRootIsADraftNotAnAbsence()

	public function testAPublishedPageAtTheRootClearsTheError(): void {
		$verdict = $this->service(rows: [self::PUBLISHED])->verdict(slug: 'wilgenboom');

		$this->assertSame('published', $verdict['state']);
		$this->assertSame('page-live', $verdict['pageId'], 'the identifier must be read from the @self envelope as well as the flat row');
	}//end testAPublishedPageAtTheRootClearsTheError()

	/**
	 * A route is unique within a portal, so a duplicate is already a fault.
	 * What a visitor gets is the published page, so that is what is reported.
	 */
	public function testAPublishedRowWinsOverADuplicateDraft(): void {
		$verdict = $this->service(rows: [self::DRAFT, self::PUBLISHED])->verdict(slug: 'wilgenboom');

		$this->assertSame('published', $verdict['state']);
	}//end testAPublishedRowWinsOverADuplicateDraft()

	/**
	 * The query filter is a narrowing, not the verdict. A reader that widened
	 * it, or a schema that stored a route this app did not ask for, must not
	 * turn another page into a home page.
	 */
	public function testAPageAtAnotherRouteIsNeverAHomePage(): void {
		$elsewhere = ['id' => 'page-about', 'route' => '/over-ons', 'status' => 'published', 'portal' => 'wilgenboom'];

		$this->assertSame('missing', PortalHomePage::classify(rows: [$elsewhere])['state']);
		$this->assertSame('missing', PortalHomePage::classify(rows: ['not-a-row', null, []])['state']);
		$this->assertSame('published', PortalHomePage::classify(rows: [$elsewhere, self::PUBLISHED])['state']);
	}//end testAPageAtAnotherRouteIsNeverAHomePage()

	/**
	 * The read asks OpenRegister for this portal's pages at the root route and
	 * nothing wider. A filter naming a property the rows do not carry matches
	 * nothing and says nothing, so the arguments are asserted, not assumed.
	 */
	public function testTheReadAsksForThisPortalsRootRouteOnly(): void {
		$seen = [];
		$reader = $this->reader();
		$reader->method('readCollection')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation = '', int $limit = 200, string $scopeClaim = '', string $contributingApp = '', mixed $via = null, string $audience = '', mixed $fields = null, array $filter = []) use (&$seen): array {
				$seen = [
					'register'   => $register,
					'schema'     => $schema,
					'scopeField' => $scopeField,
					'subjectRef' => $subjectRef,
					'filter'     => $filter,
				];

				return [];
			}
		);

		(new PortalHomePage($reader))->verdict(slug: 'wilgenboom');

		$this->assertSame(
			[
				'register'   => 'portaliq',
				'schema'     => 'page',
				'scopeField' => 'portal',
				'subjectRef' => 'wilgenboom',
				'filter'     => ['route' => '/'],
			],
			$seen
		);
	}//end testTheReadAsksForThisPortalsRootRouteOnly()

	public function testAnEmptySlugReadsNothingAndStillAnswers(): void {
		$reader = $this->reader();
		$reader->expects($this->never())->method('readCollection');

		$this->assertSame('missing', (new PortalHomePage($reader))->verdict(slug: '')['state']);
	}//end testAnEmptySlugReadsNothingAndStillAnswers()

	/**
	 * The service under test, over a fixed set of rows at the root route.
	 *
	 * @param array<int, mixed> $rows The rows the reader answers.
	 *
	 * @return PortalHomePage
	 */
	private function service(array $rows): PortalHomePage {
		$reader = $this->reader();
		$reader->method('readCollection')->willReturn($rows);

		return new PortalHomePage($reader);
	}//end service()

	/**
	 * A reader whose only stubbed method is the collection read.
	 *
	 * @return PortalObjectReader&MockObject
	 */
	private function reader(): PortalObjectReader {
		return $this->getMockBuilder(PortalObjectReader::class)
			->disableOriginalConstructor()
			->onlyMethods(['readCollection'])
			->getMock();
	}//end reader()
}//end class
