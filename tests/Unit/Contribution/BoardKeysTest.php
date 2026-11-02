<?php

/**
 * Portaliq Board Keys Test (zuiddrecht-resident-pages-match-the-boards)
 *
 * @category Test
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

use OCA\Portaliq\Contribution\BoardKeys;
use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * The board keys survive normalisation when well formed, and nothing else does.
 *
 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-contribution-may-declare-the-board-displays
 */
class BoardKeysTest extends TestCase {


	/**
	 * A cases block keeps compact, showAll and its turn values.
	 *
	 * @return void
	 */
	public function testACasesBlockKeepsItsBoardKeys(): void {
		$keys = new BoardKeys();
		$this->assertSame(
			expected: ['display' => 'compact', 'showAll' => true, 'yourTurn' => ['applicant']],
			actual: $keys->casesKeys(block: ['display' => 'compact', 'showAll' => true, 'yourTurn' => ['applicant', '', 7, 'applicant']])
		);
		$this->assertSame(
			expected: [],
			actual: $keys->casesKeys(block: ['display' => 'cards', 'showAll' => 'yes', 'yourTurn' => 'applicant'])
		);
	}//end testACasesBlockKeepsItsBoardKeys()


	/**
	 * A highlight keeps a tone it knows and the due line flag.
	 *
	 * @return void
	 */
	public function testAHighlightKeepsItsToneAndDueLine(): void {
		$keys = new BoardKeys();
		$this->assertSame(
			expected: ['tone' => 'warning', 'dueInLine' => true],
			actual: $keys->highlightKeys(block: ['tone' => 'warning', 'dueInLine' => true])
		);
		$this->assertSame(
			expected: ['tone' => 'info'],
			actual: $keys->highlightKeys(block: ['tone' => 'info', 'dueInLine' => 'true'])
		);
		$this->assertSame(expected: [], actual: $keys->highlightKeys(block: ['tone' => 'danger']));
		$this->assertSame(expected: ['emptyNotice' => true], actual: $keys->tasksKeys(block: ['emptyNotice' => true]));
		$this->assertSame(expected: [], actual: $keys->tasksKeys(block: ['emptyNotice' => 'yes']));
	}//end testAHighlightKeepsItsToneAndDueLine()


	/**
	 * The one-key blocks keep exactly their key.
	 *
	 * @return void
	 */
	public function testTheOneKeyBlocksKeepTheirKey(): void {
		$keys = new BoardKeys();
		$this->assertSame(expected: ['display' => 'list'], actual: $keys->inboxKeys(block: ['display' => 'list']));
		$this->assertSame(expected: [], actual: $keys->inboxKeys(block: ['display' => 'compact']));
		$this->assertSame(expected: ['upload' => true], actual: $keys->documentsKeys(block: ['upload' => true]));
		$this->assertSame(expected: [], actual: $keys->documentsKeys(block: ['upload' => 'true']));
		$this->assertSame(expected: ['display' => 'actions'], actual: $keys->citizenCaseKeys(block: ['display' => 'actions']));
		$this->assertSame(expected: [], actual: $keys->citizenCaseKeys(block: ['display' => 'quiet']));
	}//end testTheOneKeyBlocksKeepTheirKey()


	/**
	 * A detail block keeps a short label and the timeline switch.
	 *
	 * @return void
	 */
	public function testADetailBlockKeepsItsLabelAndTimelineSwitch(): void {
		$keys = new BoardKeys();
		$this->assertSame(
			expected: ['label' => 'Gegevens', 'timeline' => false],
			actual: $keys->detailKeys(block: ['label' => ' Gegevens ', 'timeline' => false])
		);
		$this->assertSame(
			expected: [],
			actual: $keys->detailKeys(block: ['label' => str_repeat(string: 'x', times: 121), 'timeline' => 'no'])
		);
	}//end testADetailBlockKeepsItsLabelAndTimelineSwitch()


	/**
	 * A page record keeps its heading and breadcrumb keys.
	 *
	 * @return void
	 */
	public function testARecordKeepsItsHeadingAndUnder(): void {
		$keys = new BoardKeys();
		$this->assertSame(
			expected: ['heading' => 'record', 'under' => 'cases'],
			actual: $keys->recordKeys(record: ['heading' => 'record', 'under' => 'cases'])
		);
		$this->assertSame(expected: [], actual: $keys->recordKeys(record: ['heading' => 'page', 'under' => 'tasks']));
	}//end testARecordKeepsItsHeadingAndUnder()


	/**
	 * Through the manifest normaliser, as dossiq declares them: the keys
	 * land on the blocks and the page record; a block without them is as before.
	 *
	 * @return void
	 */
	public function testTheKeysSurviveTheManifestNormaliser(): void {
		$out = (new PortalManifestNormaliser())->normalise(
			[
				'collections' => [
					[
						'id'        => 'mijnZaken',
						'schema'    => 'case',
						'kind'      => 'cases',
						'fields'    => ['title', 'deadline', 'portalTurn'],
						'documents' => ['label' => 'Documenten', 'provider' => 'caseDocuments'],
					],
					['id' => 'berichten', 'schema' => 'message', 'kind' => 'inbox'],
					['id' => 'vragenAanU', 'schema' => 'question', 'fields' => ['summary', 'hersteltermijn']],
				],
				'actions'     => [],
				'pages'       => [
					[
						'id'     => 'overzicht',
						'label'  => 'Overzicht',
						'blocks' => [
							['type' => 'tasks', 'collection' => 'vragenAanU', 'display' => 'highlight', 'tone' => 'warning', 'dueInLine' => true],
							['type' => 'cases', 'collection' => 'mijnZaken', 'display' => 'compact', 'showAll' => true, 'yourTurn' => ['applicant']],
							['type' => 'inbox', 'collection' => 'berichten', 'display' => 'list'],
							['type' => 'cases', 'collection' => 'mijnZaken'],
						],
					],
					[
						'id'     => 'mijnZaken',
						'label'  => 'Uw zaak',
						'record' => ['collection' => 'mijnZaken', 'titleFields' => ['title'], 'heading' => 'record', 'under' => 'cases'],
						'blocks' => [
							['type' => 'documents', 'collection' => 'mijnZaken', 'upload' => true],
							['type' => 'detail', 'collection' => 'mijnZaken', 'label' => 'Gegevens', 'timeline' => false],
							['type' => 'citizenCase', 'collection' => 'mijnZaken', 'display' => 'actions'],
						],
					],
				],
			]
		);

		$overview = $out['pages'][0]['blocks'];
		$this->assertSame(expected: 'warning', actual: $overview[0]['tone']);
		$this->assertTrue(condition: $overview[0]['dueInLine']);
		$this->assertSame(expected: 'compact', actual: $overview[1]['display']);
		$this->assertTrue(condition: $overview[1]['showAll']);
		$this->assertSame(expected: ['applicant'], actual: $overview[1]['yourTurn']);
		$this->assertSame(expected: 'list', actual: $overview[2]['display']);
		$this->assertSame(expected: ['type' => 'cases', 'collection' => 'mijnZaken'], actual: $overview[3]);

		$case = $out['pages'][1];
		$this->assertSame(expected: 'record', actual: $case['record']['heading']);
		$this->assertSame(expected: 'cases', actual: $case['record']['under']);
		$this->assertTrue(condition: $case['blocks'][0]['upload']);
		$this->assertSame(expected: 'Gegevens', actual: $case['blocks'][1]['label']);
		$this->assertFalse(condition: $case['blocks'][1]['timeline']);
		$this->assertSame(expected: 'actions', actual: $case['blocks'][2]['display']);
	}//end testTheKeysSurviveTheManifestNormaliser()

	/**
	 * A documents block keeps a plain `groupBy` and a short `note`, and drops the rest.
	 *
	 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-1
	 *
	 * @return void
	 */
	public function testADocumentsBlockKeepsGroupByAndNote(): void {
		$keys = new BoardKeys();

		$this->assertSame(
			['upload' => true, 'groupBy' => 'group', 'note' => 'Het eerste rapport komt op vrijdag 12 februari 2027.'],
			$keys->documentsKeys(block: ['upload' => true, 'groupBy' => 'group', 'note' => '  Het eerste rapport komt op vrijdag 12 februari 2027.  '])
		);
		$this->assertSame([], $keys->documentsKeys(block: ['groupBy' => 'a b', 'note' => '   ']));
		$this->assertSame([], $keys->documentsKeys(block: ['groupBy' => ['x'], 'note' => str_repeat('x', 401)]));
		$this->assertSame(['upload' => true], $keys->documentsKeys(block: ['upload' => true]));
	}//end testADocumentsBlockKeepsGroupByAndNote()
}//end class
