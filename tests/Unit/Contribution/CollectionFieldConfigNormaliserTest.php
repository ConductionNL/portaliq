<?php

/**
 * CollectionFieldConfigNormaliser: a collection's per-field labels and value
 * labels, kept only for fields the collection projects.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Contribution
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\CollectionFieldConfigNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * The collection field configs.
 */
class CollectionFieldConfigNormaliserTest extends TestCase {
	/**
	 * A collection without field configs is left as it was.
	 *
	 * @return void
	 */
	public function testACollectionWithoutConfigsIsUntouched(): void {
		$collection = ['id' => 'cases', 'fields' => ['status']];
		$this->assertSame(expected: $collection, actual: (new CollectionFieldConfigNormaliser())->normalise(collection: $collection));
	}//end testACollectionWithoutConfigsIsUntouched()

	/**
	 * Configs for projected fields keep their label and value labels.
	 *
	 * @return void
	 */
	public function testProjectedFieldsKeepTheirConfig(): void {
		$normalised = (new CollectionFieldConfigNormaliser())->normalise(
			collection: [
				'id'           => 'cases',
				'fields'       => ['status', 'type'],
				'fieldConfigs' => [
					'status' => ['label' => 'Status', 'valueLabels' => ['open' => 'Open', 'closed' => 'Afgerond'], 'widget' => 'count'],
					'type'   => ['label' => 'Soort'],
					'bsn'    => ['label' => 'BSN'],
				],
			]
		);
		$this->assertSame(
			expected: [
				'status' => ['label' => 'Status', 'valueLabels' => ['open' => 'Open', 'closed' => 'Afgerond']],
				'type'   => ['label' => 'Soort'],
			],
			actual: $normalised['fieldConfigs']
		);
	}//end testProjectedFieldsKeepTheirConfig()

	/**
	 * Without a field list every named field may carry a config.
	 *
	 * @return void
	 */
	public function testWithoutAFieldListEveryFieldMayHaveAConfig(): void {
		$normalised = (new CollectionFieldConfigNormaliser())->normalise(collection: ['fieldConfigs' => ['anything' => ['label' => 'Iets']]]);
		$this->assertSame(expected: ['fieldConfigs' => ['anything' => ['label' => 'Iets']]], actual: $normalised);
	}//end testWithoutAFieldListEveryFieldMayHaveAConfig()

	/**
	 * Malformed configs are dropped, and the key goes when none survive.
	 *
	 * @return void
	 */
	public function testMalformedConfigsAreDropped(): void {
		$normaliser = new CollectionFieldConfigNormaliser();
		$this->assertSame(
			expected: ['id' => 'cases'],
			actual: $normaliser->normalise(
				collection: [
					'id'           => 'cases',
					'fieldConfigs' => [
						''       => ['label' => 'Empty name'],
						0        => ['label' => 'Numeric name'],
						'status' => 'Status',
						'type'   => ['label' => '   '],
						'note'   => ['label' => str_repeat('x', 201)],
						'other'  => ['label' => ['Other']],
					],
				]
			)
		);
		$this->assertSame(expected: ['id' => 'cases'], actual: $normaliser->normalise(collection: ['id' => 'cases', 'fieldConfigs' => 'status']));
	}//end testMalformedConfigsAreDropped()

	/**
	 * A label of exactly 200 characters is kept as declared, untrimmed.
	 *
	 * @return void
	 */
	public function testALabelAtTheLimitIsKeptAsDeclared(): void {
		$label      = ' '.str_repeat('y', 199);
		$normalised = (new CollectionFieldConfigNormaliser())->normalise(collection: ['fieldConfigs' => ['status' => ['label' => $label]]]);
		$this->assertSame(expected: $label, actual: $normalised['fieldConfigs']['status']['label']);
	}//end testALabelAtTheLimitIsKeptAsDeclared()
}//end class
