<?php

/**
 * Portaliq Task Notice Body
 *
 * The B1 inbox body of a task delivery, in the portal's language.
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
 * @spec openspec/specs/portal-task-delivery/spec.md#requirement-an-overdue-delivery-reaches-the-resident-as-overdue-req-trd-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\BackgroundJob;

use DateTimeImmutable;
use OCP\IL10N;
use Throwable;

/**
 * Words a task delivery's inbox body. Built by PortalTaskDeliveryJob for each
 * inbox message it writes.
 *
 * @spec openspec/specs/portal-task-delivery/spec.md#requirement-an-overdue-delivery-reaches-the-resident-as-overdue-req-trd-001
 */
final class TaskNoticeBody {
	/**
	 * Body-part translation keys (B1).
	 */
	private const BODY_DUE_KEY = 'Please finish this task before %1$s.';

	/**
	 * The due line once the date has passed: "finish before" would ask for
	 * the impossible (tasks-reminders-after-the-deadline D3).
	 */
	private const BODY_WAS_DUE_KEY = 'This task was due on %1$s.';

	private const BODY_CONSEQUENCE_KEY = 'If you do not respond: %1$s';

	private const BODY_REASON_KEY = 'The reason: %1$s';

	private const BODY_OPEN_KEY = 'Open "Mijn taken" in the portal to complete this task.';

	/**
	 * The B1 inbox body: the engine's description, the due date, the re-ask
	 * reason when present, and where to act, in the portal's language. An
	 * `overdue` notice says when the task was due and, when the engine sends
	 * one, what happens without a response.
	 *
	 * @param TaskDelivery $delivery The delivery.
	 * @param IL10N        $l10n     The translations in the portal's language.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/portal-task-delivery/spec.md#requirement-an-overdue-delivery-reaches-the-resident-as-overdue-req-trd-001
	 * @spec openspec/changes/resident-sees-words-not-codes/specs/portal-notifications-and-preferences/spec.md#requirement-a-receipt-a-notification-mail-and-a-task-notice-are-written-in-the-portals-language-only
	 */
	public function text(TaskDelivery $delivery, IL10N $l10n): string {
		$parts = [];

		$description = trim((string)($delivery->message['description'] ?? ''));
		if ($description !== '') {
			$parts[] = $description;
		}

		$reason = trim((string)($delivery->message['reason'] ?? ''));
		if ($reason !== '') {
			$parts[] = $l10n->t(self::BODY_REASON_KEY, [$reason]);
		}

		$parts   = array_merge($parts, $this->deadlineParts(delivery: $delivery, l10n: $l10n));
		$parts[] = $l10n->t(self::BODY_OPEN_KEY, []);

		return implode("\n\n", $parts);
	}//end text()

	/**
	 * The due date, or the date it was due on an `overdue` notice, and on
	 * that notice what happens without a response when the engine says.
	 *
	 * @param TaskDelivery $delivery The delivery.
	 * @param IL10N        $l10n     The translations in the portal's language.
	 *
	 * @return array<int, string>
	 */
	private function deadlineParts(TaskDelivery $delivery, IL10N $l10n): array {
		$parts = [];
		$due = $this->dueDate(raw: (string)($delivery->message['dueAt'] ?? ''));
		$overdue = ($delivery->kind === 'overdue');
		if ($due !== '') {
			$parts[] = $l10n->t($this->dueKey(overdue: $overdue), [$due]);
		}

		$consequence = trim((string)($delivery->message['consequence'] ?? ''));
		if ($overdue === true && $consequence !== '') {
			$parts[] = $l10n->t(self::BODY_CONSEQUENCE_KEY, [$consequence]);
		}

		return $parts;
	}//end deadlineParts()

	/**
	 * The key of the due line: "was due" on an `overdue` notice.
	 *
	 * @param bool $overdue Whether the notice is overdue.
	 *
	 * @return string
	 */
	private function dueKey(bool $overdue): string {
		if ($overdue === true) {
			return self::BODY_WAS_DUE_KEY;
		}

		return self::BODY_DUE_KEY;
	}//end dueKey()

	/**
	 * A readable day for an ISO-8601 due timestamp, or '' when unparseable.
	 *
	 * @param string $raw The engine's `dueAt` value.
	 *
	 * @return string
	 */
	private function dueDate(string $raw): string {
		if ($raw === '') {
			return '';
		}

		try {
			return (new DateTimeImmutable($raw))->format('d-m-Y');
		} catch (Throwable) {
			return '';
		}
	}//end dueDate()
}//end class
