<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Contribution\PortalProviderLocator;
use OCA\Portaliq\Service\PublicRecordReader;
use PHPUnit\Framework\TestCase;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A provider that records what it was asked.
 */
class RecordingRecordsProvider {

	/**
	 * Every record id asked for.
	 *
	 * @var string[]
	 */
	public array $asked = [];

	/**
	 * How often the list was asked for.
	 *
	 * @var int
	 */
	public int $listed = 0;

	/**
	 * The audience.
	 *
	 * @return string
	 */
	public function getAudience(): string {
		return 'citizen';
	}

	/**
	 * The declaration.
	 *
	 * @param array<string, mixed> $subject The subject.
	 *
	 * @return array<string, mixed>
	 */
	public function getContribution(array $subject): array {
		return ['publicRecords' => [['id' => 'memberVotingRecords', 'label' => 'Raadsleden', 'listProvider' => 'publicMembers', 'recordProvider' => 'publicVotingRecord']]];
	}

	/**
	 * The list: one good row with markup and a secret key, one without a title, and filler.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function publicMembers(): array {
		$this->listed++;
		$rows = [['id' => 'p1', 'title' => '<b>Sanne Mulder</b>', 'subtitle' => 'Raadslid, GroenLinks', 'bsn' => '111222333'], ['id' => 'p2'], 'x'];
		for ($i = 0; $i < 600; $i++) {
			$rows[] = ['id' => 'f'.$i, 'title' => 'Lid '.$i];
		}

		return $rows;
	}

	/**
	 * One record.
	 *
	 * @param string $id The id.
	 *
	 * @return array<string, mixed>
	 */
	public function publicVotingRecord(string $id): array {
		$this->asked[] = $id;
		return [
			'title' => 'Sanne Mulder',
			'subtitle' => 'Raadslid',
			'summary' => [['label' => 'Voor', 'value' => 61, 'detail' => 'stemmen']],
			'columns' => [['key' => 'subject', 'label' => 'Onderwerp'], ['key' => 'vote', 'label' => 'Stem']],
			'rows' => [['subject' => 'Groen dak', 'vote' => 'Voor', 'secret' => 'x', 'subjectUrl' => 'javascript:alert(1)'], ['subject' => 'Brug', 'vote' => 'Tegen', 'subjectUrl' => '/besluit/2']],
			'note' => 'Alleen openbare rondes.',
		];
	}
}

/**
 * site-member-voting-record-and-confidential-papers REQ-SCR-001, REQ-SCR-006.
 *
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t2
 */
class PublicRecordReaderTest extends TestCase {

	private RecordingRecordsProvider $provider;

	private function reader(?object $provider=null, bool $throws=false): PublicRecordReader {
		$this->provider = new RecordingRecordsProvider();
		$store          = [];
		$cache          = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(static function (string $key) use (&$store) {
			return ($store[$key] ?? null);
		});
		$cache->method('set')->willReturnCallback(static function (string $key, $value) use (&$store): bool {
			$store[$key] = $value;
			return true;
		});
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);
		$locator = $this->createMock(PortalProviderLocator::class);
		$locator->method('locate')->willReturnCallback(fn (string $app) => ($app === 'decidiq' ? ($provider ?? $this->provider) : null));
		$locator->method('contributionOf')->willReturnCallback(static function (object $provider, array $subject) use ($throws) {
			if ($throws === true) {
				throw new RuntimeException('down');
			}

			return $provider->getContribution($subject);
		});

		return new PublicRecordReader($locator, $factory, $this->createMock(LoggerInterface::class));
	}

	public function testTheListKeepsAtMostFiveHundredEntriesAndOnlyTheContractKeys(): void {
		$entries = $this->reader()->entries(app: 'decidiq', list: 'memberVotingRecords');

		$this->assertCount(PublicRecordReader::MAX_ENTRIES, $entries);
		$this->assertSame(['id' => 'p1', 'title' => 'Sanne Mulder', 'subtitle' => 'Raadslid, GroenLinks'], $entries[0]);
		$this->assertStringNotContainsString('111222333', json_encode($entries));

	}//end testTheListKeepsAtMostFiveHundredEntriesAndOnlyTheContractKeys()

	public function testARecordIsFetchedOnlyForAListedId(): void {
		$reader = $this->reader();

		$this->assertNull($reader->record(app: 'decidiq', list: 'memberVotingRecords', id: 'nobody'));
		$this->assertSame([], $this->provider->asked, 'the record provider is not called for an unlisted id');
		$this->assertNotNull($reader->record(app: 'decidiq', list: 'memberVotingRecords', id: 'p1'));
		$this->assertSame(['p1'], $this->provider->asked);

	}//end testARecordIsFetchedOnlyForAListedId()

	public function testARecordKeepsOnlyPlainValuesInTheColumnOrder(): void {
		$record = $this->reader()->record(app: 'decidiq', list: 'memberVotingRecords', id: 'p1');

		$this->assertSame('61', $record['summary'][0]['value']);
		$this->assertSame(['subject', 'vote'], array_column($record['columns'], 'key'));
		$this->assertSame(['subject' => 'Groen dak', 'vote' => 'Voor'], $record['rows'][0], 'no unknown key, no unsafe link');
		$this->assertSame('/besluit/2', $record['rows'][1]['subjectUrl']);
		$this->assertSame('Alleen openbare rondes.', $record['note']);

	}//end testARecordKeepsOnlyPlainValuesInTheColumnOrder()

	public function testAnswersAreCachedPerAppAndList(): void {
		$reader = $this->reader();
		$reader->entries(app: 'decidiq', list: 'memberVotingRecords');
		$reader->entries(app: 'decidiq', list: 'memberVotingRecords');

		$this->assertSame(1, $this->provider->listed);

	}//end testAnswersAreCachedPerAppAndList()

	public function testAnUnknownAppOrListIsNull(): void {
		$reader = $this->reader();

		$this->assertNull($reader->entries(app: 'other', list: 'memberVotingRecords'));
		$this->assertNull($reader->entries(app: 'decidiq', list: 'nope'));
		$this->assertNull($reader->record(app: 'decidiq', list: 'nope', id: 'p1'));

	}//end testAnUnknownAppOrListIsNull()

	public function testAProviderThatFailsDeclaresNothing(): void {
		$this->assertNull($this->reader(throws: true)->entries(app: 'decidiq', list: 'memberVotingRecords'));

	}//end testAProviderThatFailsDeclaresNothing()
}//end class
