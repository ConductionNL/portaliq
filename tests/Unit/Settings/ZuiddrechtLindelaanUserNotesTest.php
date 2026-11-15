<?php

/**
 * Example data for DqZaakEmail Notities on the Lindelaan Woo case: two notes
 * by colleagues, Pieter asking Anouk to look along and Lars pointing at the
 * 2025 documents, on the case's third working day.
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
 * The user notes on the Lindelaan case.
 */
class ZuiddrechtLindelaanUserNotesTest extends TestCase {

	/**
	 * Pieter's note mentions Anouk; Lars's note is earlier the same day.
	 *
	 * @return void
	 */
	public function testTwoColleaguesNoteOnTheCase(): void {
		$set   = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$notes = array_column($set['notes'] ?? [], 'data', 'key');

		$pieter = $notes['woo-fietspad-notitie-pieter'] ?? null;
		$lars   = $notes['woo-fietspad-notitie-lars'] ?? null;
		$this->assertNotNull($pieter);
		$this->assertNotNull($lars);
		foreach ([$pieter, $lars] as $note) {
			$this->assertSame('{{object:woo-fietspad.id}}', $note['objectUuid']);
		}

		$this->assertSame('{{cast:pieter.uid}}', $pieter['user']);
		$this->assertStringStartsWith('@"{{cast:anouk.uid}}" ', $pieter['message']);
		$this->assertSame('{{datetime:-11 15:52}}', $pieter['createdAt']);
		$this->assertSame('{{cast:lars.uid}}', $lars['user']);
		$this->assertSame('Stukken uit 2025 staan in het dossier Fietspad Lindelaan', $lars['message']);
		$this->assertSame('{{datetime:-11 09:31}}', $lars['createdAt']);
	}//end testTwoColleaguesNoteOnTheCase()
}//end class
