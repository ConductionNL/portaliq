<?php

/**
 * Portaliq Reviewer Object Writer
 *
 * The one write portaliq makes as the logged-in user rather than as itself.
 *
 * Accepting a change proposal must land on the record with the reviewer's own
 * rights, not with portaliq's trusted-intermediary posture: the audit trail
 * has to name the person who accepted it, and a reviewer who may not write the
 * record must not be able to write it by proxy. So this goes through
 * OpenRegister with RBAC and multitenancy ON and fails closed on anything it
 * cannot do.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Proposals
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
 * @spec openspec/changes/change-proposal-queue/specs/change-proposal-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Proposals;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes a record as the reviewer, with their rights.
 *
 * @spec openspec/changes/change-proposal-queue/specs/change-proposal-queue/spec.md
 */
class ReviewerObjectWriter {
	/**
	 * OpenRegister's object service, resolved lazily.
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's service.
	 * @param LoggerInterface $logger Records a refused write.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write the accepted values onto the record, in one update.
	 *
	 * @param string $register The register the record lives in.
	 * @param string $schema The schema the record lives in.
	 * @param string $id The record.
	 * @param array<string, mixed> $values The properties to write.
	 *
	 * @return bool True when the write landed.
	 *
	 * @spec openspec/changes/change-proposal-queue/specs/change-proposal-queue/spec.md
	 */
	public function write(string $register, string $schema, string $id, array $values): bool {
		if ($register === '' || $schema === '' || $id === '' || $values === []) {
			return false;
		}

		$objectService = $this->objectService();
		if ($objectService === null) {
			return false;
		}

		try {
			$saved = $objectService->saveObject(
				object: $values,
				register: $register,
				schema: $schema,
				uuid: $id,
				// As the reviewer: their rights decide, and the audit trail
				// names them.
				_rbac: true,
				_multitenancy: true
			);
		} catch (Throwable $refused) {
			$this->logger->warning('Portaliq: accepting a proposal was refused by OpenRegister: ' . $refused->getMessage());
			return false;
		}

		return $saved !== null;
	}//end write()

	/**
	 * OpenRegister's object service, or null when it is not installed.
	 *
	 * @return object|null
	 */
	private function objectService(): ?object {
		try {
			$service = $this->container->get(self::OBJECT_SERVICE);
		} catch (Throwable $missing) {
			return null;
		}

		if (is_object($service) === true) {
			return $service;
		}

		return null;
	}//end objectService()
}//end class
