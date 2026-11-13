<?php

/**
 * Contract of CitizenCaseDocuments
 *
 * The public surface of CitizenCaseDocuments that other components call, so they depend
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

use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;

/**
 * Contract of CitizenCaseDocuments.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CitizenCaseDocumentsInterface {

	/**
	 * The entries on this case, as the browser may see them: decisions first,
	 * newest first, then the other documents as the app gave them, then the
	 * resident's own uploads.
	 *
	 * @param array<string, mixed> $context  The resolved case context (app, documents, filesDownload).
	 * @param string               $register The case's register.
	 * @param string               $schema   The case's schema.
	 * @param string               $id       The case id, already proven the resident's.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/citizen-case-documents/spec.md#requirement-the-decision-is-shown-first-req-cdc-003
	 */
	public function listFor(array $context, string $register, string $schema, string $id): array;

	/**
	 * The download route's answer: the file, 401 without a session, or one
	 * identical 404 for a foreign case, a missing one and an unlisted id, so
	 * a document id gives no existence oracle (REQ-CDC-002).
	 *
	 * @param array<string, mixed>|JSONResponse $context    The resolved case context, or its refusal.
	 * @param string                            $register   The case's register.
	 * @param string                            $schema     The case's schema.
	 * @param string                            $id         The case id.
	 * @param string                            $documentId The entry id the screen listed.
	 *
	 * @return Response
	 *
	 * @spec openspec/specs/citizen-case-documents/spec.md#requirement-every-listed-document-opens-from-the-case-screen-req-cdc-002
	 */
	public function open(array|JSONResponse $context, string $register, string $schema, string $id, string $documentId): Response;
}//end interface
