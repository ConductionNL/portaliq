<?php

/**
 * Contract of CollectionDocuments
 *
 * The public surface of CollectionDocuments that other components call, so they depend
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

use OCP\AppFramework\Http\StreamResponse;

/**
 * Contract of CollectionDocuments.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CollectionDocumentsInterface {

	/**
	 * What open() answers when the `opened` hook did not return true.
	 *
	 * @var string
	 */
	public const REFUSED = 'refused';

	/**
	 * The documents an object lists now, without where the files live.
	 *
	 * @param string $app The contributing app.
	 * @param array<string, mixed> $documents The collection's `documents` declaration.
	 * @param string $id The object id, already proven the subject's.
	 *
	 * @return array<int, array<string, mixed>> The entries.
	 *
	 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t4
	 */
	public function listFor(string $app, array $documents, string $id): array;

	/**
	 * Open one listed document.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param string $app The contributing app.
	 * @param array<string, mixed> $documents The collection's `documents` declaration.
	 * @param array{register: string, schema: string, id: string} $object The proven object.
	 * @param string $documentId The listed entry's id.
	 *
	 * @return StreamResponse|string The stream, or NOT_FOUND, or REFUSED.
	 *
	 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t4
	 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t5
	 */
	public function open(array $subject, string $app, array $documents, array $object, string $documentId): StreamResponse|string;
}//end interface
