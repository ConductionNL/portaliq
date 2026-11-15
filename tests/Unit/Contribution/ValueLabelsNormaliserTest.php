<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use OCA\Portaliq\Contribution\ValueLabelsNormaliser;
use OCA\Portaliq\Service\PortalSchemaReader;
use PHPUnit\Framework\TestCase;

/**
 * An app declares how its stored values read, once per column or field
 * (contribution-value-labels). Driven through PortalManifestNormaliser, the
 * one entry point the registry uses, plus the map rules on their own.
 *
 * @spec openspec/changes/contribution-value-labels/specs/portal-contribution-contract/spec.md#requirement-a-column-and-a-form-field-may-declare-how-their-values-read
 */
class ValueLabelsNormaliserTest extends TestCase {
	/**
	 * A column keeps its well-formed `valueLabels`; a malformed map is
	 * dropped and the rest of the column stays.
	 *
	 * @return void
	 */
	public function testAColumnKeepsItsValueLabels(): void {
		$out = (new PortalManifestNormaliser())->normalise(
			[
				'collections' => [
					[
						'id'      => 'c1',
						'schema'  => 'excuse-request',
						'columns' => [
							['field' => 'lifecycle', 'label' => 'Status', 'valueLabels' => ['approved' => 'Goedgekeurd', 'submitted' => 'Ingediend']],
							['field' => 'reason', 'valueLabels' => 'not-a-map'],
							['field' => 'other', 'valueLabels' => ['x' => ['nested']]],
						],
					],
				],
				'actions'     => [],
			]
		);

		$columns = $out['collections'][0]['columns'];
		$this->assertSame(
			['field' => 'lifecycle', 'label' => 'Status', 'render' => 'text', 'valueLabels' => ['approved' => 'Goedgekeurd', 'submitted' => 'Ingediend']],
			$columns[0]
		);
		$this->assertSame(['field' => 'reason', 'render' => 'text'], $columns[1]);
		$this->assertSame(['field' => 'other', 'render' => 'text'], $columns[2]);
	}//end testAColumnKeepsItsValueLabels()

	/**
	 * The map keeps string labels on string or integer keys, within their
	 * caps, and at most a hundred of them.
	 *
	 * @return void
	 */
	public function testTheMapIsFailClosed(): void {
		$labels = (new ValueLabelsNormaliser())->normalise(
			[
				'approved'            => 'Goedgekeurd',
				3                     => 'Drie',
				''                    => 'Leeg',
				'blank'               => '   ',
				'number'              => 7,
				'long'                => str_repeat('a', 201),
				str_repeat('k', 101)  => 'Te lange waarde',
			]
		);

		$this->assertSame(['approved' => 'Goedgekeurd', '3' => 'Drie'], $labels);
		$this->assertSame([], (new ValueLabelsNormaliser())->normalise('approved'));

		$many = [];
		for ($i = 0; $i < 150; $i++) {
			$many['v'.$i] = 'Label '.$i;
		}

		$this->assertCount(100, (new ValueLabelsNormaliser())->normalise($many));
	}//end testTheMapIsFailClosed()

	/**
	 * The field's `valueLabels` label the enum select; the option value stays
	 * the raw enum value and an unlabelled value falls back to its words.
	 *
	 * @return void
	 */
	public function testAFieldsValueLabelsLabelItsEnumOptions(): void {
		$schemaReader = $this->createMock(PortalSchemaReader::class);
		$schemaReader->method('readSchema')->willReturn(
			['properties' => ['reasonKind' => ['type' => 'string', 'enum' => ['illness', 'medical-appointment', 'bereavement']]]]
		);

		$out = (new PortalManifestNormaliser($schemaReader))->normalise(
			[
				'collections' => [],
				'actions'     => [
					[
						'id'           => 'createExcuseRequest',
						'type'         => 'create',
						'schema'       => 'excuse-request',
						'fields'       => ['reasonKind'],
						'fieldConfigs' => [
							'reasonKind' => [
								'label'       => 'Soort afwezigheid',
								'valueLabels' => ['illness' => 'Ziekte', 'bereavement' => 'Overlijden', 'unknown' => 'Onbekend'],
							],
						],
					],
				],
			]
		);

		$action = $out['actions'][0];
		$this->assertSame(
			[
				['value' => 'illness', 'label' => 'Ziekte'],
				['value' => 'medical-appointment', 'label' => 'Medical appointment'],
				['value' => 'bereavement', 'label' => 'Overlijden'],
			],
			$action['optionsProviders']['reasonKind']['options']
		);
		$this->assertSame(
			['illness' => 'Ziekte', 'bereavement' => 'Overlijden', 'unknown' => 'Onbekend'],
			$action['fieldConfigs']['reasonKind']['valueLabels']
		);
	}//end testAFieldsValueLabelsLabelItsEnumOptions()

	/**
	 * A declared label wins over a schema `oneOf` title.
	 *
	 * @return void
	 */
	public function testAValueLabelWinsOverAOneOfTitle(): void {
		$schemaReader = $this->createMock(PortalSchemaReader::class);
		$schemaReader->method('readSchema')->willReturn(
			['properties' => ['kind' => ['type' => 'string', 'oneOf' => [['const' => 'ill', 'title' => 'Ill'], ['const' => 'other', 'title' => 'Other']]]]]
		);

		$out = (new PortalManifestNormaliser($schemaReader))->normalise(
			[
				'collections' => [],
				'actions'     => [
					[
						'id'           => 'a',
						'type'         => 'create',
						'schema'       => 's',
						'fields'       => ['kind'],
						'fieldConfigs' => ['kind' => ['valueLabels' => ['ill' => 'Ziek']]],
					],
				],
			]
		);

		$this->assertSame(
			[['value' => 'ill', 'label' => 'Ziek'], ['value' => 'other', 'label' => 'Other']],
			$out['actions'][0]['optionsProviders']['kind']['options']
		);
	}//end testAValueLabelWinsOverAOneOfTitle()
}//end class
