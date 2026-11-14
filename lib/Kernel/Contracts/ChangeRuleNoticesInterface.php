<?php

/**
 * Contract of ChangeRuleNotices
 *
 * The public surface of ChangeRuleNotices that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Portaliq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel\Contracts;

/**
 * Contract of ChangeRuleNotices.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ChangeRuleNoticesInterface {

	/**
	 * The accounts that hear about this record, each with the record as they
	 * may read it.
	 *
	 * @param string               $appId      The contributing app.
	 * @param string               $field      The record field holding the claim value.
	 * @param string               $claim      The app's claim name.
	 * @param array<string, mixed> $collection The rule's collection.
	 * @param array<string, mixed> $data       The record after the change.
	 * @param array{id: string, organisation: string} $record The record's uuid and organisation uuid.
	 *
	 * @return array<int, array{account: array<string, mixed>, row: array<string, mixed>}>
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md
	 * @spec openspec/changes/portal-acts-for-its-organisation/specs/portal-tenancy/spec.md#requirement-claim-addressed-recipients-are-found-in-the-records-organisation-req-pao-003
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- each argument is one
	 * part of the declared rule; an options array would hide which one a
	 * caller forgot.
	 */
	public function recipients(string $appId, string $field, string $claim, array $collection, array $data, array $record): array;

	/**
	 * The rule's own subject and body for the new value, or null when it has
	 * no words for it.
	 *
	 * @param array<string, mixed> $messages The rule's messages, by value.
	 * @param string               $value    The field's new value.
	 * @param array<string, mixed> $row      The record as the resident may read it.
	 * @param string               $language The portal's language.
	 *
	 * @return array{subject: string, body: string}|null
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md
	 */
	public function text(array $messages, string $value, array $row, string $language): ?array;
}//end interface
