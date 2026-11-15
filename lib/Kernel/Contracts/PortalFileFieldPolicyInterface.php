<?php

/**
 * Contract of PortalFileFieldPolicy
 *
 * The public surface of PortalFileFieldPolicy that other components call, so they depend
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

use OCP\IRequest;

/**
 * Contract of PortalFileFieldPolicy.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalFileFieldPolicyInterface {

	/**
	 * Refusal: the file is larger than the field allows (HTTP 413).
	 */
	public const ERROR_TOO_LARGE = 'file_too_large';

	/**
	 * Refusal: the file matches none of the field's `accept` entries (HTTP 415).
	 */
	public const ERROR_TYPE_REFUSED = 'file_type_refused';

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
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-subject-must-be-able-to-upload-into-a-declared-file-field-of-an-object-they-own
	 */
	public function fileConfig(array $action, string $field): ?array;

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
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-subject-must-be-able-to-upload-into-a-declared-file-field-of-an-object-they-own
	 */
	public function hasRoom(array $config, mixed $current, ?bool $arrayProperty): bool;

	/**
	 * Whether the schema property behind a field is an array, or null when the
	 * schema cannot be read or says nothing usable.
	 *
	 * @param string $schemaSlug The schema slug.
	 * @param string $field The field.
	 *
	 * @return bool|null
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-subject-must-be-able-to-upload-into-a-declared-file-field-of-an-object-they-own
	 */
	public function isArrayProperty(string $schemaSlug, string $field): ?bool;

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
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-subject-must-be-able-to-upload-into-a-declared-file-field-of-an-object-they-own
	 */
	public function mergedValue(array $config, mixed $current, string $fileId, ?bool $arrayProperty): array|string|null;

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
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-subject-must-be-able-to-upload-into-a-declared-file-field-of-an-object-they-own
	 */
	public function readUpload(IRequest $request): ?array;

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
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-subject-must-be-able-to-upload-into-a-declared-file-field-of-an-object-they-own
	 */
	public function refusal(array $config, string $fileName, string $content): ?string;

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
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-subject-must-be-able-to-upload-into-a-declared-file-field-of-an-object-they-own
	 */
	public function windowOpen(string $actionType, array $row): bool;
}//end interface
