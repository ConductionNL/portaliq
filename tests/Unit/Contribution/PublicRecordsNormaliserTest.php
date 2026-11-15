<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PublicRecordsNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * A stand-in for decidiq's provider: two public methods and the contract's own.
 */
class FakeRecordsProvider {

	/**
	 * The list.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function publicMembers(): array {
		return [];
	}

	/**
	 * One record.
	 *
	 * @param string $id The id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function publicVotingRecord(string $id): ?array {
		return null;
	}

	/**
	 * A method that needs two arguments, so it is not callable with one id.
	 *
	 * @param string $a One.
	 * @param string $b Two.
	 *
	 * @return array<int, string>
	 */
	public function needsTwo(string $a, string $b): array {
		return [];
	}

	/**
	 * The contract's own method.
	 *
	 * @param array<string, mixed> $subject The subject.
	 *
	 * @return array<string, mixed>|null
	 */
	public function getContribution(array $subject): ?array {
		return null;
	}
}

/**
 * site-member-voting-record-and-confidential-papers REQ-SCR-001: only well
 * formed record lists survive, and the aggregate never carries a provider name.
 *
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t1
 */
class PublicRecordsNormaliserTest extends TestCase {

	private function entry(array $over=[]): array {
		return $over + ['id' => 'memberVotingRecords', 'label' => 'Raadsleden', 'group' => 'Gemeenteraad', 'listProvider' => 'publicMembers', 'recordProvider' => 'publicVotingRecord'];
	}

	public function testAValidEntrySurvivesAndTheViewHasNoProviderName(): void {
		$normaliser = new PublicRecordsNormaliser();
		$kept       = $normaliser->normalise(entries: [$this->entry()], provider: new FakeRecordsProvider());

		$this->assertCount(1, $kept);
		$this->assertSame('publicMembers', $kept[0]['listProvider']);
		$view = $normaliser->view(kept: $kept, app: 'decidiq');
		$this->assertSame([['id' => 'memberVotingRecords', 'label' => 'Raadsleden', 'group' => 'Gemeenteraad', 'app' => 'decidiq']], $view);
		$this->assertStringNotContainsString('publicMembers', json_encode($view));
		$this->assertStringNotContainsString('publicVotingRecord', json_encode($view));

	}//end testAValidEntrySurvivesAndTheViewHasNoProviderName()

	public function testAContractMethodIsNeverAProvider(): void {
		$kept = (new PublicRecordsNormaliser())->normalise(entries: [$this->entry(['recordProvider' => 'getContribution'])], provider: new FakeRecordsProvider());

		$this->assertSame([], $kept);

	}//end testAContractMethodIsNeverAProvider()

	public function testAMissingNonIdentifierOrUncallableMethodDropsTheEntry(): void {
		$normaliser = new PublicRecordsNormaliser();
		foreach (['doesNotExist', 'not an identifier', 'needsTwo', '', null, 7] as $bad) {
			$this->assertSame([], $normaliser->normalise(entries: [$this->entry(['listProvider' => $bad])], provider: new FakeRecordsProvider()), (string)json_encode($bad));
		}

	}//end testAMissingNonIdentifierOrUncallableMethodDropsTheEntry()

	public function testAnEntryWithoutAnIdOrALabelAndADuplicateIdAreDropped(): void {
		$kept = (new PublicRecordsNormaliser())->normalise(
			entries: [$this->entry(['id' => 'Bad Id']), $this->entry(['label' => ' ']), $this->entry(), $this->entry(), 'x'],
			provider: new FakeRecordsProvider()
		);

		$this->assertCount(1, $kept);

	}//end testAnEntryWithoutAnIdOrALabelAndADuplicateIdAreDropped()

	public function testNoProviderOrNoListIsNothing(): void {
		$normaliser = new PublicRecordsNormaliser();

		$this->assertSame([], $normaliser->normalise(entries: [$this->entry()], provider: null));
		$this->assertSame([], $normaliser->normalise(entries: 'x', provider: new FakeRecordsProvider()));

	}//end testNoProviderOrNoListIsNothing()
}//end class
