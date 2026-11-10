<?php

/**
 * An ObjectService double that can act for an organisation, as OpenRegister's
 * `runForOrganisation()` does, and records for every call which organisation
 * it ran for. Signatures follow openregister's ObjectService on the
 * act-for-an-organisation branch.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Portaliq\Tests\Doubles
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Doubles;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;

/**
 * ObjectService stand-in that acts for organisations.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class ActingObjectService {

	/**
	 * The organisation the current call acts for, or null.
	 *
	 * @var string|null
	 */
	public ?string $actingFor = null;

	/**
	 * Every call: [method, organisation acted for or null].
	 *
	 * @var array<int, array{0: string, 1: string|null}>
	 */
	public array $calls = [];

	/**
	 * The rows `findAll()` returns, per schema.
	 *
	 * @var array<string, array<int, mixed>>
	 */
	public array $rows = [];

	/**
	 * The rows `find()` returns, per id.
	 *
	 * @var array<string, mixed>
	 */
	public array $found = [];

	/**
	 * Every saved object.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $saves = [];

	/**
	 * The schema set last.
	 *
	 * @var string
	 */
	public string $schema = '';

	/**
	 * Run the operation acting for the organisation.
	 *
	 * @param string   $organisationUuid The organisation.
	 * @param callable $operation        The operation.
	 *
	 * @return mixed
	 */
	public function runForOrganisation(string $organisationUuid, callable $operation) {
		$previous = $this->actingFor;
		$this->actingFor = $organisationUuid;
		try {
			return $operation();
		} finally {
			$this->actingFor = $previous;
		}
	}//end runForOrganisation()

	/**
	 * @param mixed $register The register.
	 *
	 * @return static
	 */
	public function setRegister(mixed $register): static {
		$this->record(method: 'setRegister');
		return $this;
	}//end setRegister()

	/**
	 * @param mixed $schema The schema.
	 *
	 * @return static
	 */
	public function setSchema(mixed $schema): static {
		$this->record(method: 'setSchema');
		$this->schema = (string)$schema;
		return $this;
	}//end setSchema()

	/**
	 * @param array<string, mixed> $config        The query.
	 * @param bool                 $_rbac         Unused.
	 * @param bool                 $_multitenancy Unused.
	 *
	 * @return array<int, mixed>
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
		$this->record(method: 'findAll');
		return ($this->rows[$this->schema] ?? []);
	}//end findAll()

	/**
	 * @param int|string             $id            The id.
	 * @param ObjectFindOptions|null $options       Unused.
	 * @param bool                   $_rbac         Unused.
	 * @param bool                   $_multitenancy Unused.
	 *
	 * @return mixed
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 */
	public function find(
		int|string $id,
		?ObjectFindOptions $options = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
	): mixed {
		$this->record(method: 'find');
		return ($this->found[(string)$id] ?? null);
	}//end find()

	/**
	 * @param array<string, mixed> $object        The object.
	 * @param array|null           $extend        Unused.
	 * @param mixed                $register      Unused.
	 * @param mixed                $schema        Unused.
	 * @param string|null          $uuid          The uuid.
	 * @param bool                 $_rbac         Unused.
	 * @param bool                 $_multitenancy Unused.
	 *
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 */
	public function saveObject(
		array $object,
		?array $extend = [],
		mixed $register = null,
		mixed $schema = null,
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array {
		$this->record(method: 'saveObject');
		$this->saves[] = $object;
		return $object + ['id' => ($uuid ?? 'new-1')];
	}//end saveObject()

	/**
	 * @param string $uuid          The uuid.
	 * @param mixed  $register      Unused.
	 * @param mixed  $schema        Unused.
	 * @param bool   $_rbac         Unused.
	 * @param bool   $_multitenancy Unused.
	 *
	 * @return bool
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 */
	public function deleteObject(
		string $uuid,
		mixed $register = null,
		mixed $schema = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
	): bool {
		$this->record(method: 'deleteObject');
		return true;
	}//end deleteObject()

	/**
	 * The organisations the calls of a method acted for, in order.
	 *
	 * @param string $method The method.
	 *
	 * @return array<int, string|null>
	 */
	public function actedFor(string $method): array {
		$acted = [];
		foreach ($this->calls as [$name, $organisation]) {
			if ($name === $method) {
				$acted[] = $organisation;
			}
		}

		return $acted;
	}//end actedFor()

	/**
	 * Note a call.
	 *
	 * @param string $method The method.
	 *
	 * @return void
	 */
	private function record(string $method): void {
		$this->calls[] = [$method, $this->actingFor];
	}//end record()
}//end class
