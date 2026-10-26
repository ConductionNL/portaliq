<?php

/**
 * Example data for DqZaakContact "Gesprek met de inwoner" on the Lindelaan Woo
 * case: the five messages, oldest first, with the board's texts and times.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/example-set-portaal/specs/example-set-portaal/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The conversation with Sanne on the Lindelaan case.
 */
class ZuiddrechtLindelaanConversationTest extends TestCase {

	/**
	 * Each message's moment and the text the thread shows (its summary).
	 *
	 * @return void
	 */
	public function testTheConversationReadsAsTheBoard(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$moments = [];
		foreach ($set['objects'] as $object) {
			if ($object['schema'] === 'contactmoment' && ($object['data']['case'] ?? '') === '{{object:woo-fietspad.id}}') {
				$moments[] = [$object['data']['startTime'], $object['data']['summary']];
			}
		}

		$this->assertSame(
			[
				['{{datetime:-14 14:10}}', 'Hoe vraag ik de stukken over de verlichting van het fietspad op?'],
				['{{datetime:-14 14:32}}', 'Uw Woo-verzoek is ontvangen onder nummer {{object:woo-fietspad.identifier}}. U hoort binnen vier weken van ons.'],
				['{{datetime:-13 10:24}}', 'De aanleg begint in maart. Er komt verlichting langs het hele fietspad.'],
				['{{datetime:-13 10:52}}', 'Dank u wel. Ik wil graag alle stukken over de verlichting zien.'],
				['{{datetime:-12 09:15}}', 'Goedemorgen, ik wil mijn verzoek aanvullen. Kunt u ook de offertes voor de lantaarnpalen meenemen? Met vriendelijke groet, Sanne de Vries'],
			],
			$moments
		);
	}//end testTheConversationReadsAsTheBoard()
}//end class
