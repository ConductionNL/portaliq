<?php

/**
 * Portaliq Cross Reference Guard
 *
 * The enforcement half of the `crossRefs` declaration. Before a portal write
 * reaches OpenRegister, every field the action declares as a reference is
 * resolved through the SAME scoped read the portal uses to show the subject
 * one of their own objects. If the read answers nothing, the reference is not
 * theirs, and the write is refused.
 *
 * 🔴 IT IS THE SCOPED READ THAT MAKES THIS A GUARD. A check written here that
 * fetched the referenced object directly would answer "it exists", which is
 * exactly the question a write-IDOR does not turn on. `PortalObjectReader::
 * readObject()` already fails closed to null on a foreign object, a malformed
 * scope claim and an unreachable OpenRegister alike, so this class asks one
 * question and never has to decide what a partial answer means.
 *
 * A reference the client left out is allowed unless the declaration marks it
 * `required`. That is the domain app's call: `tegenZaakId` on a bezwaar is
 * required, an optional case link on a message is not.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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
 * @spec openspec/changes/portal-create-cross-refs/specs/portal-contribution-contract/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Contribution\CrossRefConfigNormaliser;
use Psr\Log\LoggerInterface;

/**
 * Refuses a portal write whose references are not the subject's own.
 *
 * @spec openspec/changes/portal-create-cross-refs/specs/portal-contribution-contract/spec.md#requirement-a-declared-cross-reference-must-resolve-inside-the-subjects-own-scope
 */
class PortalCrossRefGuard {
	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader The scoped read that answers whose an object is.
	 * @param LoggerInterface    $logger Logger.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The first declared reference this body fails on, or an empty string.
	 *
	 * @param array<string, mixed> $action  The matched action, already normalised.
	 * @param array<string, mixed> $data    The whitelisted write body.
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param string               $app     The contributing app, for the scope claim.
	 *
	 * @return string The refusing field, empty when every reference is the subject's.
	 *
	 * @spec openspec/changes/portal-create-cross-refs/specs/portal-contribution-contract/spec.md#requirement-a-declared-cross-reference-must-resolve-inside-the-subjects-own-scope
	 */
	public function refusedField(array $action, array $data, array $subject, string $app): string {
		$declared = ($action[CrossRefConfigNormaliser::KEY] ?? []);
		if (is_array($declared) === false || $declared === []) {
			return '';
		}

		foreach ($declared as $field => $reference) {
			$ids = $this->idsIn(value: ($data[(string)$field] ?? null));
			if ($ids === []) {
				if (($reference['required'] ?? false) === true) {
					return (string)$field;
				}

				continue;
			}

			foreach ($ids as $id) {
				if ($this->ownedBySubject(reference: $reference, id: $id, subject: $subject, app: $app) === false) {
					$this->logger->warning(
						'Portaliq: a portal write named an object outside the subject’s own scope',
						[
							'field' => (string)$field,
							'schema' => (string)($reference['schema'] ?? ''),
							'audience' => (string)($subject['audience'] ?? ''),
						]
					);

					return (string)$field;
				}
			}
		}

		return '';
	}//end refusedField()

	/**
	 * Whether one referenced id is inside the subject's own scope.
	 *
	 * @param array<string, mixed> $reference The declared reference.
	 * @param string               $id        The referenced object.
	 * @param array<string, mixed> $subject   The resolved subject.
	 * @param string               $app       The contributing app.
	 *
	 * @return bool True when the subject may already see it.
	 */
	private function ownedBySubject(array $reference, string $id, array $subject, string $app): bool {
		$owned = $this->reader->readObject(
			register: (string)($reference['register'] ?? ''),
			schema: (string)($reference['schema'] ?? ''),
			scopeField: (string)($reference['scopeField'] ?? ''),
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			id: $id,
			organisation: (string)($subject['organisation'] ?? ''),
			scopeClaim: (string)($reference['scopeClaim'] ?? ''),
			contributingApp: $app,
			via: null,
			audience: (string)($subject['audience'] ?? ''),
			fields: ['id']
		);

		return $owned !== null;
	}//end ownedBySubject()

	/**
	 * The ids a written value names, whether it is one reference or a list.
	 *
	 * A value that is neither a string nor a list of them answers an id that
	 * cannot be owned, so the guard refuses rather than skipping: a caller who
	 * sends an object where a uuid belongs is not sending nothing.
	 *
	 * @param mixed $value The written value.
	 *
	 * @return array<int, string> The ids, empty when the field was left out.
	 */
	private function idsIn(mixed $value): array {
		if ($value === null || $value === '' || $value === []) {
			return [];
		}

		if (is_string($value) === true) {
			return [$value];
		}

		if (is_array($value) === false) {
			// Not a reference at all. An id nothing can resolve refuses.
			return [''];
		}

		$ids = [];
		foreach ($value as $entry) {
			$ids[] = (is_string($entry) === true ? $entry : '');
		}

		return $ids;
	}//end idsIn()
}//end class
