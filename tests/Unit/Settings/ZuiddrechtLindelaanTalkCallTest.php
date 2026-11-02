<?php

/**
 * Example data for DqZaakContact Live gesprek on the Lindelaan Woo case: the
 * Talk conversation with Sanne about the documents, held on the day after
 * receipt for twelve minutes, and the case's record of it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/example-set-sanne-cases/spec.md#requirement-sannes-lindelaan-case-is-the-case-the-boards-draw-and-her-cases-name-her
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Sanne's held call on the Lindelaan case.
 */
class ZuiddrechtLindelaanTalkCallTest extends TestCase {

	/**
	 * The Talk conversation is on the case with Pieter and Sanne, and the case records the same call.
	 *
	 * @return void
	 */
	public function testSannesCallIsHeldAndRecordedOnTheCase(): void {
		$set   = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$talks = array_column($set['talkConversations'] ?? [], 'data', 'key');
		$call  = $talks['woo-fietspad-gesprek-sanne'] ?? null;

		$this->assertNotNull($call);
		$this->assertSame('{{object:woo-fietspad.id}}', $call['object']);
		$this->assertSame('Gesprek met Sanne de Vries over de stukken', $call['name']);
		$this->assertSame('{{cast:pieter.uid}}', $call['owner']);
		$this->assertSame(['{{cast:sanne.uid}}'], $call['participants']);
		$this->assertSame(['start' => '{{datetime:-13 10:30}}', 'end' => '{{datetime:-13 10:42}}'], $call['call']);

		$objects  = array_column($set['objects'], 'data', 'key');
		$recorded = $objects['woo-fietspad']['conversations'] ?? [];
		$this->assertCount(1, $recorded);
		$this->assertSame($call['name'], $recorded[0]['subject']);
		$this->assertSame($call['call']['start'], $recorded[0]['startedAt']);
		$this->assertSame($call['call']['end'], $recorded[0]['endedAt']);
		$this->assertSame(720, $recorded[0]['durationSeconds']);
	}//end testSannesCallIsHeldAndRecordedOnTheCase()
}//end class
