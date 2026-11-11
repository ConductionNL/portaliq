<?php

/**
 * Golden outcomes of relaying a landing page submission: the exact consumer
 * event, what an incomplete row becomes, and what is logged when nothing is
 * relayed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-submission-is-relayed-to-the-contributing-app-as-a-fail-safe-not-a-fail-closed-cross-app-event
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Listener;

require_once __DIR__ . '/../../Stubs/Pqtestconsumer/Event/LandingPageFormSubmittedEvent.php';

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Portaliq\Listener\LandingPageSubmissionDispatchListener;
use OCA\Pqtestconsumer\Event\LandingPageFormSubmittedEvent as FixtureConsumerEvent;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The relayed event and the logged outcomes, recorded.
 */
class LandingPageSubmissionRelayGoldenTest extends TestCase {
	/**
	 * Relay one row and record what was dispatched and logged.
	 *
	 * @param mixed  $data     The row's object data.
	 * @param string $register The row's register.
	 * @param string $schema   The row's schema.
	 *
	 * @return array{events: array<int, mixed>, logs: array<int, mixed>}
	 */
	private function relay(mixed $data, string $register = 'portaliq', string $schema = 'landingPageSubmission'): array {
		$seen       = ['events' => [], 'logs' => []];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(static function (object $event) use (&$seen): void {
			$seen['events'][] = [get_class($event), ($event instanceof FixtureConsumerEvent) ? $event->captured : null];
		});
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(static function (string $message, array $context = []) use (&$seen, $level): void {
				$seen['logs'][] = [$level, $message, $context];
			});
		}

		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($data);
		$entity->method('getRegister')->willReturn($register);
		$entity->method('getSchema')->willReturn($schema);
		(new LandingPageSubmissionDispatchListener(dispatcher: $dispatcher, logger: $logger))->handle(new ObjectCreatedEvent($entity));

		return $seen;
	}//end relay()

	/**
	 * A complete row relays every field in the consumer's own order, the
	 * external reference doubling as the correlation id.
	 *
	 * @return void
	 */
	public function testACompleteRowRelaysEveryField(): void {
		$row = [
			'sourceApp'         => '  pqtestconsumer ',
			'formId'            => 'form-1',
			'pageId'            => 'page-1',
			'pageRoute'         => '/campagne/x',
			'portal'            => 'open-tilburg',
			'externalReference' => 'ext-1',
			'values'            => ['email' => 'a@b.c'],
			'utmFirstTouch'     => ['campaign' => 'a'],
			'utmLastTouch'      => ['campaign' => 'b'],
			'referrer'          => 'https://example.org/',
			'submittedAt'       => '2026-09-04T12:00:00+00:00',
			'nonce'             => 'nonce-1',
		];
		$expected = array_merge($row, ['sourceApp' => 'pqtestconsumer', 'correlationId' => 'ext-1']);

		$this->assertSame(['events' => [[FixtureConsumerEvent::class, $expected]], 'logs' => []], $this->relay(data: $row));
	}//end testACompleteRowRelaysEveryField()

	/**
	 * A row with only its source app relays empty values, not nulls.
	 *
	 * @return void
	 */
	public function testAnIncompleteRowRelaysEmptyValues(): void {
		$expected = [
			'sourceApp'         => 'pqtestconsumer',
			'formId'            => '',
			'pageId'            => '',
			'pageRoute'         => '',
			'portal'            => '',
			'externalReference' => '',
			'values'            => [],
			'utmFirstTouch'     => [],
			'utmLastTouch'      => [],
			'referrer'          => '',
			'submittedAt'       => '',
			'nonce'             => '',
			'correlationId'     => '',
		];

		$this->assertSame(['events' => [[FixtureConsumerEvent::class, $expected]], 'logs' => []], $this->relay(data: ['sourceApp' => 'pqtestconsumer']));
	}//end testAnIncompleteRowRelaysEmptyValues()

	/**
	 * What is relayed and logged when nothing may be relayed.
	 *
	 * @return void
	 */
	public function testTheRowsThatAreNotRelayed(): void {
		$none = ['events' => [], 'logs' => []];
		$this->assertSame($none, $this->relay(data: ['sourceApp' => 'pqtestconsumer'], register: 'other'));
		$this->assertSame($none, $this->relay(data: ['sourceApp' => 'pqtestconsumer'], schema: 'page'));
		$this->assertSame(
			['events' => [], 'logs' => [['warning', 'Portaliq: landing page submission has no sourceApp, cannot relay', []]]],
			$this->relay(data: ['sourceApp' => '   ', 'formId' => 'f'])
		);
		$this->assertSame(
			['events' => [], 'logs' => [['info', 'Portaliq: no landing page submission consumer registered yet', ['sourceApp' => 'nosuchapp']]]],
			$this->relay(data: ['sourceApp' => 'nosuchapp'])
		);
	}//end testTheRowsThatAreNotRelayed()
}//end class
