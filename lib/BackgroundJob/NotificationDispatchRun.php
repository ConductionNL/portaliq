<?php

/**
 * Portaliq Notification Dispatch Run
 *
 * One queued dispatch while it runs: who and what it is for, the account it
 * found, the channels it may use and the words of the send in hand. Built
 * and used inside NotificationDispatchJob only.
 *
 * @category BackgroundJob
 * @package  OCA\Portaliq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/supplier-portal/spec.md#every-dispatch-attempt-is-logged
 */

declare(strict_types=1);

namespace OCA\Portaliq\BackgroundJob;

use OCA\Portaliq\Kernel\Contracts\NotificationChannelsInterface;

/**
 * The values every step of one dispatch shares.
 *
 * @spec openspec/specs/supplier-portal/spec.md#every-dispatch-attempt-is-logged
 */
final class NotificationDispatchRun {
	/**
	 * The subject's own account, once found.
	 *
	 * @var array<string, mixed>
	 */
	public array $account = [];

	/**
	 * The account's id/uuid, once read.
	 *
	 * @var string
	 */
	public string $accountId = '';

	/**
	 * The record a change rule is about, or [].
	 *
	 * @var array<string, string>
	 */
	public array $record = [];

	/**
	 * Whether the subject wants this notice by e-mail.
	 *
	 * @var bool
	 */
	public bool $wantsEmail = false;

	/**
	 * Whether the subject wants this notice as a push.
	 *
	 * @var bool
	 */
	public bool $wantsPush = false;

	/**
	 * The tenant's display name, for the send in hand.
	 *
	 * @var string
	 */
	public string $organisationName = '';

	/**
	 * The deep link, for the send in hand.
	 *
	 * @var string
	 */
	public string $deepLink = '';

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>  $argument     The job argument.
	 * @param string                $subjectRef   The subject.
	 * @param string                $organisation The tenant (may be empty).
	 * @param string                $ruleKey      The matched rule key.
	 * @param string                $appId        The contributing app.
	 * @param NotificationChannelsInterface  $channels     The subject's choices and the push.
	 */
	public function __construct(
		public readonly array $argument,
		public readonly string $subjectRef,
		public readonly string $organisation,
		public readonly string $ruleKey,
		public readonly string $appId,
		public readonly NotificationChannelsInterface $channels,
	) {
	}//end __construct()

	/**
	 * The outcome of an e-mail attempt: sent resets the streak, failed
	 * extends it.
	 *
	 * @param bool $sent           Whether the mail went through.
	 * @param int  $previousStreak The failure streak going into the attempt.
	 *
	 * @return NotificationOutcome
	 *
	 * @spec openspec/specs/supplier-portal/spec.md#repeated-failure-flags-an-alternative-contact-fallback
	 */
	public function emailOutcome(bool $sent, int $previousStreak): NotificationOutcome {
		if ($sent === true) {
			return new NotificationOutcome(status: 'sent', attempts: 0);
		}

		return new NotificationOutcome(status: 'failed', attempts: ($previousStreak + 1));
	}//end emailOutcome()

	/**
	 * The consecutive-failure streak a rule's logged attempts carry into the
	 * next one: the latest e-mail attempt's counter, or 0 without history or
	 * after a send that went through.
	 *
	 * @param array<int, array<string, mixed>> $rows The logged attempts.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/supplier-portal/spec.md#repeated-failure-flags-an-alternative-contact-fallback
	 */
	public function failureStreakIn(array $rows): int {
		return $this->streakOf(attempts: $this->emailAttempts(rows: $rows));
	}//end failureStreakIn()

	/**
	 * The logged e-mail attempts of one rule. Only e-mail attempts count
	 * toward needsAlternativeContact: a push that did not arrive says nothing
	 * about the address.
	 *
	 * @param array<int, array<string, mixed>> $rows The logged attempts.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function emailAttempts(array $rows): array {
		$matching = [];
		foreach ($rows as $row) {
			$channel = (string)($row['channel'] ?? NotificationChannelsInterface::CHANNEL_EMAIL);
			if ((string)($row['ruleKey'] ?? '') === $this->ruleKey && $channel === NotificationChannelsInterface::CHANNEL_EMAIL) {
				$matching[] = $row;
			}
		}

		return $matching;
	}//end emailAttempts()

	/**
	 * The failure streak of the latest attempt: 0 without history or after a
	 * send that went through.
	 *
	 * @param list<array<string, mixed>> $attempts The logged e-mail attempts of the rule.
	 *
	 * @return int
	 */
	private function streakOf(array $attempts): int {
		if (count($attempts) === 0) {
			return 0;
		}

		usort(
			$attempts,
			static fn (array $a, array $b): int => strcmp((string)($b['lastAttemptAt'] ?? ''), (string)($a['lastAttemptAt'] ?? ''))
		);

		$latest = $attempts[0];
		if ((string)($latest['status'] ?? '') === 'sent') {
			return 0;
		}

		return (int)($latest['attempts'] ?? 0);
	}//end streakOf()
}//end class
