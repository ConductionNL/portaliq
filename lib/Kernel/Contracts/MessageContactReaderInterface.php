<?php

/**
 * Contract of MessageContactReader
 *
 * The public surface of MessageContactReader that other components call, so they depend
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
 * Contract of MessageContactReader.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MessageContactReaderInterface {

	/**
	 * The resident's contacts, one entry per person per record, and the form's
	 * own words: `{composeLabel, composeHint, contacts: [{staffRef, name, role,
	 * recordRef, recordLabel, collection}]}`.
	 *
	 * @param array<string, mixed> $subject The resolved portal subject.
	 *
	 * @return array{composeLabel: string, composeHint: string, contacts: array<int, array<string, string>>}
	 *
	 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-a-resident-may-start-a-conversation-only-with-a-contact-of-their-own-record
	 */
	public function contactsFor(array $subject): array;

	/**
	 * The one contact a new conversation names, proven now: the record is
	 * read again through the scoped reader and the provider asked again. Null
	 * when the record is not the resident's or the provider does not name
	 * that person for it.
	 *
	 * @param array<string, mixed> $subject   The resolved portal subject.
	 * @param string               $staffRef  The person the resident writes to.
	 * @param string               $recordRef The record the conversation is about.
	 *
	 * @return array<string, string>|null
	 *
	 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-a-resident-may-start-a-conversation-only-with-a-contact-of-their-own-record
	 */
	public function contactFor(array $subject, string $staffRef, string $recordRef): ?array;
}//end interface
