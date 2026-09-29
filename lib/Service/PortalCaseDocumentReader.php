<?php

/**
 * Portaliq Portal Case Document Reader
 *
 * Reads the documents a case app published on one of a resident's cases. The
 * app names a method on its own provider in the collection's
 * `documents.provider` (dossiq: `caseDocuments`), and this calls it with the
 * case id. The app decides what is published; portaliq keeps only well-formed
 * entries and adds nothing (cases-documents-on-the-case, REQ-CDC-001).
 *
 * An entry is `{id, title, kind, date, file: {register, schema, id, fileId},
 * mimeType?, size?}`. The `file` reference is for the server only: the caller
 * strips it before anything reaches the browser.
 *
 * Calling it proves nothing about who may see the case. The caller does that
 * first, through the same scoped read as the case itself.
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
 * @spec openspec/changes/cases-documents-on-the-case/specs/citizen-case-documents/spec.md#requirement-the-case-app-declares-which-documents-a-resident-may-see-req-cdc-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Contribution\PortalProviderLocator;
use OCA\Portaliq\Contribution\TimelineProviderMethod;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Calls a case app's declared documents method.
 *
 * @spec openspec/changes/cases-documents-on-the-case/specs/citizen-case-documents/spec.md#requirement-the-case-app-declares-which-documents-a-resident-may-see-req-cdc-001
 */
class PortalCaseDocumentReader {
	/**
	 * Constructor.
	 *
	 * @param PortalProviderLocator $locator Finds the app's provider.
	 * @param LoggerInterface       $logger  Records a provider that failed or answered badly.
	 */
	public function __construct(
		private readonly PortalProviderLocator $locator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The well-formed entries the app's provider returns for one case.
	 *
	 * @param string $appId  The contributing app.
	 * @param string $method The declared `documents.provider`.
	 * @param string $caseId The case id, already proven to be the resident's.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/cases-documents-on-the-case/specs/citizen-case-documents/spec.md#requirement-the-case-app-declares-which-documents-a-resident-may-see-req-cdc-001
	 */
	public function entries(string $appId, string $method, string $caseId): array {
		$provider = $this->locator->locate(appId: $appId);
		if ($provider === null || (new TimelineProviderMethod())->callableOn(provider: $provider, method: $method) === false) {
			return [];
		}

		try {
			$answer = $provider->{$method}($caseId);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: documents provider failed', ['app' => $appId, 'method' => $method, 'reason' => $e->getMessage()]);
			return [];
		}

		if (is_array($answer) === false) {
			return [];
		}

		$entries = [];
		foreach ($answer as $entry) {
			$kept = $this->wellFormed(entry: $entry);
			if ($kept === null) {
				$this->logger->info('Portaliq: documents provider returned an entry without id, title or file', ['app' => $appId, 'method' => $method]);
				continue;
			}

			$entries[] = $kept;
		}

		return $entries;
	}//end entries()

	/**
	 * The entry in portaliq's shape, or null when it lacks an id, a title or a
	 * complete file reference.
	 *
	 * @param mixed $entry One entry as the provider returned it.
	 *
	 * @return array<string, mixed>|null
	 */
	private function wellFormed(mixed $entry): ?array {
		if (is_array($entry) === false || $this->text(value: ($entry['id'] ?? null)) === '' || $this->text(value: ($entry['title'] ?? null)) === '') {
			return null;
		}

		$file = $this->file(value: ($entry['file'] ?? null));
		if ($file === null) {
			return null;
		}

		$kept = [
			'id' => $this->text(value: $entry['id']),
			'title' => $this->text(value: $entry['title']),
			'kind' => (($entry['kind'] ?? null) === 'decision') ? 'decision' : 'document',
			'date' => $this->text(value: ($entry['date'] ?? null)),
			'file' => $file,
		];
		if (is_string(($entry['mimeType'] ?? null)) === true) {
			$kept['mimeType'] = $entry['mimeType'];
		}

		if (is_int(($entry['size'] ?? null)) === true) {
			$kept['size'] = $entry['size'];
		}

		return $kept;
	}//end wellFormed()

	/**
	 * A complete file reference, or null.
	 *
	 * @param mixed $value The entry's `file`.
	 *
	 * @return array{register: string, schema: string, id: string, fileId: string}|null
	 */
	private function file(mixed $value): ?array {
		if (is_array($value) === false) {
			return null;
		}

		$file = [];
		foreach (['register', 'schema', 'id', 'fileId'] as $key) {
			$file[$key] = $this->text(value: ($value[$key] ?? null));
			if ($file[$key] === '') {
				return null;
			}
		}

		return $file;
	}//end file()

	/**
	 * A string or integer as text, or ''.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function text(mixed $value): string {
		if (is_string($value) === true || is_int($value) === true) {
			return (string)$value;
		}

		return '';
	}//end text()
}//end class
