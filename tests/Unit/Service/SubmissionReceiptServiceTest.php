<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\NotificationDispatchService;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\SubmissionReceiptService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the WMEBV receipt + proof-log generator: a successful write produces
 * a linked `portalMessage` (whitelisted data copy, bilingual NL/EN body) and a
 * `portalSubmission` proof record with `deliveryStatus: delivered` — and, the
 * security/reliability-critical part, EVERY failure mode (message write
 * fails, proof-log write fails, or record() itself throws) is swallowed:
 * record() never throws, and a failed proof-log write still yields a minimal
 * fallback row with `deliveryStatus: failed` so the submission stays
 * retriable rather than silently missing.
 *
 * @spec openspec/specs/supplier-portal/spec.md#automatic-ontvangstbevestiging-on-a-successful-create-action
 * @spec openspec/specs/supplier-portal/spec.md#proof-of-receipt-log-satisfying-the-wmebv-burden-of-proof
 * @spec openspec/specs/supplier-portal/spec.md#a-receipt-or-log-failure-never-loses-the-submission
 */
class SubmissionReceiptServiceTest extends TestCase {

	/**
	 * An IFactory stub whose `get('portaliq', $long)` returns an IL10N that
	 * prefixes the sprintf-substituted key with the requested language code
	 * (e.g. `[nl] ...` / `[en] ...`) — a stand-in for the real l10n/*.json
	 * translation (tested separately) that still lets THIS test prove the
	 * service actually requests BOTH 'nl' and 'en' and concatenates both.
	 */
	private function l10nFactory(): IFactory {
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturnCallback(
			function (string $app, $long = null, $locale = null) {
				$l10n = $this->createMock(IL10N::class);
				$l10n->method('t')->willReturnCallback(
					static fn (string $text, $parameters = []) => '[' . $long . '] ' . vsprintf($text, $parameters)
				);

				return $l10n;
			}
		);

		return $factory;
	}//end l10nFactory()

	private function timeFactory(int $time = 1700000000): ITimeFactory {
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn($time);

		return $timeFactory;
	}//end timeFactory()

	public function testSuccessfulRecordWritesAReceiptMessageAndALinkedProofLog(): void {
		$writes = [];
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('createObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data) use (&$writes) {
				$writes[] = compact('register', 'schema', 'scopeField', 'subjectRef', 'organisation', 'data');
				return (['@self' => ['id' => $schema . '-id']] + $data);
			}
		);

		$service = new SubmissionReceiptService($writer, $this->l10nFactory(), $this->timeFactory(), $this->createMock(LoggerInterface::class), $this->createMock(NotificationDispatchService::class));
		$service->record('s1', 'org-1', 'portaliq', 'createExample', ['title' => 'Voorbeeld']);

		$this->assertCount(2, $writes);

		$message = $writes[0];
		$this->assertSame('portaliq', $message['register']);
		$this->assertSame('portalMessage', $message['schema']);
		$this->assertSame('subjectRef', $message['scopeField']);
		$this->assertSame('s1', $message['subjectRef']);
		$this->assertSame('org-1', $message['organisation']);
		$this->assertSame(['title' => 'Voorbeeld'], $message['data']['dataCopy']);
		$this->assertFalse($message['data']['read']);
		$this->assertNotEmpty($message['data']['referenceId']);
		$this->assertNotEmpty($message['data']['subject']);
		$this->assertNotEmpty($message['data']['body']);
		// Bilingual: both an NL and an EN rendering of the body are present
		// (and of the subject line), each carrying the SAME reference id.
		$this->assertStringContainsString('[nl] ', $message['data']['body']);
		$this->assertStringContainsString('[en] ', $message['data']['body']);
		$this->assertStringContainsString('[nl] ', $message['data']['subject']);
		$this->assertStringContainsString('[en] ', $message['data']['subject']);
		$this->assertStringContainsString($message['data']['referenceId'], $message['data']['body']);

		$submission = $writes[1];
		$this->assertSame('portalSubmission', $submission['schema']);
		$this->assertSame('s1', $submission['subjectRef']);
		$this->assertSame('org-1', $submission['organisation']);
		$this->assertSame('portaliq', $submission['data']['appId']);
		$this->assertSame('createExample', $submission['data']['actionId']);
		$this->assertSame(['title' => 'Voorbeeld'], $submission['data']['payloadCopy']);
		$this->assertSame('delivered', $submission['data']['deliveryStatus']);
		// The proof log links to the SAME reference id the receipt carries.
		$this->assertSame($message['data']['referenceId'], $submission['data']['receiptMessageRef']);

	}//end testSuccessfulRecordWritesAReceiptMessageAndALinkedProofLog()

	/**
	 * portal-notifications-dispatch: a successfully written receipt message
	 * fires NotificationDispatchService::dispatch() with the `message.created`
	 * rule key, the contributing appId, and a subject carrying subjectRef +
	 * organisation + the passed-through audience.
	 */
	public function testSuccessfulMessageWriteFiresTheMessageCreatedDispatchTrigger(): void {
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('createObject')->willReturnCallback(
			static fn (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data) => (['@self' => ['id' => $schema . '-id']] + $data)
		);

		$received = [];
		$notificationDispatch = $this->createMock(NotificationDispatchService::class);
		$notificationDispatch->expects($this->once())->method('dispatch')->willReturnCallback(
			function (string $ruleKey, string $appId, array $subject) use (&$received) {
				$received = compact('ruleKey', 'appId', 'subject');
			}
		);

		$service = new SubmissionReceiptService($writer, $this->l10nFactory(), $this->timeFactory(), $this->createMock(LoggerInterface::class), $notificationDispatch);
		$service->record('s1', 'org-1', 'portaliq', 'createExample', ['title' => 'Voorbeeld'], 'supplier');

		$this->assertSame(NotificationDispatchService::RULE_MESSAGE_CREATED, $received['ruleKey']);
		$this->assertSame('portaliq', $received['appId']);
		$this->assertSame('s1', $received['subject']['subjectRef']);
		$this->assertSame('org-1', $received['subject']['organisation']);
		$this->assertSame('supplier', $received['subject']['audience']);

	}//end testSuccessfulMessageWriteFiresTheMessageCreatedDispatchTrigger()

	/**
	 * A failed receipt message write never fires the dispatch trigger — there
	 * is no message to notify about.
	 */
	public function testFailedMessageWriteNeverFiresTheDispatchTrigger(): void {
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('createObject')->willReturnCallback(
			static function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data) {
				if ($schema === 'portalMessage') {
					return null;
				}

				return ['id' => 'submission-1'];
			}
		);

		$notificationDispatch = $this->createMock(NotificationDispatchService::class);
		$notificationDispatch->expects($this->never())->method('dispatch');

		$service = new SubmissionReceiptService($writer, $this->l10nFactory(), $this->timeFactory(), $this->createMock(LoggerInterface::class), $notificationDispatch);
		$service->record('s1', 'org-1', 'portaliq', 'createExample', ['title' => 'X']);

	}//end testFailedMessageWriteNeverFiresTheDispatchTrigger()

	public function testReceiptMessageWriteFailureStillWritesAFailedProofLogAndNeverThrows(): void {
		$writer = $this->createMock(PortalObjectWriter::class);
		$captured = [];
		$writer->method('createObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data) use (&$captured) {
				if ($schema === 'portalMessage') {
					return null;
				}

				$captured[] = $data;
				return ['id' => 'submission-1'];
			}
		);

		$service = new SubmissionReceiptService($writer, $this->l10nFactory(), $this->timeFactory(), $this->createMock(LoggerInterface::class), $this->createMock(NotificationDispatchService::class));

		// Must not throw.
		$service->record('s1', 'org-1', 'portaliq', 'createExample', ['title' => 'X']);
		$this->addToAssertionCount(1);

		// The proof log is still written — with deliveryStatus:'failed' since
		// the receipt message itself could not be written (retriable).
		$this->assertNotEmpty($captured);
		$this->assertSame('failed', $captured[0]['deliveryStatus']);

	}//end testReceiptMessageWriteFailureStillWritesAFailedProofLogAndNeverThrows()

	public function testProofLogWriteFailureRetriesWithAMinimalFallbackRecord(): void {
		$captured = [];
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('createObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data) use (&$captured) {
				$captured[] = ['schema' => $schema, 'data' => $data];

				if ($schema === 'portalMessage') {
					return ['id' => 'message-1'];
				}

				// The FIRST portalSubmission attempt (the enriched one) fails;
				// the fallback retry (second call) succeeds.
				$submissionCalls = count(array_filter($captured, static fn ($c) => $c['schema'] === 'portalSubmission'));
				return ($submissionCalls > 1) ? ['id' => 'fallback-1'] : null;
			}
		);

		$service = new SubmissionReceiptService($writer, $this->l10nFactory(), $this->timeFactory(), $this->createMock(LoggerInterface::class), $this->createMock(NotificationDispatchService::class));
		$service->record('s1', 'org-1', 'portaliq', 'createExample', ['title' => 'X']);

		$submissionWrites = array_values(array_filter($captured, static fn ($c) => $c['schema'] === 'portalSubmission'));
		// The enriched attempt, then the minimal fallback — both fail-safe,
		// never propagated. The enriched attempt's own `deliveryStatus`
		// reflects that the RECEIPT MESSAGE itself was written successfully
		// ('delivered') — independent of the fact that saving THIS log row
		// then failed; the fallback retry always carries 'failed'.
		$this->assertCount(2, $submissionWrites);
		$this->assertSame('delivered', $submissionWrites[0]['data']['deliveryStatus']);
		$this->assertSame('failed', $submissionWrites[1]['data']['deliveryStatus']);
		// The fallback record is minimal — no payloadCopy/receiptMessageRef —
		// but still carries the identifying fields for a retry job.
		$this->assertArrayNotHasKey('payloadCopy', $submissionWrites[1]['data']);
		$this->assertSame('portaliq', $submissionWrites[1]['data']['appId']);
		$this->assertSame('createExample', $submissionWrites[1]['data']['actionId']);

	}//end testProofLogWriteFailureRetriesWithAMinimalFallbackRecord()

	public function testAThrownExceptionAnywhereIsNeverPropagatedAndStillYieldsAFallbackRecord(): void {
		$captured = [];
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('createObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data) use (&$captured) {
				if ($schema === 'portalMessage') {
					throw new RuntimeException('OR is down');
				}

				$captured[] = $data;
				return ['id' => 'fallback-1'];
			}
		);

		$service = new SubmissionReceiptService($writer, $this->l10nFactory(), $this->timeFactory(), $this->createMock(LoggerInterface::class), $this->createMock(NotificationDispatchService::class));

		// Must not throw — the domain create already succeeded; a WMEBV
		// side-effect exception must never surface to the caller.
		$service->record('s1', 'org-1', 'portaliq', 'createExample', ['title' => 'X']);

		$this->assertNotEmpty($captured);
		$this->assertSame('failed', $captured[0]['deliveryStatus']);

	}//end testAThrownExceptionAnywhereIsNeverPropagatedAndStillYieldsAFallbackRecord()

	public function testAFallbackWriteThatAlsoThrowsIsSwallowed(): void {
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('createObject')->willThrowException(new RuntimeException('OR is entirely down'));

		$service = new SubmissionReceiptService($writer, $this->l10nFactory(), $this->timeFactory(), $this->createMock(LoggerInterface::class), $this->createMock(NotificationDispatchService::class));

		// Even when EVERY write throws, record() must never propagate.
		$service->record('s1', 'org-1', 'portaliq', 'createExample', ['title' => 'X']);
		$this->addToAssertionCount(1);

	}//end testAFallbackWriteThatAlsoThrowsIsSwallowed()
}//end class
