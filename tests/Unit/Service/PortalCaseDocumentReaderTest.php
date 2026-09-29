<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Contribution\PortalProviderLocator;
use OCA\Portaliq\Service\PortalCaseDocumentReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The documents a case app publishes on one case, as its declared method
 * returns them (cases-documents-on-the-case, REQ-CDC-001). Only well-formed
 * entries are kept; a provider that fails gives none.
 *
 * @spec openspec/specs/citizen-case-documents/spec.md#requirement-the-case-app-declares-which-documents-a-resident-may-see-req-cdc-001
 */
class PortalCaseDocumentReaderTest extends TestCase {

	/**
	 * A reader over a provider that answers `$entries`, or throws.
	 *
	 * @param mixed $entries What the method returns, or a Throwable to throw.
	 *
	 * @return PortalCaseDocumentReader
	 */
	private function reader(mixed $entries): PortalCaseDocumentReader {
		$provider = new class ($entries) {
			/**
			 * @param mixed $entries The answer.
			 */
			public function __construct(private mixed $entries) {
			}

			/**
			 * The documents on one case.
			 *
			 * @param string $caseId The case.
			 *
			 * @return mixed
			 */
			public function caseDocuments(string $caseId): mixed {
				if ($this->entries instanceof \Throwable) {
					throw $this->entries;
				}

				return ($caseId === 'case-1' ? $this->entries : []);
			}
		};
		$locator = $this->createMock(PortalProviderLocator::class);
		$locator->method('locate')->willReturnCallback(static fn (string $app): ?object => ($app === 'dossiq' ? $provider : null));

		return new PortalCaseDocumentReader(locator: $locator, logger: $this->createMock(LoggerInterface::class));
	}//end reader()

	/**
	 * Well-formed entries come back as the app gave them.
	 *
	 * @return void
	 */
	public function testKeepsWellFormedEntries(): void {
		$decision = ['id' => 'doc-1', 'title' => 'Besluit', 'kind' => 'decision', 'date' => '2026-09-01', 'file' => ['register' => 'zaken', 'schema' => 'document', 'id' => 'd-1', 'fileId' => '71'], 'mimeType' => 'application/pdf', 'size' => 2048];
		$letter = ['id' => 'doc-2', 'title' => 'Ontvangstbevestiging', 'file' => ['register' => 'zaken', 'schema' => 'document', 'id' => 'd-2', 'fileId' => 72]];

		$entries = $this->reader([$decision, $letter])->entries(appId: 'dossiq', method: 'caseDocuments', caseId: 'case-1');

		$this->assertSame($decision, $entries[0]);
		$this->assertSame('document', $entries[1]['kind'], 'no kind is a document');
		$this->assertSame('', $entries[1]['date']);
		$this->assertSame('72', $entries[1]['file']['fileId']);
		$this->assertSame([], $this->reader([$decision])->entries(appId: 'other', method: 'caseDocuments', caseId: 'case-1'), 'no provider, no entries');
		$this->assertSame([], $this->reader([$decision])->entries(appId: 'dossiq', method: 'getContribution', caseId: 'case-1'), 'a contract method is never called');
	}//end testKeepsWellFormedEntries()

	/**
	 * An entry without an id, a title or a complete file reference is dropped.
	 *
	 * @return void
	 */
	public function testDropsAnEntryWithoutFile(): void {
		$file = ['register' => 'zaken', 'schema' => 'document', 'id' => 'd-1', 'fileId' => '71'];
		$entries = $this->reader([
			['id' => 'a', 'title' => 'Zonder bestand'],
			['id' => 'b', 'title' => 'Half bestand', 'file' => ['register' => 'zaken', 'id' => 'd-1']],
			['title' => 'Zonder id', 'file' => $file],
			['id' => 'c', 'file' => $file],
			'geen entry',
			['id' => 'd', 'title' => 'Goed', 'file' => $file],
		])->entries(appId: 'dossiq', method: 'caseDocuments', caseId: 'case-1');

		$this->assertSame(['d'], array_column($entries, 'id'));
	}//end testDropsAnEntryWithoutFile()

	/**
	 * A provider that throws or answers something else gives no entries.
	 *
	 * @return void
	 */
	public function testAFailingProviderGivesNoEntries(): void {
		$this->assertSame([], $this->reader(new RuntimeException('down'))->entries(appId: 'dossiq', method: 'caseDocuments', caseId: 'case-1'));
		$this->assertSame([], $this->reader('niet een lijst')->entries(appId: 'dossiq', method: 'caseDocuments', caseId: 'case-1'));
	}//end testAFailingProviderGivesNoEntries()
}//end class
