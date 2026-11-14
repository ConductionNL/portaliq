<?php

/**
 * Unit tests for CaseQuestions.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-dossier-in-my-cases/specs/citizen-writes-on-their-own-case/spec.md#requirement-the-open-question-is-answered-on-the-case-page-req-wdm-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Contribution\RowActionResolver;
use OCA\Portaliq\Kernel\Support\PortalBranchScope;
use OCA\Portaliq\Kernel\Support\SessionTrust;
use OCA\Portaliq\Service\CaseQuestions;
use OCA\Portaliq\Service\PortalObjectReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Which questions of the case's own app reach the case page.
 *
 * @covers \OCA\Portaliq\Service\CaseQuestions
 * @uses   \OCA\Portaliq\Contribution\RowActionResolver
 * @uses   \OCA\Portaliq\Kernel\Support\PortalBranchScope
 * @uses   \OCA\Portaliq\Kernel\Support\SessionTrust
 */
class CaseQuestionsTest extends TestCase {

	private const SUBJECT = ['subjectRef' => 'bsn-hash-1', 'audience' => 'client', 'organisation' => 'gemeente-1', 'trust' => 'low'];

	private const CASE = ['id' => '12', 'uuid' => 'case-uuid-1'];

	/**
	 * The contributions dossiq gives: the question collection and its answer action.
	 *
	 * @param array<string, mixed> $collection Overrides on the collection.
	 * @param array<string, mixed> $action     Overrides on the action.
	 * @param string               $app        The contributing app.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function contributions(array $collection = [], array $action = [], string $app = 'dossiq'): array {
		return [[
			'app' => $app,
			'collections' => [array_merge([
				'id' => 'vragenAanU',
				'register' => 'zaken',
				'schema' => 'aanvullingsverzoek',
				'scopeField' => 'portalSubject',
				'minTrust' => 'low',
				'rowActions' => ['beantwoordVraag'],
			], $collection)],
			'actions' => [array_merge([
				'id' => 'beantwoordVraag',
				'endpoint' => '/index.php/apps/dossiq/api/portal/vragen/antwoord',
				'rowField' => 'requestId',
				'rowWhen' => ['field' => 'state', 'in' => ['open']],
			], $action)],
		]];
	}//end contributions()

	/**
	 * The reader under test, over the given rows (or throwing).
	 *
	 * @param array<int, array<string, mixed>>|null $rows The rows; null throws.
	 *
	 * @return CaseQuestions
	 */
	private function questions(?array $rows): CaseQuestions {
		$reader = $this->createMock(PortalObjectReader::class);
		if ($rows === null) {
			$reader->method('readCollection')->willThrowException(new \RuntimeException('store down'));
		} else {
			$reader->method('readCollection')->willReturn($rows);
		}

		return new CaseQuestions(
			reader: $reader,
			rowAction: new RowActionResolver(),
			branches: new PortalBranchScope(),
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end questions()

	public function testAQuestionOnTheCaseUuidIsListedWithoutAnEmptyTerm(): void {
		$found = $this->questions(rows: [['id' => 'v-1', 'case' => 'case-uuid-1', 'state' => 'open', 'summary' => 'Welke jaren?', 'hersteltermijn' => '']])
			->forCase(subject: self::SUBJECT, contributions: $this->contributions(), app: 'dossiq', case: self::CASE);

		$this->assertCount(1, $found);
		$this->assertSame('v-1', $found[0]['id']);
		$this->assertSame('Welke jaren?', $found[0]['summary']);
		$this->assertArrayNotHasKey('hersteltermijn', $found[0]);
		$this->assertSame('beantwoordVraag', $found[0]['action']['id']);
	}//end testAQuestionOnTheCaseUuidIsListedWithoutAnEmptyTerm()

	public function testAnAnsweredQuestionIsLeftOutEvenWhenTheActionHasNoRowCondition(): void {
		$contributions = $this->contributions();
		unset($contributions[0]['actions'][0]['rowWhen']);

		$found = $this->questions(rows: [
			['id' => 'v-1', 'case' => '12', 'state' => 'answered', 'summary' => 'Beantwoord'],
			['id' => 'v-2', 'case' => '12', 'state' => 'open', 'summary' => 'Open'],
		])->forCase(subject: self::SUBJECT, contributions: $contributions, app: 'dossiq', case: self::CASE);

		$this->assertSame(['v-2'], array_column($found, 'id'));
	}//end testAnAnsweredQuestionIsLeftOutEvenWhenTheActionHasNoRowCondition()

	public function testAnotherAppsCollectionIsNotRead(): void {
		$found = $this->questions(rows: [['id' => 'v-1', 'case' => '12', 'state' => 'open']])
			->forCase(subject: self::SUBJECT, contributions: $this->contributions(app: 'pipelinq'), app: 'dossiq', case: self::CASE);

		$this->assertSame([], $found);
	}//end testAnotherAppsCollectionIsNotRead()

	public function testACollectionOnAnotherSchemaHoldsNoQuestions(): void {
		$found = $this->questions(rows: [['id' => 'v-1', 'case' => '12', 'state' => 'open']])
			->forCase(subject: self::SUBJECT, contributions: $this->contributions(collection: ['schema' => 'bericht']), app: 'dossiq', case: self::CASE);

		$this->assertSame([], $found);
	}//end testACollectionOnAnotherSchemaHoldsNoQuestions()

	public function testWithoutAnEndpointActionNoQuestionIsOffered(): void {
		$found = $this->questions(rows: [['id' => 'v-1', 'case' => '12', 'state' => 'open']])
			->forCase(subject: self::SUBJECT, contributions: $this->contributions(action: ['endpoint' => 'https://elsewhere.example/answer']), app: 'dossiq', case: self::CASE);

		$this->assertSame([], $found);
	}//end testWithoutAnEndpointActionNoQuestionIsOffered()

	public function testASessionBelowTheCollectionsTrustSeesNone(): void {
		$found = $this->questions(rows: [['id' => 'v-1', 'case' => '12', 'state' => 'open']])
			->forCase(subject: self::SUBJECT, contributions: $this->contributions(collection: ['minTrust' => 'high']), app: 'dossiq', case: self::CASE);

		$this->assertSame([], $found);
	}//end testASessionBelowTheCollectionsTrustSeesNone()

	public function testAFailingReadGivesNoQuestions(): void {
		$found = $this->questions(rows: null)
			->forCase(subject: self::SUBJECT, contributions: $this->contributions(), app: 'dossiq', case: self::CASE);

		$this->assertSame([], $found);
	}//end testAFailingReadGivesNoQuestions()

	public function testACaseWithoutAnIdHasNoQuestions(): void {
		$found = $this->questions(rows: [['id' => 'v-1', 'case' => '', 'state' => 'open']])
			->forCase(subject: self::SUBJECT, contributions: $this->contributions(), app: 'dossiq', case: []);

		$this->assertSame([], $found);
	}//end testACaseWithoutAnIdHasNoQuestions()
}//end class
