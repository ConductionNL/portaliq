<?php

/**
 * Portaliq Payment Intents (intake-pay-on-submit)
 *
 * @category Intake
 * @package  OCA\Portaliq\Service\Intake
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
 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t05
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the status of a payment from integriq's `payment_intent` object.
 *
 * The id is the one portaliq stored when the case app answered the pay
 * forward; it never comes from the browser. The register and schema slugs
 * are integriq's (`openconnector` / `payment_intent`).
 *
 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t05
 */
class PortalPaymentIntents {
	/**
	 * Where integriq keeps its payment intents.
	 */
	public const REGISTER = 'openconnector';

	/**
	 * The payment intent schema.
	 */
	public const SCHEMA = 'payment_intent';

	/**
	 * OpenRegister's object service.
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's object service.
	 * @param LoggerInterface    $logger    Logs an intent that could not be read.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The `paymentStatus` of an intent, or null when it cannot be read.
	 *
	 * @param string $id The payment intent id stored on the submission.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t05
	 */
	public function status(string $id): ?string {
		if ($id === '') {
			return null;
		}

		try {
			$service = $this->container->get(self::OBJECT_SERVICE);
			$service->setRegister(register: self::REGISTER);
			$service->setSchema(schema: self::SCHEMA);
			$object = $service->find(id: $id, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: a payment intent could not be read', ['reason' => $e->getMessage()]);

			return null;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$object = $object->jsonSerialize();
		}

		if (is_array($object) === false) {
			return null;
		}

		$data   = (array)($object['object'] ?? $object);
		$status = $data['paymentStatus'] ?? null;
		if (is_string($status) === false || $status === '') {
			return null;
		}

		return $status;
	}//end status()
}//end class
