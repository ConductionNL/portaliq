<?php

/**
 * CollectionConfigNormaliser::normaliseCollections(): a mixed collection list
 * through the whole chain, as recorded.
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

use OCA\Portaliq\Contribution\CollectionConfigNormaliser;
use OCA\Portaliq\Contribution\ManifestValueNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * The collection chain as a whole.
 */
class CollectionConfigNormaliserGoldenTest extends TestCase {
	/**
	 * Junk is dropped and every key goes through its normaliser, in order.
	 *
	 * @return void
	 */
	public function testAMixedCollectionListIsNormalisedAsRecorded(): void {
		$in = [
			'junk',
			3,
			[
				'id' => 'cases', 'kind' => 'cases', 'columns' => [['field' => 'title', 'label' => 'Titel'], 'bad'], 'detail' => ['fields' => ['title', 4]],
				'defaults' => ['status' => 'open', 'x' => ['y']], 'download' => 'yes', 'upload' => true, 'closedField' => 'closed', 'groupBy' => 'status',
				'caseField' => 'case', 'intro' => 'Hallo', 'anonymous' => 1, 'steps' => ['provider' => 'stepsOf'],
				'timeline' => ['provider' => 'historyOf', 'label' => 'Tijdlijn'], 'documents' => ['provider' => 'docsOf', 'label' => 'Stukken'],
			],
			['id' => 'inbox', 'kind' => 7, 'messageFields' => ['subject' => 'title'], 'noticeField' => 'n'],
		];

		$expected = <<<'JSON'
[{"id":"cases","kind":"cases","columns":[{"field":"title","label":"Titel","render":"text"}],"detail":{"layout":"card","fields":["title"]},"defaults":{"status":"open","x":["y"]},"download":"yes","upload":true,"closedField":"closed","groupBy":"status","caseField":"case","intro":"Hallo","anonymous":false,"timeline":{"label":"Tijdlijn","provider":"historyOf"},"documents":{"label":"Stukken","provider":"docsOf"},"steps":{"label":"","provider":"stepsOf"}},{"id":"inbox","noticeField":"n"}]
JSON;

		$this->assertSame(
			json_decode($expected, true),
			(new CollectionConfigNormaliser(new ManifestValueNormaliser()))->normaliseCollections($in)
		);
	}//end testAMixedCollectionListIsNormalisedAsRecorded()
}//end class
