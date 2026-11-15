<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Proposals;

use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\Proposals\ProposalQueueReader;
use OCA\Portaliq\Service\Proposals\ProposalService;
use OCA\Portaliq\Service\Proposals\ReviewerObjectWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * change-proposal-queue REQ-CPQ-003: the refusals on the decision paths. A
 * proposal without a reviewer, without values, or without an id to close it by
 * is refused, and drift skips changes that name no property.
 *
 * @spec openspec/specs/change-proposal-queue/spec.md
 */
#[CoversClass(ProposalService::class)]
class ProposalServiceDecisionEdgesTest extends TestCase {

	/**
	 * Records the proposal.
	 *
	 * @var PortalObjectWriter&MockObject
	 */
	private $writer;

	/**
	 * Writes the record as the reviewer.
	 *
	 * @var ReviewerObjectWriter&MockObject
	 */
	private $reviewerWriter;

	/**
	 * The service under test.
	 *
	 * @var ProposalService
	 */
	private ProposalService $service;

	protected function setUp(): void {
		$this->writer         = $this->createMock(PortalObjectWriter::class);
		$this->reviewerWriter = $this->createMock(ReviewerObjectWriter::class);
		$this->service        = new ProposalService($this->writer, $this->reviewerWriter, $this->createMock(ProposalQueueReader::class));

	}//end setUp()

	public function testNeitherAcceptPathRunsWithoutAReviewer(): void {
		$this->reviewerWriter->expects($this->never())->method('write');
		$this->writer->expects($this->never())->method('updateObject');
		$proposal = $this->proposal();

		$this->assertSame(['error' => 'forbidden'], $this->service->accept(proposal: $proposal, subjectRow: ['applicantPhone' => '0612345678'], reviewer: ''));
		$this->assertSame(['error' => 'forbidden'], $this->service->acceptConfirmingDrift(proposal: $proposal, reviewer: ''));

	}//end testNeitherAcceptPathRunsWithoutAReviewer()

	public function testConfirmingDriftOnADecidedProposalIsRefused(): void {
		$this->reviewerWriter->expects($this->never())->method('write');

		$result = $this->service->acceptConfirmingDrift(proposal: ['state' => 'rejected'] + $this->proposal(), reviewer: 'handler-anna');

		$this->assertSame(['error' => 'not_queued'], $result);

	}//end testConfirmingDriftOnADecidedProposalIsRefused()

	public function testAProposalWithNoUsableChangesHasNothingToApply(): void {
		$this->reviewerWriter->expects($this->never())->method('write');
		$this->writer->expects($this->never())->method('updateObject');
		$proposal = ['changes' => ['applicantPhone', ['proposedValue' => 'x'], ['property' => '']]] + $this->proposal();

		$result = $this->service->acceptConfirmingDrift(proposal: $proposal, reviewer: 'handler-anna');

		$this->assertSame(['error' => 'nothing_to_apply'], $result);

	}//end testAProposalWithNoUsableChangesHasNothingToApply()

	public function testOnlyTheNamedChangesAreWrittenAndTheProposalIsClosedByItsId(): void {
		$this->reviewerWriter->expects($this->once())
			->method('write')
			->with('dossiq', 'zaak', 'zaak-1', ['applicantPhone' => '0687654321'])
			->willReturn(true);
		$this->writer->expects($this->once())
			->method('updateObject')
			->with(
				$this->identicalTo('portaliq'),
				$this->identicalTo('changeProposal'),
				$this->identicalTo(''),
				$this->identicalTo(''),
				$this->identicalTo(''),
				$this->identicalTo('p-1'),
				$this->callback(static fn (array $data): bool => $data['state'] === 'accepted' && $data['decidedBy'] === 'handler-anna' && $data['decisionReason'] === '')
			)
			->willReturn(['uuid' => 'p-1']);
		$proposal = ['changes' => ['not-a-change', ['property' => 'applicantPhone', 'proposedValue' => '0687654321']]] + $this->proposal();

		$this->assertSame(['accepted' => true], $this->service->acceptConfirmingDrift(proposal: $proposal, reviewer: 'handler-anna'));

	}//end testOnlyTheNamedChangesAreWrittenAndTheProposalIsClosedByItsId()

	public function testAProposalKnownOnlyByItsSelfBlockIsClosedByThatId(): void {
		$this->writer->expects($this->once())
			->method('updateObject')
			->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->identicalTo('self-9'), $this->anything())
			->willReturn(['uuid' => 'self-9']);
		$proposal = ['@self' => ['id' => 'self-9']] + $this->proposal(id: null);

		$this->assertSame(['rejected' => true], $this->service->reject(proposal: $proposal, reviewer: 'handler-anna', reason: ' Nee. '));

	}//end testAProposalKnownOnlyByItsSelfBlockIsClosedByThatId()

	public function testAWrittenRecordWhoseProposalHasNoIdIsNotClosed(): void {
		$this->reviewerWriter->expects($this->once())->method('write')->willReturn(true);
		$this->writer->expects($this->never())->method('updateObject');

		$result = $this->service->acceptConfirmingDrift(proposal: $this->proposal(id: null), reviewer: 'handler-anna');

		$this->assertSame(['error' => 'not_closed'], $result);

	}//end testAWrittenRecordWhoseProposalHasNoIdIsNotClosed()

	public function testARejectionTheStoreDoesNotTakeIsNotClosed(): void {
		$this->writer->expects($this->once())->method('updateObject')->willReturn(null);

		$this->assertSame(['error' => 'not_closed'], $this->service->reject(proposal: $this->proposal(), reviewer: 'handler-anna', reason: 'Nee.'));

	}//end testARejectionTheStoreDoesNotTakeIsNotClosed()

	public function testAWithdrawalTheStoreDoesNotTakeIsNotClosed(): void {
		$this->writer->expects($this->once())
			->method('updateObject')
			->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->identicalTo('p-1'), $this->callback(static fn (array $data): bool => $data['state'] === 'withdrawn' && $data['decidedBy'] === ''))
			->willReturn(null);

		$this->assertSame(['error' => 'not_closed'], $this->service->withdraw(proposal: $this->proposal(), proposedBy: 'subject-1'));

	}//end testAWithdrawalTheStoreDoesNotTakeIsNotClosed()

	public function testDriftSkipsChangesThatNameNoPropertyAndReportsTheRest(): void {
		$proposal = [
			'changes' => [
				'not-a-change',
				['property' => '', 'currentValue' => 'a'],
				['property' => 'applicantPhone', 'currentValue' => '0612345678', 'proposedValue' => '0687654321'],
				['property' => 'email', 'currentValue' => 'a@example.nl', 'proposedValue' => 'b@example.nl'],
			],
		];

		$drift = $this->service->drift(proposal: $proposal, subjectRow: ['applicantPhone' => '0611111111', 'email' => 'a@example.nl']);

		$this->assertSame([['property' => 'applicantPhone', 'snapshot' => '0612345678', 'current' => '0611111111', 'proposedValue' => '0687654321']], $drift);

	}//end testDriftSkipsChangesThatNameNoPropertyAndReportsTheRest()

	/**
	 * A queued proposal on zaak-1.
	 *
	 * @param string|null $id The proposal's uuid, or null for none.
	 *
	 * @return array<string, mixed>
	 */
	private function proposal(?string $id = 'p-1'): array {
		$proposal = [
			'subjectRegister' => 'dossiq',
			'subjectSchema' => 'zaak',
			'subjectId' => 'zaak-1',
			'proposedBy' => 'subject-1',
			'state' => 'queued',
			'changes' => [['property' => 'applicantPhone', 'currentValue' => '0612345678', 'proposedValue' => '0687654321']],
		];
		if ($id !== null) {
			$proposal['uuid'] = $id;
		}

		return $proposal;
	}//end proposal()

}//end class
