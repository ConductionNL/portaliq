<?php

/**
 * Portaliq Collection Configuration Normaliser (contribution-manifest-v3)
 *
 * The collection half of the fail-closed v3 UI-configuration vocabulary
 * (ADR-046 / ADR-063): columns, detail layout, default sort/filters, the
 * opt-in file flags, and the `rowActions` resolution against the surviving
 * update actions of the SAME contribution.
 *
 * INVARIANT: presentation-only. It NEVER alters a collection's scope /
 * scopeClaim / via / projection and never throws — every reject path returns
 * the safe subset.
 *
 * @category Contribution
 * @package  OCA\Portaliq\Contribution
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
 * @spec openspec/changes/contribution-manifest-v3/tasks.md#T1
 * @spec openspec/specs/supplier-portal/spec.md#download-is-opt-in-per-collection-fail-closed
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Validates and sanitises the v3 collection presentation config, fail-closed.
 *
 * @spec openspec/changes/contribution-manifest-v3/tasks.md#T1
 */
class CollectionConfigNormaliser
{
    /**
     * Allowed column render kinds; anything else normalises to `text`.
     */
    private const RENDER_KINDS = ['text', 'date', 'datetime', 'badge', 'currency', 'boolean', 'link'];

    /**
     * Allowed detail layouts; anything else normalises to `card`.
     */
    private const DETAIL_LAYOUTS = ['card', 'timeline'];

    /**
     * Allowed sort directions; anything else normalises to `asc`.
     */
    private const SORT_DIRECTIONS = ['asc', 'desc'];

    /**
     * Constructor.
     *
     * @param ManifestValueNormaliser $values The shared value-level primitives.
     */
    public function __construct(
        private readonly ManifestValueNormaliser $values,
    ) {
    }//end __construct()

    /**
     * Sanitise the presentation keys on every collection.
     *
     * @param array<int, mixed> $collections The collection list.
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec openspec/specs/supplier-portal/spec.md#download-is-opt-in-per-collection-fail-closed
     */
    public function normaliseCollections(array $collections): array
    {
        $out = [];
        foreach ($collections as $collection) {
            if (is_array($collection) === false) {
                continue;
            }

            $collection = $this->normaliseColumns(collection: $collection);
            $collection = $this->normaliseDetail(collection: $collection);
            $collection = $this->normaliseDefaults(collection: $collection);
            $collection = $this->normaliseFileFlags(collection: $collection);
            $collection = $this->values->normaliseAnonymousFlag(entry: $collection);

            $out[] = $collection;
        }//end foreach

        return $out;
    }//end normaliseCollections()

    /**
     * Filter each collection's `rowActions` to ids that resolve to a `type:
     * update` action in the same contribution; drop the key when it empties out
     * or is malformed. A per-row transition can only ever invoke an update
     * action the subject already holds.
     *
     * @param array<int, array<string, mixed>> $collections The sanitised collections.
     * @param array<int, array<string, mixed>> $actions     The sanitised actions.
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec openspec/changes/contribution-manifest-v3/tasks.md#T1
     */
    public function resolveRowActions(array $collections, array $actions): array
    {
        $updateIds = $this->updateActionIds(actions: $actions);
        foreach ($collections as $index => $collection) {
            $collections[$index] = $this->resolveEntryRowActions(collection: $collection, updateIds: $updateIds);
        }

        return $collections;
    }//end resolveRowActions()

    /**
     * The ids of the `type: update` actions in a sanitised action list.
     *
     * @param array<int, array<string, mixed>> $actions The sanitised actions.
     *
     * @return array<int, string>
     */
    private function updateActionIds(array $actions): array
    {
        $updateIds = [];
        foreach ($actions as $action) {
            $id = ($action['id'] ?? null);
            if (($action['type'] ?? null) === 'update' && is_string($id) === true && $id !== '') {
                $updateIds[] = $id;
            }
        }

        return $updateIds;
    }//end updateActionIds()

    /**
     * Resolve ONE collection's `rowActions` against the allowed update ids.
     *
     * @param array<string, mixed> $collection The sanitised collection.
     * @param array<int, string>   $updateIds  The resolvable update-action ids.
     *
     * @return array<string, mixed>
     */
    private function resolveEntryRowActions(array $collection, array $updateIds): array
    {
        if (array_key_exists('rowActions', $collection) === false) {
            return $collection;
        }

        if (is_array($collection['rowActions']) === false) {
            unset($collection['rowActions']);
            return $collection;
        }

        $resolved = [];
        foreach ($collection['rowActions'] as $ref) {
            if (in_array($ref, $updateIds, true) === true) {
                $resolved[] = $ref;
            }
        }

        if ($resolved === []) {
            unset($collection['rowActions']);
            return $collection;
        }

        $collection['rowActions'] = $resolved;
        return $collection;
    }//end resolveEntryRowActions()

    /**
     * Coerce the opt-in file flags to strict booleans.
     *
     * `filesUpload` opts the collection into the scoped file-upload block and
     * `filesDownload` into the scoped file-download block
     * (portal-document-download). Only an explicit true enables either; a
     * malformed or absent value means false (fail-closed).
     *
     * @param array<string, mixed> $collection The collection.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/specs/supplier-portal/spec.md#download-is-opt-in-per-collection-fail-closed
     */
    private function normaliseFileFlags(array $collection): array
    {
        foreach (['filesUpload', 'filesDownload'] as $flag) {
            if (array_key_exists($flag, $collection) === true) {
                $collection[$flag] = ($collection[$flag] === true || $collection[$flag] === 'true');
            }
        }

        return $collection;
    }//end normaliseFileFlags()

    /**
     * Keep only well-formed `columns`; drop the key otherwise.
     *
     * @param array<string, mixed> $collection The collection.
     *
     * @return array<string, mixed>
     */
    private function normaliseColumns(array $collection): array
    {
        if (array_key_exists('columns', $collection) === false) {
            return $collection;
        }

        if (is_array($collection['columns']) === false) {
            unset($collection['columns']);
            return $collection;
        }

        $columns = [];
        foreach ($collection['columns'] as $column) {
            $entry = $this->normaliseColumn(column: $column);
            if ($entry !== null) {
                $columns[] = $entry;
            }
        }

        $collection['columns'] = $columns;
        return $collection;
    }//end normaliseColumns()

    /**
     * Sanitise ONE column entry, or null when it carries no usable `field`.
     *
     * @param mixed $column The declared column.
     *
     * @return array<string, mixed>|null
     */
    private function normaliseColumn(mixed $column): ?array
    {
        if (is_array($column) === false) {
            return null;
        }

        $field = ($column['field'] ?? '');
        if (is_string($field) === false || $field === '') {
            return null;
        }

        $entry = ['field' => $field];
        if (isset($column['label']) === true && is_string($column['label']) === true) {
            $entry['label'] = $column['label'];
        }

        $entry['render'] = $this->values->oneOf(value: ($column['render'] ?? null), allowed: self::RENDER_KINDS, default: 'text');
        return $entry;
    }//end normaliseColumn()

    /**
     * Keep a well-formed `detail` (layout + string `fields`); drop otherwise.
     *
     * @param array<string, mixed> $collection The collection.
     *
     * @return array<string, mixed>
     */
    private function normaliseDetail(array $collection): array
    {
        if (array_key_exists('detail', $collection) === false) {
            return $collection;
        }

        if (is_array($collection['detail']) === false) {
            unset($collection['detail']);
            return $collection;
        }

        $layout = $this->values->oneOf(value: ($collection['detail']['layout'] ?? null), allowed: self::DETAIL_LAYOUTS, default: 'card');
        $detail = ['layout' => $layout];
        $fields = ($collection['detail']['fields'] ?? null);
        if (is_array($fields) === true) {
            $detail['fields'] = array_values(array_filter($fields, static fn($f) => is_string($f) === true && $f !== ''));
        }

        $collection['detail'] = $detail;
        return $collection;
    }//end normaliseDetail()

    /**
     * Keep well-formed `defaultSort` / `defaultFilters`; drop otherwise.
     *
     * @param array<string, mixed> $collection The collection.
     *
     * @return array<string, mixed>
     */
    private function normaliseDefaults(array $collection): array
    {
        if (isset($collection['defaultSort']) === true) {
            $collection = $this->normaliseDefaultSort(collection: $collection);
        }

        if (isset($collection['defaultFilters']) === true
            && $this->values->isStringKeyedScalarMap(value: $collection['defaultFilters']) === false
        ) {
            unset($collection['defaultFilters']);
        }

        return $collection;
    }//end normaliseDefaults()

    /**
     * Keep a well-formed `defaultSort` (non-empty string field + allowed
     * direction); drop the key entirely otherwise.
     *
     * @param array<string, mixed> $collection The collection (with `defaultSort` set).
     *
     * @return array<string, mixed>
     */
    private function normaliseDefaultSort(array $collection): array
    {
        $sortField = ($collection['defaultSort']['field'] ?? null);
        if (is_array($collection['defaultSort']) === false || is_string($sortField) === false || $sortField === '') {
            unset($collection['defaultSort']);
            return $collection;
        }

        $collection['defaultSort'] = [
            'field'     => $sortField,
            'direction' => $this->values->oneOf(
                value: ($collection['defaultSort']['direction'] ?? null),
                allowed: self::SORT_DIRECTIONS,
                default: 'asc'
            ),
        ];

        return $collection;
    }//end normaliseDefaultSort()
}//end class
