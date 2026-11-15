<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use OCA\Portaliq\Service\PortalSchemaReader;
use PHPUnit\Framework\TestCase;

/**
 * A collection field the app gave no label reads under its schema
 * property's title, never under its key (collection-column-labels).
 *
 * Runs through the real PortalManifestNormaliser; only the register lookup
 * (PortalSchemaReader) is a double.
 *
 * @spec openspec/changes/collection-column-labels/specs/portal-contribution-contract/spec.md#requirement-a-collection-field-must-read-under-its-schema-title-when-the-app-gave-no-label
 */
class CollectionSchemaLabelsTest extends TestCase {
	/**
	 * The schema of a grade, as learniq's register declares it (trimmed).
	 *
	 * @var array<string, mixed>
	 */
	private const GRADE_SCHEMA = [
		'slug'       => 'grade-entry',
		'properties' => [
			'courseName' => ['type' => 'string', 'title' => 'Vak'],
			'value'      => ['type' => 'string', 'title' => 'Cijfer'],
			'weight'     => ['type' => 'number', 'title' => '  '],
			'gradedAt'   => ['type' => 'string', 'format' => 'date-time', 'title' => 'Gegeven op'],
			'secret'     => ['type' => 'string', 'title' => 'Niet geprojecteerd'],
		],
	];

	/**
	 * Normalise one collection with a reader that knows the grade schema.
	 *
	 * @param array<string, mixed> $collection The declared collection.
	 * @param int                  $reads      How often the reader may be asked.
	 *
	 * @return array<int, array<string, mixed>> The normalised collections.
	 */
	private function normalised(array $collection, int $reads = 1): array {
		$reader = $this->createMock(PortalSchemaReader::class);
		$reader->expects($this->exactly($reads))
			->method('readSchema')
			->with('grade-entry')
			->willReturn(self::GRADE_SCHEMA);

		return (new PortalManifestNormaliser($reader))->normalise(['collections' => [$collection, $collection], 'actions' => []])['collections'];
	}//end normalised()

	/**
	 * Every projected field without a label gets its title; a blank title and
	 * a field the collection does not project get nothing.
	 *
	 * @return void
	 */
	public function testAProjectedFieldReadsUnderItsTitle(): void {
		$out = $this->normalised(
			collection: [
				'id'     => 'studentGrades',
				'schema' => 'grade-entry',
				'fields' => ['courseName', 'value', 'weight', 'gradedAt', 'learnerRef'],
			]
		);

		$this->assertSame(
			[
				'courseName' => ['label' => 'Vak'],
				'value'      => ['label' => 'Cijfer'],
				'gradedAt'   => ['label' => 'Gegeven op'],
			],
			$out[0]['fieldConfigs']
		);
	}//end testAProjectedFieldReadsUnderItsTitle()

	/**
	 * The app's own label wins, and its value labels stay.
	 *
	 * @return void
	 */
	public function testTheAppsLabelWins(): void {
		$out = $this->normalised(
			collection: [
				'id'           => 'studentGrades',
				'schema'       => 'grade-entry',
				'fields'       => ['courseName', 'value'],
				'fieldConfigs' => [
					'courseName' => ['label' => 'Onderwerp'],
					'value'      => ['valueLabels' => ['V' => 'Voldoende']],
				],
			]
		);

		$this->assertSame(
			[
				'courseName' => ['label' => 'Onderwerp'],
				'value'      => ['valueLabels' => ['V' => 'Voldoende'], 'label' => 'Cijfer'],
			],
			$out[0]['fieldConfigs']
		);
	}//end testTheAppsLabelWins()

	/**
	 * Without a reader, or for a schema that cannot be read, nothing changes.
	 *
	 * @return void
	 */
	public function testNoReaderChangesNothing(): void {
		$collection = ['id' => 'studentGrades', 'schema' => 'grade-entry', 'fields' => ['courseName']];
		$out = (new PortalManifestNormaliser())->normalise(['collections' => [$collection], 'actions' => []]);
		$this->assertArrayNotHasKey('fieldConfigs', $out['collections'][0]);

		$reader = $this->createMock(PortalSchemaReader::class);
		$reader->method('readSchema')->willReturn(null);
		$out = (new PortalManifestNormaliser($reader))->normalise(['collections' => [$collection], 'actions' => []]);
		$this->assertArrayNotHasKey('fieldConfigs', $out['collections'][0]);
	}//end testNoReaderChangesNothing()
}//end class
