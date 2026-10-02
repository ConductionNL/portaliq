<?php

/**
 * Portaliq user display names
 *
 * A collection column may declare `render: "user"`: its value is a Nextcloud
 * user id, such as the teacher who handled a request. A resident must read
 * that person's name, and the user id itself must not leave the server, so
 * this class replaces the value in every row before the row is answered.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/contribution-user-display-name/specs/portal-contribution-contract/spec.md#requirement-a-column-may-show-a-nextcloud-user-by-name
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCP\IUserManager;

/**
 * Replaces user ids in `render: "user"` columns with the users' display names.
 *
 * Fail-closed: a value that names no user on this instance, or that is not a
 * user id at all, is answered as `''`, never as itself. Without a user
 * manager every such value is `''`.
 */
class PortalUserDisplayNames {

	/**
	 * The render kind that marks a column as a Nextcloud user.
	 */
	public const RENDER = 'user';

	/**
	 * Display names already looked up in this request, by user id.
	 *
	 * @var array<string, string>
	 */
	private array $cache = [];

	/**
	 * Constructor.
	 *
	 * @param IUserManager|null $users Looks up a user's display name.
	 */
	public function __construct(
		private readonly ?IUserManager $users=null,
	) {
	}//end __construct()

	/**
	 * The fields a collection declares as Nextcloud users.
	 *
	 * @param array<string, mixed> $collection The normalised collection.
	 *
	 * @return list<string> The field names, each once.
	 *
	 * @spec openspec/changes/contribution-user-display-name/specs/portal-contribution-contract/spec.md#requirement-a-column-may-show-a-nextcloud-user-by-name
	 */
	public function userFields(array $collection): array {
		$fields = [];
		foreach ((array)($collection['columns'] ?? []) as $column) {
			if (is_array($column) === false || ($column['render'] ?? null) !== self::RENDER) {
				continue;
			}

			$field = ($column['field'] ?? '');
			if (is_string($field) === true && $field !== '') {
				$fields[$field] = true;
			}
		}

		return array_keys($fields);
	}//end userFields()

	/**
	 * Every row with its user fields read as display names.
	 *
	 * @param array<int, mixed>    $rows       The rows to answer.
	 * @param array<string, mixed> $collection The normalised collection.
	 *
	 * @return array<int, mixed> The rows, in the same order.
	 *
	 * @spec openspec/changes/contribution-user-display-name/specs/portal-contribution-contract/spec.md#requirement-a-column-may-show-a-nextcloud-user-by-name
	 */
	public function rows(array $rows, array $collection): array {
		$fields = $this->userFields(collection: $collection);
		if ($fields === []) {
			return $rows;
		}

		foreach ($rows as $index => $row) {
			if (is_array($row) === true) {
				$rows[$index] = $this->replace(row: $row, fields: $fields);
			}
		}

		return $rows;
	}//end rows()

	/**
	 * One row with its user fields read as display names.
	 *
	 * @param array<string, mixed> $row        The row to answer.
	 * @param array<string, mixed> $collection The normalised collection.
	 *
	 * @return array<string, mixed> The row.
	 *
	 * @spec openspec/changes/contribution-user-display-name/specs/portal-contribution-contract/spec.md#requirement-a-column-may-show-a-nextcloud-user-by-name
	 */
	public function row(array $row, array $collection): array {
		$fields = $this->userFields(collection: $collection);
		if ($fields === []) {
			return $row;
		}

		return $this->replace(row: $row, fields: $fields);
	}//end row()

	/**
	 * Replace the named fields of one row.
	 *
	 * A list of user ids becomes a list of names, so a column holding several
	 * teachers reads each of them.
	 *
	 * @param array<string, mixed> $row    The row.
	 * @param list<string>         $fields The user fields.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function replace(array $row, array $fields): array {
		foreach ($fields as $field) {
			if (array_key_exists($field, $row) === false || $row[$field] === null) {
				continue;
			}

			$value = $row[$field];
			if (is_array($value) === true) {
				$row[$field] = array_values(array_map(fn ($uid): string => $this->nameOf(uid: $uid), $value));
				continue;
			}

			$row[$field] = $this->nameOf(uid: $value);
		}

		return $row;
	}//end replace()

	/**
	 * The display name of one user id, or '' when it names no user.
	 *
	 * @param mixed $uid The stored value.
	 *
	 * @return string The display name, or ''.
	 */
	private function nameOf(mixed $uid): string {
		if (is_string($uid) === false || trim($uid) === '' || $this->users === null) {
			return '';
		}

		if (array_key_exists($uid, $this->cache) === false) {
			$name = $this->users->getDisplayName($uid);
			$this->cache[$uid] = trim((string)$name);
		}

		return $this->cache[$uid];
	}//end nameOf()
}//end class
