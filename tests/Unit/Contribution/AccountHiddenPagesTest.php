<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\AccountHiddenPages;
use OCA\Portaliq\Kernel\Contracts\PortalAccountLookupInterface;
use OCA\Portaliq\Kernel\Contracts\PortalContributionRegistryInterface;
use OCA\Portaliq\Kernel\Contracts\PortalObjectWriterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * operate-pages-per-portal-and-client REQ-PGC-002: the "Pages hidden for
 * this client" field lists the pages of the account's audience with whether
 * each is hidden, and its save writes clean page keys onto the account.
 *
 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-client-sees-only-the-pages-and-records-left-to-them-req-pgc-002
 */
class AccountHiddenPagesTest extends TestCase {
	private PortalAccountLookupInterface&MockObject $accounts;

	private PortalObjectWriterInterface&MockObject $writer;

	private PortalContributionRegistryInterface&MockObject $registry;

	protected function setUp(): void {
		$this->accounts = $this->createMock(PortalAccountLookupInterface::class);
		$this->writer   = $this->createMock(PortalObjectWriterInterface::class);
		$this->registry = $this->createMock(PortalContributionRegistryInterface::class);
	}//end setUp()

	private function pages(): AccountHiddenPages {
		return new AccountHiddenPages(accounts: $this->accounts, writer: $this->writer, registry: $this->registry);
	}//end pages()

	public function testTheAccountsAudiencePagesAreListedWithTheirHiddenFlag(): void {
		$this->registry->expects($this->once())->method('aggregateFor')
			->with(['audience' => 'client', 'trust' => 'high', 'subjectRef' => '', 'organisation' => ''])
			->willReturn(
				[
					'contributions' => [
						[
							'app'   => 'pipelinq',
							'label' => 'Pipelinq',
							'pages' => [
								['id' => 'quotes', 'label' => 'Quotes'],
								['id' => 'invoices', 'label' => 'Invoices'],
								['id' => 'invoice', 'label' => 'Invoice', 'menu' => false],
							],
						],
					],
				]
			);

		$list = $this->pages()->listFor(
			account: ['audience' => 'client', 'hiddenPages' => ['pipelinq:invoices', 'gone:page', 42]]
		);

		$this->assertSame(['pipelinq:quotes', 'pipelinq:invoices', 'pipelinq:invoice', 'gone:page'], array_column($list, 'page'));
		$this->assertSame([false, true, false, true], array_column($list, 'hidden'));
		$this->assertSame([true, true, true, false], array_column($list, 'contributed'));
		// A page out of the menu still closes its collections, so it is listed.
		$this->assertSame('Invoice', $list[2]['label']);
		$this->assertSame(['gone', 'page'], [$list[3]['app'], $list[3]['pageId']]);
	}//end testTheAccountsAudiencePagesAreListedWithTheirHiddenFlag()

	public function testAnAccountWithoutAnAudienceListsOnlyWhatItHides(): void {
		$this->registry->expects($this->never())->method('aggregateFor');

		$list = $this->pages()->listFor(account: ['hiddenPages' => ['pipelinq:invoices']]);

		$this->assertSame(['pipelinq:invoices'], array_column($list, 'page'));
	}//end testAnAccountWithoutAnAudienceListsOnlyWhatItHides()

	public function testASaveWritesCleanKeysOnceEach(): void {
		$this->writer->expects($this->once())->method('updateObject')
			->with('portaliq', 'portalAccount', '', '', '', 'a-1', ['hiddenPages' => ['pipelinq:invoices', 'pipelinq:quotes']])
			->willReturn(['uuid' => 'a-1']);

		$saved = $this->pages()->save(
			account: ['@self' => ['id' => 'a-1'], 'audience' => 'client'],
			hidden: ['pipelinq:invoices', 'pipelinq:quotes', 'pipelinq:invoices', 'no key', 7, '']
		);

		$this->assertSame(['uuid' => 'a-1'], $saved);
	}//end testASaveWritesCleanKeysOnceEach()

	public function testAnAccountWithoutAnIdentifierIsNotWritten(): void {
		$this->writer->expects($this->never())->method('updateObject');

		$this->assertNull($this->pages()->save(account: ['audience' => 'client'], hidden: []));
	}//end testAnAccountWithoutAnIdentifierIsNotWritten()

	public function testABlankSubjectRefFindsNoAccount(): void {
		$this->accounts->expects($this->never())->method('bySubjectRef');

		$this->assertNull($this->pages()->accountBySubjectRef(subjectRef: ' '));
	}//end testABlankSubjectRefFindsNoAccount()

	public function testTheAccountIsReadBySubjectRef(): void {
		$this->accounts->expects($this->once())->method('bySubjectRef')->with('ref-1')->willReturn(['uuid' => 'a-1']);

		$this->assertSame(['uuid' => 'a-1'], $this->pages()->accountBySubjectRef(subjectRef: 'ref-1'));
	}//end testTheAccountIsReadBySubjectRef()
}//end class
