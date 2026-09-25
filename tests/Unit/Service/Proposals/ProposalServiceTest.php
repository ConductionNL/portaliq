<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Proposals;

use OCA\Portaliq\Service\Proposals\ProposalService;
use OCA\Portaliq\Service\Proposals\ReviewerObjectWriter;
use OCA\Portaliq\Tests\Unit\Service\Identity\PortalIdentityStoreTrait;
use PHPUnit\Framework\TestCase;

/**
 * change-proposal-queue REQ-CPQ-001 and REQ-CPQ-003: a proposal records what it
 * saw, proposes only what the contribution allows, and never writes the record
 * itself. Accepting writes the record as the reviewer and only then closes the
 * proposal; a drifted snapshot stops the accept until the reviewer says again.
 *
 * @spec openspec/changes/change-proposal-queue/specs/change-proposal-queue/spec.md
 */
class ProposalServiceTest extends TestCase {
	use PortalIdentityStoreTrait;

	/**
	 * What the reviewer writer was asked to write.
	 *
	 * @var array<string, mixed>
	 */
	private array $written = [];

	protected function setUp(): void {
		$this->rows = [];
		$this->written = [];

	}//end setUp()

	public function testAProposalRecordsWhatItSaw(): void {
		$service = $this->service();

		$result = $service->propose(
			subject: $this->subject(),
			changes: [['property' => 'applicantPhone', 'proposedValue' => '0687654321']],
			proposable: ['applicantPhone'],
			subjectRow: ['applicantPhone' => '0612345678'],
			proposedBy: 'subject-1'
		);

		$this->assertArrayNotHasKey('error', $result);
		$row = $this->storedRows('changeProposal')[0];
		$this->assertSame('queued', $row['state']);
		$this->assertSame('0612345678', $row['changes'][0]['currentValue']);
		$this->assertSame('0687654321', $row['changes'][0]['proposedValue']);
		$this->assertSame('portal', $row['channel']);

	}//end testAProposalRecordsWhatItSaw()

	public function testAPropertyTheContributionDoesNotListIsRefused(): void {
		$service = $this->service();

		$result = $service->propose(
			subject: $this->subject(),
			changes: [['property' => 'status', 'proposedValue' => 'afgehandeld']],
			proposable: ['applicantPhone'],
			subjectRow: ['status' => 'ontvangen'],
			proposedBy: 'subject-1'
		);

		$this->assertSame('property_not_proposable', $result['error']);
		$this->assertSame([], $this->storedRows('changeProposal'));

	}//end testAPropertyTheContributionDoesNotListIsRefused()

	public function testAcceptingWritesTheRecordThenClosesTheProposal(): void {
		$service = $this->service();
		$service->propose(
			subject: $this->subject(),
			changes: [['property' => 'applicantPhone', 'proposedValue' => '0687654321']],
			proposable: ['applicantPhone'],
			subjectRow: ['applicantPhone' => '0612345678'],
			proposedBy: 'subject-1'
		);
		$proposal = $this->storedRows('changeProposal')[0];

		$result = $service->accept(proposal: $proposal, subjectRow: ['applicantPhone' => '0612345678'], reviewer: 'handler-anna');

		$this->assertTrue($result['accepted']);
		$this->assertSame(['applicantPhone' => '0687654321'], $this->written['values']);
		$this->assertSame('zaak-1', $this->written['id']);
		$closed = $this->storedRows('changeProposal')[0];
		$this->assertSame('accepted', $closed['state']);
		$this->assertSame('handler-anna', $closed['decidedBy']);

	}//end testAcceptingWritesTheRecordThenClosesTheProposal()

	public function testAProposalIsNotClosedWhenTheRecordWriteDidNotLand(): void {
		$service = $this->service(writeLands: false);
		$service->propose(
			subject: $this->subject(),
			changes: [['property' => 'applicantPhone', 'proposedValue' => '0687654321']],
			proposable: ['applicantPhone'],
			subjectRow: ['applicantPhone' => '0612345678'],
			proposedBy: 'subject-1'
		);
		$proposal = $this->storedRows('changeProposal')[0];

		$result = $service->accept(proposal: $proposal, subjectRow: ['applicantPhone' => '0612345678'], reviewer: 'handler-anna');

		// `accepted` beside a record that never changed is the one outcome
		// worth preventing.
		$this->assertSame('not_written', $result['error']);
		$this->assertSame('queued', $this->storedRows('changeProposal')[0]['state']);

	}//end testAProposalIsNotClosedWhenTheRecordWriteDidNotLand()

	public function testADriftedSnapshotStopsTheAcceptUntilItIsConfirmed(): void {
		$service = $this->service();
		$service->propose(
			subject: $this->subject(),
			changes: [['property' => 'applicantPhone', 'proposedValue' => '0687654321']],
			proposable: ['applicantPhone'],
			subjectRow: ['applicantPhone' => '0612345678'],
			proposedBy: 'subject-1'
		);
		$proposal = $this->storedRows('changeProposal')[0];
		$moved = ['applicantPhone' => '0611111111'];

		$stopped = $service->accept(proposal: $proposal, subjectRow: $moved, reviewer: 'handler-anna');

		$this->assertSame('drifted', $stopped['error']);
		$this->assertSame('0611111111', $stopped['drift'][0]['current']);
		$this->assertSame([], $this->written);
		$this->assertSame('queued', $this->storedRows('changeProposal')[0]['state']);

		// Confirming drift is its own act, on its own method, so it cannot be
		// taken by leaving a flag set.
		$confirmed = $service->acceptConfirmingDrift(proposal: $proposal, reviewer: 'handler-anna');

		$this->assertTrue($confirmed['accepted']);

	}//end testADriftedSnapshotStopsTheAcceptUntilItIsConfirmed()

	public function testRejectingNeedsAReasonAndTouchesOnlyTheProposal(): void {
		$service = $this->service();
		$service->propose(
			subject: $this->subject(),
			changes: [['property' => 'applicantPhone', 'proposedValue' => '0687654321']],
			proposable: ['applicantPhone'],
			subjectRow: ['applicantPhone' => '0612345678'],
			proposedBy: 'subject-1'
		);
		$proposal = $this->storedRows('changeProposal')[0];

		$this->assertSame('reason_required', $service->reject(proposal: $proposal, reviewer: 'handler-anna', reason: '  ')['error']);
		$this->assertSame('queued', $this->storedRows('changeProposal')[0]['state']);

		$rejected = $service->reject(proposal: $proposal, reviewer: 'handler-anna', reason: 'Al op een andere manier gewijzigd.');

		$this->assertTrue($rejected['rejected']);
		$closed = $this->storedRows('changeProposal')[0];
		$this->assertSame('rejected', $closed['state']);
		$this->assertSame('Al op een andere manier gewijzigd.', $closed['decisionReason']);
		$this->assertSame([], $this->written);

	}//end testRejectingNeedsAReasonAndTouchesOnlyTheProposal()

	public function testOnlyItsOwnProposerMayWithdrawIt(): void {
		$service = $this->service();
		$service->propose(
			subject: $this->subject(),
			changes: [['property' => 'applicantPhone', 'proposedValue' => '0687654321']],
			proposable: ['applicantPhone'],
			subjectRow: ['applicantPhone' => '0612345678'],
			proposedBy: 'subject-1'
		);
		$proposal = $this->storedRows('changeProposal')[0];

		$this->assertSame('forbidden', $service->withdraw(proposal: $proposal, proposedBy: 'subject-2')['error']);
		$this->assertTrue($service->withdraw(proposal: $proposal, proposedBy: 'subject-1')['withdrawn']);
		$this->assertSame('withdrawn', $this->storedRows('changeProposal')[0]['state']);

	}//end testOnlyItsOwnProposerMayWithdrawIt()

	public function testADecidedProposalIsNotDecidedAgain(): void {
		$service = $this->service();
		$service->propose(
			subject: $this->subject(),
			changes: [['property' => 'applicantPhone', 'proposedValue' => '0687654321']],
			proposable: ['applicantPhone'],
			subjectRow: ['applicantPhone' => '0612345678'],
			proposedBy: 'subject-1'
		);
		$proposal = $this->storedRows('changeProposal')[0];
		$service->reject(proposal: $proposal, reviewer: 'handler-anna', reason: 'Nee.');
		$closed = $this->storedRows('changeProposal')[0];

		$this->assertSame('not_queued', $service->accept(proposal: $closed, subjectRow: [], reviewer: 'handler-anna')['error']);
		$this->assertSame('not_queued', $service->reject(proposal: $closed, reviewer: 'handler-anna', reason: 'Nogmaals nee.')['error']);
		$this->assertSame('not_queued', $service->withdraw(proposal: $closed, proposedBy: 'subject-1')['error']);

	}//end testADecidedProposalIsNotDecidedAgain()

	public function testTheQueueOnARecordListsOnlyThatRecordsProposals(): void {
		$service = $this->service();
		$service->propose(subject: $this->subject(), changes: [['property' => 'applicantPhone', 'proposedValue' => '1']], proposable: ['applicantPhone'], subjectRow: [], proposedBy: 'subject-1');
		$service->propose(subject: ['register' => 'dossiq', 'schema' => 'zaak', 'id' => 'zaak-2'], changes: [['property' => 'applicantPhone', 'proposedValue' => '2']], proposable: ['applicantPhone'], subjectRow: [], proposedBy: 'subject-1');

		$queued = $service->forSubject(subject: $this->subject());

		$this->assertCount(1, $queued);
		$this->assertSame('zaak-1', $queued[0]['subjectId']);

	}//end testTheQueueOnARecordListsOnlyThatRecordsProposals()

	/**
	 * `mine()` returns every proposal a proposer made, any state, and never
	 * another proposer's.
	 *
	 * @return void
	 */
	public function testMineListsOnlyThatProposersProposalsAnyState(): void {
		$service = $this->service();
		$service->propose(subject: $this->subject(), changes: [['property' => 'applicantPhone', 'proposedValue' => '1']], proposable: ['applicantPhone'], subjectRow: [], proposedBy: 'guardian-1');
		$service->propose(subject: ['register' => 'learniq', 'schema' => 'guardianProfile', 'id' => 'profile-9'], changes: [['property' => 'phone', 'proposedValue' => '2']], proposable: ['phone'], subjectRow: [], proposedBy: 'guardian-2');
		$service->propose(subject: $this->subject(), changes: [['property' => 'applicantPhone', 'proposedValue' => '3']], proposable: ['applicantPhone'], subjectRow: [], proposedBy: 'guardian-1');

		$mine = $service->mine(proposedBy: 'guardian-1');

		$this->assertCount(expectedCount: 2, haystack: $mine);
		foreach ($mine as $proposal) {
			$this->assertSame(expected: 'guardian-1', actual: $proposal['proposedBy']);
		}

	}//end testMineListsOnlyThatProposersProposalsAnyState()

	/**
	 * An empty reference is never treated as "everyone" — it answers nothing.
	 *
	 * @return void
	 */
	public function testMineOfAnEmptyReferenceReturnsNothing(): void {
		$service = $this->service();
		$service->propose(subject: $this->subject(), changes: [['property' => 'applicantPhone', 'proposedValue' => '1']], proposable: ['applicantPhone'], subjectRow: [], proposedBy: 'guardian-1');

		$this->assertSame(expected: [], actual: $service->mine(proposedBy: ''));

	}//end testMineOfAnEmptyReferenceReturnsNothing()

	/**
	 * The record a proposal is about.
	 *
	 * @return array<string, string>
	 */
	private function subject(): array {
		return ['register' => 'dossiq', 'schema' => 'zaak', 'id' => 'zaak-1'];
	}//end subject()

	/**
	 * The service over the fake store, with a reviewer writer that records what
	 * it was asked to write.
	 *
	 * @param bool $writeLands Whether the record write succeeds.
	 *
	 * @return ProposalService
	 */
	private function service(bool $writeLands = true): ProposalService {
		$reviewerWriter = $this->getMockBuilder(ReviewerObjectWriter::class)
			->disableOriginalConstructor()
			->onlyMethods(['write'])
			->getMock();
		$reviewerWriter->method('write')->willReturnCallback(
			function (string $register, string $schema, string $id, array $values) use ($writeLands): bool {
				$this->written = ['register' => $register, 'schema' => $schema, 'id' => $id, 'values' => $values];
				return $writeLands;
			}
		);

		return new ProposalService($this->fakeReader(), $this->fakeWriter(), $reviewerWriter);
	}//end service()

}//end class
