<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Proposals;

use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\Proposals\ProposalQueueReader;
use OCA\Portaliq\Service\Proposals\ProposalService;
use OCA\Portaliq\Service\Proposals\ReviewerObjectWriter;
use OCA\Portaliq\Tests\Unit\Service\Identity\PortalIdentityStoreTrait;
use PHPUnit\Framework\TestCase;

/**
 * Pins every answer of ProposalService::propose() and the exact proposal row
 * it writes: which calls are refused before anything is written, which
 * changes are skipped, and the snapshot it records.
 *
 * @spec openspec/changes/change-proposal-queue/specs/change-proposal-queue/spec.md
 */
class ProposalServiceProposeTest extends TestCase {
	use PortalIdentityStoreTrait;

	protected function setUp(): void {
		$this->rows = [];

	}//end setUp()

	/**
	 * Refused before anything is written.
	 *
	 * @return void
	 */
	public function testRefusalsWriteNothing(): void {
		$service = $this->service();
		$subject = ['register' => 'dossiq', 'schema' => 'zaak', 'id' => 'zaak-1'];
		$changes = [['property' => 'a', 'proposedValue' => 1]];

		$this->assertSame(['error' => 'refused'], $service->propose(subject: $subject, changes: $changes, proposable: ['a'], subjectRow: [], proposedBy: ''));
		$this->assertSame(['error' => 'refused'], $service->propose(subject: ['id' => ''], changes: $changes, proposable: ['a'], subjectRow: [], proposedBy: 'p'));
		$this->assertSame(['error' => 'refused'], $service->propose(subject: [], changes: $changes, proposable: ['a'], subjectRow: [], proposedBy: 'p'));
		$this->assertSame(
			['error' => 'property_not_proposable', 'property' => 'b'],
			$service->propose(
				subject: $subject,
				changes: [['property' => 'a'], 'not an array', ['property' => ''], ['property' => 'b'], ['property' => 'c']],
				proposable: ['a'],
				subjectRow: [],
				proposedBy: 'p'
			)
		);
		$this->assertSame(
			['error' => 'nothing_proposed'],
			$service->propose(subject: $subject, changes: ['x', ['proposedValue' => 1], ['property' => '']], proposable: ['a'], subjectRow: [], proposedBy: 'p')
		);
		$this->assertSame(['error' => 'nothing_proposed'], $service->propose(subject: $subject, changes: [], proposable: [], subjectRow: [], proposedBy: 'p'));
		$this->assertSame([], $this->storedRows('changeProposal'));

	}//end testRefusalsWriteNothing()

	/**
	 * The exact row: subject, proposer, channel, note, snapshot and state.
	 *
	 * @return void
	 */
	public function testTheProposalRowRecordsTheSnapshot(): void {
		$result = $this->service()->propose(
			subject: ['register' => 'dossiq', 'schema' => 'zaak', 'id' => 'zaak-1'],
			changes: [
				['property' => 'phone', 'proposedValue' => '06'],
				'skipped',
				['property' => 'missing'],
				['property' => 7, 'proposedValue' => 'seven'],
			],
			proposable: ['phone', 'missing', '7'],
			subjectRow: ['phone' => '05', '7' => 'old'],
			proposedBy: 'subject-1',
			channel: 'staff',
			note: 'Graag aanpassen'
		);

		$row = $this->storedRows('changeProposal')[0];
		$this->assertSame($row, $result['proposal']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $row['proposedAt']);
		unset($row['proposedAt'], $row['uuid'], $row['_schema']);
		$this->assertSame(
			[
				'subjectRegister' => 'dossiq',
				'subjectSchema' => 'zaak',
				'subjectId' => 'zaak-1',
				'proposedBy' => 'subject-1',
				'channel' => 'staff',
				'changes' => [
					['property' => 'phone', 'currentValue' => '05', 'proposedValue' => '06'],
					['property' => 'missing', 'currentValue' => null, 'proposedValue' => null],
					['property' => '7', 'currentValue' => 'old', 'proposedValue' => 'seven'],
				],
				'note' => 'Graag aanpassen',
				'state' => 'queued',
			],
			$row
		);

	}//end testTheProposalRowRecordsTheSnapshot()

	/**
	 * Defaults: a portal proposal with no note, and a subject missing its
	 * register and schema.
	 *
	 * @return void
	 */
	public function testTheDefaultsAreAPortalProposalWithoutANote(): void {
		$this->service()->propose(subject: ['id' => 'zaak-1'], changes: [['property' => 'a']], proposable: ['a'], subjectRow: [], proposedBy: 'p');

		$row = $this->storedRows('changeProposal')[0];
		$this->assertSame(['', '', 'zaak-1', 'portal', ''], [$row['subjectRegister'], $row['subjectSchema'], $row['subjectId'], $row['channel'], $row['note']]);

	}//end testTheDefaultsAreAPortalProposalWithoutANote()

	/**
	 * A write the store does not take answers not_recorded.
	 *
	 * @return void
	 */
	public function testAProposalTheStoreDoesNotTakeIsNotRecorded(): void {
		$writer = $this->getMockBuilder(PortalObjectWriter::class)
			->disableOriginalConstructor()
			->onlyMethods(['createObject'])
			->getMock();
		$writer->expects($this->once())->method('createObject')->willReturn(null);

		$result = $this->service($writer)->propose(subject: ['id' => 'z'], changes: [['property' => 'a']], proposable: ['a'], subjectRow: [], proposedBy: 'p');

		$this->assertSame(['error' => 'not_recorded'], $result);

	}//end testAProposalTheStoreDoesNotTakeIsNotRecorded()

	/**
	 * The service over the fake store.
	 *
	 * @param PortalObjectWriter|null $writer A writer to use instead of the fake one.
	 *
	 * @return ProposalService
	 */
	private function service(?PortalObjectWriter $writer = null): ProposalService {
		$reviewerWriter = $this->getMockBuilder(ReviewerObjectWriter::class)
			->disableOriginalConstructor()
			->onlyMethods(['write'])
			->getMock();

		return new ProposalService($writer ?? $this->fakeWriter(), $reviewerWriter, new ProposalQueueReader($this->fakeReader()));
	}//end service()

}//end class
