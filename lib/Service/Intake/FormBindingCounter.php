<?php

/**
 * The submissions of a form binding's current period, against its limit.
 *
 * The status panel on a form's settings page reads "Inzendingen deze maand 41
 * van maximaal 500". This reads the binding the page shows by its id and asks
 * PortalFormAvailability for the count, so the panel counts exactly what the
 * submit path's limit check counts.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Intake
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://Portaliq.app
 *
 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#scenario-the-admin-sees-the-counter
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Reads one binding and counts its current period.
 *
 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#scenario-the-admin-sees-the-counter
 */
class FormBindingCounter {

	/**
	 * OpenRegister's object service.
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * The register the bindings live in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The schema of a binding.
	 */
	private const SCHEMA = 'portalFormBinding';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface     $container    For resolving OpenRegister's object service.
	 * @param PortalFormAvailability $availability Counts the period against the limit.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly PortalFormAvailability $availability,
	) {
	}//end __construct()

	/**
	 * The counter of the binding with this id.
	 *
	 * @param string                 $id  The binding's id.
	 * @param DateTimeImmutable|null $now The moment to judge; now when null.
	 *
	 * @return array{found: bool, limited: bool, count: int|null, limit: int|null, per: string|null}
	 *         `found` false for an unknown or unreadable binding; `limited`
	 *         false when the binding sets no usable limit.
	 *
	 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#scenario-the-admin-sees-the-counter
	 */
	public function forBinding(string $id, ?DateTimeImmutable $now = null): array {
		$binding = $this->binding(id: trim($id));
		if ($binding === null) {
			return ['found' => false, 'limited' => false, 'count' => null, 'limit' => null, 'per' => null];
		}

		$counter = $this->availability->counter(binding: $binding, now: $now);
		if ($counter === null) {
			return ['found' => true, 'limited' => false, 'count' => null, 'limit' => null, 'per' => null];
		}

		return ['found' => true, 'limited' => true] + $counter;
	}//end forBinding()

	/**
	 * The binding row, or null when it cannot be read.
	 *
	 * @param string $id The binding's id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function binding(string $id): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$entity = $this->container->get(self::OBJECT_SERVICE)->find(
				id: $id,
				register: self::REGISTER,
				schema: self::SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			return null;
		}

		if (is_array($entity) === true) {
			return $entity;
		}

		if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {
			$data = $entity->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return null;
	}//end binding()
}//end class
