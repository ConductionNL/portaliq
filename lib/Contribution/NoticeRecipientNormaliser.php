<?php

/**
 * Portaliq Notice Recipient Normaliser (claim-addressed-change-notices)
 *
 * Checks the two optional parts of a change rule that say who hears about a
 * change and in which words:
 *
 *     "recipients": {"field": "guardianRef", "claim": "guardianRef"}
 *     "messages": {"acknowledged": {"subject": {"nl": "..."}, "body": {"nl": "... {startsAt|datetime} ..."}}}
 *
 * `recipients` addresses the portal accounts whose claim of the contributing
 * app holds the value the record has at `field`. The claim is the app's own:
 * a bare name, or `<app>.<name>` with the contributing app's id. An app can
 * never address residents by another app's claim.
 *
 * `messages` gives the inbox text per new value of the rule's field. Every
 * subject and body is a string or a map of language code to string, and a
 * `{field}` or `{field|datetime}` placeholder may only name a field the
 * collection projects to the resident, so a notice can never print what the
 * resident may not read.
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
 * @spec openspec/changes/claim-addressed-change-notices/specs/portal-notifications-and-preferences/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Validates and shapes a change rule's recipients and message texts.
 *
 * @spec openspec/changes/claim-addressed-change-notices/specs/portal-notifications-and-preferences/spec.md
 */
class NoticeRecipientNormaliser {

	/**
	 * A field or claim name.
	 *
	 * @var string
	 */
	private const NAME = '/^[A-Za-z_][A-Za-z0-9_-]*$/';

	/**
	 * A placeholder in a message text: `{field}` or `{field|datetime}`.
	 *
	 * @var string
	 */
	public const PLACEHOLDER = '/\{([A-Za-z_][A-Za-z0-9_-]*)(\|datetime)?\}/';

	/**
	 * Why the rule's recipients or messages cannot be kept, or null.
	 *
	 * @param array<string, mixed> $rule       The rule.
	 * @param array<string, mixed> $collection Its collection.
	 * @param string               $appId      The contributing app.
	 * @param string               $label      The rule, named for the log.
	 *
	 * @return string|null The reason.
	 *
	 * @spec openspec/changes/claim-addressed-change-notices/specs/portal-notifications-and-preferences/spec.md
	 */
	public function refusal(array $rule, array $collection, string $appId, string $label): ?string {
		if (array_key_exists('recipients', $rule) === true && $this->claimOf(recipients: $rule['recipients'], appId: $appId) === null) {
			return $label.': recipients need a field and a claim of the app itself, not of another app';
		}

		if (array_key_exists('messages', $rule) === false) {
			return null;
		}

		$messages = $rule['messages'];
		if (is_array($messages) === false || $messages === []) {
			return $label.': messages must map a value to a subject and a body';
		}

		foreach ($messages as $message) {
			$reason = $this->messageRefusal(message: $message, collection: $collection);
			if ($reason !== null) {
				return $label.': '.$reason;
			}
		}

		return null;
	}//end refusal()

	/**
	 * The rule's recipients and messages, shaped for the listener. Call only
	 * after refusal() returned null.
	 *
	 * @param array<string, mixed> $rule  The rule.
	 * @param string               $appId The contributing app.
	 *
	 * @return array<string, mixed> `recipients` and `messages`, each only when declared.
	 *
	 * @spec openspec/changes/claim-addressed-change-notices/specs/portal-notifications-and-preferences/spec.md
	 */
	public function shape(array $rule, string $appId): array {
		$shaped = [];
		if (array_key_exists('recipients', $rule) === true) {
			$shaped['recipients'] = [
				'field' => (string)$rule['recipients']['field'],
				'claim' => (string)$this->claimOf(recipients: $rule['recipients'], appId: $appId),
			];
		}

		if (array_key_exists('messages', $rule) === true) {
			$messages = [];
			foreach ($rule['messages'] as $value => $message) {
				$messages[(string)$value] = ['subject' => $message['subject'], 'body' => $message['body']];
			}

			$shaped['messages'] = $messages;
		}

		return $shaped;
	}//end shape()

	/**
	 * The bare claim name the recipients address, or null when malformed or
	 * another app's.
	 *
	 * @param mixed  $recipients The declared recipients.
	 * @param string $appId      The contributing app.
	 *
	 * @return string|null
	 */
	private function claimOf(mixed $recipients, string $appId): ?string {
		if (is_array($recipients) === false) {
			return null;
		}

		$claim = ($recipients['claim'] ?? null);
		if ($this->isName(value: ($recipients['field'] ?? null)) === false || is_string($claim) === false) {
			return null;
		}

		$parts = explode('.', $claim);
		if (count($parts) === 2 && $parts[0] === $appId && $appId !== '') {
			$parts = [$parts[1]];
		}

		if (count($parts) !== 1 || $this->isName(value: $parts[0]) === false) {
			return null;
		}

		return $parts[0];
	}//end claimOf()

	/**
	 * Whether a value is a field or claim name.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function isName(mixed $value): bool {
		return is_string($value) === true && preg_match(self::NAME, $value) === 1;
	}//end isName()

	/**
	 * Why one message cannot be kept, or null.
	 *
	 * @param mixed                $message    The message.
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return string|null
	 */
	private function messageRefusal(mixed $message, array $collection): ?string {
		if (is_array($message) === false) {
			return 'a message needs a subject and a body';
		}

		foreach (['subject', 'body'] as $part) {
			$texts = ($message[$part] ?? null);
			if (is_string($texts) === true) {
				$texts = [$texts];
			}

			if (is_array($texts) === false || $texts === []) {
				return 'a message needs a subject and a body';
			}

			foreach ($texts as $text) {
				$reason = $this->textRefusal(text: $text, collection: $collection);
				if ($reason !== null) {
					return $reason;
				}
			}
		}

		return null;
	}//end messageRefusal()

	/**
	 * Why one text cannot be kept, or null.
	 *
	 * @param mixed                $text       The text.
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return string|null
	 */
	private function textRefusal(mixed $text, array $collection): ?string {
		if (is_string($text) === false || trim($text) === '') {
			return 'a message text must be a string that says something';
		}

		preg_match_all(self::PLACEHOLDER, $text, $matches);
		$fields = (array)($collection['fields'] ?? []);
		foreach ($matches[1] as $field) {
			if (in_array($field, $fields, true) === false) {
				return 'the placeholder "'.$field.'" is not a field projected to the resident';
			}
		}

		return null;
	}//end textRefusal()
}//end class
