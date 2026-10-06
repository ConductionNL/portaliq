<?php

/**
 * Portaliq Example Resident Way In
 *
 * The two rows that let the example resident sign in: a portal account whose
 * subject reference is the Nextcloud account's id, and the sign-in mode
 * `nextcloud` on the portal. `SessionController::nextcloud()` mints a session
 * only when both are there, so no other Nextcloud account gets into the
 * portal through this.
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
 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-the-resident-must-sign-in-without-a-test-door
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\ExampleResident;

/**
 * Writes and withdraws the portal account and the portal's sign-in mode.
 *
 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-the-resident-must-sign-in-without-a-test-door
 */
class ExampleResidentWayIn {
	/**
	 * The register the portal and the portal account live in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * Constructor.
	 *
	 * @param ExampleResidentStore $store Reads and writes the rows.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExampleResidentStore $store,
	) {
	}//end __construct()

	/**
	 * The portal row with this slug, or null.
	 *
	 * @param string $slug The portal's slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-resident-with-one-command
	 */
	public function portal(string $slug): ?array {
		foreach ($this->store->find(register: self::REGISTER, schema: 'portal', filters: []) as $row) {
			if (($row['slug'] ?? null) === $slug) {
				return $row;
			}
		}

		return null;
	}//end portal()

	/**
	 * The id of the portal account with this subject reference, or ''.
	 *
	 * @param string $subject The subject reference.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-the-resident-must-sign-in-without-a-test-door
	 */
	public function accountOf(string $subject): string {
		foreach ($this->store->find(register: self::REGISTER, schema: 'portalAccount', filters: ['subjectRef' => $subject]) as $row) {
			if (($row['subjectRef'] ?? null) === $subject) {
				return $this->store->idOf(row: $row);
			}
		}

		return '';
	}//end accountOf()

	/**
	 * The portal account the declaration describes, for this subject.
	 *
	 * No e-mail address on purpose: the resident then meets the prompt that
	 * asks for one, as the design shows it.
	 *
	 * @param array<string, mixed> $resident The declaration's `resident`.
	 * @param string               $subject  The subject reference, which is the Nextcloud account's id.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-the-resident-must-sign-in-without-a-test-door
	 */
	public function accountFor(array $resident, string $subject): array {
		return [
			'subjectRef'     => $subject,
			'audience'       => (string)$resident['audience'],
			'organisation'   => (string)$resident['organisation'],
			'displayName'    => (string)$resident['displayName'],
			'identityType'   => 'dev',
			'identityRef'    => 'example-resident',
			'status'         => 'active',
			'contactChannel' => 'portal',
		];
	}//end accountFor()

	/**
	 * Write the portal account and return its id, or '' when the write failed.
	 *
	 * @param array<string, mixed> $account The account, from accountFor().
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-the-resident-must-sign-in-without-a-test-door
	 */
	public function createAccount(array $account): string {
		return (string)$this->store->create(register: self::REGISTER, schema: 'portalAccount', data: $account);
	}//end createAccount()

	/**
	 * One portal account by its id, or null.
	 *
	 * @param string $id The account's id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-the-install-must-prove-what-arrived
	 */
	public function account(string $id): ?array {
		return $this->store->get(register: self::REGISTER, schema: 'portalAccount', id: $id);
	}//end account()

	/**
	 * Delete a portal account.
	 *
	 * @param string $id The account's id.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-remove-an-example-resident
	 */
	public function deleteAccount(string $id): bool {
		return $this->store->delete(register: self::REGISTER, schema: 'portalAccount', id: $id);
	}//end deleteAccount()

	/**
	 * Whether the portal offers the sign-in mode.
	 *
	 * @param array<string, mixed> $portal The portal row.
	 * @param string               $mode   The mode.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-the-resident-must-sign-in-without-a-test-door
	 */
	public function offered(array $portal, string $mode): bool {
		return in_array($mode, (array)($portal['authentication']['modes'] ?? []), true);
	}//end offered()

	/**
	 * Add the sign-in mode to the portal, with its card when the portal has none for it.
	 *
	 * Everything else on the portal stays as it is.
	 *
	 * @param array<string, mixed> $portal The portal row.
	 * @param array<string, mixed> $signIn The declaration's `signIn`: `mode` and `label`.
	 *
	 * @return array{saved: bool, label: bool} Whether the portal was saved, and whether this call wrote the card.
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-the-resident-must-sign-in-without-a-test-door
	 */
	public function offer(array $portal, array $signIn): array {
		$mode    = (string)$signIn['mode'];
		$auth    = (array)($portal['authentication'] ?? []);
		$labels  = (array)($auth['modeLabels'] ?? []);
		$written = false;
		if (is_array($signIn['label'] ?? null) === true && $signIn['label'] !== [] && isset($labels[$mode]) === false) {
			$labels[$mode] = $signIn['label'];
			$written       = true;
		}

		$auth['modes'] = array_values(array_unique(array_merge((array)($auth['modes'] ?? []), [$mode])));
		if ($labels !== []) {
			$auth['modeLabels'] = $labels;
		}

		$saved = $this->store->update(
			register: self::REGISTER,
			schema: 'portal',
			id: $this->store->idOf(row: $portal),
			changes: ['authentication' => $auth]
		);

		return ['saved' => $saved, 'label' => ($saved === true && $written === true)];
	}//end offer()

	/**
	 * Take the sign-in mode off the portal again, and its card when the install wrote it.
	 *
	 * @param array<string, mixed> $portal The portal row.
	 * @param string               $mode   The mode the install added.
	 * @param bool                 $label  Whether the install wrote the card.
	 *
	 * @return bool True when the portal no longer offers the mode.
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-remove-an-example-resident
	 */
	public function withdraw(array $portal, string $mode, bool $label): bool {
		if ($this->offered(portal: $portal, mode: $mode) === false) {
			return true;
		}

		$auth          = (array)($portal['authentication'] ?? []);
		$auth['modes'] = array_values(array_diff((array)($auth['modes'] ?? []), [$mode]));
		if ($label === true && is_array($auth['modeLabels'] ?? null) === true) {
			unset($auth['modeLabels'][$mode]);
		}

		return $this->store->update(
			register: self::REGISTER,
			schema: 'portal',
			id: $this->store->idOf(row: $portal),
			changes: ['authentication' => $auth]
		);
	}//end withdraw()
}//end class
