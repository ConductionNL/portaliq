<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Listener;

use OCA\OpenRegister\Event\RegisterLeafProvidersEvent;
use OCA\OpenRegister\Service\Integration\LeafDescriptor;
use OCA\Portaliq\Listener\RegisterProposalLeavesListener;
use OCA\Portaliq\Service\Proposals\ChangeProposalsProvider;
use OCP\EventDispatcher\Event;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * change-proposal-queue REQ-CPQ-004: portaliq contributes the data leaf with
 * its provider and the review surface as a mount-mode render leaf, on
 * OpenRegister's real collection event.
 *
 * @spec openspec/changes/change-proposal-queue/specs/change-proposal-queue/spec.md
 */
class RegisterProposalLeavesListenerTest extends TestCase {

	/**
	 * Skip without OpenRegister's classes on the autoloader.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		if (class_exists(RegisterLeafProvidersEvent::class) === false) {
			$this->markTestSkipped('OpenRegister is not loadable: run inside Nextcloud or set PORTALIQ_OPENREGISTER_LIB.');
		}

	}//end setUp()

	public function testTheListenerNamesTheEventOpenRegisterDispatches(): void {
		$this->assertSame(RegisterLeafProvidersEvent::class, RegisterProposalLeavesListener::EVENT);

	}//end testTheListenerNamesTheEventOpenRegisterDispatches()

	public function testBothLeavesAreContributedAndOnlyTheDataLeafCarriesTheProvider(): void {
		$provider = $this->getMockBuilder(ChangeProposalsProvider::class)->disableOriginalConstructor()->getMock();
		$event = new RegisterLeafProvidersEvent();

		$this->listener(provider: $provider)->handle($event);

		$leaves = [];
		foreach ($event->getLeaves() as $leaf) {
			$leaves[$leaf['descriptor']->getId()] = $leaf;
		}

		$this->assertSame(['portaliq-change-proposals', 'portaliq-change-proposal-queue'], array_keys($leaves));

		$data = $leaves['portaliq-change-proposals'];
		$this->assertSame([LeafDescriptor::KIND_DATA_PROVIDER], $data['descriptor']->getKinds());
		$this->assertSame($provider, $data['provider']);

		$queue = $leaves['portaliq-change-proposal-queue'];
		$this->assertSame([LeafDescriptor::KIND_RENDER_SURFACE], $queue['descriptor']->getKinds());
		$this->assertNull($queue['provider']);
		$this->assertSame(LeafDescriptor::RENDER_MODE_MOUNT, $queue['descriptor']->getRenderMode());
		$this->assertSame(['detail-page', 'single-entity'], $queue['descriptor']->getSurfaces());
		$this->assertTrue($queue['descriptor']->claimsSharedEntry());
		$this->assertSame('portaliq', $queue['descriptor']->getRequiredApp());

		foreach (array_keys($leaves) as $id) {
			// LeafRegistry skips an id that is not kebab-case.
			$this->assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $id);
		}

	}//end testBothLeavesAreContributedAndOnlyTheDataLeafCarriesTheProvider()

	public function testAnotherEventIsIgnored(): void {
		$provider = $this->getMockBuilder(ChangeProposalsProvider::class)->disableOriginalConstructor()->getMock();

		$this->listener(provider: $provider)->handle(new Event());

		$this->addToAssertionCount(1);

	}//end testAnotherEventIsIgnored()

	/**
	 * The listener under test.
	 *
	 * @param ChangeProposalsProvider $provider The data half.
	 *
	 * @return RegisterProposalLeavesListener
	 */
	private function listener(ChangeProposalsProvider $provider): RegisterProposalLeavesListener {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new RegisterProposalLeavesListener($provider, $l10n, $this->createMock(LoggerInterface::class));
	}//end listener()

}//end class
