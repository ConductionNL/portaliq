<?php

/**
 * Portaliq Confirmation Mail Keys (contact-page-question-form-and-not-found)
 *
 * @category Contribution
 * @package  OCA\Portaliq\Contribution
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
 * @spec openspec/changes/contact-page-question-form-and-not-found/tasks.md#t02
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Keeps an action's `confirmationMail` and `topicField` when they fit.
 *
 * `confirmationMail` names the mail the portal sends the resident after a
 * successful create. Only a template the portal ships is kept, so a manifest
 * can never make the portal send text of its own choosing. `topicField`
 * names the whitelisted field whose value the mail names; the question text
 * itself never goes in the mail.
 *
 * @spec openspec/changes/contact-page-question-form-and-not-found/tasks.md#t02
 */
class ConfirmationMailKeys {
	/**
	 * The mails an action may ask for.
	 *
	 * @var string[]
	 */
	public const TEMPLATES = ['contact-confirmation'];

	/**
	 * Keep each key that fits and drop the rest, on a `create` action only.
	 *
	 * @param array<string, mixed> $action    The action.
	 * @param string[]             $whitelist The action's whitelisted fields.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/contact-page-question-form-and-not-found/tasks.md#t02
	 */
	public function normalise(array $action, array $whitelist): array {
		$mail    = ($action['confirmationMail'] ?? null);
		$subject = ($action['topicField'] ?? null);
		unset($action['confirmationMail'], $action['topicField']);

		if (($action['type'] ?? null) !== 'create') {
			return $action;
		}

		if (is_string($mail) === true && in_array($mail, self::TEMPLATES, true) === true) {
			$action['confirmationMail'] = $mail;
		}

		if (is_string($subject) === true && in_array($subject, $whitelist, true) === true) {
			$action['topicField'] = $subject;
		}

		return $action;
	}//end normalise()
}//end class
