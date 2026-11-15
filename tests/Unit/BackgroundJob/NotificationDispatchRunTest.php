<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\BackgroundJob;

use OCA\Portaliq\BackgroundJob\NotificationDispatchRun;
use OCA\Portaliq\BackgroundJob\NotificationOutcome;
use OCA\Portaliq\Kernel\Contracts\NotificationChannelsInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * One dispatch run's e-mail failure streak: the latest e-mail attempt of the
 * rule decides it, a send that went through resets it, and other rules and
 * channels never count.
 *
 * @spec openspec/specs/supplier-portal/spec.md#repeated-failure-flags-an-alternative-contact-fallback
 */
#[CoversClass(NotificationDispatchRun::class)]
#[UsesClass(NotificationOutcome::class)]
class NotificationDispatchRunTest extends TestCase {
	/**
	 * Without logged attempts the streak is zero.
	 *
	 * @return void
	 */
	public function testNoHistoryIsNoStreak(): void {
		$this->assertSame(0, $this->dispatchRun()->failureStreakIn(rows: []));
	}//end testNoHistoryIsNoStreak()

	/**
	 * The latest attempt counts, whatever order the rows arrive in.
	 *
	 * @return void
	 */
	public function testTheLatestFailedAttemptCarriesItsCounter(): void {
		$rows = [
			['ruleKey' => 'message.created', 'status' => 'failed', 'attempts' => 1, 'lastAttemptAt' => '2026-01-01T00:00:00+00:00'],
			['ruleKey' => 'message.created', 'status' => 'failed', 'attempts' => 3, 'lastAttemptAt' => '2026-01-03T00:00:00+00:00'],
			['ruleKey' => 'message.created', 'status' => 'failed', 'attempts' => 2, 'lastAttemptAt' => '2026-01-02T00:00:00+00:00'],
		];

		$this->assertSame(3, $this->dispatchRun()->failureStreakIn(rows: $rows));
	}//end testTheLatestFailedAttemptCarriesItsCounter()

	/**
	 * A latest send that went through resets the streak, even after failures.
	 *
	 * @return void
	 */
	public function testALatestSentAttemptResetsTheStreak(): void {
		$rows = [
			['ruleKey' => 'message.created', 'status' => 'failed', 'attempts' => 4, 'lastAttemptAt' => '2026-01-01T00:00:00+00:00'],
			['ruleKey' => 'message.created', 'status' => 'sent', 'attempts' => 0, 'lastAttemptAt' => '2026-01-02T00:00:00+00:00'],
		];

		$this->assertSame(0, $this->dispatchRun()->failureStreakIn(rows: $rows));
	}//end testALatestSentAttemptResetsTheStreak()

	/**
	 * Push attempts and other rules' attempts are ignored; a row without a
	 * channel is an e-mail attempt.
	 *
	 * @return void
	 */
	public function testOnlyThisRulesEmailAttemptsCount(): void {
		$rows = [
			['ruleKey' => 'message.created', 'status' => 'failed', 'attempts' => 2, 'lastAttemptAt' => '2026-01-01T00:00:00+00:00'],
			['ruleKey' => 'message.created', 'channel' => NotificationChannelsInterface::CHANNEL_PUSH, 'status' => 'sent', 'attempts' => 0, 'lastAttemptAt' => '2026-01-05T00:00:00+00:00'],
			['ruleKey' => 'case.updated', 'status' => 'sent', 'attempts' => 0, 'lastAttemptAt' => '2026-01-06T00:00:00+00:00'],
		];

		$this->assertSame(2, $this->dispatchRun()->failureStreakIn(rows: $rows));
	}//end testOnlyThisRulesEmailAttemptsCount()

	/**
	 * A send resets the counter; a failure extends the previous streak.
	 *
	 * @return void
	 */
	public function testEmailOutcomeResetsOrExtendsTheStreak(): void {
		$sent = $this->dispatchRun()->emailOutcome(sent: true, previousStreak: 5);
		$failed = $this->dispatchRun()->emailOutcome(sent: false, previousStreak: 5);

		$this->assertSame(['sent', 0], [$sent->status, $sent->attempts]);
		$this->assertSame(['failed', 6], [$failed->status, $failed->attempts]);
	}//end testEmailOutcomeResetsOrExtendsTheStreak()

	/**
	 * A run for the `message.created` rule.
	 *
	 * @return NotificationDispatchRun
	 */
	private function dispatchRun(): NotificationDispatchRun {
		return new NotificationDispatchRun(
			argument: [],
			subjectRef: 's1',
			organisation: '',
			ruleKey: 'message.created',
			appId: 'portaliq',
			channels: $this->createMock(NotificationChannelsInterface::class)
		);
	}//end dispatchRun()
}//end class
