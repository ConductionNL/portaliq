<?php

/**
 * Portaliq Own Account Rows
 *
 * Reads the portal account behind a subjectRef while it is not removed, and
 * writes fields onto an account row by its id, for the services that let a
 * signed-in person change their own account.
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
 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/specs/portal-profile/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalObjectWriter;

/**
 * The account row a person acts on themselves.
 *
 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/specs/portal-profile/spec.md
 */
final class OwnAccountRows {
	/**
	 * The register the account lives in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The account schema.
	 */
	private const SCHEMA = 'portalAccount';

	/**
	 * Constructor.
	 *
	 * @param PortalAccountService $accounts Finds the account by subjectRef.
	 * @param PortalObjectWriter   $writer   Writes the account row.
	 */
	public function __construct(
		private readonly PortalAccountService $accounts,
		private readonly PortalObjectWriter $writer,
	) {
	}//end __construct()

	/**
	 * The account behind a subjectRef, while it is not removed.
	 *
	 * @param string $subjectRef The account.
	 *
	 * @return array<string, mixed>|null
	 *
	* @spec openspec/changes/archive/2026-09-30-identity-profile-page/specs/portal-profile/spec.md
	 */
	public function find(string $subjectRef): ?array {
		if ($subjectRef === '') {
			return null;
		}

		$account = $this->accounts->findBySubjectRef(subjectRef: $subjectRef);
		if ($account === null || ($account['status'] ?? '') === 'removed') {
			return null;
		}

		return $account;
	}//end find()

	/**
	 * Write fields on the account row with this id; an empty id is never written.
	 *
	 * @param string               $id   The row's id, '' when the row has none.
	 * @param array<string, mixed> $data The fields to write.
	 *
	 * @return bool Whether the row was written.
	 *
	* @spec openspec/changes/archive/2026-09-30-identity-profile-page/specs/portal-profile/spec.md
	 */
	public function update(string $id, array $data): bool {
		if ($id === '') {
			return false;
		}

		return $this->writer->updateObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: '',
			id: $id,
			data: $data
		) !== null;
	}//end update()
}//end class
