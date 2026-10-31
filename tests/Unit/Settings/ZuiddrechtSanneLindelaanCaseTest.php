<?php

/**
 * The Zuiddrecht example set's Woo case "Verlichting fietspad Lindelaan", Sanne de
 * Vries's request through Mijn Zuiddrecht, is the case the DqZaak boards draw: in
 * behandeling, her contact record as requester, with its contact moments,
 * documents, tasks and advice.
 *
 * The boards measure every case-page screen against one in-progress case, and
 * DqZaakContact draws four contact moments on it (telephone, Mijn Zuiddrecht
 * twice, e-mail). The set seeds that case, so the screens have a reproducible
 * case to measure against.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/example-set-sanne-cases/spec.md#requirement-sannes-lindelaan-case-is-the-case-the-boards-draw-and-her-cases-name-her
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The seeded Lindelaan case: in-progress status, contact moments.
 *
 * @spec openspec/specs/example-set-sanne-cases/spec.md#requirement-sannes-lindelaan-case-is-the-case-the-boards-draw-and-her-cases-name-her
 */
class ZuiddrechtSanneLindelaanCaseTest extends TestCase {

	/**
	 * The set's objects.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 */
	private function objects(): array {
		$set = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$this->assertIsArray(actual: $set);

		return (array)$set['objects'];
	}//end objects()

	/**
	 * The object with this key.
	 *
	 * @param string $key The object key.
	 *
	 * @return array<string, mixed> The object.
	 */
	private function object(string $key): array {
		foreach ($this->objects() as $object) {
			if (($object['key'] ?? '') === $key) {
				return $object;
			}
		}

		$this->fail(message: "No object $key in the set");
	}//end object()

	/**
	 * The case sits in a Woo status whose role is in-progress, and carries
	 * no publication state of a later stage.
	 *
	 * @return void
	 */
	public function testTheCaseIsInProgress(): void {
		$case   = $this->object(key: 'woo-fietspad');
		$status = (string)$case['lookups']['status']['where']['name'];

		// Version 2 of dossiq's Woo case type gives this status, step 2, the role in-progress (register.d/81-woo-verzoek-version-2.json).
		$this->assertSame(expected: 'In behandeling', actual: $status);
		$this->assertArrayNotHasKey(key: 'wooPublicationStatus', array: $case['data']);
		$this->assertSame(expected: '{{lookup:status}}', actual: $case['data']['statusHistory'][1]['status']);
		$this->assertSame(expected: '{{lookup:sanne}}', actual: $case['data']['requester']);
		$this->assertSame(expected: ['citizenServiceNumber' => '{{cast:sanne.bsn}}'], actual: $case['lookups']['sanne']['where']);
		$this->assertSame(expected: 'MZ-ZDR-2026-0412', actual: $case['data']['externalReference']);
	}//end testTheCaseIsInProgress()

	/**
	 * Sanne logged in with DigiD on Mijn Zuiddrecht, so the requester card reads "Ingelogd met DigiD" and the header "via Mijn Zuiddrecht".
	 *
	 * @return void
	 */
	public function testTheCaseCameInThroughThePortal(): void {
		$case = $this->object(key: 'woo-fietspad');

		$this->assertSame(expected: 'digid', actual: $case['data']['intakeIdentification']);
		$this->assertSame(expected: 'Mijn Zuiddrecht', actual: $case['data']['intakeSourceName']);
	}//end testTheCaseCameInThroughThePortal()

	/**
	 * Every case of Sanne's names her contact record as its requester, so her contact page lists them.
	 *
	 * @return void
	 */
	public function testSannesCasesNameHerAsRequester(): void {
		$cases = array_filter(
			$this->objects(),
			static fn (array $object): bool => ($object['schema'] ?? '') === 'case' && ($object['data']['portalSubject'] ?? '') === '{{cast:sanne.uid}}'
		);

		$this->assertGreaterThanOrEqual(expected: 2, actual: count($cases));
		foreach ($cases as $case) {
			$this->assertSame(expected: '{{lookup:sanne}}', actual: $case['data']['requester'], message: $case['key']);
			$this->assertSame(expected: 'brpPerson', actual: $case['lookups']['sanne']['schema'], message: $case['key']);
		}
	}//end testSannesCasesNameHerAsRequester()

	/**
	 * The five contact moments of DqZaakContact are on the case, after it,
	 * with the board's channels and directions: Pieter's acknowledgement
	 * through Mijn Zuiddrecht follows Sanne's call.
	 *
	 * @return void
	 */
	public function testTheCaseCarriesTheBoardsContactMoments(): void {
		$keys    = array_column($this->objects(), 'key');
		$caseAt  = array_search('woo-fietspad', $keys, true);
		$moments = [];
		foreach ($this->objects() as $index => $object) {
			if (($object['schema'] ?? '') === 'contactmoment' && ($object['data']['case'] ?? '') === '{{object:woo-fietspad.id}}') {
				$this->assertGreaterThan(expected: $caseAt, actual: $index, message: $object['key'].' comes before the case it points at');
				$moments[] = [$object['data']['notificationChannel'], $object['data']['direction']];
			}
		}

		$this->assertSame(
			expected: [['phone', 'inbound'], ['webformulier', 'outbound'], ['webformulier', 'outbound'], ['webformulier', 'inbound'], ['email', 'inbound']],
			actual: $moments
		);
	}//end testTheCaseCarriesTheBoardsContactMoments()

	/**
	 * Each contact moment uses values the contact moment schema allows.
	 *
	 * @return void
	 */
	public function testTheContactMomentsFitTheSchema(): void {
		// The contactmoment schema's enums, as dossiq's register.d/40-kcc-werkplek.json declares them.
		$properties = [
			'notificationChannel' => ['enum' => ['phone', 'email', 'webformulier', 'chat', 'social_media', 'balie']],
			'direction'           => ['enum' => ['inbound', 'outbound']],
			'nature'              => ['enum' => ['informatieverzoek', 'statusverzoek', 'complaint', 'report', 'new_request', 'doorverbinding']],
		];
		foreach ($this->objects() as $object) {
			if (($object['schema'] ?? '') !== 'contactmoment' || ($object['data']['case'] ?? '') !== '{{object:woo-fietspad.id}}') {
				continue;
			}

			foreach (['notificationChannel', 'direction', 'nature'] as $field) {
				$this->assertContains(needle: $object['data'][$field], haystack: $properties[$field]['enum'], message: $object['key'].".$field");
			}
		}
	}//end testTheContactMomentsFitTheSchema()
	/**
	 * Seven documents are on the case through zaakinformatieobject rows, and
	 * four of them carry a verdict: "4 van 7 beoordeeld", with the board's
	 * tally (two public, one in part, one not).
	 *
	 * @return void
	 */
	public function testFourOfSevenDocumentsAreAssessed(): void {
		$documents   = [];
		$assessments = [];
		foreach ($this->objects() as $object) {
			$data = (array)($object['data'] ?? []);
			// The concept decision letter is the case's own document, not one under review.
			if (($object['schema'] ?? '') === 'zaakinformatieobject' && ($data['case'] ?? '') === '{{object:woo-fietspad.id}}' && ($object['key'] ?? '') !== 'woo-fietspad-zio-besluit-concept') {
				$documents[] = (string)$data['informatieobject'];
			}

			if (($object['schema'] ?? '') === 'wooDocumentAssessment' && ($data['caseRef'] ?? '') === '{{object:woo-fietspad.id}}') {
				$assessments[(string)$data['documentRef']] = (string)$data['classification'];
			}
		}

		$this->assertCount(expectedCount: 7, haystack: array_unique($documents));
		$this->assertCount(expectedCount: 4, haystack: $assessments);
		$this->assertSame(expected: [], actual: array_diff(array_keys($assessments), $documents));
		$tally = array_count_values($assessments);
		ksort($tally);
		$this->assertSame(expected: ['deels_openbaar' => 1, 'niet_openbaar' => 1, 'openbaar' => 2], actual: $tally);

		foreach ($documents as $reference) {
			$key      = substr($reference, strlen('{{object:'), -strlen('.id}}'));
			$document = $this->object(key: $key);
			$this->assertSame(expected: 'informatieobject', actual: $document['schema']);
			$file = __DIR__.'/../../../lib/Settings/example-sets/'.$document['files'][0]['path'];
			$this->assertFileExists(filename: $file);
		}
	}//end testFourOfSevenDocumentsAreAssessed()

	/**
	 * The current status carries the day it was reached: the history holds
	 * receipt, then the in-progress status entered the day after, and the
	 * case says it entered its status then ("sinds 4 okt", a day after
	 * "Ontvangen 3 okt").
	 *
	 * @return void
	 */
	public function testTheStatusHistoryDatesTheCurrentStatus(): void {
		$case    = $this->object(key: 'woo-fietspad');
		$history = $case['data']['statusHistory'];

		$this->assertSame(expected: ['{{lookup:ontvangen}}', '{{lookup:status}}'], actual: array_column($history, 'status'));
		$this->assertSame(expected: $case['data']['receivedAt'], actual: $history[0]['enteredAt']);
		$this->assertSame(expected: $case['data']['currentStatusEnteredAt'], actual: $history[1]['enteredAt']);
		$this->assertSame(expected: '{{datetime:-14 09:12}}', actual: $case['data']['receivedAt']);
		$this->assertSame(expected: '{{datetime:-13 10:05}}', actual: $case['data']['currentStatusEnteredAt']);
	}//end testTheStatusHistoryDatesTheCurrentStatus()

	/**
	 * Two advice requests, as DqZaakBesluiten and DqZaak draw them: one still
	 * open (Mobiliteit en verkeer, its answer still due) and one received
	 * (Juridische zaken).
	 *
	 * @return void
	 */
	public function testTheAdviceRequests(): void {
		$advice = [];
		foreach ($this->objects() as $object) {
			if (($object['schema'] ?? '') === 'adviesAanvraag' && ($object['data']['case'] ?? '') === '{{object:woo-fietspad.id}}') {
				$advice[(string)$object['data']['advisor']] = $object['data'];
			}
		}

		$this->assertSame(expected: ['Mobiliteit en verkeer', 'Juridische zaken'], actual: array_keys($advice));
		$this->assertSame(expected: 'requested', actual: $advice['Mobiliteit en verkeer']['status']);
		$this->assertMatchesRegularExpression(pattern: '/^\{\{date:\d+\}\}$/', string: $advice['Mobiliteit en verkeer']['deadline']);
		$this->assertSame(expected: 'received', actual: $advice['Juridische zaken']['status']);
		$this->assertArrayHasKey(key: 'receivedAt', array: $advice['Juridische zaken']);
	}//end testTheAdviceRequests()

	/**
	 * An open case is still to archive, and its retention is not fixed yet:
	 * DqZaakArchivering draws the destruction date as set on closing and
	 * "Vastgelegd op: nog niet".
	 *
	 * @return void
	 */
	public function testTheOpenCaseIsStillToArchive(): void {
		$data = $this->object(key: 'woo-fietspad')['data'];
		$this->assertSame(expected: 'nog_te_archiveren', actual: $data['archiveStatus']);
		$this->assertArrayNotHasKey(key: 'archiveNomination', array: $data);
		$this->assertArrayNotHasKey(key: 'archiveActionDate', array: $data);
		$this->assertArrayNotHasKey(key: 'endingAct', array: $data);
	}//end testTheOpenCaseIsStillToArchive()
	/**
	 * The case's four tasks, as DqZaakTaken draws them: one open with its
	 * handler, a high priority and a deadline still ahead, and three done,
	 * each with when it was done. Only the fields OpenRegister's example-set
	 * tasks take (ExampleSetTasks::FIELDS).
	 *
	 * @return void
	 */
	public function testTheCaseCarriesTheBoardsTasks(): void {
		$set   = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$tasks = array_values(
			array_filter(
				(array)($set['tasks'] ?? []),
				static fn (array $task): bool => ($task['data']['objectUuid'] ?? '') === '{{object:woo-fietspad.id}}'
			)
		);
		$fields = ['title', 'description', 'objectUuid', 'state', 'assignee', 'candidateGroups', 'priority', 'dueAt', 'completedAt', 'completedBy', 'kind'];

		$this->assertCount(expectedCount: 4, haystack: $tasks);
		$open = array_values(array_filter($tasks, static fn (array $task): bool => $task['data']['state'] === 'active'));
		$this->assertCount(expectedCount: 1, haystack: $open);
		$this->assertSame(expected: 'Documenten beoordelen', actual: $open[0]['data']['title']);
		$this->assertSame(expected: 'high', actual: $open[0]['data']['priority']);
		$this->assertMatchesRegularExpression(pattern: '/^\{\{date:\d+\}\}$/', string: $open[0]['data']['dueAt']);
		foreach ($tasks as $task) {
			$this->assertSame(expected: [], actual: array_diff(array_keys($task['data']), $fields), message: $task['key']);
			$this->assertStringStartsWith(prefix: '{{cast:', string: $task['data']['assignee']);
			if ($task['data']['state'] === 'completed') {
				$this->assertArrayHasKey(key: 'completedAt', array: $task['data']);
			}
		}
	}//end testTheCaseCarriesTheBoardsTasks()
}//end class
