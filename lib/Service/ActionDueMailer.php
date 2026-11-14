<?php

/**
 * Portaliq Action Due Mailer
 *
 * The mail an assignee gets three days before the end date of an action
 * that is not done.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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
 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-the-assignee-gets-a-reminder-before-the-end-date-req-ral-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Service\Mail\ReminderMailer;
use OCP\IL10N;

/**
 * Mails the assignee that an action is nearly due (template `action-due`).
 *
 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-the-assignee-gets-a-reminder-before-the-end-date-req-ral-003
 */
class ActionDueMailer extends ReminderMailer {
	/**
	 * The template key, also the mail log's.
	 */
	public const TEMPLATE = 'action-due';

	/**
	 * The id of the mail template.
	 */
	protected const MAIL_ID = 'portaliq.action.due';

	/**
	 * What the mail is about, as it appears in log lines.
	 */
	protected const NOUN = 'action due';

	/**
	 * Mail the assignee about an action near its end date.
	 *
	 * @param string $subjectRef   The assignee.
	 * @param string $organisation The tenant.
	 * @param string $action       The action's title.
	 * @param string $endDate      The end date, `Y-m-d`.
	 *
	 * @return bool Whether a mail left; false for an assignee without a usable address or with a withdrawn account.
	 *
	 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-the-assignee-gets-a-reminder-before-the-end-date-req-ral-003
	 */
	public function send(string $subjectRef, string $organisation, string $action, string $endDate): bool {
		return $this->deliver(subjectRef: $subjectRef, organisation: $organisation, values: ['action' => $action, 'date' => $endDate]);
	}//end send()

	/**
	 * The heading, default subject and default body of the mail.
	 *
	 * @param IL10N                 $l10n   The portal's language.
	 * @param array<string, string> $values `action` and `date`.
	 *
	 * @return array{heading: string, subject: string, body: string}
	 *
	 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-the-assignee-gets-a-reminder-before-the-end-date-req-ral-003
	 */
	protected function text(IL10N $l10n, array $values): array {
		return [
			'heading' => $l10n->t('Your action is nearly due'),
			'subject' => $l10n->t('Your action %1$s is nearly due', [$values['action']]),
			'body'    => $l10n->t('Your action "%1$s" must be done by %2$s.', [$values['action'], $values['date']]),
		];
	}//end text()
}//end class
