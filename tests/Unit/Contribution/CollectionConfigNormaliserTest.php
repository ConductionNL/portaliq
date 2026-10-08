<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * A cases collection may say where a case stands: `steps`, `dueField` and
 * `turnField` (site-mijn-omgeving-components REQ-SMO-022). Driven through
 * PortalManifestNormaliser, the one entry point the registry uses.
 *
 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-cases-collection-may-supply-steps-an-answer-date-and-whose-turn-it-is-req-smo-022
 */
class CollectionConfigNormaliserTest extends TestCase {
	/**
	 * Normalise one collection.
	 *
	 * @param array<string, mixed> $collection The declared collection.
	 *
	 * @return array<string, mixed> The normalised collection.
	 */
	private function collection(array $collection): array {
		$out = (new PortalManifestNormaliser())->normalise(
			['collections' => [$collection + ['id' => 'mijnZaken', 'schema' => 'case']], 'actions' => []]
		);

		return $out['collections'][0];
	}//end collection()

	/**
	 * Steps need a provider portaliq may call; a label that is not text
	 * becomes empty.
	 *
	 * @return void
	 */
	public function testStepsNeedAProviderMethod(): void {
		$kept = $this->collection(['kind' => 'cases', 'steps' => ['label' => 'Waar staat uw aanvraag?', 'provider' => 'caseSteps']]);
		$this->assertSame(['label' => 'Waar staat uw aanvraag?', 'provider' => 'caseSteps'], $kept['steps']);

		$this->assertSame(
			['label' => '', 'provider' => 'caseSteps'],
			$this->collection(['kind' => 'cases', 'steps' => ['label' => 7, 'provider' => 'caseSteps']])['steps']
		);

		foreach ([['label' => 'x'], ['provider' => 'getContribution'], ['provider' => 'case-steps'], 'caseSteps', null] as $steps) {
			$this->assertArrayNotHasKey('steps', $this->collection(['kind' => 'cases', 'steps' => $steps]));
		}
	}//end testStepsNeedAProviderMethod()

	/**
	 * A turn or due field the collection does not project is dropped; a
	 * collection that projects nothing accepts any field.
	 *
	 * @return void
	 */
	public function testATurnFieldMustBeProjected(): void {
		$projected = $this->collection(
			[
				'kind'      => 'cases',
				'fields'    => ['title', 'status', 'answerBy'],
				'turnField' => 'waitingOn',
				'dueField'  => 'answerBy',
			]
		);
		$this->assertArrayNotHasKey('turnField', $projected);
		$this->assertSame('answerBy', $projected['dueField']);

		$open = $this->collection(['kind' => 'cases', 'turnField' => 'portalTurn', 'dueField' => '']);
		$this->assertSame('portalTurn', $open['turnField']);
		$this->assertArrayNotHasKey('dueField', $open);
	}//end testATurnFieldMustBeProjected()

	/**
	 * The progress keys belong to cases: any other kind loses all three.
	 *
	 * @return void
	 */
	public function testOnlyACasesCollectionKeepsItsProgressKeys(): void {
		$other = $this->collection(
			['kind' => 'inbox', 'steps' => ['provider' => 'caseSteps'], 'dueField' => 'x', 'turnField' => 'y']
		);
		foreach (['steps', 'dueField', 'turnField'] as $key) {
			$this->assertArrayNotHasKey($key, $other);
		}
	}//end testOnlyACasesCollectionKeepsItsProgressKeys()

	/**
	 * cases-export-own-data-pdf REQ-OPX-001: only an explicit true opts a
	 * collection into the PDF export; anything else is false.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cases-export-own-data-pdf/tasks.md#t01
	 */
	public function testExportPdfIsBoolean(): void {
		$this->assertTrue($this->collection(['exportPdf' => true])['exportPdf']);
		$this->assertTrue($this->collection(['exportPdf' => 'true'])['exportPdf']);
		foreach ([false, 'yes', 1, 'false', null, []] as $declared) {
			$this->assertFalse($this->collection(['exportPdf' => $declared])['exportPdf'], json_encode($declared));
		}

		$this->assertArrayNotHasKey('exportPdf', $this->collection([]), 'a collection that says nothing offers nothing');
	}//end testExportPdfIsBoolean()
}//end class
