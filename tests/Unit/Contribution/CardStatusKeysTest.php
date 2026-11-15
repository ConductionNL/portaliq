<?php

/**
 * A cards block keeps its `status` lookup whole through the real manifest
 * normaliser, or drops it (card-status-today).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/card-status-today/specs/portal-contribution-contract/spec.md#requirement-a-card-may-say-where-its-record-stands-today
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/card-status-today/specs/portal-contribution-contract/spec.md#requirement-a-card-may-say-where-its-record-stands-today
 */
class CardStatusKeysTest extends TestCase {
	/**
	 * The declaration learniq sends for a guardian's child cards.
	 *
	 * @var array<string, mixed>
	 */
	private const STATUS = [
		'collection'     => 'parentExcuseRequests',
		'matchField'     => 'learnerRef',
		'fromField'      => 'dateFrom',
		'toField'        => 'dateTo',
		'only'           => ['field' => 'lifecycle', 'in' => ['submitted', 'approved']],
		'label'          => 'Reported sick',
		'tone'           => 'warning',
		'otherLabel'     => 'At school',
		'otherTone'      => 'positive',
		'schoolDaysOnly' => true,
	];

	/**
	 * The cards block after the real normaliser.
	 *
	 * @param array<string, mixed> $status The declared status.
	 * @param string               $display The block's display.
	 *
	 * @return array<string, mixed>
	 */
	private function cards(array $status, string $display='cards'): array {
		$out = (new PortalManifestNormaliser())->normalise(
			[
				'collections' => [
					['id' => 'parentChildren', 'schema' => 'learner', 'fields' => ['givenName', 'groupLabel']],
					['id' => 'parentExcuseRequests', 'schema' => 'excuse-request', 'fields' => ['learnerRef', 'dateFrom', 'dateTo', 'lifecycle']],
				],
				'actions'     => [],
				'pages'       => [
					[
						'id'     => 'overzicht',
						'label'  => 'Overzicht',
						'blocks' => [
							['type' => 'collection', 'collection' => 'parentChildren', 'display' => $display, 'titleFields' => ['givenName'], 'status' => $status],
							['type' => 'richText', 'markdown' => 'Welkom'],
						],
					],
				],
			]
		);

		return $out['pages'][0]['blocks'][0];
	}//end cards()

	/**
	 * The whole declaration survives; an app's `positive` reads as the chip's `success`.
	 *
	 * @return void
	 */
	public function testTheDeclarationSurvivesWhole(): void {
		$this->assertSame(
			[
				'collection'     => 'parentExcuseRequests',
				'matchField'     => 'learnerRef',
				'fromField'      => 'dateFrom',
				'toField'        => 'dateTo',
				'label'          => 'Reported sick',
				'tone'           => 'warning',
				'otherLabel'     => 'At school',
				'otherTone'      => 'success',
				'only'           => ['field' => 'lifecycle', 'in' => ['submitted', 'approved']],
				'schoolDaysOnly' => true,
			],
			$this->cards(status: self::STATUS)['status']
		);
	}//end testTheDeclarationSurvivesWhole()

	/**
	 * A collection of another contribution, a field the collection does not
	 * project, a missing label or a display other than cards drops it.
	 *
	 * @return void
	 */
	public function testAHalfDeclarationIsDropped(): void {
		$this->assertArrayNotHasKey('status', $this->cards(status: ['collection' => 'elsewhere'] + self::STATUS));
		$this->assertArrayNotHasKey('status', $this->cards(status: ['fromField' => 'bsn'] + self::STATUS));
		$this->assertArrayNotHasKey('status', $this->cards(status: ['label' => '  '] + self::STATUS));
		$this->assertArrayNotHasKey('status', $this->cards(status: self::STATUS, display: 'rows'));
	}//end testAHalfDeclarationIsDropped()

	/**
	 * An `only` on a field that is not projected is left out, the rest stays;
	 * an unknown tone reads as neutral.
	 *
	 * @return void
	 */
	public function testTheOptionalPartsDegrade(): void {
		$status = $this->cards(status: ['only' => ['field' => 'secret', 'in' => ['x']], 'tone' => 'purple'] + self::STATUS)['status'];
		$this->assertArrayNotHasKey('only', $status);
		$this->assertSame('neutral', $status['tone']);
	}//end testTheOptionalPartsDegrade()
}//end class
