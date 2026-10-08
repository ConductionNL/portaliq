<?php

/**
 * Portaliq Citizen Write Action Finder
 *
 * Which contributed action, if any, lets a citizen write on a given register
 * and schema. Only a `type: update` action carrying a sanitised citizen-write
 * declaration counts: an action that never declared one is not a licence to
 * write, whatever else it says.
 *
 * Its own class rather than a method on the registry or on the controller.
 * The registry answers "what has this subject got", the controller answers an
 * HTTP request, and this answers a third question that is neither.
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
 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Finds the contributed action a citizen write runs under.
 *
 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
 */
class CitizenWriteActionFinder {

	/**
	 * Constructor.
	 *
	 * @param PortalContributionRegistry $registry What the subject's apps contribute.
	 */
	public function __construct(
		private readonly PortalContributionRegistry $registry,
	) {
	}//end __construct()

	/**
	 * The action that lets this subject write on this register and schema.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param string $register The register the case lives in.
	 * @param string $schema The schema the case lives in.
	 *
	 * @return array{action: array<string, mixed>, app: string, filesDownload: bool,
	 *     documents: array{label: string, provider: string}|null, fields: mixed}|null
	 *         Null when no contributed action admits a citizen write here.
	 *         `filesDownload` says whether the same app opted a collection on
	 *         this register and schema into downloads (portaliq#798);
	 *         `documents` is the documents method a collection there declares
	 *         (cases-documents-on-the-case), or null. `fields` is the
	 *         `fields` whitelist a collection there declares, or null when
	 *         none declares one (citizen-case-shows-only-its-fields).
	 *         `closedField` is the closed marker a collection there
	 *         declares, or ''.
	 *
	 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function forSubject(array $subject, string $register, string $schema): ?array {
		$aggregate = $this->registry->aggregateFor($subject);
		foreach (($aggregate['contributions'] ?? []) as $contribution) {
			foreach (($contribution['actions'] ?? []) as $action) {
				if (($action['type'] ?? '') !== 'update'
					|| ($action['register'] ?? '') !== $register
					|| ($action['schema'] ?? '') !== $schema
					|| is_array(($action[CitizenWriteConfigNormaliser::KEY] ?? null)) === false
				) {
					continue;
				}

				return [
					'action' => $action,
					'app' => (string)($contribution['app'] ?? ''),
					'filesDownload' => $this->filesDownload(contribution: $contribution, register: $register, schema: $schema),
					'documents' => $this->documents(contribution: $contribution, register: $register, schema: $schema),
					'fields' => $this->caseFields(contribution: $contribution, register: $register, schema: $schema),
					'closedField' => $this->closedField(contribution: $contribution, register: $register, schema: $schema),
				];
			}
		}

		return null;
	}//end forSubject()

	/**
	 * Whether the contribution opts a collection on this register and schema
	 * into downloads. Only the app's own collections count, and only on the
	 * case's own schema, the same opt-in contribution#object and
	 * contribution#downloadFile honour. Absent means no.
	 *
	 * @param array<string, mixed> $contribution The contribution the action came from.
	 * @param string $register The register the case lives in.
	 * @param string $schema The schema the case lives in.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/supplier-portal/spec.md#download-is-opt-in-per-collection-fail-closed
	 */
	private function filesDownload(array $contribution, string $register, string $schema): bool {
		foreach (($contribution['collections'] ?? []) as $collection) {
			if (is_array($collection) === true
				&& ($collection['register'] ?? '') === $register
				&& ($collection['schema'] ?? '') === $schema
				&& ($collection['filesDownload'] ?? false) === true
			) {
				return true;
			}
		}

		return false;
	}//end filesDownload()

	/**
	 * The `documents` declaration of the contribution's collection on this
	 * register and schema, already normalised, or null.
	 *
	 * @param array<string, mixed> $contribution One app's contribution.
	 * @param string               $register     The case's register.
	 * @param string               $schema       The case's schema.
	 *
	 * @return array{label: string, provider: string}|null
	 *
	 * @spec openspec/specs/citizen-case-documents/spec.md#requirement-the-case-app-declares-which-documents-a-resident-may-see-req-cdc-001
	 */
	private function documents(array $contribution, string $register, string $schema): ?array {
		foreach (($contribution['collections'] ?? []) as $collection) {
			if (is_array($collection) === true
				&& ($collection['register'] ?? '') === $register
				&& ($collection['schema'] ?? '') === $schema
				&& is_array(($collection['documents'] ?? null)) === true
			) {
				return [
					'label' => (string)($collection['documents']['label'] ?? ''),
					'provider' => (string)($collection['documents']['provider'] ?? ''),
				];
			}
		}

		return null;
	}//end documents()

	/**
	 * The `fields` whitelist of the contribution's collection on this register
	 * and schema, exactly as declared, or null when no collection there
	 * declares one.
	 *
	 * The case screen shows a case the resident also reads in that collection,
	 * so it shows no more of it than the collection does. A malformed
	 * declaration is passed on as it is: the projector narrows it to the
	 * identifiers, never widens it to the whole row.
	 *
	 * @param array<string, mixed> $contribution One app's contribution.
	 * @param string               $register     The case's register.
	 * @param string               $schema       The case's schema.
	 *
	 * @return mixed The raw `fields` declaration, or null.
	 *
	 * @spec openspec/changes/citizen-case-shows-only-its-fields/specs/citizen-writes-on-their-own-case/spec.md
	 */
	private function caseFields(array $contribution, string $register, string $schema): mixed {
		foreach (($contribution['collections'] ?? []) as $collection) {
			if (is_array($collection) === true
				&& ($collection['register'] ?? '') === $register
				&& ($collection['schema'] ?? '') === $schema
				&& array_key_exists('fields', $collection) === true
				&& $collection['fields'] !== null
			) {
				return $collection['fields'];
			}
		}

		return null;
	}//end caseFields()

	/**
	 * The closed marker of the contribution's case collection on this
	 * register and schema, or '' when none declares one.
	 *
	 * "My cases" files a case under Closed by this field; the case screen
	 * reads the same field, so a case listed as closed also shows as over.
	 * The contribution is already normalised, so a marker the collection does
	 * not project is gone by now.
	 *
	 * @param array<string, mixed> $contribution One app's contribution.
	 * @param string               $register     The case's register.
	 * @param string               $schema       The case's schema.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/citizen-case-ended-shows-only-its-state/specs/citizen-writes-on-their-own-case/spec.md#requirement-a-case-that-has-ended-offers-nothing-and-explains-nothing
	 */
	private function closedField(array $contribution, string $register, string $schema): string {
		foreach (($contribution['collections'] ?? []) as $collection) {
			if (is_array($collection) === true
				&& ($collection['register'] ?? '') === $register
				&& ($collection['schema'] ?? '') === $schema
				&& is_string(($collection['closedField'] ?? null)) === true
			) {
				return $collection['closedField'];
			}
		}

		return '';
	}//end closedField()
}//end class
