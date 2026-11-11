<?php

/**
 * Portaliq Address Lookup (data-lookups-and-checks-in-forms)
 *
 * Street and town for a postcode and house number, read from OpenRegister's
 * BAG register. Portaliq stores none of it (ADR-022); a miss and an
 * unreachable register both answer null, so the form falls back to typing.
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
 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t01
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Finds the street and town of an address by its postcode and number.
 *
 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t01
 */
class PortalAddressLookup {
	/**
	 * OpenRegister's BAG register and its address schema.
	 */
	public const REGISTER = 'bag';

	/**
	 * The schema of one address (nummeraanduiding).
	 */
	public const SCHEMA = 'nummeraanduiding';

	/**
	 * OpenRegister's object service.
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's object service.
	 * @param DutchFormats       $formats   Normalises the postcode.
	 * @param LoggerInterface    $logger    Logs a register that could not be read.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly DutchFormats $formats,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The street and town of an address, or null when none is found.
	 *
	 * @param string $postcode The postcode, the space optional.
	 * @param string $number   The house number, digits only.
	 * @param string $letter   The house letter, or ''.
	 * @param string $addition The house number addition, or ''.
	 *
	 * @return array{street: string, town: string}|null
	 *
	 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t01
	 */
	public function find(string $postcode, string $number, string $letter='', string $addition=''): ?array {
		$normalised = $this->formats->normalise(format: 'postcode', value: $postcode);
		if ($normalised === null || preg_match('/^[1-9]\d{0,4}$/', $number) !== 1) {
			return null;
		}

		$query = new AddressQuery(postcode: $normalised, number: $number, letter: $letter, addition: $addition);
		$row   = $this->firstRow(filters: $this->filters(query: $query));
		if ($row === null) {
			return null;
		}

		return $this->streetAndTown(row: $row);
	}//end find()

	/**
	 * The first row the register answers for the filters, as an array; null
	 * when there is none or the register could not be read.
	 *
	 * @param array<string, mixed> $filters The register filters.
	 *
	 * @return array<string, mixed>|null
	 */
	private function firstRow(array $filters): ?array {
		$rows = $this->rowsFor(filters: $filters);
		if ($rows === null) {
			return null;
		}

		return $this->plainRow(row: ($rows[0] ?? null));
	}//end firstRow()

	/**
	 * The register's rows for the filters, at most one; null when it could not be read.
	 *
	 * @param array<string, mixed> $filters The register filters.
	 *
	 * @return array<mixed>|null
	 */
	private function rowsFor(array $filters): ?array {
		try {
			$service = $this->container->get(self::OBJECT_SERVICE);
			$service->setRegister(register: self::REGISTER);
			$service->setSchema(schema: self::SCHEMA);
			return (array)$service->findAll(
				config: ['filters' => $filters, 'limit' => 1, 'offset' => 0],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: the address register could not be read', ['reason' => $e->getMessage()]);

			return null;
		}
	}//end rowsFor()

	/**
	 * A row as an array: an entity serialised, an array as it is; null for anything else.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>|null
	 */
	private function plainRow(mixed $row): ?array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		if (is_array($row) === false) {
			return null;
		}

		return $row;
	}//end plainRow()

	/**
	 * The street and town of a row, or null when it lacks either.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array{street: string, town: string}|null
	 */
	private function streetAndTown(array $row): ?array {
		$street = $this->streetOf(row: $row);
		$town   = trim((string)($row['woonplaats'] ?? ''));
		if ($street === '' || $town === '') {
			return null;
		}

		return ['street' => $street, 'town' => $town];
	}//end streetAndTown()

	/**
	 * A row's street: its public space name, else its street.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private function streetOf(array $row): string {
		return trim((string)($row['openbareRuimte'] ?? $row['straat'] ?? ''));
	}//end streetOf()

	/**
	 * The register filters for a normalised postcode and a house number.
	 *
	 * @param AddressQuery $query The address asked for.
	 *
	 * @return array<string, mixed>
	 */
	private function filters(AddressQuery $query): array {
		$filters = ['postcode' => str_replace(' ', '', $query->postcode), 'huisnummer' => (int)$query->number];
		if ($query->letter !== '') {
			$filters['huisletter'] = strtoupper(substr($query->letter, 0, 1));
		}

		if ($query->addition !== '') {
			$filters['huisnummertoevoeging'] = substr($query->addition, 0, 4);
		}

		return $filters;
	}//end filters()
}//end class
