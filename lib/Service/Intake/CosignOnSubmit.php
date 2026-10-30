<?php

/**
 * Portaliq Cosign On Submit
 *
 * The two moments of a co-signed submit: before the case is created, the
 * entry it is created with (or the field to refuse); after, the mail with
 * the sign link to the person the form named (decision 271).
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
 * @spec openspec/changes/resident-identity-in-forms/specs/resident-identity-in-forms/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use OCA\Portaliq\Service\PortalDeepLinkBuilder;
use OCP\IL10N;

/**
 * Builds the co-sign entry for a submit, and invites the co-signer after it.
 *
 * @spec openspec/changes/resident-identity-in-forms/specs/resident-identity-in-forms/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */
class CosignOnSubmit {
	/**
	 * Constructor.
	 *
	 * @param CosignInvitation $invitation The entry and the signed link.
	 * @param CosignInviteMailer $mailer Mails the link.
	 * @param PortalDeepLinkBuilder $links The portal's address.
	 * @param IL10N $l10n The sentence a refused field is given with.
	 */
	public function __construct(
		private readonly CosignInvitation $invitation,
		private readonly CosignInviteMailer $mailer,
		private readonly PortalDeepLinkBuilder $links,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The server values to create the case with, or the per-field refusal.
	 *
	 * @param array<string, mixed> $binding The binding.
	 * @param array<string, mixed> $answers The checked answers.
	 *
	 * @return array{server: array<string, mixed>, errors: array<string, string>}
	 *         `server` is empty for a form without a co-signer.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/resident-identity-in-forms/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	public function prepare(array $binding, array $answers): array {
		$settings = $this->invitation->settings(binding: $binding);
		if ($settings === null) {
			return ['server' => [], 'errors' => []];
		}

		$entry = $this->invitation->entry(settings: $settings, answers: $answers);
		if (is_string($entry) === true) {
			return ['server' => [], 'errors' => [$entry => $this->l10n->t('Fill in the name and e-mail address of the person who co-signs.')]];
		}

		return ['server' => [CosignInvitation::SERVER_FIELD => [$entry]], 'errors' => []];
	}//end prepare()

	/**
	 * Mail the co-signer the link, once the case exists.
	 *
	 * @param array<string, mixed> $site The portal.
	 * @param array<string, mixed> $binding The binding.
	 * @param array<string, mixed> $server What prepare() answered as `server`.
	 * @param array<string, mixed> $answer The destination's answer: id and reference.
	 * @param string $subjectRef The applicant's subject.
	 *
	 * @return array{holderName: string, dueAt: string, state: string, invited: bool}|null Null for a form without a co-signer.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/resident-identity-in-forms/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	public function invite(array $site, array $binding, array $server, array $answer, string $subjectRef): ?array {
		$entry = ($server[CosignInvitation::SERVER_FIELD][0] ?? null);
		if (is_array($entry) === false) {
			return null;
		}

		$slug  = (string)($site['slug'] ?? '');
		$token = $this->invitation->token(
			claims: [
				'portal' => $slug,
				'route' => (string)($binding['route'] ?? ''),
				'case' => (string)($answer['id'] ?? ''),
				'reference' => (string)($answer['reference'] ?? ''),
				'kind' => (string)$entry['kind'],
				'title' => (string)$entry['title'],
				'formName' => (string)($binding['formName'] ?? ''),
				'holderName' => (string)$entry['holderName'],
				'dueAt' => (string)$entry['dueAt'],
				'applicant' => $subjectRef,
			]
		);

		$invited = $this->mailer->send(
			invite: [
				'email' => (string)$entry['holderEmail'],
				'holderName' => (string)$entry['holderName'],
				'formName' => (string)($binding['formName'] ?? ''),
				'link' => $this->links->forSite(portalSlug: $slug, organisation: (string)($site['organisation'] ?? '')).'#cosign='.$token,
				'dueAt' => (string)$entry['dueAt'],
			],
			site: $site
		);

		return ['holderName' => (string)$entry['holderName'], 'dueAt' => (string)$entry['dueAt'], 'state' => 'awaiting', 'invited' => $invited];
	}//end invite()
}//end class
