<?php

/**
 * Unit tests for the body of a task notice.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\BackgroundJob;

use OCA\Portaliq\BackgroundJob\TaskDelivery;
use OCA\Portaliq\BackgroundJob\TaskNoticeBody;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The body lists the description, the reason, the deadline and, for an overdue
 * task, the consequence, and always ends with where to complete the task.
 */
#[CoversClass(TaskNoticeBody::class)]
#[UsesClass(TaskDelivery::class)]
class TaskNoticeBodyTest extends TestCase {

	/**
	 * A due task reads description, reason, deadline and the closing line; a consequence is not mentioned yet.
	 *
	 * @return void
	 */
	public function testADueTask(): void {
		$delivery = self::delivery(
			kind: 'due',
			message: ['description' => '  Upload your payslip  ', 'reason' => ' We need your income ', 'dueAt' => '2026-12-01T10:00:00+01:00', 'consequence' => 'The request is closed']
		);

		$this->assertSame(
			"Upload your payslip\n\n[The reason: %1\$s|We need your income]\n\n[Please finish this task before %1\$s.|01-12-2026]\n\n[Open \"Mijn taken\" in the portal to complete this task.|]",
			(new TaskNoticeBody())->text(delivery: $delivery, l10n: $this->l10n())
		);
	}//end testADueTask()

	/**
	 * An overdue task says when it was due and what happens without a response.
	 *
	 * @return void
	 */
	public function testAnOverdueTask(): void {
		$delivery = self::delivery(kind: 'overdue', message: ['dueAt' => '2026-11-01', 'consequence' => ' The request is closed ']);

		$this->assertSame(
			"[This task was due on %1\$s.|01-11-2026]\n\n[If you do not respond: %1\$s|The request is closed]\n\n[Open \"Mijn taken\" in the portal to complete this task.|]",
			(new TaskNoticeBody())->text(delivery: $delivery, l10n: $this->l10n())
		);
	}//end testAnOverdueTask()

	/**
	 * Without texts or a readable deadline only the closing line remains.
	 *
	 * @return void
	 */
	public function testOnlyTheClosingLineWithoutTextsOrAReadableDeadline(): void {
		$closing = "[Open \"Mijn taken\" in the portal to complete this task.|]";
		$body    = new TaskNoticeBody();

		$this->assertSame($closing, $body->text(delivery: self::delivery(kind: 'due', message: []), l10n: $this->l10n()));
		$this->assertSame(
			$closing,
			$body->text(delivery: self::delivery(kind: 'overdue', message: ['description' => ' ', 'dueAt' => 'not a date']), l10n: $this->l10n())
		);
	}//end testOnlyTheClosingLineWithoutTextsOrAReadableDeadline()

	/**
	 * A delivery of the given kind and message.
	 *
	 * @param string              $kind    The kind.
	 * @param array<string,mixed> $message The message.
	 *
	 * @return TaskDelivery
	 */
	private static function delivery(string $kind, array $message): TaskDelivery {
		return new TaskDelivery(uuid: 't-1', subjectRef: 'bsn:1', kind: $kind, channel: 'email', message: $message);
	}//end delivery()

	/**
	 * A translator that shows the key and its parameters.
	 *
	 * @return IL10N
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => '['.$text.'|'.implode(',', $parameters).']');
		return $l10n;
	}//end l10n()
}//end class
