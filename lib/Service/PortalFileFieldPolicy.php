<?php

/**
 * Portaliq Portal File Field Policy (assignment-portal-file-upload)
 *
 * The rules behind an upload into a declared file field, kept out of the
 * controller so each one can be tested on its own: which action field is a
 * file field, how long a create action keeps its upload window open, what a
 * field accepts and how large a file may be, how the new reference joins the
 * value already there, and how the multipart part is read.
 *
 * No I/O except reading the uploaded temp file. Nothing here authorises a
 * subject; the controller has proven the subject and the object before it
 * asks.
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
 * @spec openspec/changes/assignment-portal-file-upload/specs/portal-contribution-contract/spec.md#requirement-a-subject-must-be-able-to-upload-into-a-declared-file-field-of-an-object-they-own
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use DateTimeImmutable;
use finfo;
use OCA\Portaliq\Contribution\FileFieldConfigNormaliser;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use Throwable;

/**
 * Decides the checks of one upload into a declared file field.
 *
 * @spec openspec/changes/assignment-portal-file-upload/specs/portal-contribution-contract/spec.md#requirement-a-subject-must-be-able-to-upload-into-a-declared-file-field-of-an-object-they-own
 */
class PortalFileFieldPolicy {
	/**
	 * Refusal: the file is larger than the field allows (HTTP 413).
	 */
	public const ERROR_TOO_LARGE = 'file_too_large';

	/**
	 * Refusal: the file matches none of the field's `accept` entries (HTTP 415).
	 */
	public const ERROR_TYPE_REFUSED = 'file_type_refused';

	/**
	 * How long after its creation a create action may still add files, in seconds.
	 */
	public const CREATE_WINDOW_SECONDS = 1800;

	/**
	 * The most references one file field holds.
	 */
	public const MAX_FILES_PER_FIELD = 20;

	/**
	 * The detected types a file with one of these extensions may have. An
	 * extension `accept` entry only passes a file whose bytes fit its name,
	 * so an HTML page renamed to `.pdf` is refused.
	 */
	private const EXTENSION_TYPES = [
		'pdf' => ['application/pdf'],
		'png' => ['image/png'],
		'jpg' => ['image/jpeg'],
		'jpeg' => ['image/jpeg'],
		'gif' => ['image/gif'],
		'webp' => ['image/webp'],
		'svg' => ['image/svg+xml'],
		'html' => ['text/html'],
		'htm' => ['text/html'],
		'xml' => ['text/xml', 'application/xml'],
	];

	/**
	 * Extensions of plain text. Their bytes may sniff as any text type, or
	 * JSON (a note that starts with `#include` is `text/x-c`), as long as it
	 * is not one of {@see self::ACTIVE_TYPES}.
	 */
	private const TEXT_EXTENSIONS = ['txt', 'csv'];

	/**
	 * Types a browser runs or renders as a page. A file of an extension not
	 * in {@see self::EXTENSION_TYPES} or {@see self::TEXT_EXTENSIONS} passes
	 * on its name unless its bytes are one of these, and a `type/*` entry
	 * never admits them: only an entry that names the type does.
	 */
	private const ACTIVE_TYPES = [
		'text/html',
		'application/xhtml+xml',
		'image/svg+xml',
		'text/xml',
		'application/xml',
		'text/javascript',
		'application/javascript',
		'application/x-javascript',
	];

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The clock for the create window.
	 * @param PortalSchemaReader $schemaReader Tells an array field from a string one.
	 */
	public function __construct(
		private readonly ITimeFactory $time,
		private readonly PortalSchemaReader $schemaReader,
	) {
	}//end __construct()

	/**
	 * The normalised config of a declared file field on an action, or null.
	 *
	 * The field must be in the action's `fields` whitelist and its
	 * `fieldConfigs` entry must carry `type: file`. The action must be a create
	 * or update action; the normaliser already dropped the type elsewhere, and
	 * this repeats it because it is the gate.
	 *
	 * @param array<string, mixed> $action The matched (normalised) action.
	 * @param string $field The requested field.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/assignment-portal-file-upload/specs/portal-contribution-contract/spec.md#requirement-a-subject-must-be-able-to-upload-into-a-declared-file-field-of-an-object-they-own
	 */
	public function fileConfig(array $action, string $field): ?array {
		if (in_array(($action['type'] ?? ''), ['create', 'update'], true) === false
			|| in_array($field, (array)($action['fields'] ?? []), true) === false
		) {
			return null;
		}

		$config = ($action['fieldConfigs'][$field] ?? null);
		if (is_array($config) === false || ($config['type'] ?? null) !== FileFieldConfigNormaliser::TYPE_FILE) {
			return null;
		}

		return $config;
	}//end fileConfig()

	/**
	 * Whether the action may still add a file to this object.
	 *
	 * An update action always may; the leaf app's lifecycle decides what an
	 * update may change. A create action may only for thirty minutes after the
	 * object was created, read from its own `@self.created`. An absent or
	 * unreadable timestamp closes the window (fail closed).
	 *
	 * @param string $actionType The action's type.
	 * @param array<string, mixed> $row The owned object.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/assignment-portal-file-upload/specs/portal-contribution-contract/spec.md#requirement-a-subject-must-be-able-to-upload-into-a-declared-file-field-of-an-object-they-own
	 */
	public function windowOpen(string $actionType, array $row): bool {
		if ($actionType === 'update') {
			return true;
		}

		$created = ($row['@self']['created'] ?? null);
		if (is_string($created) === false || $created === '') {
			return false;
		}

		try {
			$createdAt = (new DateTimeImmutable($created))->getTimestamp();
		} catch (Throwable $e) {
			return false;
		}

		$age = ($this->time->getTime() - $createdAt);
		return $age >= 0 && $age <= self::CREATE_WINDOW_SECONDS;
	}//end windowOpen()

	/**
	 * Why this file may not go into this field, or null when it may.
	 *
	 * Size first, against `maxSizeMb` (default twenty). Then `accept`, which
	 * matches like the browser attribute: an extension entry against the
	 * lowercased file name, a MIME entry (with `type/*`) against the type
	 * detected in the bytes. An extension entry also needs bytes that fit the
	 * name ({@see self::contentFitsExtension()}). Any match passes; no
	 * `accept` accepts any file.
	 *
	 * @param array<string, mixed> $config The file field's config.
	 * @param string $fileName The sanitised file name.
	 * @param string $content The file bytes.
	 *
	 * @return string|null One of the ERROR_* constants, or null.
	 *
	 * @spec openspec/changes/assignment-portal-file-upload/specs/portal-contribution-contract/spec.md#requirement-a-subject-must-be-able-to-upload-into-a-declared-file-field-of-an-object-they-own
	 */
	public function refusal(array $config, string $fileName, string $content): ?string {
		$limitMb = ($config['maxSizeMb'] ?? FileFieldConfigNormaliser::DEFAULT_SIZE_MB);
		$limitMb = max(1, min(FileFieldConfigNormaliser::MAX_SIZE_MB, (int)$limitMb));
		if (strlen($content) > ($limitMb * 1024 * 1024)) {
			return self::ERROR_TOO_LARGE;
		}

		$accept = array_values(array_filter((array)($config['accept'] ?? []), 'is_string'));
		if ($accept === []) {
			return null;
		}

		$extension = strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION));
		$mime = strtolower((string)(new finfo(FILEINFO_MIME_TYPE))->buffer($content));
		foreach ($accept as $entry) {
			if ($this->accepts(entry: strtolower($entry), extension: $extension, mime: $mime) === true) {
				return null;
			}
		}

		return self::ERROR_TYPE_REFUSED;
	}//end refusal()

	/**
	 * The field value once the new reference is added, or null when the field
	 * is full.
	 *
	 * An array-shaped field that is `multiple` keeps its existing string
	 * references and gains the new one at the end. Every other case writes the
	 * new reference as the only value: a one-element array for an array
	 * property, a string otherwise.
	 *
	 * @param array<string, mixed> $config The file field's config.
	 * @param mixed $current The value the object holds now.
	 * @param string $fileId The new file's id.
	 * @param bool|null $arrayProperty Whether the schema property is an array;
	 *                                 null when the schema could not be read.
	 *
	 * @return array<int, string>|string|null
	 *
	 * @spec openspec/changes/assignment-portal-file-upload/specs/portal-contribution-contract/spec.md#requirement-a-subject-must-be-able-to-upload-into-a-declared-file-field-of-an-object-they-own
	 */
	public function mergedValue(array $config, mixed $current, string $fileId, ?bool $arrayProperty): array|string|null {
		if ($this->hasRoom(config: $config, current: $current, arrayProperty: $arrayProperty) === false) {
			return null;
		}

		$multiple = (($config['multiple'] ?? false) === true);
		if (($arrayProperty ?? $multiple) === false) {
			return $fileId;
		}

		if ($multiple === false) {
			return [$fileId];
		}

		$existing = $this->references(current: $current);
		$existing[] = $fileId;
		return $existing;
	}//end mergedValue()

	/**
	 * Whether the field can take one more reference.
	 *
	 * Only an appending field (multiple and array-shaped) can fill up; a
	 * single field is replaced, so it always has room. Asked before the
	 * attach, so a full field never gains an orphan file in the object's
	 * folder.
	 *
	 * @param array<string, mixed> $config The file field's config.
	 * @param mixed $current The value the object holds now.
	 * @param bool|null $arrayProperty Whether the schema property is an array.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/assignment-portal-file-upload/specs/portal-contribution-contract/spec.md#requirement-a-subject-must-be-able-to-upload-into-a-declared-file-field-of-an-object-they-own
	 */
	public function hasRoom(array $config, mixed $current, ?bool $arrayProperty): bool {
		$multiple = (($config['multiple'] ?? false) === true);
		if ($multiple === false || ($arrayProperty ?? $multiple) === false) {
			return true;
		}

		return count($this->references(current: $current)) < self::MAX_FILES_PER_FIELD;
	}//end hasRoom()

	/**
	 * Whether the schema property behind a field is an array, or null when the
	 * schema cannot be read or says nothing usable.
	 *
	 * @param string $schemaSlug The schema slug.
	 * @param string $field The field.
	 *
	 * @return bool|null
	 *
	 * @spec openspec/changes/assignment-portal-file-upload/specs/portal-contribution-contract/spec.md#requirement-a-subject-must-be-able-to-upload-into-a-declared-file-field-of-an-object-they-own
	 */
	public function isArrayProperty(string $schemaSlug, string $field): ?bool {
		$schema = $this->schemaReader->readSchema(slug: $schemaSlug);
		$type = ($schema['properties'][$field]['type'] ?? null);
		if (is_string($type) === false) {
			return null;
		}

		return $type === 'array';
	}//end isArrayProperty()

	/**
	 * The string references a field holds now; anything else is dropped.
	 *
	 * @param mixed $current The value the object holds now.
	 *
	 * @return array<int, string>
	 */
	private function references(mixed $current): array {
		if (is_array($current) === false) {
			return [];
		}

		return array_values(array_filter($current, static fn ($ref) => is_string($ref) === true && $ref !== ''));
	}//end references()

	/**
	 * Read the multipart part `file` into a sanitised name and its bytes, or
	 * null when there is nothing usable.
	 *
	 * The name is reduced to its basename, never a client path, and becomes
	 * `upload` when that leaves nothing.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return array{name: string, content: string}|null
	 *
	 * @spec openspec/changes/assignment-portal-file-upload/specs/portal-contribution-contract/spec.md#requirement-a-subject-must-be-able-to-upload-into-a-declared-file-field-of-an-object-they-own
	 */
	public function readUpload(IRequest $request): ?array {
		$uploaded = $request->getUploadedFile('file');
		$tmpName = (string)($uploaded['tmp_name'] ?? '');
		if ((int)($uploaded['error'] ?? 1) !== 0 || $tmpName === '' || is_readable($tmpName) === false) {
			return null;
		}

		$content = file_get_contents($tmpName);
		if ($content === false) {
			return null;
		}

		$fileName = basename((string)($uploaded['name'] ?? 'upload'));
		if ($fileName === '' || $fileName === '.' || $fileName === '..') {
			$fileName = 'upload';
		}

		return ['name' => $fileName, 'content' => $content];
	}//end readUpload()

	/**
	 * Whether one `accept` entry admits the file.
	 *
	 * @param string $entry The lowercased entry.
	 * @param string $extension The lowercased file extension, without the dot.
	 * @param string $mime The detected MIME type.
	 *
	 * @return bool
	 */
	private function accepts(string $entry, string $extension, string $mime): bool {
		if (str_starts_with($entry, '.') === true) {
			return $extension !== '' && $entry === '.' . $extension && $this->contentFitsExtension(extension: $extension, mime: $mime);
		}

		if (str_ends_with($entry, '/*') === true) {
			// A wildcard never lets active content in (`image/*` would admit an
			// SVG with a script in it); a field that wants it names the type.
			return str_starts_with($mime, substr($entry, 0, -1)) && in_array($mime, self::ACTIVE_TYPES, true) === false;
		}

		return $entry === $mime;
	}//end accepts()

	/**
	 * Whether the type detected in the bytes fits the file's extension: for
	 * plain text any text type or JSON that is not active content, for a
	 * known extension one of its own types, else any type a browser does not
	 * run or render as a page.
	 *
	 * @param string $extension The lowercased file extension, without the dot.
	 * @param string $mime The detected MIME type.
	 *
	 * @return bool
	 */
	private function contentFitsExtension(string $extension, string $mime): bool {
		if (in_array($extension, self::TEXT_EXTENSIONS, true) === true) {
			return (str_starts_with($mime, 'text/') === true || $mime === 'application/json')
				&& in_array($mime, self::ACTIVE_TYPES, true) === false;
		}

		if (isset(self::EXTENSION_TYPES[$extension]) === true) {
			return in_array($mime, self::EXTENSION_TYPES[$extension], true);
		}

		return in_array($mime, self::ACTIVE_TYPES, true) === false;
	}//end contentFitsExtension()
}//end class
