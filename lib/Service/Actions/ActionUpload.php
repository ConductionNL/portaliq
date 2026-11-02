<?php

/**
 * Portaliq Action Upload
 *
 * The one file an action keeps: PDF, JPG or PNG, at most 10 MB.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Actions
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
 * @spec openspec/changes/personal-action-list/tasks.md#t03
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Actions;

/**
 * Accepts a file by its extension and by what its first bytes say it is, so
 * a renamed file of another type is refused.
 *
 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-a-resident-keeps-a-list-of-own-actions-req-ral-001
 */
class ActionUpload {

	public const MAX_BYTES = 10485760;

	/**
	 * The first bytes of each accepted type, by extension.
	 *
	 * @var array<string, string>
	 */
	private const SIGNATURES = [
		'pdf'  => '%PDF-',
		'jpg'  => "\xFF\xD8\xFF",
		'jpeg' => "\xFF\xD8\xFF",
		'png'  => "\x89PNG\r\n\x1A\n",
	];

	/**
	 * Whether the file may be kept with an action.
	 *
	 * @param string $name The file's name.
	 * @param string $content The file's bytes.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-a-resident-keeps-a-list-of-own-actions-req-ral-001
	 */
	public function accepts(string $name, string $content): bool {
		$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		$signature = (self::SIGNATURES[$extension] ?? null);
		if ($signature === null || $content === '' || strlen($content) > self::MAX_BYTES) {
			return false;
		}

		return str_starts_with($content, $signature);
	}//end accepts()
}//end class
