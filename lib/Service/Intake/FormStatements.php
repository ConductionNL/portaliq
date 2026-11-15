<?php

/**
 * Portaliq Form Statements (form-statements-intro-and-confirmation-mail)
 *
 * The statements a resident accepts on the review step: a statement of truth
 * and the privacy consent. A binding names which are asked and which are
 * required; the portal owns the wording and its version. A submission records
 * per accepted statement the key, the version of the text and the moment, so
 * "accepted" always means a text that can be shown again.
 *
 * @category Intake
 * @package  OCA\Portaliq\Service\Intake
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
 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t03
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use DateTimeImmutable;
use OCA\Portaliq\Kernel\Contracts\FormStatementsInterface;
use OCP\IL10N;

/**
 * Resolves, checks and records the statements of a form.
 *
 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t03
 */
class FormStatements implements FormStatementsInterface {
	/**
	 * The statements a binding may ask.
	 *
	 * @var string[]
	 */
	public const KEYS = ['truth', 'privacy'];

	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n The sentences a refusal is given with.
	 */
	public function __construct(private readonly IL10N $l10n) {
	}//end __construct()

	/**
	 * The statements a form asks, with the portal's wording.
	 *
	 * A statement is listed when the binding names it. Its text and version
	 * come from the portal; a missing text is kept empty so the check can
	 * refuse a required statement nobody can read.
	 *
	 * @param mixed                $declared The binding's `statements`.
	 * @param array<string, mixed> $site     The portal.
	 *
	 * @return array<int, array{key: string, required: bool, text: string, version: string}>
	 *
	 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t03
	 */
	public function asked(mixed $declared, array $site): array {
		if (is_array($declared) === false) {
			return [];
		}

		$texts = (array)($site['statementTexts'] ?? []);
		$out   = [];
		foreach (self::KEYS as $key) {
			if (is_array($declared[$key] ?? null) === false) {
				continue;
			}

			$entry = (array)($texts[$key] ?? []);
			$out[] = [
				'key' => $key,
				'required' => (($declared[$key]['required'] ?? false) === true),
				'text' => trim((string)($entry['text'] ?? '')),
				'version' => trim((string)($entry['version'] ?? '')),
			];
		}

		return $out;
	}//end asked()

	/**
	 * Check what the resident accepted against what the form asks.
	 *
	 * A required statement that was not accepted, or that has no text to show,
	 * is an error; a statement accepted that the form does not ask is ignored,
	 * so nothing the client invents is recorded.
	 *
	 * @param array<int, array{key: string, required: bool, text: string, version: string}> $asked    The asked statements.
	 * @param mixed                                                                         $accepted The keys the client says were ticked.
	 *
	 * @return array{errors: array<string, string>, record: array<int, array{key: string, textVersion: string, acceptedAt: string}>}
	 *
	 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t03
	 */
	public function check(array $asked, mixed $accepted): array {
		$ticked = $this->ticked(accepted: $accepted);

		return ['errors' => $this->errorsFor(asked: $asked, ticked: $ticked), 'record' => $this->recordFor(asked: $asked, ticked: $ticked)];
	}//end check()

	/**
	 * The error of every statement that stops the form, by key.
	 *
	 * @param array<int, array{key: string, required: bool, text: string, version: string}> $asked  The asked statements.
	 * @param array<string, true>                                                           $ticked The ticked keys.
	 *
	 * @return array<string, string>
	 */
	private function errorsFor(array $asked, array $ticked): array {
		$errors = [];
		foreach ($asked as $statement) {
			$error = $this->errorFor(statement: $statement, ticked: $ticked);
			if ($error !== null) {
				$errors[$statement['key']] = $error;
			}
		}

		return $errors;
	}//end errorsFor()

	/**
	 * What is recorded as accepted: every ticked statement with text to show, now.
	 * Ticked but unreadable: nothing is recorded as accepted.
	 *
	 * @param array<int, array{key: string, required: bool, text: string, version: string}> $asked  The asked statements.
	 * @param array<string, true>                                                           $ticked The ticked keys.
	 *
	 * @return array<int, array{key: string, textVersion: string, acceptedAt: string}>
	 */
	private function recordFor(array $asked, array $ticked): array {
		$now    = (new DateTimeImmutable())->format(DATE_ATOM);
		$record = [];
		foreach ($asked as $statement) {
			if (isset($ticked[$statement['key']]) === true && $statement['text'] !== '') {
				$record[] = ['key' => $statement['key'], 'textVersion' => $statement['version'], 'acceptedAt' => $now];
			}
		}

		return $record;
	}//end recordFor()

	/**
	 * The keys the client says were ticked, as a set; anything but a string is ignored.
	 *
	 * @param mixed $accepted The keys the client says were ticked.
	 *
	 * @return array<string, true>
	 */
	private function ticked(mixed $accepted): array {
		$ticked = [];
		foreach ((array)$accepted as $key) {
			if (is_string($key) === true) {
				$ticked[$key] = true;
			}
		}

		return $ticked;
	}//end ticked()

	/**
	 * Why a statement stops the form: a required one with no text to show,
	 * or a required one not accepted; null when it does not.
	 *
	 * @param array{key: string, required: bool, text: string, version: string} $statement The asked statement.
	 * @param array<string, true>                                                $ticked    The ticked keys.
	 *
	 * @return string|null
	 */
	private function errorFor(array $statement, array $ticked): ?string {
		if ($statement['required'] === false) {
			return null;
		}

		if ($statement['text'] === '') {
			return $this->l10n->t('This form cannot be sent yet: a statement it needs has no text.');
		}

		if (isset($ticked[$statement['key']]) === false) {
			return $this->l10n->t('Accept this statement before you send the form.');
		}

		return null;
	}//end errorFor()
}//end class
