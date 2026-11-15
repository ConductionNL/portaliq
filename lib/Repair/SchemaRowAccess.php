<?php

/**
 * Portaliq Schema Row Access
 *
 * @category Repair
 * @package  OCA\Portaliq\Repair
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
 * @spec exclude Shared row helpers of the repair steps; the @spec of each step covers what it repairs.
 */

declare(strict_types=1);

namespace OCA\Portaliq\Repair;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use Throwable;

/**
 * Every row of a schema, and one field written onto one row, for a repair step.
 *
 * The using step provides `$context` (PortalRegisterContext), `$logger`, and
 * the constants `REGISTER`, `PAGE`, `MAX_PAGES`, `ROWS_UNREADABLE_LOG` (logged
 * when a schema cannot be read) and `WRITE_FAILED_LOG` (logged when a row
 * cannot be written).
 */
trait SchemaRowAccess {

	/**
	 * Every stored row of a schema, a page at a time.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $schema        The schema slug.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows(object $objectService, string $schema): array {
		$out    = [];
		$offset = 0;
		$pages  = 0;
		do {
			$pages++;
			try {
				if ($this->context->apply(objectService: $objectService, schemaSlug: $schema) === false) {
					return $out;
				}

				$page = (array)$objectService->findAll(config: ['limit' => self::PAGE, 'offset' => $offset], _rbac: false, _multitenancy: false);
			} catch (Throwable $e) {
				$this->logger->info(self::ROWS_UNREADABLE_LOG, ['schema' => $schema, 'reason' => $e->getMessage()]);

				return $out;
			}

			$read    = count($page);
			$offset += $read;
			$out     = array_merge($out, self::arraysOf(page: $page));
		} while ($read === self::PAGE && $pages < self::MAX_PAGES);

		return $out;
	}//end rows()

	/**
	 * The rows of one page as arrays; an entity is serialised, anything else dropped.
	 *
	 * @param array<mixed> $page The page as read.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function arraysOf(array $page): array {
		$out = [];
		foreach ($page as $entry) {
			$value = $entry;
			if (is_object($entry) === true && method_exists($entry, 'jsonSerialize') === true) {
				$value = $entry->jsonSerialize();
			}

			if (is_array($value) === true) {
				$out[] = $value;
			}
		}

		return $out;
	}//end arraysOf()

	/**
	 * Write fields onto one stored row.
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param string               $schema        The schema slug.
	 * @param array<string, mixed> $row           The stored row.
	 * @param array<string, mixed> $fields        The fields to set.
	 *
	 * @return bool True when the row was written.
	 */
	private function writeFields(object $objectService, string $schema, array $row, array $fields): bool {
		$self = [];
		if (is_array($row['@self'] ?? null) === true) {
			$self = $row['@self'];
		}

		$uuid = (string)($self['uuid'] ?? $self['id'] ?? $row['id'] ?? '');
		if ($uuid === '') {
			return false;
		}

		try {
			if ($this->context->apply(objectService: $objectService, schemaSlug: $schema) === false) {
				return false;
			}

			unset($row['@self']);
			$objectService->saveObject(
				object: array_merge($row, $fields),
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: self::REGISTER, schema: $schema),
					uuid: $uuid
				),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning(self::WRITE_FAILED_LOG, ['uuid' => $uuid, 'schema' => $schema, 'reason' => $e->getMessage()]);

			return false;
		}

		return true;
	}//end writeFields()
}//end trait
