<?php

/**
 * Example data for DqZaak and DqZaakTaken on the Lindelaan Woo case: the Deck
 * card on the case, the appointment at the desk and the case plan's four
 * items.
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
 * The Lindelaan case's card, appointment and plan.
 */
class ZuiddrechtLindelaanCardsAppointmentsPlanTest extends TestCase {

	/**
	 * The set, decoded.
	 *
	 * @return array<string, mixed>
	 */
	private function set(): array {
		return json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
	}//end set()

	/**
	 * One Deck card on the case, on the Woo team's board and shared with that team, assigned to Lars.
	 *
	 * @return void
	 */
	public function testTheCaseHasItsDeckCard(): void {
		$cards = array_column($this->set()['deckCards'] ?? [], 'data', 'key');
		$card  = $cards['woo-fietspad-kaart-lakken'] ?? null;

		$this->assertNotNull($card);
		$this->assertSame('{{object:woo-fietspad.id}}', $card['object']);
		$this->assertSame(['Woo en informatie', 'Deze week', 'Lakken offerte lantaarnpalen'], [$card['board'], $card['list'], $card['title']]);
		$this->assertSame('{{cast:pieter.uid}}', $card['owner']);
		$this->assertSame(['{{cast:lars.uid}}'], $card['assignees']);
		$this->assertSame('Bezig', $card['labels'][0]['title']);
		$this->assertSame(['{{cast:woo-en-informatie.gid}}'], $card['shareWith']);
	}//end testTheCaseHasItsDeckCard()

	/**
	 * The desk appointment with the requester: half an hour, Sanne invited.
	 *
	 * @return void
	 */
	public function testTheCaseHasItsAppointment(): void {
		$rows        = array_column($this->set()['appointments'] ?? [], 'data', 'key');
		$appointment = $rows['woo-fietspad-afspraak-toelichting'] ?? null;

		$this->assertNotNull($appointment);
		$this->assertSame('{{object:woo-fietspad.id}}', $appointment['object']);
		$this->assertSame('{{cast:pieter.uid}}', $appointment['owner']);
		$this->assertSame('Toelichting stukken aan de verzoeker', $appointment['summary']);
		$this->assertSame('{{datetime:3 10:00}}', $appointment['start'], 'an upcoming appointment, as the board draws it');
		$this->assertSame('{{datetime:3 10:30}}', $appointment['end']);
		$this->assertSame('Stadskantoor, balie 3', $appointment['location']);
		$this->assertSame([['name' => '{{cast:sanne.displayName}}', 'email' => '{{cast:sanne.email}}', 'status' => 'invited']], $appointment['attendees']);
	}//end testTheCaseHasItsAppointment()

	/**
	 * The plan's four items in the board's states, the milestone reached the day after receipt.
	 *
	 * @return void
	 */
	public function testTheCasePlanHasItsFourItems(): void {
		$plans = array_column($this->set()['casePlans'] ?? [], 'data', 'key');
		$plan  = $plans['woo-fietspad-zaakplan'] ?? null;

		$this->assertNotNull($plan);
		$this->assertSame('{{object:woo-fietspad.id}}', $plan['object']);
		$items = array_map(
			static fn (array $item): array => [$item['name'], $item['type'], $item['state'], (bool) ($item['discretionary'] ?? false), $item['description'] ?? null],
			$plan['definition']['items']
		);
		$this->assertSame(
			[
				['Mijlpaal: verzoek compleet', 'milestone', 'completed', false, 'bereikt op 4 okt'],
				['Documenten beoordelen', 'humanTask', 'active', false, '3 van 7 nog open'],
				['Zienswijze derde vragen', 'humanTask', 'available', true, 'alleen als een derde belang heeft'],
				['Besluit opstellen', 'humanTask', 'available', false, 'start als alle documenten beoordeeld zijn'],
			],
			$items
		);
		$this->assertSame([['item' => 'verzoek-compleet', 'to' => 'completed', 'at' => '{{datetime:-13 10:00}}']], $plan['history']);
		$besluit = array_column($plan['definition']['items'], null, 'key')['besluit-opstellen'];
		$this->assertSame(
			[['id' => 'documenten-beoordeeld', 'on' => ['event' => 'case.item.completed', 'item' => 'documenten-beoordelen']]],
			$besluit['entryCriteria'] ?? null,
			'the decision waits until the documents are assessed'
		);
	}//end testTheCasePlanHasItsFourItems()

	/**
	 * The case location's coordinates carry no more decimals than OpenRegister keeps for a number (NUMERIC(10,2)),
	 * or a refresh reports them as written but not kept.
	 *
	 * @return void
	 */
	public function testTheLocationHoldsWhatTheStoreKeeps(): void {
		$objects  = array_column($this->set()['objects'], 'data', 'key');
		$location = $objects['woo-fietspad-locatie'];

		foreach (['latitude', 'longitude'] as $field) {
			$this->assertSame(round($location[$field], 2), $location[$field], $field);
		}
	}//end testTheLocationHoldsWhatTheStoreKeeps()
}//end class
