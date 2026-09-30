<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Contribution\PortalProviderLocator;
use OCA\Portaliq\Service\PortalItemReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Reading a dossier's items from its app (my-dossiers D2).
 *
 * @spec openspec/changes/my-dossiers/specs/portal-contribution-contract/spec.md#requirement-a-collection-must-be-able-to-declare-an-item-list-read-from-its-app-req-myd-001
 */
class PortalItemReaderTest extends TestCase {
	/**
	 * A reader over a provider answering with the given items.
	 *
	 * @param mixed $answer What the provider method returns, or a Throwable.
	 *
	 * @return PortalItemReader
	 */
	private function reader(mixed $answer): PortalItemReader {
		$provider = new class ($answer) {
			/**
			 * @param mixed $answer The answer.
			 */
			public function __construct(private mixed $answer) {
			}

			/**
			 * @param string $id The dossier id.
			 *
			 * @return mixed
			 */
			public function dossierItems(string $id): mixed {
				if ($this->answer instanceof RuntimeException) {
					throw $this->answer;
				}
				return $this->answer;
			}
		};
		$locator = $this->getMockBuilder(PortalProviderLocator::class)->disableOriginalConstructor()->onlyMethods(['locate'])->getMock();
		$locator->method('locate')->willReturn($provider);

		return new PortalItemReader(locator: $locator, logger: $this->createMock(LoggerInterface::class));
	}//end reader()

	/**
	 * Only the six keys pass, `public` defaults to true.
	 *
	 * @return void
	 */
	public function testKeepsOnlyTheItemKeys(): void {
		$items = $this->reader([
			['id' => 'i1', 'title' => 'Besluit fietspad', 'url' => 'https://gemeente.example/publicatie/1', 'note' => 'Lees p. 3', 'addedAt' => '2026-09-30T10:00:00+00:00', 'owner' => 'bsn-1', 'public' => false],
			['id' => 'i2', 'title' => 'Inventaris', 'url' => '/index.php/apps/opencatalogi/api/search/2'],
		])->items(appId: 'opencatalogi', method: 'dossierItems', id: 'dos-1');

		self::assertSame(
			[
				['id' => 'i1', 'title' => 'Besluit fietspad', 'url' => 'https://gemeente.example/publicatie/1', 'note' => 'Lees p. 3', 'public' => false, 'addedAt' => '2026-09-30T10:00:00+00:00'],
				['id' => 'i2', 'title' => 'Inventaris', 'url' => '/index.php/apps/opencatalogi/api/search/2', 'note' => '', 'public' => true, 'addedAt' => ''],
			],
			$items
		);
	}//end testKeepsOnlyTheItemKeys()

	/**
	 * Unsafe url dropped.
	 *
	 * @return void
	 */
	public function testUnsafeUrlDropped(): void {
		$items = $this->reader([
			['id' => 'a', 'title' => 'x', 'url' => 'javascript:alert(1)'],
			['id' => 'b', 'title' => 'y', 'url' => '//evil.example/p'],
			['id' => 'c', 'title' => 'z', 'url' => 'http://plain.example/p'],
		])->items(appId: 'opencatalogi', method: 'dossierItems', id: 'dos-1');

		self::assertSame(['', '', ''], array_column($items, 'url'));
	}//end testUnsafeUrlDropped()

	/**
	 * A failing or non-list answer is null (502), not an empty dossier.
	 *
	 * @return void
	 */
	public function testAFailureIsNull(): void {
		self::assertNull($this->reader(new RuntimeException('down'))->items(appId: 'opencatalogi', method: 'dossierItems', id: 'dos-1'));
		self::assertNull($this->reader('nope')->items(appId: 'opencatalogi', method: 'dossierItems', id: 'dos-1'));
		self::assertNull($this->reader([])->items(appId: 'opencatalogi', method: 'getContribution', id: 'dos-1'));
	}//end testAFailureIsNull()
}//end class
