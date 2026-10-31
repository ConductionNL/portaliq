<?php

/**
 * Portaliq Mandate Store (site-mandates-the-represented-manage)
 *
 * Reads and updates the mandate and invitation rows of an organisation.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Identity
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
 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;

/**
 * The mandate and invitation rows of one organisation.
 *
 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md
 */
class MandateStore {
	/**
	 * The register the rows live in.
	 */
	public const REGISTER = 'portaliq';

	/**
	 * The schema of a mandate.
	 */
	public const MANDATES = 'portalMandate';

	/**
	 * The schema of an invitation.
	 */
	public const INVITATIONS = 'portalInvitation';

	/**
	 * The most rows read at once.
	 */
	private const LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader The scoped reader.
	 * @param PortalObjectWriter $writer The scoped writer.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
	) {
	}//end __construct()

	/**
	 * Every row of one schema in one tenant, re-checked against the tenant.
	 *
	 * @param string $schema       The schema.
	 * @param string $organisation The tenant.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md
	 */
	public function rows(string $schema, string $organisation): array {
		if ($organisation === '') {
			return [];
		}

		$out = [];
		foreach ($this->reader->readCollection(
			register: self::REGISTER,
			schema: $schema,
			scopeField: 'organisation',
			subjectRef: $organisation,
			organisation: $organisation,
			limit: self::LIMIT
		) as $row) {
			if (is_array($row) === true && ($row['organisation'] ?? '') === $organisation) {
				$out[] = $row;
			}
		}

		return $out;
	}//end rows()

	/**
	 * @param array<string, mixed> $row A stored row.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md
	 */
	public function idOf(array $row): string {
		$self = (array)($row['@self'] ?? []);
		foreach ([($row['uuid'] ?? null), ($row['id'] ?? null), ($self['uuid'] ?? null), ($self['id'] ?? null)] as $candidate) {
			if ((is_string($candidate) === true || is_int($candidate) === true) && (string)$candidate !== '') {
				return (string)$candidate;
			}
		}

		return '';
	}//end idOf()

	/**
	 * @param string $id           The mandate id.
	 * @param string $organisation The tenant.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md
	 */
	public function mandate(string $id, string $organisation): ?array {
		if ($id === '') {
			return null;
		}

		foreach ($this->rows(schema: self::MANDATES, organisation: $organisation) as $row) {
			if ($this->idOf(row: $row) === $id) {
				return $row;
			}
		}

		return null;
	}//end mandate()

	/**
	 * @param string $token The invitation secret.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md
	 */
	public function invitationByToken(string $token): ?array {
		if ($token === '') {
			return null;
		}

		$hash = hash('sha256', $token);
		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::INVITATIONS,
			scopeField: 'tokenHash',
			subjectRef: $hash,
			organisation: '',
			limit: 5
		);
		foreach ($rows as $row) {
			if (is_array($row) === true && hash_equals((string)($row['tokenHash'] ?? ''), $hash) === true && isset($row['mandate']) === true) {
				return $row;
			}
		}

		return null;
	}//end invitationByToken()

	/**
	 * @param string               $schema The schema.
	 * @param array<string, mixed> $row    The row.
	 * @param array<string, mixed> $data   The change.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md
	 */
	public function update(string $schema, array $row, array $data): void {
		$organisation = (string)($row['organisation'] ?? '');
		$this->writer->updateObject(
			register: self::REGISTER,
			schema: $schema,
			scopeField: 'organisation',
			subjectRef: $organisation,
			organisation: $organisation,
			id: $this->idOf(row: $row),
			data: $data
		);
	}//end update()
}//end class
