<?php

/**
 * Portaliq Registered Details Shape
 *
 * Maps the raw answer of OpenRegister's BRP and KvK lookups to the small,
 * fixed shape the portal shows (identity-registered-details design D3). The
 * raw object never leaves this class: no BSN, no address object id, no
 * transport metadata.
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
 * @spec openspec/changes/identity-registered-details/specs/registered-details/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

/**
 * The fixed shapes of a person and a company record.
 *
 * @spec openspec/changes/identity-registered-details/specs/registered-details/spec.md
 */
class RegisteredDetailsShape {

	/**
	 * A HaalCentraal BRP 2 person as the portal shows it.
	 *
	 * @param array<string, mixed> $raw The person object.
	 *
	 * @return array<string, mixed> `name`, `birthDate`, `address` (street, number,
	 *                              postcode, city) and `residentsAtAddress` (null).
	 *
	 * @spec openspec/changes/identity-registered-details/specs/registered-details/spec.md#requirement-a-resident-sees-their-own-brp-record-req-ird-001
	 */
	public function person(array $raw): array {
		$name  = (array)($raw['naam'] ?? []);
		$full  = trim((string)($name['volledigeNaam'] ?? ''));
		if ($full === '') {
			$full = $this->join(parts: [$name['voornamen'] ?? '', $name['voorvoegsel'] ?? '', $name['geslachtsnaam'] ?? '']);
		}

		$born = (($raw['geboorte'] ?? [])['datum'] ?? '');
		if (is_array($born) === true) {
			$born = ($born['datum'] ?? '');
		}

		$address = (array)((($raw['verblijfplaats'] ?? [])['verblijfadres'] ?? []));

		return [
			'name'               => $full,
			'birthDate'          => (string)$born,
			'address'            => [
				'street'   => (string)($address['officieleStraatnaam'] ?? $address['korteStraatnaam'] ?? ''),
				'number'   => $this->join(parts: [$address['huisnummer'] ?? '', $address['huisletter'] ?? '', $address['huisnummertoevoeging'] ?? ''], glue: ''),
				'postcode' => (string)($address['postcode'] ?? ''),
				'city'     => (string)($address['woonplaats'] ?? ''),
			],
			// The count waits for openregister (design D5): never guessed.
			'residentsAtAddress' => null,
		];
	}//end person()

	/**
	 * KvK Zoeken rows for one KvK number as the portal shows the company.
	 *
	 * The trade name is the main branch's name, else the legal entity's, else
	 * the first row's. Every row with a branch number is a branch.
	 *
	 * @param array<int, array<string, mixed>> $rows      The rows.
	 * @param string                           $kvkNumber The number asked for.
	 *
	 * @return array<string, mixed> `tradeName`, `kvkNumber`, `legalForm` and
	 *                              `branches` (number, name, address, main).
	 *
	 * @spec openspec/changes/identity-registered-details/specs/registered-details/spec.md#requirement-a-business-user-sees-their-companys-kvk-record-req-ird-002
	 */
	public function company(array $rows, string $kvkNumber): array {
		$names     = ['hoofdvestiging' => '', 'rechtspersoon' => ''];
		$legalForm = '';
		$branches  = [];
		foreach ($rows as $row) {
			$name = (string)($row['naam'] ?? $row['handelsnaam'] ?? '');
			$type = (string)($row['type'] ?? '');
			if (isset($names[$type]) === true && $names[$type] === '') {
				$names[$type] = $name;
			}

			if ($legalForm === '') {
				$legalForm = (string)($row['rechtsvorm'] ?? '');
			}

			$branch = $this->branch(row: $row, name: $name, type: $type);
			if ($branch !== null) {
				$branches[] = $branch;
			}
		}

		return [
			'tradeName' => $this->firstFilled(values: [$names['hoofdvestiging'], $names['rechtspersoon'], (string)(($rows[0] ?? [])['naam'] ?? '')]),
			'kvkNumber' => $kvkNumber,
			'legalForm' => $legalForm,
			'branches'  => $branches,
		];
	}//end company()

	/**
	 * One KvK row as a branch, or null for a row without a branch number.
	 *
	 * @param array<string, mixed> $row  The row.
	 * @param string               $name The row's name.
	 * @param string               $type The row's type.
	 *
	 * @return array{number: string, name: string, address: string, main: bool}|null
	 */
	private function branch(array $row, string $name, string $type): ?array {
		$number = (string)($row['vestigingsnummer'] ?? '');
		if ($number === '') {
			return null;
		}

		return ['number' => $number, 'name' => $name, 'address' => $this->kvkAddress(row: $row), 'main' => ($type === 'hoofdvestiging')];
	}//end branch()

	/**
	 * The first value that is not empty, or ''.
	 *
	 * @param array<int, string> $values The candidates, in order.
	 *
	 * @return string
	 */
	private function firstFilled(array $values): string {
		foreach ($values as $value) {
			if ($value !== '') {
				return $value;
			}
		}

		return '';
	}//end firstFilled()

	/**
	 * One KvK row's address on one line: "street number, postcode city".
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private function kvkAddress(array $row): string {
		$address = (array)((($row['adres'] ?? [])['binnenlandsAdres'] ?? []));
		if ($address === []) {
			// KvK Zoeken v1 rows carry the address flat on the row.
			$address = $row;
		}

		$street = $this->join(parts: [$address['straatnaam'] ?? '', $address['huisnummer'] ?? '', $address['huisletter'] ?? '']);
		$place  = $this->join(parts: [$address['postcode'] ?? '', $address['plaats'] ?? '']);

		return $this->join(parts: [$street, $place], glue: ', ');
	}//end kvkAddress()

	/**
	 * Join the non-empty parts.
	 *
	 * @param array<int, mixed> $parts The parts.
	 * @param string            $glue  What goes between them.
	 *
	 * @return string
	 */
	private function join(array $parts, string $glue = ' '): string {
		$kept = [];
		foreach ($parts as $part) {
			if (is_scalar($part) === false) {
				continue;
			}

			$text = trim((string)$part);
			if ($text !== '') {
				$kept[] = $text;
			}
		}

		return implode($glue, $kept);
	}//end join()
}//end class
