<?php

/**
 * The rows of opencatalogi's publication endpoint, shaped for the plain site page.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Cms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/site-honest-without-javascript/tasks.md#3.1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

/**
 * One search row, one file and the by-id check, with the field names the
 * blocks read (see `PlainPublicationReader` for where they come from).
 *
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-a-publication-can-be-read-without-javascript-req-shj-004
 */
class PlainPublicationRows {

	/**
	 * Characters of a summary kept, as the search block keeps them.
	 *
	 * @var int
	 */
	private const SUMMARY_LENGTH = 280;

	/**
	 * One result row as the plain page shows it.
	 *
	 * @param array<string, mixed> $row The endpoint's row.
	 *
	 * @return array{id: string, title: string, date: string, summary: string}
	 *
	 * @spec openspec/changes/site-honest-without-javascript/tasks.md#3.1
	 */
	public function result(array $row): array {
		$self = (array)($row['@self'] ?? []);

		return [
			'id' => $this->text(values: [$self['id'] ?? null, $row['id'] ?? null]),
			'title' => $this->text(values: [$row['name'] ?? null, $self['name'] ?? null, $self['title'] ?? null, $row['title'] ?? null]),
			'date' => $this->text(values: [$row['publicationDate'] ?? null, $self['published'] ?? null]),
			'summary' => mb_substr(
				$this->text(values: [$self['summary'] ?? null, $row['summary'] ?? null, $row['description'] ?? null]),
				0,
				self::SUMMARY_LENGTH
			),
		];
	}//end result()

	/**
	 * One file as the plain page links it.
	 *
	 * @param array<string, mixed> $file The endpoint's file.
	 *
	 * @return array{name: string, type: string, size: int, href: string}
	 *
	 * @spec openspec/changes/site-honest-without-javascript/tasks.md#3.1
	 */
	public function document(array $file): array {
		$name = $this->text(values: [$file['title'] ?? null, $file['name'] ?? null]);
		$type = strtoupper($this->text(values: [$file['extension'] ?? null]));
		if ($type === '' && preg_match('/\.([A-Za-z0-9]{1,8})$/', $name, $match) === 1) {
			$type = strtoupper($match[1]);
		}

		$href = $this->safeLink(value: $file['downloadUrl'] ?? null);
		if ($href === '') {
			$href = $this->safeLink(value: $file['accessUrl'] ?? null);
		}

		$size = 0;
		if (is_numeric($file['size'] ?? null) === true) {
			$size = max(0, (int)$file['size']);
		}

		if ($name === '') {
			$name = 'Document';
		}

		return ['name' => $name, 'type' => $type, 'size' => $size, 'href' => $href];
	}//end document()

	/**
	 * Whether a by-id answer is the publication asked for.
	 *
	 * @param array<string, mixed> $row The answer.
	 * @param string               $id  The id asked for.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/site-honest-without-javascript/tasks.md#3.1
	 */
	public function isPublication(array $row, string $id): bool {
		$self = (array)($row['@self'] ?? []);

		return (string)($self['id'] ?? '') === $id || (string)($row['id'] ?? '') === $id;
	}//end isPublication()

	/**
	 * The first value that is a non-blank string or a number, trimmed.
	 *
	 * @param array<int, mixed> $values The candidates, preferred first.
	 *
	 * @return string
	 */
	private function text(array $values): string {
		foreach ($values as $value) {
			if (is_string($value) === true || is_int($value) === true || is_float($value) === true) {
				$value = trim((string)$value);
				if ($value !== '') {
					return $value;
				}
			}
		}

		return '';
	}//end text()

	/**
	 * An http(s) address or a path on this instance, else ''.
	 *
	 * @param mixed $value The stored address.
	 *
	 * @return string
	 */
	private function safeLink(mixed $value): string {
		$text = '';
		if (is_string($value) === true) {
			$text = trim($value);
		}

		if (preg_match('#^https?://#i', $text) === 1 || preg_match('#^/(?!/)#', $text) === 1) {
			return $text;
		}

		return '';
	}//end safeLink()
}//end class
