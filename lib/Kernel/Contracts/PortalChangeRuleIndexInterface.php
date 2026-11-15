<?php

/**
 * Contract of PortalChangeRuleIndex
 *
 * The public surface of PortalChangeRuleIndex that other components call, so they depend
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
 * Contract of PortalChangeRuleIndex.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalChangeRuleIndexInterface {

	/**
	 * The change rules for a record of this register and schema.
	 *
	 * @param string $register The object's register id or slug.
	 * @param string $schema   The object's schema id or slug.
	 *
	 * @return array<int, array<string, string>> Entries with app, ruleKey, collection, label, scopeField, field,
	 *                                           titleField, recipientField and recipientClaim.
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-declared-change-reaches-the-residents-inbox-req-nap-002
	 */
	public function changeRulesFor(string $register, string $schema): array;

	/**
	 * A change rule's collection and the message texts it declares per value.
	 *
	 * @param array<string, string> $entry An entry changeRulesFor() returned.
	 *
	 * @return array{collection: array<string, mixed>, messages: array<string, mixed>}
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md
	 */
	public function details(array $entry): array;

	/**
	 * The inbox collections a new record of this register and schema lands in.
	 *
	 * @param string $register The object's register id or slug.
	 * @param string $schema   The object's schema id or slug.
	 *
	 * @return array<int, array<string, string>> Entries with app, collection, register, schema, scopeField, nudge and recipientProvider.
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-case-apps-message-triggers-an-e-mail-req-nap-004
	 */
	public function inboxesFor(string $register, string $schema): array;

	/**
	 * Whether this register and schema are portaliq's own inbox messages.
	 *
	 * @param string $register The object's register id or slug.
	 * @param string $schema   The object's schema id or slug.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-case-apps-message-triggers-an-e-mail-req-nap-004
	 */
	public function isPortalMessage(string $register, string $schema): bool;
}//end interface
