<?php

/**
 * Contract of MailTemplateRenderer
 *
 * The public surface of MailTemplateRenderer that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Portaliq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel\Contracts;

/**
 * Contract of MailTemplateRenderer.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MailTemplateRendererInterface {

	/**
	 * Each kind of mail and the variables its text may use.
	 *
	 * @var array<string, array{label: string, variables: string[], sample: array<string, string>}>
	 */
	public const TEMPLATES = [
		'reference-link' => [
			'label' => 'Link to follow a case',
			'variables' => ['portal', 'link'],
			'sample' => [
				'portal' => 'Gemeente Voorbeeld',
				'link' => 'https://portaal.example/mijn',
			],
		],
		'invitation' => [
			'label' => 'Invitation',
			'variables' => ['portal', 'link'],
			'sample' => [
				'portal' => 'Gemeente Voorbeeld',
				'link' => 'https://portaal.example/mijn',
			],
		],
		'account-invitation' => [
			'label' => 'Invitation to an account',
			'variables' => ['portal', 'link'],
			'sample' => [
				'portal' => 'Gemeente Voorbeeld',
				'link' => 'https://portaal.example/mijn',
			],
		],
		'email-confirmation' => [
			'label' => 'Confirm an e-mail address',
			'variables' => ['portal', 'link'],
			'sample' => [
				'portal' => 'Gemeente Voorbeeld',
				'link' => 'https://portaal.example/mijn',
			],
		],
		'registration-activation' => [
			'label' => 'Activate an account',
			'variables' => ['portal', 'link'],
			'sample' => [
				'portal' => 'Gemeente Voorbeeld',
				'link' => 'https://portaal.example/mijn',
			],
		],
		'contact-confirmation' => [
			'label' => 'Confirmation of a question',
			'variables' => ['portal', 'topic'],
			'sample' => [
				'portal' => 'Gemeente Voorbeeld',
				'topic' => 'Afval',
			],
		],
		'form-email-code' => [
			'label' => 'Code to check an e-mail address',
			'variables' => ['portal', 'code'],
			'sample' => [
				'portal' => 'Gemeente Voorbeeld',
				'code' => '482915',
			],
		],
		'form-confirmation' => [
			'label' => 'Confirmation of a request',
			'variables' => ['portal', 'reference', 'formName'],
			'sample' => [
				'portal' => 'Gemeente Voorbeeld',
				'reference' => 'AANVRAAG-7',
				'formName' => 'Woo-verzoek',
			],
		],
		'plan-ending' => [
			'label' => 'A plan is nearly ending',
			'variables' => ['portal', 'plan', 'date', 'open'],
			'sample' => [
				'portal' => 'Gemeente Voorbeeld',
				'plan' => 'Schuldhulp op orde',
				'date' => '2026-10-20',
				'open' => '3',
			],
		],
	];

	/**
	 * Whether a key names a kind of mail.
	 *
	 * @param string $key The template key.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t02
	 */
	public function knows(string $key): bool;

	/**
	 * A text filled with sample values, for the preview and the test mail.
	 *
	 * @param string $key  The template key.
	 * @param string $text The subject or the text.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t04
	 */
	public function preview(string $key, string $text): string;

	/**
	 * The variables used in a text that its kind does not declare.
	 *
	 * @param string $key   The template key.
	 * @param string ...$texts The subject and the text.
	 *
	 * @return string[] The unknown variable names, once each; every name when the kind is unknown.
	 *
	 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t02
	 */
	public function unknownVariables(string $key, string ...$texts): array;
}//end interface
