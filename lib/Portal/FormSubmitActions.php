<?php

/**
 * The anonymous submission action of a landing-page form
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Portal
 * @package  OCA\Portaliq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Portal;

use Psr\Log\LoggerInterface;
use OCP\Security\ISecureRandom;

/**
 * Turns one active `form` row into an anonymous `create` action that posts to
 * the form's own destination, whitelists the form's field ids plus the
 * tracking fields, and carries server values a client can never override.
 *
 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-landing-pages-form-is-submittable-with-no-portal-session
 */
final class FormSubmitActions {
	/**
	 * The register the submission address lives in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The collection address a landing-page form posts to. A published page
	 * carries only its form id, not the form's destination, so the address
	 * stays `portaliq/landingPageSubmission`; nothing is written under it.
	 */
	private const SUBMIT_ADDRESS = 'landingPageSubmission';

	/**
	 * Constructor.
	 *
	 * @param ISecureRandom   $random Mints each action's nonce.
	 * @param LoggerInterface $logger Logs a form that names no destination.
	 */
	public function __construct(
		private readonly ISecureRandom $random,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The submission action of one form, or null when the form has no id, no
	 * fields or no destination (the last is logged).
	 *
	 * @param array<string, mixed> $row    The `form` row.
	 * @param string               $formId The form's id, '' when it has none.
	 *
	 * @return array<string, mixed>|null The action.
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-landing-pages-form-is-submittable-with-no-portal-session
	 */
	public function action(array $row, string $formId): ?array {
		if ($formId === '') {
			return null;
		}

		$fieldIds = $this->fieldIdsOf(row: $row);
		if ($fieldIds === []) {
			return null;
		}

		$destination = $this->destinationOf(row: $row, formId: $formId);
		if ($destination === null) {
			return null;
		}

		return $this->submitAction(row: $row, target: ['formId' => $formId, 'fields' => $fieldIds, 'destination' => $destination]);
	}//end action()

	/**
	 * A form's destination with its register and schema trimmed, or null
	 * (logged) when either is missing.
	 *
	 * @param array<string, mixed> $row    The `form` row.
	 * @param string               $formId The form's id, for the log.
	 *
	 * @return array<string, mixed>|null The destination.
	 */
	private function destinationOf(array $row, string $formId): ?array {
		$destination = (array)($row['destination'] ?? []);
		$register    = trim((string)($destination['register'] ?? ''));
		$schema      = trim((string)($destination['schema'] ?? ''));
		if ($register === '' || $schema === '') {
			$this->logger->warning('Portaliq: a landing-page form names no destination and cannot be submitted', ['form' => $formId]);
			return null;
		}

		return array_merge($destination, ['register' => $register, 'schema' => $schema]);
	}//end destinationOf()

	/**
	 * The anonymous create action for a form.
	 *
	 * @param array<string, mixed>                                                                  $row    The `form` row.
	 * @param array{formId: string, fields: array<int, string>, destination: array<string, mixed>} $target The form's id, field ids and destination.
	 *
	 * @return array<string, mixed> The action.
	 */
	private function submitAction(array $row, array $target): array {
		return [
			'id' => ('submit-' . $target['formId']),
			'type' => 'create',
			'anonymous' => true,
			// The address the public form posts to. It is only an address:
			// the submit creates `destination`, never an object here.
			'register' => self::REGISTER,
			'schema' => self::SUBMIT_ADDRESS,
			'label' => (string)($row['submitLabel'] ?? 'Submit'),
			'fields' => array_merge($target['fields'], ['utmFirstTouch', 'utmLastTouch', 'referrer']),
			'destination' => $target['destination'],
			'intakeScope' => (string)($row['portal'] ?? '') . '/' . (string)($row['pageRoute'] ?? ''),
			'server' => $this->serverValues(row: $row, formId: $target['formId']),
		];
	}//end submitAction()

	/**
	 * The values the server sets on a submission, which a client can never override.
	 *
	 * @param array<string, mixed> $row    The `form` row.
	 * @param string               $formId The form's id.
	 *
	 * @return array<string, mixed> The server values, with a fresh time and nonce.
	 */
	private function serverValues(array $row, string $formId): array {
		return [
			'formId' => $formId,
			'pageId' => (string)($row['pageId'] ?? ''),
			'pageRoute' => (string)($row['pageRoute'] ?? ''),
			'portal' => (string)($row['portal'] ?? ''),
			'sourceApp' => (string)($row['sourceApp'] ?? ''),
			'externalReference' => (string)($row['externalReference'] ?? ''),
			'campaign' => (array)($row['campaign'] ?? []),
			'submittedAt' => date(DATE_ATOM),
			'nonce' => $this->random->generate(32, (ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS)),
		];
	}//end serverValues()

	/**
	 * A form's declared field ids, in order.
	 *
	 * @param array<string, mixed> $row The `form` row.
	 *
	 * @return array<int, string>
	 */
	private function fieldIdsOf(array $row): array {
		$fieldIds = [];
		foreach ((array)($row['fields'] ?? []) as $field) {
			$fieldId = null;
			if (is_array($field) === true) {
				$fieldId = ($field['id'] ?? null);
			}

			if (is_string($fieldId) === true && $fieldId !== '') {
				$fieldIds[] = $fieldId;
			}
		}

		return $fieldIds;
	}//end fieldIdsOf()
}//end class
