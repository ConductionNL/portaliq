<?php

/**
 * Portaliq Plan Ending Mailer
 *
 * The mail a participant gets when a plan they share is near its end date
 * with actions still open.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Plans
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
 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md#requirement-a-plan-near-its-end-date-asks-for-action-req-spl-004
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Plans;

use OCA\Portaliq\Service\Mail\ReminderMailer;
use OCP\IL10N;

/**
 * Mails one participant that a plan is nearly ending (template `plan-ending`).
 *
 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md#requirement-a-plan-near-its-end-date-asks-for-action-req-spl-004
 */
class PlanEndingMailer extends ReminderMailer {
	/**
	 * The template key, also the mail log's.
	 */
	public const TEMPLATE = 'plan-ending';

	/**
	 * The id of the mail template.
	 */
	protected const MAIL_ID = 'portaliq.plan.ending';

	/**
	 * What the mail is about, as it appears in log lines.
	 */
	protected const NOUN = 'plan end';

	/**
	 * Mail one participant about a plan near its end date.
	 *
	 * @param string $subjectRef   The participant.
	 * @param string $organisation The tenant.
	 * @param string $plan         The plan's title.
	 * @param string $endDate      The end date, `Y-m-d`.
	 * @param int    $open         How many actions are still open.
	 *
	 * @return bool Whether a mail left; false for a participant without a usable address or with a withdrawn account.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md#requirement-a-plan-near-its-end-date-asks-for-action-req-spl-004
	 */
	public function send(string $subjectRef, string $organisation, string $plan, string $endDate, int $open): bool {
		return $this->deliver(
			subjectRef: $subjectRef,
			organisation: $organisation,
			values: ['plan' => $plan, 'date' => $endDate, 'open' => (string)$open]
		);
	}//end send()

	/**
	 * The heading, default subject and default body of the mail.
	 *
	 * @param IL10N                 $l10n   The portal's language.
	 * @param array<string, string> $values `plan`, `date` and `open`.
	 *
	 * @return array{heading: string, subject: string, body: string}
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md#requirement-a-plan-near-its-end-date-asks-for-action-req-spl-004
	 */
	protected function text(IL10N $l10n, array $values): array {
		return [
			'heading' => $l10n->t('Your plan is nearly ending'),
			'subject' => $l10n->t('The plan %1$s ends on %2$s', [$values['plan'], $values['date']]),
			'body'    => $l10n->t(
				'The plan "%1$s" ends on %2$s. %3$s actions are still open. Extend the plan with your caseworker, or complete the actions.',
				[$values['plan'], $values['date'], $values['open']]
			),
		];
	}//end text()
}//end class
