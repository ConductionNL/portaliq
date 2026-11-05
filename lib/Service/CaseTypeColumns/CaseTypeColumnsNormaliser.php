<?php

/**
 * Portaliq case type columns normaliser
 *
 * Cleans the columns an administrator sets for one case type in one portal:
 * a known field name, a render kind from a short list, and the words of a
 * link or QR code.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\CaseTypeColumns
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-type-columns-in-my-cases/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-chooses-how-each-column-of-a-case-type-reads
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\CaseTypeColumns;

/**
 * Turns a submitted list of column settings into the stored shape.
 *
 * @spec openspec/changes/case-type-columns-in-my-cases/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-chooses-how-each-column-of-a-case-type-reads
 */
class CaseTypeColumnsNormaliser {
	/**
	 * The render kinds an administrator may choose.
	 *
	 * @var array<int, string>
	 */
	public const RENDERS = ['text', 'link', 'qr', 'date'];

	/**
	 * The most columns one case type keeps.
	 *
	 * @var int
	 */
	private const MAX_COLUMNS = 30;

	/**
	 * The longest link text.
	 *
	 * @var int
	 */
	private const MAX_LINK_LABEL = 60;

	/**
	 * The stored columns: one per field, in the order given.
	 *
	 * @param array<int|string, mixed> $columns The submitted columns.
	 *
	 * @return array<int, array{field: string, render: string, linkLabel?: string}>
	 *
	 * @spec openspec/changes/case-type-columns-in-my-cases/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-chooses-how-each-column-of-a-case-type-reads
	 */
	public function normalise(array $columns): array {
		$out = [];
		foreach ($columns as $column) {
			$clean = $this->column(column: $column);
			if ($clean !== null && isset($out[$clean['field']]) === false) {
				$out[$clean['field']] = $clean;
			}
		}

		return array_slice(array_values($out), 0, self::MAX_COLUMNS);
	}//end normalise()

	/**
	 * A portal's stored columns per case type id; a type with no usable
	 * column is left out.
	 *
	 * @param array<string, mixed>|null $portal The portal.
	 *
	 * @return array<string, array<int, array{field: string, render: string, linkLabel?: string}>>
	 *
	 * @spec openspec/changes/case-type-columns-in-my-cases/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-chooses-how-each-column-of-a-case-type-reads
	 */
	public function byType(?array $portal): array {
		$stored = [];
		foreach ((array)($portal['caseTypeColumns'] ?? []) as $typeId => $columns) {
			$clean = $this->normalise(columns: (array)$columns);
			if ((string)$typeId !== '' && $clean !== []) {
				$stored[(string)$typeId] = $clean;
			}
		}

		return $stored;
	}//end byType()

	/**
	 * One column, or null when its field or render is not acceptable.
	 *
	 * @param mixed $column The submitted column.
	 *
	 * @return array{field: string, render: string, linkLabel?: string}|null
	 *
	 * @spec openspec/changes/case-type-columns-in-my-cases/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-chooses-how-each-column-of-a-case-type-reads
	 */
	private function column(mixed $column): ?array {
		if (is_array($column) === false || $this->accepts(column: $column) === false) {
			return null;
		}

		$clean = ['field' => trim((string)$column['field']), 'render' => (string)$column['render']];
		$label = $this->linkLabel(render: $clean['render'], label: (string)($column['linkLabel'] ?? ''));
		if ($label !== '') {
			$clean['linkLabel'] = $label;
		}

		return $clean;
	}//end column()

	/**
	 * Whether a column names a usable field and a known render kind.
	 *
	 * @param array<string, mixed> $column The submitted column.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/case-type-columns-in-my-cases/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-chooses-how-each-column-of-a-case-type-reads
	 */
	private function accepts(array $column): bool {
		$field = trim((string)($column['field'] ?? ''));

		return preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $field) === 1
			&& in_array((string)($column['render'] ?? ''), self::RENDERS, true) === true;
	}//end accepts()

	/**
	 * The words of a link or QR code, or '' for any other render or an
	 * empty or too long text.
	 *
	 * @param string $render The column's render kind.
	 * @param string $label The submitted words.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/case-type-columns-in-my-cases/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-chooses-how-each-column-of-a-case-type-reads
	 */
	private function linkLabel(string $render, string $label): string {
		$label = trim($label);
		if (in_array($render, ['link', 'qr'], true) === false || mb_strlen($label) > self::MAX_LINK_LABEL) {
			return '';
		}

		return $label;
	}//end linkLabel()
}//end class
