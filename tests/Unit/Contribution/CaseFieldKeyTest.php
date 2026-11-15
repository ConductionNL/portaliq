<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * case-page-tasks-decision-dates-and-next-step T02: `caseField` on a tasks
 * collection is kept when it names a projected field and dropped when it does
 * not, through the real manifest normaliser.
 *
 * @spec openspec/changes/case-page-tasks-decision-dates-and-next-step/tasks.md#t02
 */
class CaseFieldKeyTest extends TestCase {
	/**
	 * The first collection after the real normaliser.
	 *
	 * @param array<string, mixed> $extra Keys added to the collection.
	 *
	 * @return array<string, mixed>
	 */
	private function collection(array $extra): array {
		$out = (new PortalManifestNormaliser())->normalise(
			['collections' => [['id' => 'taken', 'schema' => 'taak', 'fields' => ['title', 'zaak', 'due']] + $extra], 'actions' => [], 'pages' => []]
		);

		return $out['collections'][0];
	}//end collection()

	public function testACaseFieldNamingAProjectedFieldIsKept(): void {
		$this->assertSame('zaak', $this->collection(['caseField' => 'zaak'])['caseField']);
	}//end testACaseFieldNamingAProjectedFieldIsKept()

	public function testACaseFieldNamingNoProjectedFieldIsDropped(): void {
		$this->assertArrayNotHasKey('caseField', $this->collection(['caseField' => 'elders']));
		$this->assertArrayNotHasKey('caseField', $this->collection(['caseField' => '']));
		$this->assertArrayNotHasKey('caseField', $this->collection(['caseField' => ['zaak']]));
	}//end testACaseFieldNamingNoProjectedFieldIsDropped()
}//end class
