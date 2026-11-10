<?php

/**
 * Portaliq Object File Lookup
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
 * @spec openspec/specs/portal-contribution-contract/spec.md#scoped-file-attachment-on-a-subject-owned-object
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use Throwable;

/**
 * The OpenRegister lookups a class needs to read or write the files of an
 * object the subject already owns.
 *
 * The using class provides `$container`, `$logger`, the `FILE_SERVICE` and
 * `OBJECT_SERVICE` ids, and `ENTITY_LOOKUP_WARNING`, the message logged when
 * the object cannot be resolved.
 *
 * @spec openspec/specs/portal-contribution-contract/spec.md#scoped-file-attachment-on-a-subject-owned-object
 */
trait ObjectFileLookup {
	/**
	 * Resolve OpenRegister's FileService, or null when unavailable.
	 *
	 * @return object|null
	 */
	private function fileService(): ?object {
		try {
			$service = $this->container->get(self::FILE_SERVICE);
		} catch (Throwable $e) {
			$service = null;
		}

		if (is_object($service) === true) {
			return $service;
		}

		return null;
	}//end fileService()

	/**
	 * Resolve an object UUID to a full OpenRegister ObjectEntity via the object
	 * service. The file service's folder resolution takes its entity-input
	 * branch only when handed an ObjectEntity; handed a bare string id it
	 * cannot resolve the register. RBAC and tenancy are off: the caller
	 * re-verified ownership already.
	 *
	 * @param string $register The register slug/id.
	 * @param string $schema The schema slug/id.
	 * @param string $id The owned object's UUID.
	 *
	 * @return object|null The ObjectEntity, or null on any resolution failure.
	 */
	private function resolveObjectEntity(string $register, string $schema, string $id): ?object {
		try {
			$objectService = $this->container->get(self::OBJECT_SERVICE);
			if (is_object($objectService) === false) {
				return null;
			}

			$entity = $objectService->find(
				id: $id,
				options: new ObjectFindOptions(register: $register, schema: $schema),
				_rbac: false,
				_multitenancy: false
			);
			if (is_object($entity) === true) {
				return $entity;
			}

			return null;
		} catch (Throwable $e) {
			$this->logger->warning(self::ENTITY_LOOKUP_WARNING, ['reason' => $e->getMessage()]);
			return null;
		}//end try
	}//end resolveObjectEntity()

	/**
	 * Shape an OpenRegister file down to safe metadata for the client: the
	 * raw stored path is never included.
	 *
	 * @param mixed $file The OpenRegister file (or array).
	 * @param string|null $fileName The name to answer when the file has none.
	 *
	 * @return array<string, mixed>
	 */
	private function normalise(mixed $file, ?string $fileName = null): array {
		$name = $fileName;
		$size = null;
		$id = null;
		if (is_object($file) === true) {
			if (method_exists($file, 'getName') === true) {
				$name = (string)$file->getName();
			}

			if (method_exists($file, 'getSize') === true) {
				$size = $file->getSize();
			}

			if (method_exists($file, 'getId') === true) {
				$id = $file->getId();
			}
		}

		return ['id' => $id, 'name' => $name, 'size' => $size];
	}//end normalise()
}//end trait
