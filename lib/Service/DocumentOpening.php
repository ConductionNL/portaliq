<?php

/**
 * Portaliq Document Opening
 *
 * One opening of a listed collection document: who opens it, in which app's
 * collection, on which proven object, and which entry.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t4
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * The state of one CollectionDocuments::open() call. Never leaves that class.
 *
 * @internal
 *
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t4
 */
final class DocumentOpening {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>                                $subject    The resolved subject.
	 * @param string                                              $app        The contributing app.
	 * @param array<string, mixed>                                $documents  The collection's `documents` declaration.
	 * @param array{register: string, schema: string, id: string} $object     The proven object.
	 * @param string                                              $documentId The listed entry's id.
	 */
	public function __construct(
		public readonly array $subject,
		public readonly string $app,
		public readonly array $documents,
		public readonly array $object,
		public readonly string $documentId,
	) {
	}//end __construct()
}//end class
