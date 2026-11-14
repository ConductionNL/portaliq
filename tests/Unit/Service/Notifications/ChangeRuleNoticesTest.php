<?php

/**
 * Unit tests for the residents a claim-addressed change notice reaches.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Notifications;

use OCA\Portaliq\Service\Identity\PortalAccountsByClaim;
use OCA\Portaliq\Service\Notifications\ChangeNoticeText;
use OCA\Portaliq\Service\Notifications\ChangeRuleNotices;
use OCA\Portaliq\Service\PortalObjectReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Every account holding the record's claim value is a recipient once, and only
 * when it can read the record itself.
 */
#[CoversClass(ChangeRuleNotices::class)]
#[UsesClass(ChangeNoticeText::class)]
class ChangeRuleNoticesTest extends TestCase {

	/**
	 * The accounts lookup.
	 *
	 * @var PortalAccountsByClaim&MockObject
	 */
	private PortalAccountsByClaim&MockObject $accounts;

	/**
	 * The object reader.
	 *
	 * @var PortalObjectReader&MockObject
	 */
	private PortalObjectReader&MockObject $reader;

	/**
	 * The service under test.
	 *
	 * @var ChangeRuleNotices
	 */
	private ChangeRuleNotices $notices;

	/**
	 * Build the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->accounts = $this->createMock(PortalAccountsByClaim::class);
		$this->reader   = $this->createMock(PortalObjectReader::class);
		$this->notices  = new ChangeRuleNotices(accounts: $this->accounts, reader: $this->reader);
	}//end setUp()

	/**
	 * The text comes from the declared message of the new value.
	 *
	 * @return void
	 */
	public function testTextRendersTheDeclaredMessage(): void {
		$this->assertSame(
			['subject' => 'Case 7 closed', 'body' => ''],
			$this->notices->text(messages: ['closed' => ['subject' => 'Case {id} closed']], value: 'closed', row: ['id' => '7'], language: 'en')
		);
		$this->assertNull($this->notices->text(messages: [], value: 'closed', row: [], language: 'en'));
	}//end testTextRendersTheDeclaredMessage()

	/**
	 * Each account is read through its own scope once; an account that cannot read the record is left out.
	 *
	 * @return void
	 */
	public function testRecipientsAreTheAccountsThatCanReadTheRecord(): void {
		$first  = ['subjectRef' => 'kvk:1', 'organisation' => 'org-a', 'audience' => 'business'];
		$second = ['subjectRef' => 'kvk:2'];
		$this->accounts->expects($this->once())->method('find')
			->with('zaken', 'kvk', '12345678', 'gemeente-x')
			->willReturn([$first, $first, $second]);
		$this->reader->expects($this->exactly(2))->method('readObject')
			->willReturnCallback(
				function (...$args) {
					static $calls = 0;
					$calls++;
					if ($calls === 1) {
						$this->assertSame(['reg', 'case', 'owner', 'kvk:1', 'r-9', 'org-a', 'kvk', 'zaken', ['x'], 'business', ['title'], ['status' => 'open']], $args);
						return ['id' => 'r-9', 'title' => 'Permit'];
					}

					$this->assertSame(['reg', 'case', 'owner', 'kvk:2', 'r-9', '', 'kvk', 'zaken', ['x'], '', ['title'], ['status' => 'open']], $args);
					return null;
				}
			);

		$collection = ['register' => 'reg', 'schema' => 'case', 'scopeField' => 'owner', 'scopeClaim' => 'kvk', 'via' => ['x'], 'fields' => ['title'], 'filter' => ['status' => 'open']];
		$recipients = $this->notices->recipients(
			appId: 'zaken',
			field: 'kvkNumber',
			claim: 'kvk',
			collection: $collection,
			data: ['kvkNumber' => '12345678'],
			record: ['id' => 'r-9', 'organisation' => 'gemeente-x']
		);

		$this->assertSame([['account' => $first, 'row' => ['id' => 'r-9', 'title' => 'Permit']]], $recipients);
	}//end testRecipientsAreTheAccountsThatCanReadTheRecord()

	/**
	 * A bare collection reads through the subject reference with no claim, via, fields or filter.
	 *
	 * @return void
	 */
	public function testABareCollectionReadsWithTheDefaults(): void {
		$account = ['subjectRef' => 'bsn:1'];
		$this->accounts->method('find')->willReturn([$account]);
		$this->reader->expects($this->once())->method('readObject')
			->with('', '', 'subjectRef', 'bsn:1', 'r-1', '', '', 'zaken', null, '', null, [])
			->willReturn(['id' => 'r-1']);

		$this->assertSame(
			[['account' => $account, 'row' => ['id' => 'r-1']]],
			$this->notices->recipients(appId: 'zaken', field: 'f', claim: 'bsn', collection: [], data: ['f' => 'v'], record: ['id' => 'r-1', 'organisation' => ''])
		);
	}//end testABareCollectionReadsWithTheDefaults()

	/**
	 * Without a usable claim value or record id nobody is looked up.
	 *
	 * @return void
	 */
	public function testNoValueOrNoRecordIdMeansNoRecipients(): void {
		$this->accounts->expects($this->never())->method('find');
		$this->reader->expects($this->never())->method('readObject');
		$record = ['id' => 'r-9', 'organisation' => ''];

		$this->assertSame([], $this->notices->recipients(appId: 'a', field: 'f', claim: 'c', collection: [], data: [], record: $record));
		$this->assertSame([], $this->notices->recipients(appId: 'a', field: 'f', claim: 'c', collection: [], data: ['f' => 123], record: $record));
		$this->assertSame([], $this->notices->recipients(appId: 'a', field: 'f', claim: 'c', collection: [], data: ['f' => ''], record: $record));
		$this->assertSame([], $this->notices->recipients(appId: 'a', field: 'f', claim: 'c', collection: [], data: ['f' => 'v'], record: ['id' => '', 'organisation' => '']));
	}//end testNoValueOrNoRecordIdMeansNoRecipients()
}//end class
