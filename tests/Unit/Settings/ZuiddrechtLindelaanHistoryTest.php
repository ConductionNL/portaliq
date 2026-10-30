<?php

/**
 * Example data for DqZaakHistorie on the Lindelaan Woo case: the ten entries
 * the board draws, under the chips Status 2, Documenten 3, Berichten 3,
 * Taken 1 and Toewijzing 1, by the people who did them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/example-resident/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The Lindelaan case's history.
 */
class ZuiddrechtLindelaanHistoryTest extends TestCase {

	/**
	 * Ten entries on the case, counted per kind as the chips read, each with the fields its kind requires.
	 *
	 * @return void
	 */
	public function testTheCaseHistoryHasTheBoardsTenEntries(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$entries = array_column($set['timelineEntries'] ?? [], 'data');

		$this->assertCount(10, $entries);
		$kinds = array_count_values(array_column($entries, 'kind'));
		ksort($kinds);
		$this->assertSame(['bericht' => 3, 'document' => 3, 'statuswijziging' => 2, 'taak' => 1, 'toewijzing' => 1], $kinds);

		$required = ['statuswijziging' => 'to', 'document' => 'act', 'bericht' => 'direction', 'taak' => 'act', 'toewijzing' => 'act'];
		foreach ($entries as $entry) {
			$this->assertSame('{{object:woo-fietspad.id}}', $entry['object']);
			$this->assertArrayHasKey($required[$entry['kind']], $entry['fields'], $entry['message']);
		}

		$created = $entries[0];
		$this->assertSame(['Zaak aangemaakt', '{{datetime:-14 14:10}}'], [$created['message'], $created['at']]);
		$this->assertArrayNotHasKey('author', $created, 'the system created the case');
	}//end testTheCaseHistoryHasTheBoardsTenEntries()
}//end class
