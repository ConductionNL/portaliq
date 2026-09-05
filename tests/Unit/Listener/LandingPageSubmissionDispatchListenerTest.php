<?php

/**
 * LandingPageSubmissionDispatchListenerTest
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Listener;

require_once __DIR__ . '/../../Stubs/Pqtestconsumer/Event/LandingPageFormSubmittedEvent.php';

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Portaliq\Listener\LandingPageSubmissionDispatchListener;
use OCA\Pqtestconsumer\Event\LandingPageFormSubmittedEvent as FixtureConsumerEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the fail-SAFE cross-app relay of a `landingPageSubmission` write:
 * resolving the consumer's `LandingPageFormSubmittedEvent` by `sourceApp`
 * via `class_exists()`, dispatching it when found, and — critically —
 * NEVER throwing when it is not found (the app has not shipped its own
 * event class yet).
 *
 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-submission-is-relayed-to-the-contributing-app-as-a-fail-safe-not-a-fail-closed-cross-app-event
 */
class LandingPageSubmissionDispatchListenerTest extends TestCase {

	/**
	 * @var IEventDispatcher&MockObject
	 */
	private IEventDispatcher $dispatcher;

	private LandingPageSubmissionDispatchListener $listener;

	protected function setUp(): void {
		parent::setUp();

		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->listener = new LandingPageSubmissionDispatchListener(
			dispatcher: $this->dispatcher,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * @param array<string, mixed> $data
	 */
	private function entity(array $data, string $register = 'portaliq', string $schema = 'landingPageSubmission'): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($data);
		$entity->method('getRegister')->willReturn($register);
		$entity->method('getSchema')->willReturn($schema);

		return $entity;
	}//end entity()

	public function testDispatchesTheConsumersOwnEventWhenItExists(): void {
		$captured = null;
		$this->dispatcher->expects($this->once())->method('dispatchTyped')->with(
			$this->callback(
				function (FixtureConsumerEvent $event) use (&$captured): bool {
					$captured = $event->captured;
					return true;
				}
			)
		);

		$this->listener->handle(
			new ObjectCreatedEvent(
				$this->entity(
					[
						'sourceApp' => 'pqtestconsumer',
						'formId' => 'form-1',
						'pageId' => 'page-1',
						'pageRoute' => '/campagne/x',
						'portal' => 'open-tilburg',
						'externalReference' => 'ext-1',
						'values' => ['email' => 'jane@example.org'],
						'utmFirstTouch' => ['campaign' => 'a'],
						'utmLastTouch' => ['campaign' => 'b'],
						'referrer' => 'https://example.org/',
						'submittedAt' => '2026-09-04T12:00:00+00:00',
						'nonce' => 'nonce-1',
					]
				)
			)
		);

		$this->assertNotNull($captured);
		$this->assertSame('pqtestconsumer', $captured['sourceApp']);
		$this->assertSame('form-1', $captured['formId']);
		$this->assertSame(['email' => 'jane@example.org'], $captured['values']);
	}//end testDispatchesTheConsumersOwnEventWhenItExists()

	public function testNeverThrowsWhenTheConsumersEventClassDoesNotExistYet(): void {
		$this->dispatcher->expects($this->never())->method('dispatchTyped');

		$this->listener->handle(
			new ObjectCreatedEvent(
				$this->entity(['sourceApp' => 'someAppWithNoEventClassYet', 'formId' => 'form-1'])
			)
		);

		// Reaching here without an exception IS the assertion — a visitor's
		// already-durable submission must never fail on account of an
		// as-yet-unbuilt consumer.
		$this->addToAssertionCount(1);
	}//end testNeverThrowsWhenTheConsumersEventClassDoesNotExistYet()

	public function testASubmissionWithNoSourceAppIsNeverDispatched(): void {
		$this->dispatcher->expects($this->never())->method('dispatchTyped');

		$this->listener->handle(new ObjectCreatedEvent($this->entity(['formId' => 'form-1'])));
	}//end testASubmissionWithNoSourceAppIsNeverDispatched()

	public function testAWriteToAnUnrelatedSchemaIsIgnored(): void {
		$this->dispatcher->expects($this->never())->method('dispatchTyped');

		$this->listener->handle(
			new ObjectCreatedEvent($this->entity(['sourceApp' => 'pqtestconsumer'], 'portaliq', 'page'))
		);
	}//end testAWriteToAnUnrelatedSchemaIsIgnored()

	public function testAnUnrelatedEventIsIgnored(): void {
		$this->dispatcher->expects($this->never())->method('dispatchTyped');

		$this->listener->handle(new Event());
	}//end testAnUnrelatedEventIsIgnored()
}//end class
