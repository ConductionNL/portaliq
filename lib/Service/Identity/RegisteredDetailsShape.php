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
 */
class RegisteredDetailsShape {

	/**
	 * A HaalCentraal BRP 2 person as the portal shows it.
	 *
	 * @param array<string, mixed> $raw The person object.
	 *
	 * @return array{name: string, birthDate: string, address: array{street: string, number: string, postcode: string, city: string}, residentsAtAddress: null}
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
	 * @return array{tradeName: string, kvkNumber: string, legalForm: string, branches: array<int, array{number: string, name: string, address: string, main: bool}>}
	 *
	 * @spec openspec/changes/identity-registered-details/specs/registered-details/spec.md#requirement-a-business-user-sees-their-companys-kvk-record-req-ird-002
	 */
	public function company(array $rows, string $kvkNumber): array {
		$tradeName = '';
		$entity    = '';
		$legalForm = '';
		$branches  = [];
		foreach ($rows as $row) {
			$name = (string)($row['naam'] ?? $row['handelsnaam'] ?? '');
			$type = (string)($row['type'] ?? '');
			if ($legalForm === '' && isset($row['rechtsvorm']) === true) {
				$legalForm = (string)$row['rechtsvorm'];
			}

			if ($type === 'rechtspersoon' && $entity === '') {
				$entity = $name;
			}

			if ($type === 'hoofdvestiging' && $tradeName === '') {
				$tradeName = $name;
			}

			$number = (string)($row['vestigingsnummer'] ?? '');
			if ($number !== '') {
				$branches[] = ['number' => $number, 'name' => $name, 'address' => $this->kvkAddress(row: $row), 'main' => ($type === 'hoofdvestiging')];
			}
		}

		$first = (string)(($rows[0] ?? [])['naam'] ?? '');

		return [
			'tradeName' => ($tradeName !== '' ? $tradeName : ($entity !== '' ? $entity : $first)),
			'kvkNumber' => $kvkNumber,
			'legalForm' => $legalForm,
			'branches'  => $branches,
		];
	}//end company()

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
			$text = trim((string)(is_scalar($part) === true ? $part : ''));
			if ($text !== '') {
				$kept[] = $text;
			}
		}

		return implode($glue, $kept);
	}//end join()
}//end class
