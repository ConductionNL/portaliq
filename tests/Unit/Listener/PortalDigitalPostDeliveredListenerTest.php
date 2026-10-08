<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Listener;

use OCA\Integriq\Event\DigitalPostDeliveredEvent;
use OCA\Portaliq\Listener\PortalDigitalPostDeliveredListener;
use OCA\Portaliq\Service\Notifications\MessageBoxStatus;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Integriq reports what became of a letter portaliq asked it to send, and the
 * notification row follows (inbox-berichtenbox-channel, REQ-MBC-004). Built
 * with integriq's REAL DigitalPostDeliveredEvent (from PORTALIQ_INTEGRIQ_LIB,
 * or the verbatim copy in tests/Stubs when integriq is absent).
 *
 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-the-resident-sees-only-a-real-delivery-req-mbc-004
 */
class PortalDigitalPostDeliveredListenerTest extends TestCase {

	/**
	 * The rows updated.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $updated = [];

	/**
	 * The lookups made: [scopeField, value].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $lookups = [];

	/**
	 * Skip, and say why, when integriq's event cannot be loaded.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		if (class_exists(DigitalPostDeliveredEvent::class) === false) {
			$this->markTestSkipped('Integriq is not loadable: run inside Nextcloud or set PORTALIQ_INTEGRIQ_LIB.');
		}
	}//end setUp()

	/**
	 * The listener over a log holding one message box row for `msg-1`.
	 *
	 * @param string $rowStatus The row's current status.
	 *
	 * @return PortalDigitalPostDeliveredListener
	 */
	private function listener(string $rowStatus = 'sent'): PortalDigitalPostDeliveredListener {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef) use ($rowStatus): array {
				$this->lookups[] = [$scopeField, $subjectRef];
				if ($register !== 'portaliq' || $schema !== 'portalNotification' || $scopeField !== 'externalMessageId' || $subjectRef !== 'msg-1') {
					return [];
				}

				return [['@self' => ['id' => 'row-1'], 'accountRef' => 'account-1', 'organisation' => 'venray', 'channel' => 'messageBox', 'status' => $rowStatus, 'externalMessageId' => 'msg-1']];
			}
		);

		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('updateObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, array $data): array {
				$this->updated[] = compact('register', 'schema', 'scopeField', 'subjectRef', 'organisation', 'id', 'data');
				return $data;
			}
		);

		return new PortalDigitalPostDeliveredListener(reader: $reader, writer: $writer, logger: $this->createMock(LoggerInterface::class));
	}//end listener()

	/**
	 * Integriq delivered the letter: the row says delivered.
	 *
	 * @return void
	 */
	public function testDeliveredUpdatesTheRow(): void {
		$this->listener()->handle(new DigitalPostDeliveredEvent(messageId: 'msg-1', status: 'delivered', requestedBy: 'portaliq', previousStatus: 'sent'));

		$this->assertCount(1, $this->updated);
		$this->assertSame('portalNotification', $this->updated[0]['schema']);
		$this->assertSame('row-1', $this->updated[0]['id']);
		$this->assertSame('externalMessageId', $this->updated[0]['scopeField']);
		$this->assertSame('msg-1', $this->updated[0]['subjectRef']);
		$this->assertSame('venray', $this->updated[0]['organisation']);
		$this->assertSame('delivered', $this->updated[0]['data']['status']);

		$this->updated = [];
		$this->listener(rowStatus: 'delivered')->handle(new DigitalPostDeliveredEvent(messageId: 'msg-1', status: 'read', requestedBy: 'portaliq', previousStatus: 'delivered'));
		$this->assertSame('read', $this->updated[0]['data']['status']);

		$this->updated = [];
		$this->listener()->handle(new DigitalPostDeliveredEvent(messageId: 'msg-1', status: 'failed', requestedBy: 'portaliq', previousStatus: 'sent', lastError: 'no box'));
		$this->assertSame('failed', $this->updated[0]['data']['status']);
	}//end testDeliveredUpdatesTheRow()

	/**
	 * A simulated binding reports delivered: the row says simulated, and the
	 * resident will see nothing (REQ-MBC-004).
	 *
	 * @return void
	 */
	public function testSimulatedStaysSimulated(): void {
		$this->listener()->handle(new DigitalPostDeliveredEvent(messageId: 'msg-1', status: 'delivered', requestedBy: 'portaliq', previousStatus: 'queued', simulated: true));
		$this->assertSame('simulated', $this->updated[0]['data']['status']);

		$this->updated = [];
		$this->listener(rowStatus: 'simulated')->handle(new DigitalPostDeliveredEvent(messageId: 'msg-1', status: 'read', requestedBy: 'portaliq', previousStatus: 'delivered', simulated: true));
		$this->assertSame([], $this->updated, 'a simulated letter never becomes read either; nothing to change');
	}//end testSimulatedStaysSimulated()

	/**
	 * A letter another app asked for is not portaliq's to record, and an
	 * event of another kind is ignored.
	 *
	 * @return void
	 */
	public function testOtherRequesterIsIgnored(): void {
		$listener = $this->listener();
		$listener->handle(new DigitalPostDeliveredEvent(messageId: 'msg-1', status: 'delivered', requestedBy: 'dossiq', previousStatus: 'sent'));
		$listener->handle(new Event());

		$this->assertSame([], $this->updated);
		$this->assertSame([], $this->lookups, 'not even looked up');
	}//end testOtherRequesterIsIgnored()

	/**
	 * A report for a row that does not exist yet is kept for the send that
	 * is about to write it: integriq announces inside the send's own dispatch.
	 *
	 * @return void
	 */
	public function testAReportBeforeTheRowIsKeptForTheSend(): void {
		$this->listener()->handle(new DigitalPostDeliveredEvent(messageId: 'msg-2', status: 'sent', requestedBy: 'portaliq', previousStatus: 'queued', simulated: true));

		$this->assertSame([], $this->updated);
		$this->assertSame('simulated', (new MessageBoxStatus())->take(messageId: 'msg-2'));
		$this->assertNull((new MessageBoxStatus())->take(messageId: 'msg-2'), 'taken once');
	}//end testAReportBeforeTheRowIsKeptForTheSend()
}//end class
