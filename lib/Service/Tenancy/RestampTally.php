<?php

/**
 * Portaliq RestampTally
 *
 * What one run of the organisation restamp found and did: per organisation
 * the rows that already carry it, the rows to restamp (or restamped) and the
 * rows whose write failed; the rows whose organisation does not resolve; and
 * the schemas that could not be read.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Tenancy
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
 * @spec openspec/changes/portal-acts-for-its-organisation/specs/portal-tenancy/spec.md#requirement-existing-rows-get-their-organisation-from-a-reported-dry-run-then-an-explicit-apply-req-pao-004
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Tenancy;

/**
 * The counts and findings of one restamp run.
 *
 * @spec openspec/changes/portal-acts-for-its-organisation/specs/portal-tenancy/spec.md#requirement-existing-rows-get-their-organisation-from-a-reported-dry-run-then-an-explicit-apply-req-pao-004
 */
final class RestampTally {

	/**
	 * The row already carries its organisation.
	 */
	public const ALREADY = 'already';

	/**
	 * The row carries another organisation; a dry run only counts it.
	 */
	public const TO_RESTAMP = 'toRestamp';

	/**
	 * The row was given its organisation.
	 */
	public const RESTAMPED = 'restamped';

	/**
	 * Writing the organisation failed.
	 */
	public const FAILED = 'failed';

	/**
	 * Per organisation slug: its uuid.
	 *
	 * @var array<string, string>
	 */
	private array $uuids = [];

	/**
	 * Per organisation slug: a count per outcome.
	 *
	 * @var array<string, array<string, int>>
	 */
	private array $counts = [];

	/**
	 * The rows whose organisation does not resolve, left as they are.
	 *
	 * @var array<int, array{schema: string, uuid: string, organisation: string}>
	 */
	private array $unresolved = [];

	/**
	 * The rows stored in another organisation than their slug names, left for a person.
	 *
	 * @var array<int, array{schema: string, uuid: string, organisation: string, stored: string}>
	 */
	private array $elsewhere = [];

	/**
	 * The schemas that could not be read, with the reason.
	 *
	 * @var array<string, string>
	 */
	private array $unreadable = [];

	/**
	 * Constructor.
	 *
	 * @param bool $apply Whether the run writes, or only reports.
	 */
	public function __construct(
		public readonly bool $apply,
	) {
	}//end __construct()

	/**
	 * Count one row for an organisation.
	 *
	 * @param array{slug: string, uuid: string} $organisation The organisation.
	 * @param string                            $outcome      One of the outcome constants.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-acts-for-its-organisation/specs/portal-tenancy/spec.md#requirement-existing-rows-get-their-organisation-from-a-reported-dry-run-then-an-explicit-apply-req-pao-004
	 */
	public function count(array $organisation, string $outcome): void {
		$slug = $organisation['slug'];
		if (isset($this->counts[$slug]) === false) {
			$this->uuids[$slug]  = $organisation['uuid'];
			$this->counts[$slug] = [self::ALREADY => 0, self::TO_RESTAMP => 0, self::RESTAMPED => 0, self::FAILED => 0];
		}

		$this->counts[$slug][$outcome] = (($this->counts[$slug][$outcome] ?? 0) + 1);
	}//end count()

	/**
	 * Note a row whose organisation does not resolve.
	 *
	 * @param array{schema: string, uuid: string, organisation: string} $row The row.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-acts-for-its-organisation/specs/portal-tenancy/spec.md#requirement-existing-rows-get-their-organisation-from-a-reported-dry-run-then-an-explicit-apply-req-pao-004
	 */
	public function unresolved(array $row): void {
		$this->unresolved[] = $row;
	}//end unresolved()

	/**
	 * Note a row stored in another organisation than its slug names.
	 *
	 * @param array{schema: string, uuid: string, organisation: string, stored: string} $row The row.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-acts-for-its-organisation/specs/portal-tenancy/spec.md#requirement-existing-rows-get-their-organisation-from-a-reported-dry-run-then-an-explicit-apply-req-pao-004
	 */
	public function elsewhere(array $row): void {
		$this->elsewhere[] = $row;
	}//end elsewhere()

	/**
	 * Note a schema that could not be read.
	 *
	 * @param string $schema The schema slug.
	 * @param string $reason Why.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-acts-for-its-organisation/specs/portal-tenancy/spec.md#requirement-existing-rows-get-their-organisation-from-a-reported-dry-run-then-an-explicit-apply-req-pao-004
	 */
	public function unreadable(string $schema, string $reason): void {
		$this->unreadable[$schema] = $reason;
	}//end unreadable()

	/**
	 * The counts, the unresolved rows and the unreadable schemas.
	 *
	 * @return array<string, mixed> `apply` (bool), `organisations` (per slug: uuid and
	 *                              a count per outcome), `unresolved` (rows),
	 *                              `elsewhere` (rows in another organisation)
	 *                              and `unreadable` (schema to reason).
	 *
	 * @spec openspec/changes/portal-acts-for-its-organisation/specs/portal-tenancy/spec.md#requirement-existing-rows-get-their-organisation-from-a-reported-dry-run-then-an-explicit-apply-req-pao-004
	 */
	public function report(): array {
		$organisations = [];
		foreach ($this->counts as $slug => $counts) {
			$organisations[$slug] = ['uuid' => $this->uuids[$slug]] + $counts;
		}

		ksort($organisations);

		return [
			'apply'         => $this->apply,
			'organisations' => $organisations,
			'unresolved'    => $this->unresolved,
			'elsewhere'     => $this->elsewhere,
			'unreadable'    => $this->unreadable,
		];
	}//end report()
}//end class
