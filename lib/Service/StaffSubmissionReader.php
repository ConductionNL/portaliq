<?php

/**
 * Portaliq Staff Submission Reader
 *
 * Reads one proof-log record (`portalSubmission`) with the rights of the
 * signed-in employee, so a record they may not read answers exactly like one
 * that does not exist.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/submission-row-actions/specs/supplier-portal/spec.md#requirement-an-employee-can-register-a-failed-receipt-again
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One proof-log record, read as the caller.
 *
 * @spec openspec/changes/submission-row-actions/specs/supplier-portal/spec.md#requirement-an-employee-can-register-a-failed-receipt-again
 */
class StaffSubmissionReader {

	use ObjectRowNormaliser;
	use ObjectServiceLookup;

	/**
	 * The register every portal-owned schema lives in.
	 */
	public const REGISTER = 'portaliq';

	/**
	 * The proof-log schema.
	 */
	public const SCHEMA = 'portalSubmission';


	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container For the lazy OpenRegister lookup.
	 * @param LoggerInterface    $logger    Records a read that failed.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()


	/**
	 * The record with this id, as the signed-in user may read it.
	 *
	 * @param string $id The record id.
	 *
	 * @return array<string, mixed>|null The record, or null when it does not exist or the caller may not read it.
	 *
	 * @spec openspec/changes/submission-row-actions/specs/supplier-portal/spec.md#requirement-an-employee-can-register-a-failed-receipt-again
	 */
	public function find(string $id): ?array {
		if (trim($id) === '') {
			return null;
		}

		$objectService = $this->objectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$entity = $objectService->find(
				id: $id,
				register: self::REGISTER,
				schema: self::SCHEMA,
				_rbac: true,
				_multitenancy: true
			);
		} catch (Throwable $e) {
			$this->logger->info('Portaliq: a submission could not be read as the caller', ['reason' => $e->getMessage()]);
			return null;
		}

		return $this->normalise(row: $entity);
	}//end find()
}//end class
