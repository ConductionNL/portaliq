<?php

/**
 * Golden outcomes of creating a poll: the refusals before anything is
 * written, and the exact write of a created poll; and of listing a
 * subject's polls: the scoped reads and the subject's own answers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/parent-polls/specs/parent-polls/spec.md#requirement-a-poll-is-created-by-staff-addressed-to-one-audience-and-organisation
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PollService;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\ScopedCollectionReaderInterface;
use PHPUnit\Framework\TestCase;

/**
 * Every refusal and the created write, recorded.
 */
class PollCreateGoldenTest extends TestCase {
	/**
	 * The writes the writer double was asked for.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $writes = [];

	/**
	 * The reads the reader double was asked for.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $reads = [];

	/**
	 * The service over a recording writer and reader.
	 *
	 * @param array<string, mixed>|null $answer What the writer answers.
	 * @param array<string, array<int, mixed>> $rows What the reader answers per schema.
	 *
	 * @return PollService
	 */
	private function service(?array $answer = ['uuid' => 'poll-1'], array $rows = []): PollService {
		$this->writes = [];
		$this->reads  = [];
		$reader       = $this->createMock(ScopedCollectionReaderInterface::class);
		$reader->method('readCollection')->willReturnCallback(function (mixed ...$arguments) use ($rows): array {
			$this->reads[] = $arguments;

			return ($rows[$arguments[1]] ?? []);
		});
		$writer       = $this->createMock(PortalObjectWriter::class);
		$writer->method('createObject')->willReturnCallback(function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data) use ($answer): ?array {
			$this->writes[] = ['register' => $register, 'schema' => $schema, 'scopeField' => $scopeField, 'subjectRef' => $subjectRef, 'organisation' => $organisation, 'data' => $data];

			return $answer;
		});

		return new PollService($reader, $writer);
	}//end service()

	/**
	 * A created poll writes its valid options and every field, and answers the row.
	 *
	 * @return void
	 */
	public function testACreatedPollIsWrittenAsRecorded(): void {
		$result = $this->service()->create(
			question: 'Welke datum?',
			options: [['id' => 'a', 'label' => 'Di'], 'junk', ['id' => '', 'label' => 'X'], ['id' => 'b', 'label' => 'Do', 'extra' => 1]],
			audience: 'parent',
			organisation: 'school-x',
			closesAt: '2026-12-01T00:00:00+00:00',
			createdBy: 'clerk-anna'
		);

		$this->assertSame(['poll' => ['uuid' => 'poll-1']], $result);
		$this->assertCount(1, $this->writes);
		$data = $this->writes[0]['data'];
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $data['createdAt']);
		$data['createdAt'] = 'now';
		$this->assertSame(
			[
				'register'     => 'portaliq',
				'schema'       => 'portalPoll',
				'scopeField'   => '',
				'subjectRef'   => '',
				'organisation' => 'school-x',
				'data'         => [
					'question'     => 'Welke datum?',
					'options'      => [['id' => 'a', 'label' => 'Di'], ['id' => 'b', 'label' => 'Do']],
					'audience'     => 'parent',
					'organisation' => 'school-x',
					'closesAt'     => '2026-12-01T00:00:00+00:00',
					'createdBy'    => 'clerk-anna',
					'createdAt'    => 'now',
				],
			],
			array_merge($this->writes[0], ['data' => $data])
		);
	}//end testACreatedPollIsWrittenAsRecorded()

	/**
	 * The refusals, each before or instead of a write.
	 *
	 * @return void
	 */
	public function testTheRefusals(): void {
		$two    = [['id' => 'a', 'label' => 'Di'], ['id' => 'b', 'label' => 'Do']];
		$cases  = [
			'noQuestion'     => [['', $two, 'parent', 'school-x', '', 'anna'], 'refused', 0],
			'noAudience'     => [['Q', $two, '', 'school-x', '', 'anna'], 'refused', 0],
			'noOrganisation' => [['Q', $two, 'parent', '', '', 'anna'], 'refused', 0],
			'noCreator'      => [['Q', $two, 'parent', 'school-x', '', ''], 'refused', 0],
			'oneOption'      => [['Q', [$two[0], ['id' => 'c']], 'parent', 'school-x', '', 'anna'], 'needs_two_options', 0],
		];
		$actual = [];
		foreach ($cases as $name => [$arguments]) {
			$actual[$name] = [$this->service()->create(...$arguments)['error'], count($this->writes)];
		}

		$service = $this->service(answer: null);
		$actual['notWritten'] = [$service->create('Q', $two, 'parent', 'school-x', '', 'anna')['error'], count($this->writes)];

		$expected = array_map(static fn (array $case): array => [$case[1], $case[2]], $cases) + ['notWritten' => ['not_created', 1]];
		$this->assertSame($expected, $actual);
	}//end testTheRefusals()

	/**
	 * A subject's polls are read within their own audience and organisation,
	 * each carrying only their own answer.
	 *
	 * @return void
	 */
	public function testASubjectsPollsAreReadAsRecorded(): void {
		$service = $this->service(rows: [
			'portalPoll'         => [['uuid' => 'p-1'], 'junk', ['id' => 'p-2'], ['uuid' => 'p-3']],
			'portalPollResponse' => [['pollId' => 'p-1', 'optionId' => 'a'], ['pollId' => 'p-2', 'optionId' => 'b'], 'junk'],
		]);
		$polls   = $service->forSubject(['audience' => 'parent', 'organisation' => 'school-x', 'subjectRef' => 'sub-1']);

		$this->assertSame(
			[['uuid' => 'p-1', 'myResponse' => 'a'], ['id' => 'p-2', 'myResponse' => 'b'], ['uuid' => 'p-3', 'myResponse' => null]],
			$polls
		);
		$this->assertSame(
			[
				['portaliq', 'portalPoll', '', '', 'school-x', 200, ['audience' => 'parent']],
				['portaliq', 'portalPollResponse', 'subjectRef', 'sub-1', '', 200, []],
			],
			array_map(static fn (array $read): array => array_merge(array_slice($read, 0, 6), [$read[11]]), $this->reads)
		);
	}//end testASubjectsPollsAreReadAsRecorded()

	/**
	 * A subject without an audience or organisation sees no polls and reads none.
	 *
	 * @return void
	 */
	public function testASubjectWithoutAudienceOrOrganisationReadsNothing(): void {
		foreach ([['organisation' => 'school-x', 'subjectRef' => 's'], ['audience' => 'parent', 'subjectRef' => 's'], []] as $subject) {
			$service = $this->service(rows: ['portalPoll' => [['uuid' => 'p-1']]]);
			$this->assertSame([[], []], [$service->forSubject($subject), $this->reads]);
		}
	}//end testASubjectWithoutAudienceOrOrganisationReadsNothing()

	/**
	 * A subject without a reference sees the polls and no answers.
	 *
	 * @return void
	 */
	public function testASubjectWithoutAReferenceSeesNoAnswers(): void {
		$service = $this->service(rows: ['portalPoll' => [['uuid' => 'p-1']], 'portalPollResponse' => [['pollId' => 'p-1', 'optionId' => 'a']]]);

		$this->assertSame([['uuid' => 'p-1', 'myResponse' => null]], $service->forSubject(['audience' => 'parent', 'organisation' => 'school-x']));
		$this->assertCount(1, $this->reads);
	}//end testASubjectWithoutAReferenceSeesNoAnswers()

	/**
	 * A subject whose audience has no polls still has its answers read.
	 *
	 * @return void
	 */
	public function testASubjectWithNoPollsStillReadsItsAnswers(): void {
		$service = $this->service(rows: ['portalPoll' => [], 'portalPollResponse' => [['pollId' => 'p-1', 'optionId' => 'a']]]);

		$this->assertSame([], $service->forSubject(['audience' => 'parent', 'organisation' => 'school-x', 'subjectRef' => 'sub-1']));
		$this->assertCount(2, $this->reads);
	}//end testASubjectWithNoPollsStillReadsItsAnswers()
}//end class
