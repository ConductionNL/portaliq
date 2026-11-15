<?php

/**
 * Golden outcomes of recording integriq's delivery status on a message box
 * row: the exact read, the exact write, and every case that writes nothing.
 * Calls the listener's private `record()` directly, so it runs without
 * integriq installed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-the-resident-sees-only-a-real-delivery-req-mbc-004
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Listener;

use OCA\Portaliq\Listener\PortalDigitalPostDeliveredListener;
use OCA\Portaliq\Service\Notifications\MessageBoxStatus;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\ScopedCollectionReaderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The status write, recorded.
 */
class PortalDigitalPostStatusGoldenTest extends TestCase {
	/**
	 * What the doubles saw.
	 *
	 * @var array<string, array<int, mixed>>
	 */
	private array $seen = [];

	/**
	 * Record one status over these rows and answer what was seen.
	 *
	 * @param array<int, mixed>         $rows    What the reader answers.
	 * @param string                    $status  The mapped status.
	 * @param array<string, mixed>|null $written What the writer answers.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	private function record(array $rows, string $status = 'delivered', ?array $written = ['ok' => true]): array {
		$this->seen = ['reads' => [], 'writes' => [], 'logs' => []];
		$reader     = $this->createMock(ScopedCollectionReaderInterface::class);
		$reader->method('readCollection')->willReturnCallback(function (mixed ...$arguments) use ($rows): array {
			$this->seen['reads'][] = array_slice($arguments, 0, 6);

			return $rows;
		});
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('updateObject')->willReturnCallback(function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, array $data) use ($written): ?array {
			$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $data['lastAttemptAt']);
			$data['lastAttemptAt']  = 'now';
			$this->seen['writes'][] = [$register, $schema, $scopeField, $subjectRef, $organisation, $id, $data];

			return $written;
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
			$this->seen['logs'][] = [$message, $context];
		});

		$listener = new PortalDigitalPostDeliveredListener(reader: $reader, writer: $writer, logger: $logger);
		(new ReflectionMethod($listener, 'record'))->invoke($listener, 'msg-1', $status);

		return $this->seen;
	}//end record()

	/**
	 * A row of this message on the message box channel gets the new status.
	 *
	 * @return void
	 */
	public function testTheRowGetsTheStatus(): void {
		$rows = [
			['@self' => ['id' => 'other'], 'externalMessageId' => 'msg-2', 'channel' => 'messageBox'],
			['@self' => ['id' => 'mail'], 'externalMessageId' => 'msg-1', 'channel' => 'email'],
			['@self' => ['id' => 'row-1'], 'id' => 'x', 'uuid' => 'y', 'externalMessageId' => 'msg-1', 'channel' => 'messageBox', 'organisation' => 'venray', 'status' => 'sent'],
		];

		$this->assertSame(
			[
				'reads'  => [['portaliq', 'portalNotification', 'externalMessageId', 'msg-1', '', 2]],
				'writes' => [['portaliq', 'portalNotification', 'externalMessageId', 'msg-1', 'venray', 'row-1', ['status' => 'delivered', 'lastAttemptAt' => 'now']]],
				'logs'   => [],
			],
			$this->record(rows: $rows)
		);
	}//end testTheRowGetsTheStatus()

	/**
	 * The row id falls back from `@self.id` to `id` to `uuid`.
	 *
	 * @return void
	 */
	public function testTheRowIdFallsBack(): void {
		$base = ['externalMessageId' => 'msg-1', 'channel' => 'messageBox'];
		$ids  = [];
		foreach ([['id' => 'by-id', 'uuid' => 'u'], ['uuid' => 'by-uuid']] as $extra) {
			$ids[] = $this->record(rows: [$base + $extra])['writes'][0][5];
		}

		$this->assertSame(['by-id', 'by-uuid'], $ids);
	}//end testTheRowIdFallsBack()

	/**
	 * A row without an id, or already carrying the status, is not written.
	 *
	 * @return void
	 */
	public function testNothingIsWrittenWithoutAnIdOrAChange(): void {
		$base = ['externalMessageId' => 'msg-1', 'channel' => 'messageBox'];
		$this->assertSame([], $this->record(rows: [$base])['writes']);
		$this->assertSame([], $this->record(rows: [$base + ['id' => 'r', 'status' => 'delivered']])['writes']);
	}//end testNothingIsWrittenWithoutAnIdOrAChange()

	/**
	 * A failed write is logged with the status.
	 *
	 * @return void
	 */
	public function testAFailedWriteIsLogged(): void {
		$seen = $this->record(rows: [['id' => 'r', 'externalMessageId' => 'msg-1', 'channel' => 'messageBox']], written: null);

		$this->assertSame([['Portaliq: message box status was not written', ['status' => 'delivered']]], $seen['logs']);
	}//end testAFailedWriteIsLogged()

	/**
	 * Without a matching row the status is kept for the send still writing it.
	 *
	 * @return void
	 */
	public function testWithoutARowTheStatusIsKept(): void {
		(new MessageBoxStatus())->take(messageId: 'msg-1');
		$seen = $this->record(rows: [['id' => 'r', 'externalMessageId' => 'msg-1', 'channel' => 'email']], status: 'failed');

		$this->assertSame([[], []], [$seen['writes'], $seen['logs']]);
		$this->assertSame('failed', (new MessageBoxStatus())->take(messageId: 'msg-1'));
	}//end testWithoutARowTheStatusIsKept()
}//end class
