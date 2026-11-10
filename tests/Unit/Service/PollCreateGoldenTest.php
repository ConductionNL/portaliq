<?php

/**
 * Golden outcomes of creating a poll: the refusals before anything is
 * written, and the exact write of a created poll.
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
	 * The service over a recording writer.
	 *
	 * @param array<string, mixed>|null $answer What the writer answers.
	 *
	 * @return PollService
	 */
	private function service(?array $answer = ['uuid' => 'poll-1']): PollService {
		$this->writes = [];
		$writer       = $this->createMock(PortalObjectWriter::class);
		$writer->method('createObject')->willReturnCallback(function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data) use ($answer): ?array {
			$this->writes[] = ['register' => $register, 'schema' => $schema, 'scopeField' => $scopeField, 'subjectRef' => $subjectRef, 'organisation' => $organisation, 'data' => $data];

			return $answer;
		});

		return new PollService($this->createMock(ScopedCollectionReaderInterface::class), $writer);
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
}//end class
