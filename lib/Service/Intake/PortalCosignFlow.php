<?php

/**
 * Portaliq Portal Cosign Flow
 *
 * The co-sign steps around the core in PortalCosign: holding a submission at
 * submit and inviting the co-signer the form names, and telling the submitter
 * when the co-signer refused or the deadline passed.
 *
 * @category Service
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
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use DateTimeImmutable;
use OCP\IL10N;

/**
 * Holds a submission for its co-signer and mails both sides.
 *
 * The co-signer is the answer to the form's `email` field marked
 * `cosigner: true`; their name, for the submitter's waiting line, is the
 * answer to a field marked `cosignerName: true`.
 *
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */
class PortalCosignFlow {

	/**
	 * Constructor.
	 *
	 * @param PortalCosign     $cosign The co-sign core.
	 * @param FormCosignMailer $mailer Mails the invitation and the notice.
	 * @param IL10N            $l10n   Words the field error.
	 */
	public function __construct(
		private readonly PortalCosign $cosign,
		private readonly FormCosignMailer $mailer,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Whether the binding makes its submissions wait for a co-signer.
	 *
	 * @param array<string, mixed> $binding The form binding.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	public function required(array $binding): bool {
		return $this->cosign->required(binding: $binding);
	}//end required()

	/**
	 * Hold a checked submission for its co-signer and mail the invitation.
	 *
	 * @param array<string, mixed> $site       The portal.
	 * @param array<string, mixed> $binding    The form binding.
	 * @param array<string, mixed> $render     The rendered form.
	 * @param QueuedSubmission     $submission The checked submission.
	 * @param DateTimeImmutable    $now        The moment of submitting.
	 *
	 * @return array{ok: bool, errors?: array<string, string>, reference?: string, state?: string, cosign?: array<string, string>, invited?: bool}
	 *         Field errors when the form names no co-signer address, `ok: false` without errors when nothing was recorded.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	public function holdAtSubmit(array $site, array $binding, array $render, QueuedSubmission $submission, DateTimeImmutable $now): array {
		$fields  = (array)($render['fields'] ?? []);
		$invitee = self::invitee(fields: $fields, answers: $submission->answers);
		if ($invitee['email'] === '') {
			return ['ok' => false, 'errors' => [$invitee['field'] => $this->l10n->t('Fill in the e-mail address of the person who co-signs.')]];
		}

		$held = $this->cosign->hold(
			submission: $submission,
			email: $invitee['email'],
			name: $invitee['name'],
			deadlineDays: (int)($binding['cosign']['deadlineDays'] ?? 0),
			now: $now,
			notify: self::submitterAddress(fields: $fields, answers: $submission->answers)
		);
		if ($held === null) {
			return ['ok' => false];
		}

		$invited = $this->mailer->invite(
			email: $invitee['email'],
			code: $held['code'],
			deadline: $held['deadline'],
			formName: (string)($render['formName'] ?? ''),
			site: $site
		);

		return [
			'ok' => true,
			'reference' => $held['reference'],
			'state' => $held['state'],
			'cosign' => ['state' => PortalCosign::AWAITING, 'name' => $invitee['name'], 'deadline' => $held['deadline']],
			'invited' => $invited,
		];
	}//end holdAtSubmit()

	/**
	 * Refuse as the co-signer, and tell the submitter.
	 *
	 * @param array<string, mixed> $site       The portal.
	 * @param string               $code       The code.
	 * @param string               $subjectRef The co-signer's subject.
	 * @param DateTimeImmutable    $now        The moment.
	 *
	 * @return array{ok: bool, reason: string, reference: string}
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	public function refuse(array $site, string $code, string $subjectRef, DateTimeImmutable $now): array {
		$refused = $this->cosign->refuse(code: $code, subjectRef: $subjectRef, now: $now);
		if ($refused['ok'] === true) {
			$this->tell(site: $site, reference: $refused['reference'], cosign: (array)($refused['cosign'] ?? []));
		}

		return ['ok' => $refused['ok'], 'reason' => $refused['reason'], 'reference' => $refused['reference']];
	}//end refuse()

	/**
	 * Lapse what is past its deadline and tell each submitter.
	 *
	 * @param DateTimeImmutable $now The moment.
	 *
	 * @return array<int, string> The references that lapsed.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	public function lapse(DateTimeImmutable $now): array {
		return $this->cosign->expire(
			now: $now,
			onLapse: function (array $row): void {
				$this->tell(
					site: ['slug' => (string)($row['portal'] ?? '')],
					reference: (string)($row['reference'] ?? ''),
					cosign: ['state' => PortalCosign::EXPIRED] + (array)($row['cosign'] ?? [])
				);
			}
		);
	}//end lapse()

	/**
	 * The co-signer the form names: the address, its field and the name.
	 *
	 * @param array<int, mixed>    $fields  The form's fields.
	 * @param array<string, mixed> $answers The answers.
	 *
	 * @return array{email: string, field: string, name: string}
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	public static function invitee(array $fields, array $answers): array {
		$found = ['email' => '', 'field' => 'cosigner', 'name' => ''];
		foreach ($fields as $field) {
			if (is_array($field) === false) {
				continue;
			}

			$name   = (string)($field['name'] ?? '');
			$answer = self::answerOf(answers: $answers, name: $name);
			if (($field['cosignerName'] ?? false) === true && $found['name'] === '') {
				$found['name'] = $answer;
			}

			if (($field['type'] ?? '') === 'email' && ($field['cosigner'] ?? false) === true && $found['email'] === '') {
				$found['field'] = $name;
				if (filter_var($answer, FILTER_VALIDATE_EMAIL) !== false) {
					$found['email'] = $answer;
				}
			}
		}

		return $found;
	}//end invitee()

	/**
	 * The submitter's own address: the first valid answer to an `email` field that is not the co-signer's.
	 *
	 * @param array<int, mixed>    $fields  The form's fields.
	 * @param array<string, mixed> $answers The answers.
	 *
	 * @return string The address, or ''.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	public static function submitterAddress(array $fields, array $answers): string {
		foreach ($fields as $field) {
			if (is_array($field) === false || ($field['type'] ?? '') !== 'email' || ($field['cosigner'] ?? false) === true) {
				continue;
			}

			$answer = self::answerOf(answers: $answers, name: (string)($field['name'] ?? ''));
			if (filter_var($answer, FILTER_VALIDATE_EMAIL) !== false) {
				return $answer;
			}
		}

		return '';
	}//end submitterAddress()

	/**
	 * A text answer, trimmed; '' for anything else.
	 *
	 * @param array<string, mixed> $answers The answers.
	 * @param string               $name    The field name.
	 *
	 * @return string
	 */
	private static function answerOf(array $answers, string $name): string {
		$answer = $answers[$name] ?? null;
		if (is_string($answer) === false) {
			return '';
		}

		return trim($answer);
	}//end answerOf()

	/**
	 * Mail the submitter that the request was not co-signed, when an address was kept.
	 *
	 * @param array<string, mixed> $site      The portal.
	 * @param string               $reference The reference.
	 * @param array<string, mixed> $cosign    The co-sign block, with `state` and `notify`.
	 *
	 * @return void
	 */
	private function tell(array $site, string $reference, array $cosign): void {
		$notify = trim((string)($cosign['notify'] ?? ''));
		if ($notify === '') {
			return;
		}

		$this->mailer->ended(email: $notify, reference: $reference, state: (string)($cosign['state'] ?? ''), site: $site);
	}//end tell()
}//end class
