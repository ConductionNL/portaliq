<?php

/**
 * Portaliq Mail Template Renderer (mail-templates-admin-screen)
 *
 * @category Mail
 * @package  OCA\Portaliq\Service\Mail
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
 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t02
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Mail;

use OCA\Portaliq\Service\PortalObjectReader;

/**
 * The kinds of mail a portal sends, their variables, and the text a portal
 * stored to replace the default.
 *
 * The code keeps the default text; a stored, active `portalMailTemplate` of
 * the portal replaces it, for that portal only. A variable is replaced as
 * plain text, and only the variables a kind declares are replaced. No kind
 * declares a variable that carries a field value of a record.
 *
 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t02
 */
class MailTemplateRenderer {
	/**
	 * The register and schema of a stored text.
	 */
	public const REGISTER = 'portaliq';

	/**
	 * The schema of a stored text.
	 */
	public const SCHEMA = 'portalMailTemplate';

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
		'form-confirmation' => [
			'label' => 'Confirmation of a request',
			'variables' => ['portal', 'reference', 'formName'],
			'sample' => [
				'portal' => 'Gemeente Voorbeeld',
				'reference' => 'AANVRAAG-7',
				'formName' => 'Woo-verzoek',
			],
		],
	];

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader Reads the stored texts of a portal.
	 */
	public function __construct(private readonly PortalObjectReader $reader) {
	}//end __construct()

	/**
	 * Whether a key names a kind of mail.
	 *
	 * @param string $key The template key.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t02
	 */
	public function knows(string $key): bool {
		return isset(self::TEMPLATES[$key]);
	}//end knows()

	/**
	 * The subject and text to send: the portal's stored text when it has an
	 * active one for this kind, otherwise the default given.
	 *
	 * @param string                $portal  The portal slug.
	 * @param string                $key     The template key.
	 * @param array<string, string> $values  The variables' values.
	 * @param string                $subject The default subject line.
	 * @param string                $body    The default text.
	 *
	 * @return array{subject: string, body: string, overridden: bool}
	 *
	 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t02
	 */
	public function render(string $portal, string $key, array $values, string $subject, string $body): array {
		$stored = $this->stored(portal: $portal, key: $key);
		if ($stored === null) {
			return ['subject' => $subject, 'body' => $body, 'overridden' => false];
		}

		return [
			// One line: a stored subject never carries a line break into a mail header.
			'subject' => trim((string)preg_replace('/\s+/', ' ', $this->fill(text: $stored['subject'], key: $key, values: $values))),
			'body' => $this->fill(text: $stored['body'], key: $key, values: $values),
			'overridden' => true,
		];
	}//end render()

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
	public function unknownVariables(string $key, string ...$texts): array {
		return $this->undeclared(key: $key, texts: $texts);
	}//end unknownVariables()

	/**
	 * The variables in the texts that the kind does not declare.
	 *
	 * @param string   $key   The template key.
	 * @param string[] $texts The texts.
	 *
	 * @return string[]
	 */
	private function undeclared(string $key, array $texts): array {
		$allowed = (self::TEMPLATES[$key]['variables'] ?? []);
		$unknown = [];
		foreach ($texts as $text) {
			preg_match_all('/\{([A-Za-z][A-Za-z0-9_]*)\}/', $text, $found);
			foreach ($found[1] as $name) {
				if (in_array($name, $allowed, true) === false && in_array($name, $unknown, true) === false) {
					$unknown[] = $name;
				}
			}
		}

		return $unknown;
	}//end undeclared()

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
	public function preview(string $key, string $text): string {
		return $this->fill(text: $text, key: $key, values: (self::TEMPLATES[$key]['sample'] ?? []));
	}//end preview()

	/**
	 * The active stored text of a portal for a kind, or null.
	 *
	 * @param string $portal The portal slug.
	 * @param string $key    The template key.
	 *
	 * @return array{subject: string, body: string}|null
	 */
	private function stored(string $portal, string $key): ?array {
		if ($portal === '' || $this->knows(key: $key) === false) {
			return null;
		}

		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'portal',
			subjectRef: $portal,
			organisation: '',
			limit: 50
		);
		foreach ($rows as $row) {
			if (is_array($row) === false || (string)($row['templateKey'] ?? '') !== $key || ($row['active'] ?? true) === false) {
				continue;
			}

			$subject = trim((string)($row['subject'] ?? ''));
			$body    = trim((string)($row['body'] ?? ''));
			if ($subject !== '' && $body !== '' && $this->undeclared(key: $key, texts: [$subject, $body]) === []) {
				return ['subject' => $subject, 'body' => $body];
			}
		}

		return null;
	}//end stored()

	/**
	 * Replace the variables a kind declares; leave anything else as written.
	 *
	 * @param string                $text   The text.
	 * @param string                $key    The template key.
	 * @param array<string, string> $values The variables' values.
	 *
	 * @return string
	 */
	private function fill(string $text, string $key, array $values): string {
		$allowed = (self::TEMPLATES[$key]['variables'] ?? []);

		return (string)preg_replace_callback(
			'/\{([A-Za-z][A-Za-z0-9_]*)\}/',
			static function (array $match) use ($allowed, $values): string {
				if (in_array($match[1], $allowed, true) === true && array_key_exists($match[1], $values) === true) {
					return (string)$values[$match[1]];
				}

				return $match[0];
			},
			$text
		);
	}//end fill()
}//end class
