<?php

/**
 * Contract of InboxMessageFields
 *
 * The public surface of InboxMessageFields that other components call, so they depend
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
 * Contract of InboxMessageFields.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface InboxMessageFieldsInterface {

	/**
	 * Keep a well-formed `messageFields` on an inbox collection; drop each
	 * malformed entry, and the whole key anywhere else.
	 *
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/supplier-portal/spec.md#requirement-an-inbox-collection-names-its-own-message-fields-req-imf-001
	 */
	public function normalise(array $collection): array;

	/**
	 * The literal payload of a mark-read.
	 *
	 * Never the request body: the client cannot write a field through this
	 * path. Rules:
	 *  1. An app's inbox that names `messageFields.readAt` gets the current
	 *     time in that field, unless it already holds a value (the first
	 *     moment stays).
	 *  2. Portaliq's own message with `readReceiptRequested` and no `readAt`
	 *     gets `read: true` and the current time in `readAt`.
	 *  3. Any other message gets `read: true` only. The moment of opening is
	 *     personal data and is kept only when the sender asked.
	 *
	 * @param array<string, mixed> $collection The normalised inbox collection.
	 * @param string               $now        The current time, ISO 8601.
	 * @param array<string, mixed> $row        The stored message, as the owner reads it.
	 *
	 * @return array<string, mixed> The fields to write; empty when nothing changes.
	 *
	 * @spec openspec/changes/inbox-read-receipt-on-request/tasks.md#t03
	 */
	public function readPayload(array $collection, string $now, array $row=[]): array;
}//end interface
