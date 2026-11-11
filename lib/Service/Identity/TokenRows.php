<?php

/**
 * Portaliq Token Rows
 *
 * Reading the rows that hold a hashed one-time secret: the row whose stored
 * hash matches, compared in constant time, and the identifier OpenRegister
 * gave the row.
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
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

/**
 * Helpers over rows found by a token hash.
 *
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
 */
final class TokenRows {
	/**
	 * The first row whose `tokenHash` equals the hash, compared in constant
	 * time; anything that is not a row is skipped.
	 *
	 * @param array<mixed> $rows The rows a read by `tokenHash` returned.
	 * @param string       $hash The SHA-256 of the secret.
	 *
	 * @return array<string, mixed>|null
	 *
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public static function matching(array $rows, string $hash): ?array {
		foreach ($rows as $row) {
			if (is_array($row) === true && hash_equals((string)($row['tokenHash'] ?? ''), $hash) === true) {
				return $row;
			}
		}

		return null;
	}//end matching()

	/**
	 * The stored identifier of a row, wherever OpenRegister put it.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The identifier, or '' when the row carries none.
	 *
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public static function identifierOf(array $row): string {
		$id = (string)($row['uuid'] ?? $row['id'] ?? '');
		if ($id !== '') {
			return $id;
		}

		$self = (array)($row['@self'] ?? []);

		return (string)($self['uuid'] ?? $self['id'] ?? '');
	}//end identifierOf()
}//end class
