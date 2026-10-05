<?php

/**
 * Portaliq Example Resident Objects
 *
 * Writes the objects of an example resident's declaration, in the order the
 * declaration lists them: the cases, the question the organisation still has
 * and the messages. Each object names its register and schema, so a case goes
 * to the app that handles cases. An object this instance cannot take (the app
 * is not installed, a case type is not there) is not written and is named
 * with the reason, and so is every later object that points at it.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\ExampleResident
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
 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-resident-with-one-command
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\ExampleResident;

use DateTimeImmutable;

/**
 * Writes what the declaration lists and the record does not hold yet.
 *
 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-resident-with-one-command
 */
class ExampleResidentObjects {
	/**
	 * Constructor.
	 *
	 * @param ExampleResidentStore  $store  Reads and writes rows.
	 * @param ExampleResidentValues $values Fills in what the declaration leaves open.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExampleResidentStore $store,
		private readonly ExampleResidentValues $values,
	) {
	}//end __construct()

	/**
	 * Write every declared object the record does not hold, and say what happened to each.
	 *
	 * @param array<int, array<string, mixed>>     $declared The declaration's `objects`.
	 * @param string                               $subject  The resident's subject reference.
	 * @param array<string, array<string, string>> $recorded What an earlier install created, by key.
	 * @param DateTimeImmutable                    $today    The day dates are counted from.
	 *
	 * @return array{types: array<string, array<string, int>>, dropped: array<int, string>,
	 *               written: array<string, array<string, mixed>>, recorded: array<string, array<string, string>>}
	 *         `types` counts per register and schema, `dropped` names what was not written and why, `written` holds
	 *         the data of each object this run created, and `recorded` is the record afterwards.
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-resident-with-one-command
	 */
	public function write(array $declared, string $subject, array $recorded, DateTimeImmutable $today): array {
		$outcome = ['types' => [], 'dropped' => [], 'written' => [], 'recorded' => $recorded];
		$context = ['subject' => $subject, 'objects' => [], 'today' => $today];
		foreach ($declared as $object) {
			$key  = (string)$object['key'];
			$type = $object['register'] . ' ' . $object['schema'];
			$outcome['types'][$type] ??= ['declared' => 0, 'created' => 0, 'kept' => 0, 'arrived' => 0];
			$outcome['types'][$type]['declared']++;

			$kept = $this->kept(recorded: ($recorded[$key] ?? null));
			if ($kept !== null) {
				$outcome['types'][$type]['kept']++;
				$context['objects'][$key] = $kept;
				continue;
			}

			$made = $this->make(object: $object, context: $context);
			if ($made['row'] === null) {
				$outcome['dropped'][] = $type . ' ' . $key . ': ' . $made['reason'];
				unset($outcome['recorded'][$key]);
				continue;
			}

			$outcome['types'][$type]['created']++;
			$outcome['written'][$key]  = $made['data'];
			$outcome['recorded'][$key] = ['register' => $object['register'], 'schema' => $object['schema'], 'id' => $made['row']['id']];
			$context['objects'][$key]  = $made['row'];
		}//end foreach

		return $outcome;
	}//end write()

	/**
	 * The row an earlier install created, when it is still there.
	 *
	 * @param array<string, string>|null $recorded The record's entry for the object, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function kept(?array $recorded): ?array {
		if ($recorded === null) {
			return null;
		}

		return $this->store->get(register: $recorded['register'], schema: $recorded['schema'], id: $recorded['id']);
	}//end kept()

	/**
	 * Write one object.
	 *
	 * @param array<string, mixed> $object  The declared object.
	 * @param array<string, mixed> $context The subject, the rows written so far and the day.
	 *
	 * @return array{row: array<string, mixed>|null, data: array<string, mixed>, reason: string}
	 *         The stored row, or null with the reason it was not written.
	 */
	private function make(array $object, array $context): array {
		$register = (string)$object['register'];
		$schema   = (string)$object['schema'];
		if ($this->store->offers(register: $register, schema: $schema) === false) {
			return ['row' => null, 'data' => [], 'reason' => 'this instance has no schema "' . $schema . '" in a register "' . $register . '"'];
		}

		$context['lookups'] = [];
		foreach ((array)($object['lookups'] ?? []) as $name => $lookup) {
			$found = $this->lookedUp(register: $register, lookup: (array)$lookup, context: $context);
			if ($found['id'] === '') {
				return ['row' => null, 'data' => [], 'reason' => $found['reason']];
			}

			$context['lookups'][$name] = $found['id'];
		}

		$gaps = [];
		$data = (array)$this->values->fill(value: $object['data'], context: $context, gaps: $gaps);
		if ($gaps !== []) {
			return ['row' => null, 'data' => [], 'reason' => 'it needs ' . implode(', ', array_unique($gaps)) . ', which was not written'];
		}

		foreach ((array)($object['jsonFields'] ?? []) as $field) {
			if (is_array($data[$field] ?? null) === true) {
				$data[$field] = (string)json_encode($data[$field]);
			}
		}

		$id = $this->store->create(register: $register, schema: $schema, data: $data);
		if ($id === null) {
			return ['row' => null, 'data' => [], 'reason' => 'OpenRegister refused the write; the Nextcloud log says why'];
		}

		// The row as stored, because a later object may quote a field the
		// register made itself, such as a case number.
		$row = ($this->store->get(register: $register, schema: $schema, id: $id) ?? ['id' => $id]);

		return ['row' => $row, 'data' => $data, 'reason' => ''];
	}//end make()

	/**
	 * The id of the one row a lookup describes.
	 *
	 * The match is checked here as well: a register that does not filter on a
	 * property hands over every row, and the first of those is not the one
	 * that was asked for.
	 *
	 * @param string               $register The register of the object being written.
	 * @param array<string, mixed> $lookup   `schema` and `where`; `register` when it is another one.
	 * @param array<string, mixed> $context  The subject and the lookups found so far.
	 *
	 * @return array{id: string, reason: string}
	 */
	private function lookedUp(string $register, array $lookup, array $context): array {
		$gaps   = [];
		$where  = (array)$this->values->fill(value: (array)($lookup['where'] ?? []), context: $context, gaps: $gaps);
		$schema = (string)($lookup['schema'] ?? '');
		$words  = [];
		foreach ($where as $field => $value) {
			$words[] = $field . ' "' . (string)$value . '"';
		}

		$reason = 'no ' . $schema . ' with ' . implode(' and ', $words) . ' on this instance';
		if ($gaps !== [] || $where === [] || $schema === '') {
			return ['id' => '', 'reason' => $reason];
		}

		// Asked with the filter first, then without: OpenRegister does not
		// filter on every kind of property (a reference to another object is
		// one it answers with nothing), and the match is checked here anyway.
		$from = (string)($lookup['register'] ?? $register);
		foreach ([$where, []] as $filters) {
			foreach ($this->store->find(register: $from, schema: $schema, filters: $filters) as $row) {
				if ($this->matches(row: $row, where: $where) === true) {
					return ['id' => $this->store->idOf(row: $row), 'reason' => $reason];
				}
			}
		}

		return ['id' => '', 'reason' => $reason];
	}//end lookedUp()

	/**
	 * Whether a row holds every asked value.
	 *
	 * @param array<string, mixed> $row   The row.
	 * @param array<string, mixed> $where The asked values, by field.
	 *
	 * @return bool
	 */
	private function matches(array $row, array $where): bool {
		foreach ($where as $field => $value) {
			if (($row[$field] ?? null) !== $value) {
				return false;
			}
		}

		return true;
	}//end matches()
}//end class
