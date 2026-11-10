<?php

/**
 * Portaliq Staff Row Write
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
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
 * @spec exclude Shared write helper; the @spec of each calling method covers the behaviour it writes for.
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\OpenRegister\Service\Object\ObjectFindTarget;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use Throwable;

/**
 * Writes fields onto one row of the controller's schema, for staff authoring.
 *
 * The using controller carries `$container`, `$logger` and the constants
 * `REGISTER` and `SCHEMA`. It checks the staff action BEFORE calling this:
 * the write itself is unscoped.
 *
 * @spec exclude Shared write helper; the @spec of each calling method covers the behaviour it writes for.
 */
trait StaffRowWrite {
	/**
	 * Merge `$data` onto the row and answer the saved row, or 404.
	 *
	 * @param string               $id   The row id.
	 * @param array<string, mixed> $data The fields to write.
	 *
	 * @return JSONResponse
	 */
	private function writeRow(string $id, array $data): JSONResponse {
		$writer = new PortalObjectWriter(container: $this->container, logger: $this->logger);
		$updated = $writer->updateObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: '',
			id: $id,
			data: $data
		);

		if ($updated === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($updated);
	}//end writeRow()

	/**
	 * Save a new row and answer it, or 500 when the store is absent or fails.
	 *
	 * @param array<string, mixed> $object  The fields of the new row.
	 * @param string               $failure The log line for a failed save.
	 *
	 * @return JSONResponse
	 */
	private function createRow(array $object, string $failure): JSONResponse {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return new JSONResponse(['error' => 'server_error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		try {
			$saved = $objectService->saveObject(
				object: $object,
				options: new ObjectSaveOptions(target: new ObjectFindTarget(register: self::REGISTER, schema: self::SCHEMA)),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->error($failure, ['reason' => $e->getMessage()]);
			return new JSONResponse(['error' => 'server_error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($this->normalise(row: $saved));
	}//end createRow()
}//end trait
