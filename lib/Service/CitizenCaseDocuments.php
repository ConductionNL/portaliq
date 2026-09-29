<?php

/**
 * Portaliq Citizen Case Documents
 *
 * The documents a resident sees on their own case, and the one way to open
 * them (cases-documents-on-the-case). Three sources, each in its own lane:
 *
 * - what the case app publishes through its declared `documents.provider`
 *   (REQ-CDC-001), a decision first (REQ-CDC-003);
 * - without such a method, the files the organisation released on the case
 *   in OpenRegister, where the collection opted into downloads (portaliq#798);
 * - the resident's own uploads, tagged when they were sent (REQ-CDC-004).
 *
 * A listed entry never carries where its file lives. A download names only an
 * entry id; the file is looked up again, on the server, among what this case
 * lists now, so a guessed id can only pick among the resident's own documents
 * (REQ-CDC-002).
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
 * @spec openspec/changes/cases-documents-on-the-case/specs/citizen-case-documents/spec.md#requirement-every-listed-document-opens-from-the-case-screen-req-cdc-002
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCP\AppFramework\Http\StreamResponse;

/**
 * Lists and opens the documents on a resident's own case.
 *
 * @spec openspec/changes/cases-documents-on-the-case/specs/citizen-case-documents/spec.md#requirement-every-listed-document-opens-from-the-case-screen-req-cdc-002
 */
class CitizenCaseDocuments {

	/**
	 * The id prefix of a resident's own upload.
	 *
	 * @var string
	 */
	private const UPLOAD = 'upload:';

	/**
	 * The id prefix of a file the organisation released in OpenRegister.
	 *
	 * @var string
	 */
	private const RELEASED = 'released:';

	/**
	 * Constructor.
	 *
	 * @param PortalFileReader         $files     Lists and streams the case folder.
	 * @param PortalCaseDocumentReader $published Asks the case app what it published.
	 * @param PortalAuditHook          $audit     Records each download.
	 */
	public function __construct(
		private readonly PortalFileReader $files,
		private readonly PortalCaseDocumentReader $published,
		private readonly PortalAuditHook $audit,
	) {
	}//end __construct()

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
	 * @spec openspec/changes/cases-documents-on-the-case/specs/citizen-case-documents/spec.md#requirement-the-decision-is-shown-first-req-cdc-003
	 */
	public function listFor(array $context, string $register, string $schema, string $id): array {
		$entries = $this->organisationEntries(context: $context, register: $register, schema: $schema, id: $id);

		$decisions = array_values(array_filter($entries, static fn (array $e): bool => $e['kind'] === 'decision'));
		usort($decisions, static fn (array $a, array $b): int => strcmp((string)$b['date'], (string)$a['date']));
		$others = array_values(array_filter($entries, static fn (array $e): bool => $e['kind'] !== 'decision'));

		// Where a file lives stays on the server (REQ-CDC-001).
		return array_map(
			static function (array $entry): array {
				unset($entry['file']);
				return $entry;
			},
			array_merge($decisions, $others, $this->uploads(register: $register, schema: $schema, id: $id))
		);
	}//end listFor()

	/**
	 * Stream one listed document, audited, or null when this case lists no
	 * entry with that id now.
	 *
	 * @param array<string, mixed> $context    The resolved case context.
	 * @param string               $register   The case's register.
	 * @param string               $schema     The case's schema.
	 * @param string               $id         The case id, already proven the resident's.
	 * @param string               $documentId The entry id the screen listed.
	 *
	 * @return StreamResponse|null
	 *
	 * @spec openspec/changes/cases-documents-on-the-case/specs/citizen-case-documents/spec.md#requirement-every-listed-document-opens-from-the-case-screen-req-cdc-002
	 */
	public function stream(array $context, string $register, string $schema, string $id, string $documentId): ?StreamResponse {
		$file = $this->fileFor(context: $context, register: $register, schema: $schema, id: $id, documentId: $documentId);
		if ($file === null) {
			return null;
		}

		$stream = $this->files->streamFile(register: $file['register'], schema: $file['schema'], id: $file['id'], fileId: $file['fileId']);
		if ($stream === null) {
			return null;
		}

		$subject = (array)($context['subject'] ?? []);
		$this->audit->download(
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			organisation: (string)($subject['organisation'] ?? ''),
			register: $register,
			schema: $schema,
			id: $id
		);

		return $stream;
	}//end stream()

	/**
	 * Where the listed entry's bytes live, looked up again among what this
	 * case lists now, or null.
	 *
	 * @param array<string, mixed> $context    The resolved case context.
	 * @param string               $register   The case's register.
	 * @param string               $schema     The case's schema.
	 * @param string               $id         The case id.
	 * @param string               $documentId The entry id.
	 *
	 * @return array{register: string, schema: string, id: string, fileId: string}|null
	 */
	private function fileFor(array $context, string $register, string $schema, string $id, string $documentId): ?array {
		if ($documentId === '') {
			return null;
		}

		if (str_starts_with($documentId, self::UPLOAD) === true) {
			$listed = $this->uploads(register: $register, schema: $schema, id: $id);
		} else {
			$listed = $this->organisationEntries(context: $context, register: $register, schema: $schema, id: $id);
		}

		foreach ($listed as $entry) {
			if ($entry['id'] === $documentId) {
				return $entry['file'];
			}
		}

		return null;
	}//end fileFor()

	/**
	 * What the organisation made visible: the app's published documents when
	 * it declares a method, else the released files where the collection
	 * opted into downloads. Entries still carry their `file`.
	 *
	 * @param array<string, mixed> $context  The resolved case context.
	 * @param string               $register The case's register.
	 * @param string               $schema   The case's schema.
	 * @param string               $id       The case id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function organisationEntries(array $context, string $register, string $schema, string $id): array {
		$provider = (string)($context['documents']['provider'] ?? '');
		if ($provider !== '') {
			return $this->published->entries(appId: (string)($context['app'] ?? ''), method: $provider, caseId: $id);
		}

		if (($context['filesDownload'] ?? false) !== true) {
			return [];
		}

		return $this->folderEntries(
			files: $this->files->listReleasedFiles(register: $register, schema: $schema, id: $id),
			prefix: self::RELEASED,
			kind: 'document',
			folder: ['register' => $register, 'schema' => $schema, 'id' => $id]
		);
	}//end organisationEntries()

	/**
	 * The resident's own uploads on the case, with their `file`.
	 *
	 * @param string $register The case's register.
	 * @param string $schema   The case's schema.
	 * @param string $id       The case id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function uploads(string $register, string $schema, string $id): array {
		return $this->folderEntries(
			files: $this->files->listTaggedFiles(register: $register, schema: $schema, id: $id, tag: PortalFileWriter::TAG_FROM_APPLICANT),
			prefix: self::UPLOAD,
			kind: 'yours',
			folder: ['register' => $register, 'schema' => $schema, 'id' => $id]
		);
	}//end uploads()

	/**
	 * Files of the case folder as entries.
	 *
	 * @param array<int, array<string, mixed>> $files  The listed files ({id, name, size}).
	 * @param string                           $prefix The id prefix.
	 * @param string                           $kind   The entry kind.
	 * @param array<string, string>            $folder The case's register, schema and id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function folderEntries(array $files, string $prefix, string $kind, array $folder): array {
		$entries = [];
		foreach ($files as $file) {
			$fileId = (string)($file['id'] ?? '');
			if ($fileId === '') {
				continue;
			}

			$entry = ['id' => $prefix.$fileId, 'title' => (string)($file['name'] ?? ''), 'kind' => $kind, 'date' => ''];
			if (is_int(($file['size'] ?? null)) === true) {
				$entry['size'] = $file['size'];
			}

			$entry['file'] = $folder + ['fileId' => $fileId];
			$entries[] = $entry;
		}

		return $entries;
	}//end folderEntries()
}//end class
