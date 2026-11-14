<?php

/**
 * Contract of PortalPdfExport
 *
 * The public surface of PortalPdfExport that other components call, so they depend
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
 * Contract of PortalPdfExport.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalPdfExportInterface {

	/**
	 * What render() answers when OpenRegister refuses the row count.
	 *
	 * @var string
	 */
	public const TOO_LARGE = 'too_large';

	/**
	 * Whether OpenRegister can render a PDF now.
	 *
	 * OpenRegister converts PDFs through an office server (Collabora or
	 * Euro-Office) and reports through `isPdfAvailable()` whether one is
	 * installed. An OpenRegister without that method renders by itself.
	 *
	 * @return bool True when the service resolves, has the render method, and can convert.
	 *
	 * @spec openspec/changes/archive/2026-10-09-cases-export-own-data-pdf/tasks.md#t01
	 */
	public function available(): bool;

	/**
	 * Render rows to a PDF. Null when OpenRegister cannot, TOO_LARGE when it
	 * refuses the row count.
	 *
	 * @param string $title The document title.
	 * @param array<int, array{key: string, label: string}> $columns The columns.
	 * @param array<int, array<string, string>> $rows The text rows, keyed by column key.
	 *
	 * @return string|null The PDF bytes, TOO_LARGE, or null on any other failure.
	 *
	 * @spec openspec/changes/archive/2026-10-09-cases-export-own-data-pdf/tasks.md#t04
	 */
	public function render(string $title, array $columns, array $rows): ?string;

	/**
	 * The text rows for the columns: only the columns' keys, each value as the screen shows it.
	 *
	 * @param array<int, array<string, mixed>> $columns The columns.
	 * @param array<int, array<string, mixed>> $rows The rows from the scoped read.
	 *
	 * @return array<int, array<string, string>> The rows.
	 *
	 * @spec openspec/changes/archive/2026-10-09-cases-export-own-data-pdf/tasks.md#t04
	 */
	public function textRows(array $columns, array $rows): array;

	/**
	 * The title: the collection's label, the organisation and the date.
	 *
	 * @param string $label The collection's label.
	 * @param string $organisation The organisation's name or id.
	 * @param string $date The export date, `Y-m-d`.
	 *
	 * @return string The title.
	 *
	 * @spec openspec/changes/archive/2026-10-09-cases-export-own-data-pdf/tasks.md#t04
	 */
	public function title(string $label, string $organisation, string $date): string;

	/**
	 * The columns of a list export: the collection's `columns` with their
	 * labels, else its projected `fields`.
	 *
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return array<int, array<string, mixed>> `{key, label, valueLabels?}` per column.
	 *
	 * @spec openspec/changes/archive/2026-10-09-cases-export-own-data-pdf/tasks.md#t04
	 */
	public function listColumns(array $collection): array;

	/**
	 * The columns of a record export: the detail fields, else the projected fields.
	 *
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return array<int, array<string, mixed>> `{key, label}` per column.
	 *
	 * @spec openspec/changes/archive/2026-10-09-cases-export-own-data-pdf/tasks.md#t04
	 */
	public function recordColumns(array $collection): array;
}//end interface
