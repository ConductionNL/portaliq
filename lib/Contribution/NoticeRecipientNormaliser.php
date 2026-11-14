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
 * @spec openspec/specs/portal-notifications-and-preferences/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

use OCA\Portaliq\Kernel\Contracts\NoticeRecipientNormaliserInterface;

/**
 * Validates and shapes a change rule's recipients and message texts.
 *
 * @spec openspec/specs/portal-notifications-and-preferences/spec.md
 */
class NoticeRecipientNormaliser implements NoticeRecipientNormaliserInterface {

	/**
	 * A field or claim name.
	 *
	 * @var string
	 */
	private const NAME = '/^[A-Za-z_][A-Za-z0-9_-]*$/';

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
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md
	 */
	public function refusal(array $rule, array $collection, string $appId, string $label): ?string {
		if (array_key_exists('recipients', $rule) === true && $this->claimOf(recipients: $rule['recipients'], appId: $appId) === null) {
			return $label.': recipients need a field and a claim of the app itself, not of another app';
		}

		if (array_key_exists('messages', $rule) === false) {
			return null;
		}

		$reason = $this->messagesRefusal(messages: $rule['messages'], collection: $collection);
		if ($reason === null) {
			return null;
		}

		return $label.': '.$reason;
	}//end refusal()

	/**
	 * Why the rule's messages cannot be kept, or null: a non-empty map whose
	 * every message can be kept.
	 *
	 * @param mixed                $messages   The declared messages.
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return string|null
	 */
	private function messagesRefusal(mixed $messages, array $collection): ?string {
		if (is_array($messages) === false || $messages === []) {
			return 'messages must map a value to a subject and a body';
		}

		foreach ($messages as $message) {
			$reason = $this->messageRefusal(message: $message, collection: $collection);
			if ($reason !== null) {
				return $reason;
			}
		}

		return null;
	}//end messagesRefusal()

	/**
	 * The rule's recipients and messages, shaped for the listener. Call only
	 * after refusal() returned null.
	 *
	 * @param array<string, mixed> $rule  The rule.
	 * @param string               $appId The contributing app.
	 *
	 * @return array<string, mixed> `recipients` and `messages`, each only when declared.
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md
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

		return $this->bareClaim(claim: $claim, appId: $appId);
	}//end claimOf()

	/**
	 * A claim name without the app's own prefix (`dossiq.kvk` is `kvk` for
	 * dossiq), or null when it is another app's or not a name.
	 *
	 * @param string $claim The declared claim.
	 * @param string $appId The contributing app.
	 *
	 * @return string|null
	 */
	private function bareClaim(string $claim, string $appId): ?string {
		$parts = $this->withoutOwnPrefix(parts: explode('.', $claim), appId: $appId);
		if (count($parts) !== 1 || $this->isName(value: $parts[0]) === false) {
			return null;
		}

		return $parts[0];
	}//end bareClaim()

	/**
	 * A claim's dotted parts with the app's own prefix taken off.
	 *
	 * @param array<int, string> $parts The claim, split on dots.
	 * @param string             $appId The contributing app.
	 *
	 * @return array<int, string>
	 */
	private function withoutOwnPrefix(array $parts, string $appId): array {
		if (count($parts) === 2 && $parts[0] === $appId && $appId !== '') {
			return [$parts[1]];
		}

		return $parts;
	}//end withoutOwnPrefix()

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
			$reason = $this->partRefusal(texts: ($message[$part] ?? null), collection: $collection);
			if ($reason !== null) {
				return $reason;
			}
		}

		return null;
	}//end messageRefusal()

	/**
	 * Why a message's subject or body cannot be kept, or null: one text or a
	 * non-empty list of texts that can each be kept.
	 *
	 * @param mixed                $texts      The declared subject or body.
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return string|null
	 */
	private function partRefusal(mixed $texts, array $collection): ?string {
		$list = $this->textsOf(texts: $texts);
		if ($list === null) {
			return 'a message needs a subject and a body';
		}

		foreach ($list as $text) {
			$reason = $this->textRefusal(text: $text, collection: $collection);
			if ($reason !== null) {
				return $reason;
			}
		}

		return null;
	}//end partRefusal()

	/**
	 * A subject or body as its list of texts: one text is a list of one,
	 * and anything but a non-empty list is null.
	 *
	 * @param mixed $texts The declared subject or body.
	 *
	 * @return array<int|string, mixed>|null
	 */
	private function textsOf(mixed $texts): ?array {
		if (is_string($texts) === true) {
			return [$texts];
		}

		if (is_array($texts) === false || $texts === []) {
			return null;
		}

		return $texts;
	}//end textsOf()

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
