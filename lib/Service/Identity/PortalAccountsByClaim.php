<?php

/**
 * Portaliq Portal Accounts By Claim
 *
 * Finds the active portal accounts whose server-managed claim of one app holds
 * one value: `claims.<app>.<claim> === value`. A change rule that names its
 * recipients by a claim (claim-addressed-change-notices) asks this for the
 * residents a record is about, when the record holds the app's own reference
 * of the resident (a school's guardian reference) and not their portal
 * subject reference.
 *
 * The accounts are listed page by page and matched here, in memory, on the
 * exact app, claim and value, so a store that ignores a filter can never
 * widen the answer. A pending or withdrawn account cannot sign in and is
 * never returned. The listing is read once per request and app claim.
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
 * @spec openspec/changes/claim-addressed-change-notices/specs/portal-notifications-and-preferences/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The active accounts whose app claim holds a value.
 *
 * @spec openspec/changes/claim-addressed-change-notices/specs/portal-notifications-and-preferences/spec.md
 */
class PortalAccountsByClaim {

	/**
	 * OpenRegister's object service, resolved by name.
	 *
	 * @var string
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * The register the accounts live in.
	 *
	 * @var string
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The account schema.
	 *
	 * @var string
	 */
	private const SCHEMA = 'portalAccount';

	/**
	 * Rows per page. The listing pages until a short page comes back.
	 *
	 * @var integer
	 */
	private const PAGE = 500;

	/**
	 * A hard stop, so a misbehaving store can never loop forever.
	 *
	 * @var integer
	 */
	private const MAX_PAGES = 40;

	/**
	 * Per `app|claim`: claim value to the accounts holding it.
	 *
	 * @var array<string, array<string, array<int, array<string, mixed>>>>
	 */
	private array $byValue = [];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's object service.
	 * @param LoggerInterface    $logger    The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The active accounts whose claim `$claim` of `$appId` is `$value`.
	 *
	 * @param string $appId The app whose claim it is.
	 * @param string $claim The claim name.
	 * @param string $value The value the record holds.
	 *
	 * @return array<int, array<string, mixed>> The accounts; empty when none or OpenRegister is unavailable.
	 *
	 * @spec openspec/changes/claim-addressed-change-notices/specs/portal-notifications-and-preferences/spec.md
	 */
	public function find(string $appId, string $claim, string $value): array {
		if ($appId === '' || $claim === '' || $value === '') {
			return [];
		}

		$key = $appId.'|'.$claim;
		if (isset($this->byValue[$key]) === false) {
			$this->byValue[$key] = $this->index(appId: $appId, claim: $claim);
		}

		return ($this->byValue[$key][$value] ?? []);
	}//end find()

	/**
	 * Every active account holding the app claim, by its value.
	 *
	 * @param string $appId The app.
	 * @param string $claim The claim.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function index(string $appId, string $claim): array {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return [];
		}

		$index = [];
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$rows = $this->page(objectService: $objectService, offset: ($page * self::PAGE));
			foreach ($rows as $row) {
				$account = $this->activeAccount(row: $row);
				$held = ($account['claims'][$appId][$claim] ?? null);
				if ($account !== null && is_string($held) === true && $held !== '') {
					$index[$held][] = $account;
				}
			}

			if (count($rows) < self::PAGE) {
				break;
			}
		}

		return $index;
	}//end index()

	/**
	 * One page of active accounts.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param int    $offset        The offset.
	 *
	 * @return array<int, mixed>
	 */
	private function page(object $objectService, int $offset): array {
		try {
			$objectService->setRegister(register: self::REGISTER);
			$objectService->setSchema(schema: self::SCHEMA);
			$rows = $objectService->findAll(
				config: ['filters' => ['status' => 'active'], 'limit' => self::PAGE, 'offset' => $offset],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: account listing by claim failed', ['reason' => $e->getMessage()]);
			return [];
		}

		if (is_array($rows) === false) {
			return [];
		}

		return array_values($rows);
	}//end page()

	/**
	 * The row as an account when it is active and has a subject reference.
	 * The status is checked again here: a store that ignores the filter must
	 * not hand a withdrawn account a message.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>|null
	 */
	private function activeAccount(mixed $row): ?array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		if (is_array($row) === false || ($row['status'] ?? null) !== 'active') {
			return null;
		}

		$subjectRef = ($row['subjectRef'] ?? null);
		if (is_string($subjectRef) === false || $subjectRef === '' || is_array($row['claims'] ?? null) === false) {
			return null;
		}

		return $row;
	}//end activeAccount()

	/**
	 * OpenRegister's ObjectService, or null when unavailable.
	 *
	 * @return object|null
	 */
	private function objectService(): ?object {
		try {
			$service = $this->container->get(self::OBJECT_SERVICE);
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($service) === true) {
			return $service;
		}

		return null;
	}//end objectService()
}//end class
