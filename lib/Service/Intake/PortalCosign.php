<?php

/**
 * Portaliq Portal Cosign
 *
 * A form that needs a second signature holds the submission until a second
 * person, signed in with their own DigiD, co-signs it. The co-signer gets a
 * short code; only its hash is stored. Signing releases the submission to the
 * delivery drain with the co-signature on it; refusing, or letting the
 * deadline pass, stops it for good.
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
use OCA\Portaliq\Kernel\Contracts\PortalCosignInterface;
use OCP\Security\ISecureRandom;

/**
 * Holds, signs, refuses and lapses co-signatures on submissions.
 *
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */
class PortalCosign implements PortalCosignInterface {

	/**
	 * The code characters: upper case and digits without the look-alikes (0 O 1 I 2 Z 5 S 8 B).
	 *
	 * @var string
	 */
	public const ALPHABET = 'ACDEFGHJKLMNPQRTUVWXY34679';

	/**
	 * The default number of days to co-sign.
	 *
	 * @var int
	 */
	public const DEFAULT_DEADLINE_DAYS = 14;

	/**
	 * Constructor.
	 *
	 * @param PortalIntakeQueue $queue  Holds the submissions.
	 * @param ISecureRandom     $random Draws the codes.
	 */
	public function __construct(
		private readonly PortalIntakeQueue $queue,
		private readonly ISecureRandom $random,
	) {
	}//end __construct()

	/**
	 * Whether a form binding asks for a co-signer.
	 *
	 * @param array<string, mixed> $binding The form binding.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	public function required(array $binding): bool {
		$cosign = $binding['cosign'] ?? null;
		return is_array($cosign) === true && ($cosign['required'] ?? false) === true;
	}//end required()

	/**
	 * Hold a submission for its co-signer and draw the code the invitation carries.
	 *
	 * @param QueuedSubmission  $submission   The checked submission.
	 * @param string            $email        The co-signer's address, from the form.
	 * @param string            $name         The co-signer's name, for the waiting line.
	 * @param int               $deadlineDays Days the co-signer has; 14 when not positive.
	 * @param DateTimeImmutable $now          The moment of submitting.
	 * @param string            $notify       The submitter's address, told on a refusal or a lapse.
	 *
	 * @return array{reference: string, state: string, code: string, deadline: string}|null Null when the address is missing or nothing was recorded.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	public function hold(
		QueuedSubmission $submission,
		string $email,
		string $name,
		int $deadlineDays,
		DateTimeImmutable $now,
		string $notify = ''
	): ?array {
		$email = trim($email);
		if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			return null;
		}

		if ($deadlineDays <= 0) {
			$deadlineDays = self::DEFAULT_DEADLINE_DAYS;
		}

		$code     = $this->code();
		$deadline = $now->modify('+' . $deadlineDays . ' days')->format(DATE_ATOM);
		$held     = $this->queue->hold(
			submission: $submission,
			cosign: [
				'state' => self::AWAITING,
				'email' => $email,
				'name' => trim($name),
				'codeHash' => self::hash(code: $code),
				'deadline' => $deadline,
				'notify' => trim($notify),
			]
		);
		if ($held === null) {
			return null;
		}

		return $held + ['code' => $code, 'deadline' => $deadline];
	}//end hold()

	/**
	 * The resident's own requests on a portal that wait for a co-signer or were not co-signed.
	 *
	 * @param string $subjectRef The resident's own portal identity.
	 * @param string $portal     The portal's slug.
	 *
	 * @return array<int, array<string, mixed>> Each with reference, route, submittedAt, state and the cosign line.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	public function heldBy(string $subjectRef, string $portal): array {
		return $this->queue->heldFor(subjectRef: $subjectRef, portal: $portal);
	}//end heldBy()

	/**
	 * The held submission behind a code, read-only, for the co-signer's review page.
	 *
	 * @param string            $code       The code the co-signer typed.
	 * @param string            $subjectRef The co-signer's DigiD subject.
	 * @param DateTimeImmutable $now        The moment.
	 *
	 * @return array{ok: bool, reason: string, submission: array<string, mixed>}
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	public function open(string $code, string $subjectRef, DateTimeImmutable $now): array {
		$found = $this->admit(code: $code, subjectRef: $subjectRef, now: $now);
		if ($found['ok'] === false) {
			return $found;
		}

		$row = $found['submission'];
		return [
			'ok' => true,
			'reason' => '',
			'submission' => [
				'reference' => (string)($row['reference'] ?? ''),
				'portal' => (string)($row['portal'] ?? ''),
				'route' => (string)($row['route'] ?? ''),
				'answers' => (array)($row['answers'] ?? []),
				'submittedAt' => (string)($row['submittedAt'] ?? ''),
				'deadline' => (string)($row['cosign']['deadline'] ?? ''),
			],
		];
	}//end open()

	/**
	 * Co-sign: record who signed, when and at what assurance, and release the submission for delivery.
	 *
	 * @param string            $code             The code.
	 * @param string            $subjectRef       The co-signer's DigiD subject.
	 * @param string            $levelOfAssurance The level the sign-in stated.
	 * @param DateTimeImmutable $now              The moment.
	 *
	 * @return array{ok: bool, reason: string, reference: string, portal?: string, cosign?: array<string, mixed>}
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	public function sign(string $code, string $subjectRef, string $levelOfAssurance, DateTimeImmutable $now): array {
		return $this->answer(
			code: $code,
			subjectRef: $subjectRef,
			now: $now,
			change: [
				'state' => self::SIGNED,
				'signedAt' => $now->format(DATE_ATOM),
				'cosignerSubject' => $subjectRef,
				'levelOfAssurance' => $levelOfAssurance,
			]
		);
	}//end sign()

	/**
	 * Refuse to co-sign: the submission is not delivered.
	 *
	 * @param string            $code       The code.
	 * @param string            $subjectRef The co-signer's DigiD subject.
	 * @param DateTimeImmutable $now        The moment.
	 *
	 * @return array{ok: bool, reason: string, reference: string, portal?: string, cosign?: array<string, mixed>}
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	public function refuse(string $code, string $subjectRef, DateTimeImmutable $now): array {
		return $this->answer(
			code: $code,
			subjectRef: $subjectRef,
			now: $now,
			change: ['state' => self::REFUSED, 'refusedAt' => $now->format(DATE_ATOM)]
		);
	}//end refuse()

	/**
	 * Lapse every held submission whose deadline has passed.
	 *
	 * @param DateTimeImmutable $now     The moment.
	 * @param callable|null     $onLapse Called with each submission row that lapsed, to tell its submitter.
	 *
	 * @return array<int, string> The references that lapsed.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	public function expire(DateTimeImmutable $now, ?callable $onLapse = null): array {
		$lapsed = [];
		foreach ($this->queue->awaitingCosign() as $row) {
			if ($this->isPastDeadline(row: $row, now: $now) === true && $this->lapse(row: $row, now: $now) === true) {
				$lapsed[] = (string)($row['reference'] ?? '');
				if ($onLapse !== null) {
					$onLapse($row);
				}
			}
		}

		return $lapsed;
	}//end expire()

	/**
	 * A code as typed, compared without case, spaces or the dash.
	 *
	 * @param string $code The typed code.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	public static function normalise(string $code): string {
		return (string)preg_replace('/[^A-Z0-9]/', '', strtoupper($code));
	}//end normalise()

	/**
	 * The stored form of a code.
	 *
	 * @param string $code The code.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	public static function hash(string $code): string {
		return hash('sha256', self::normalise(code: $code));
	}//end hash()

	/**
	 * Six characters in two groups, such as `7RQ-K2M`.
	 *
	 * @return string
	 */
	private function code(): string {
		$raw = $this->random->generate(6, self::ALPHABET);
		return substr($raw, 0, 3) . '-' . substr($raw, 3, 3);
	}//end code()

	/**
	 * Store an answer on the held submission behind a code.
	 *
	 * @param string               $code       The code.
	 * @param string               $subjectRef The co-signer's subject.
	 * @param DateTimeImmutable    $now        The moment.
	 * @param array<string, mixed> $change     The co-sign fields to set.
	 *
	 * @return array{ok: bool, reason: string, reference: string, portal?: string, cosign?: array<string, mixed>}
	 */
	private function answer(string $code, string $subjectRef, DateTimeImmutable $now, array $change): array {
		$found = $this->admit(code: $code, subjectRef: $subjectRef, now: $now);
		if ($found['ok'] === false) {
			return ['ok' => false, 'reason' => $found['reason'], 'reference' => ''];
		}

		$row    = $found['submission'];
		$cosign = array_merge((array)$row['cosign'], $change);
		if ($this->queue->answerCosign(submission: $row, cosign: $cosign) === false) {
			return ['ok' => false, 'reason' => 'unavailable', 'reference' => ''];
		}

		return [
			'ok' => true,
			'reason' => '',
			'reference' => (string)($row['reference'] ?? ''),
			'portal' => (string)($row['portal'] ?? ''),
			'cosign' => $cosign,
		];
	}//end answer()

	/**
	 * The held submission a code and a co-signer may act on, or why not.
	 *
	 * Refused: no subject, an unknown code, a lapsed deadline (which is
	 * recorded), and the submitter's own subject.
	 *
	 * @param string            $code       The code.
	 * @param string            $subjectRef The co-signer's subject.
	 * @param DateTimeImmutable $now        The moment.
	 *
	 * @return array{ok: bool, reason: string, submission: array<string, mixed>}
	 */
	private function admit(string $code, string $subjectRef, DateTimeImmutable $now): array {
		if (trim($subjectRef) === '') {
			return ['ok' => false, 'reason' => 'no-subject', 'submission' => []];
		}

		$row = $this->byCode(code: $code);
		if ($row === null) {
			return ['ok' => false, 'reason' => 'unknown', 'submission' => []];
		}

		if ($this->isPastDeadline(row: $row, now: $now) === true) {
			$this->lapse(row: $row, now: $now);
			return ['ok' => false, 'reason' => 'expired', 'submission' => []];
		}

		if ((string)($row['subjectRef'] ?? '') === $subjectRef) {
			return ['ok' => false, 'reason' => 'same-subject', 'submission' => []];
		}

		return ['ok' => true, 'reason' => '', 'submission' => $row];
	}//end admit()

	/**
	 * The held submission whose code hash matches.
	 *
	 * @param string $code The code.
	 *
	 * @return array<string, mixed>|null
	 */
	private function byCode(string $code): ?array {
		if (strlen(self::normalise(code: $code)) !== 6) {
			return null;
		}

		$hash = self::hash(code: $code);
		foreach ($this->queue->awaitingCosign() as $row) {
			$cosign = $row['cosign'] ?? null;
			if (is_array($cosign) === true && hash_equals((string)($cosign['codeHash'] ?? ''), $hash) === true) {
				return $row;
			}
		}

		return null;
	}//end byCode()

	/**
	 * Whether a held submission's deadline has passed; an unreadable deadline has.
	 *
	 * @param array<string, mixed> $row The submission.
	 * @param DateTimeImmutable    $now The moment.
	 *
	 * @return bool
	 */
	private function isPastDeadline(array $row, DateTimeImmutable $now): bool {
		$deadline = strtotime((string)($row['cosign']['deadline'] ?? ''));
		return $deadline === false || $deadline <= $now->getTimestamp();
	}//end isPastDeadline()

	/**
	 * Record that the deadline passed.
	 *
	 * @param array<string, mixed> $row The submission.
	 * @param DateTimeImmutable    $now The moment.
	 *
	 * @return bool Whether it was written.
	 */
	private function lapse(array $row, DateTimeImmutable $now): bool {
		return $this->queue->answerCosign(
			submission: $row,
			cosign: array_merge((array)$row['cosign'], ['state' => self::EXPIRED, 'expiredAt' => $now->format(DATE_ATOM)])
		);
	}//end lapse()
}//end class
