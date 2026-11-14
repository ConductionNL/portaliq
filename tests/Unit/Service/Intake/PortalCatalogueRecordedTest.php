<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\CaseTypeVisibility;
use OCA\Portaliq\Service\Intake\PortalCatalogueReader;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCA\Portaliq\Service\ScopedCollectionReaderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A portal's catalogue, grouped from recorded rows.
 */
#[CoversClass(PortalCatalogueReader::class)]
class PortalCatalogueRecordedTest extends TestCase {
	/**
	 * Every row is listed, left out or grouped exactly as recorded, the rows read before the bindings.
	 * A hidden binding without a route hides every row without one.
	 *
	 * @return void
	 */
	public function testEveryRowIsAsRecorded(): void {
		$calls  = [];
		$reader = $this->createMock(ScopedCollectionReaderInterface::class);
		$reader->method('readCollection')->willReturnCallback(
			static function (...$arguments) use (&$calls): array {
				$calls[] = 'read ' . json_encode($arguments);
				return [
					['portal' => 'x', 'status' => 'published', 'topic' => 'Wonen', 'title' => 'Verhuizen', 'summary' => 'Geef het door', 'route' => '/verhuizen'],
					'not a row',
					['portal' => 'y', 'status' => 'published', 'topic' => 'Wonen', 'title' => 'Other portal', 'route' => '/y'],
					['status' => 'published', 'topic' => 'Wonen', 'title' => 'No portal'],
					['portal' => 'x', 'status' => 'draft', 'topic' => 'Wonen', 'title' => 'Draft'],
					['portal' => 'x', 'topic' => 'Wonen', 'title' => 'No status'],
					['portal' => 'x', 'status' => 'published', 'topic' => 'Wonen', 'title' => 'Hidden', 'route' => '/hidden'],
					['portal' => 'x', 'status' => 'published', 'title' => 'No topic'],
					['portal' => 'x', 'status' => 'published', 'title' => 'No topic, a route', 'route' => '/nt'],
					['portal' => 'x', 'status' => 'published', 'topic' => 'Afval', 'title' => 'Container', 'route' => '/container'],
					['portal' => 'x', 'status' => 'published', 'topic' => 'Wonen', 'route' => '/bare'],
				];
			}
		);
		$bindings = $this->createMock(PortalFormBindingResolver::class);
		$bindings->method('publishedBindings')->willReturnCallback(
			static function (string $portal) use (&$calls): array {
				$calls[] = 'bindings ' . $portal;
				return [['route' => '/hidden', 'hidden' => true], ['route' => '/verhuizen', 'hidden' => false], ['hidden' => true]];
			}
		);
		$caseTypes = $this->createMock(CaseTypeVisibility::class);
		$caseTypes->method('hidesBinding')->willReturnCallback(static fn (array $binding): bool => $binding['hidden']);

		$topics = (new PortalCatalogueReader($reader, $bindings, $caseTypes))->topicsFor(portal: 'x');

		$this->assertSame(
			[
				[
					'topic' => 'Wonen',
					'entries' => [
						['title' => 'Verhuizen', 'summary' => 'Geef het door', 'route' => '/verhuizen', 'fee' => null],
						['title' => '', 'summary' => '', 'route' => '/bare', 'fee' => null],
					],
				],
				['topic' => '', 'entries' => [['title' => 'No topic, a route', 'summary' => '', 'route' => '/nt', 'fee' => null]]],
				['topic' => 'Afval', 'entries' => [['title' => 'Container', 'summary' => '', 'route' => '/container', 'fee' => null]]],
			],
			$topics
		);
		$this->assertCount(2, $calls);
		$this->assertStringStartsWith('read ', $calls[0]);
		$this->assertSame('bindings x', $calls[1]);
		$this->assertSame([], (new PortalCatalogueReader($reader, $bindings, $caseTypes))->topicsFor(portal: ''));
		$this->assertCount(2, $calls, 'no portal reads nothing');
	}//end testEveryRowIsAsRecorded()
}//end class
