<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalNavigationCatalogue;
use OCA\Portaliq\Kernel\Contracts\PortalContributionRegistryInterface;
use OCA\Portaliq\Kernel\Contracts\PortalObjectWriterInterface;
use OCA\Portaliq\Service\ScopedCollectionReaderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * operate-pages-per-portal-and-client REQ-PGC-003: the "Navigation" section
 * lists per audience the pages the installed apps contribute, in the
 * portal's order, and its save keeps what it was not sent.
 *
 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-the-administrator-makes-the-portal-choice-on-the-portals-page-req-pgc-003
 */
class PortalNavigationCatalogueTest extends TestCase {
	private PortalContributionRegistryInterface&MockObject $registry;

	private PortalObjectWriterInterface&MockObject $writer;

	protected function setUp(): void {
		$this->registry = $this->createMock(PortalContributionRegistryInterface::class);
		$this->writer   = $this->createMock(PortalObjectWriterInterface::class);
		$this->registry->method('servedAudiences')->willReturn(['client', 'supplier']);
		$this->registry->method('aggregateFor')->willReturnCallback(
			static function (array $subject): array {
				if ($subject['audience'] !== 'client') {
					return ['contributions' => [['app' => 'shillinq', 'label' => 'Shillinq', 'pages' => [['id' => 'orders', 'label' => 'Orders']]]]];
				}

				return [
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
						['app' => 'dossiq', 'pages' => [['id' => 'cases']]],
					],
				];
			}
		);
	}//end setUp()

	private function catalogue(): PortalNavigationCatalogue {
		return new PortalNavigationCatalogue(
			reader: $this->createMock(ScopedCollectionReaderInterface::class),
			writer: $this->writer,
			registry: $this->registry
		);
	}//end catalogue()

	public function testAPortalWithoutAChoiceListsEveryMenuPageShown(): void {
		$list = $this->catalogue()->listFor(portal: ['slug' => 'open-tilburg']);

		$this->assertSame(['client', 'supplier'], array_column($list, 'audience'));
		$this->assertSame(['pipelinq:quotes', 'pipelinq:invoices', 'dossiq:cases'], array_column($list[0]['pages'], 'page'));
		$this->assertSame([false, false, false], array_column($list[0]['pages'], 'hidden'));
		$this->assertSame('Quotes', $list[0]['pages'][0]['label']);
		$this->assertSame('Pipelinq', $list[0]['pages'][0]['appLabel']);
		// A page without a label reads as its id, an app without one as its id.
		$this->assertSame('cases', $list[0]['pages'][2]['label']);
		$this->assertSame('dossiq', $list[0]['pages'][2]['appLabel']);
		$this->assertArrayNotHasKey('menu', $list[0]['pages'][0]);
	}//end testAPortalWithoutAChoiceListsEveryMenuPageShown()

	public function testTheStoredOrderAndHiddenFlagsLeadAndUnlistedPagesFollow(): void {
		$portal = [
			'slug'       => 'open-tilburg',
			'navigation' => [
				'client' => [
					['page' => 'dossiq:cases', 'hidden' => false],
					['page' => 'pipelinq:quotes', 'hidden' => true],
					['page' => 'gone:page', 'hidden' => true],
				],
			],
		];

		$pages = $this->catalogue()->listFor(portal: $portal)[0]['pages'];

		$this->assertSame(['dossiq:cases', 'pipelinq:quotes', 'pipelinq:invoices'], array_column($pages, 'page'));
		$this->assertSame([false, true, false], array_column($pages, 'hidden'));
	}//end testTheStoredOrderAndHiddenFlagsLeadAndUnlistedPagesFollow()

	public function testASaveWritesTheSentListAndKeepsWhatWasNotSent(): void {
		$portal = [
			'id'         => 'p-1',
			'slug'       => 'open-tilburg',
			'navigation' => [
				'client'   => [['page' => 'gone:page', 'hidden' => true], ['page' => 'pipelinq:quotes', 'hidden' => true]],
				'supplier' => [['page' => 'shillinq:orders', 'hidden' => true]],
			],
		];
		$this->writer->expects($this->once())->method('updateObject')
			->with(
				'portaliq',
				'portal',
				'slug',
				'open-tilburg',
				'',
				'p-1',
				$this->callback(
					function (array $data): bool {
						$this->assertEquals(
							(object)[
								'client'   => [
									['page' => 'pipelinq:invoices', 'hidden' => false],
									['page' => 'pipelinq:quotes', 'hidden' => false],
									['page' => 'gone:page', 'hidden' => true],
								],
								'supplier' => [['page' => 'shillinq:orders', 'hidden' => true]],
							],
							$data['navigation']
						);
						return true;
					}
				)
			)
			->willReturn(['id' => 'p-1']);

		$saved = $this->catalogue()->save(
			portal: $portal,
			navigation: [
				'client' => [
					['page' => 'pipelinq:invoices'],
					['page' => 'pipelinq:quotes', 'hidden' => false],
					['page' => 'pipelinq:invoices', 'hidden' => true],
					['page' => 'not a key', 'hidden' => true],
					'stray',
				],
				'bad audience!' => [['page' => 'x:y', 'hidden' => true]],
			]
		);

		$this->assertSame(['id' => 'p-1'], $saved);
	}//end testASaveWritesTheSentListAndKeepsWhatWasNotSent()

	public function testAFailedWriteAnswersNull(): void {
		$this->writer->method('updateObject')->willReturn(null);

		$this->assertNull($this->catalogue()->save(portal: ['id' => 'p-1', 'slug' => 'open-tilburg'], navigation: []));
	}//end testAFailedWriteAnswersNull()

	public function testAHiddenFlagMustBeTrueToHide(): void {
		$portal = ['slug' => 'open-tilburg', 'navigation' => (object)['client' => [(object)['page' => 'pipelinq:quotes', 'hidden' => 'yes']]]];

		$pages = $this->catalogue()->listFor(portal: $portal)[0]['pages'];

		$this->assertSame('pipelinq:quotes', $pages[0]['page']);
		$this->assertFalse($pages[0]['hidden']);
	}//end testAHiddenFlagMustBeTrueToHide()
}//end class
