<?php

/**
 * Portaliq Contribution Manifest Normaliser (contribution-manifest-v3)
 *
 * The single, fail-closed validation point for the v3 UI-configuration
 * vocabulary (ADR-046 / ADR-063). Runs per contribution AFTER trust filtering
 * and BEFORE the aggregate is returned, so that:
 *
 *  - trust-dropped collections/actions can never be referenced by a surviving
 *    page block (references resolve against the already-filtered contribution);
 *  - the frontend engine receives a canonical, safe config and never has to
 *    defend against malformed manifests itself;
 *  - a buggy or hostile contributing provider cannot widen data access through
 *    presentation config.
 *
 * INVARIANT: this class is presentation-only. It NEVER adds a field to an
 * action's `fields` whitelist, never alters a collection's scope / scopeClaim /
 * via / projection, and never throws — every reject path returns the safe
 * subset. The data-access authorities (whitelist, scope, projection) established
 * by contract v2 remain untouched; they are read-only inputs here.
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
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Validates and sanitises the v3 UI-configuration vocabulary, fail-closed.
 *
 * @spec openspec/changes/contribution-manifest-v3/tasks.md#T1
 */
class PortalManifestNormaliser
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
     * Allowed form-field sizes; anything else normalises to `medium`.
     */
    private const FIELD_SIZES = ['small', 'medium', 'large', 'full'];

    /**
     * Allowed option-provider kinds.
     */
    private const PROVIDER_KINDS = ['static', 'collection'];

    /**
     * The block-type registry. A block of any other type is dropped.
     */
    private const BLOCK_TYPES = ['collection', 'action', 'detail', 'richText', 'cta'];

    /**
     * Normalise one (already trust-filtered) contribution manifest.
     *
     * @param array<string, mixed> $contribution The trust-filtered contribution.
     *
     * @return array<string, mixed> The contribution with sanitised v3 config and
     *                              a resolved/synthesised `pages` array.
     *
     * @spec openspec/changes/contribution-manifest-v3/tasks.md#T3
     */
    public function normalise(array $contribution): array
    {
        $collections = $this->normaliseCollections(collections: (array) ($contribution['collections'] ?? []));
        $actions     = $this->normaliseActions(actions: (array) ($contribution['actions'] ?? []));

        $contribution['collections'] = $collections;
        $contribution['actions']     = $actions;
        $contribution['pages']       = $this->normalisePages(
            pages: ($contribution['pages'] ?? null),
            collections: $collections,
            actions: $actions
        );

        return $contribution;
    }//end normalise()

    /**
     * Sanitise the presentation keys on every collection.
     *
     * @param array<int, mixed> $collections The collection list.
     *
     * @return array<int, array<string, mixed>>
     */
    private function normaliseCollections(array $collections): array
    {
        $out = [];
        foreach ($collections as $collection) {
            if (is_array($collection) === false) {
                continue;
            }

            $collection = $this->normaliseColumns(collection: $collection);
            $collection = $this->normaliseDetail(collection: $collection);
            $collection = $this->normaliseDefaults(collection: $collection);
            $out[]      = $collection;
        }

        return $out;
    }//end normaliseCollections()

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
            if (is_array($column) === false) {
                continue;
            }

            $field = $column['field'] ?? '';
            if (is_string($field) === false || $field === '') {
                continue;
            }

            $entry = ['field' => $field];
            if (isset($column['label']) === true && is_string($column['label']) === true) {
                $entry['label'] = $column['label'];
            }

            $entry['render'] = $this->oneOf(value: ($column['render'] ?? null), allowed: self::RENDER_KINDS, default: 'text');
            $columns[]       = $entry;
        }//end foreach

        $collection['columns'] = $columns;
        return $collection;
    }//end normaliseColumns()

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

        $detail = ['layout' => $this->oneOf(value: ($collection['detail']['layout'] ?? null), allowed: self::DETAIL_LAYOUTS, default: 'card')];
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
        $sortField = ($collection['defaultSort']['field'] ?? null);
        if (isset($collection['defaultSort']) === true) {
            if (is_array($collection['defaultSort']) === true && is_string($sortField) === true && $sortField !== '') {
                $direction = ($collection['defaultSort']['direction'] ?? null);
                $collection['defaultSort'] = [
                    'field'     => $sortField,
                    'direction' => $this->oneOf(value: $direction, allowed: self::SORT_DIRECTIONS, default: 'asc'),
                ];
            } else {
                unset($collection['defaultSort']);
            }
        }

        if (isset($collection['defaultFilters']) === true && $this->isStringKeyedScalarMap(value: $collection['defaultFilters']) === false) {
            unset($collection['defaultFilters']);
        }

        return $collection;
    }//end normaliseDefaults()

    /**
     * Sanitise the form-configuration keys on every action.
     *
     * @param array<int, mixed> $actions The action list.
     *
     * @return array<int, array<string, mixed>>
     */
    private function normaliseActions(array $actions): array
    {
        $out = [];
        foreach ($actions as $action) {
            if (is_array($action) === false) {
                continue;
            }

            $whitelist = [];
            if (isset($action['fields']) === true && is_array($action['fields']) === true) {
                $whitelist = array_values(array_filter($action['fields'], static fn($f) => is_string($f) === true));
            }

            $action = $this->normaliseFieldConfigs(action: $action, whitelist: $whitelist);
            $action = $this->normaliseOptionsProviders(action: $action, whitelist: $whitelist);

            foreach (['submitLabel', 'successMessage'] as $textKey) {
                if (isset($action[$textKey]) === true && is_string($action[$textKey]) === false) {
                    unset($action[$textKey]);
                }
            }

            $out[] = $action;
        }//end foreach

        return $out;
    }//end normaliseActions()

    /**
     * Keep only `fieldConfigs` whose key is in the action's whitelist.
     *
     * @param array<string, mixed> $action    The action.
     * @param array<int, string>   $whitelist The action's `fields` whitelist.
     *
     * @return array<string, mixed>
     */
    private function normaliseFieldConfigs(array $action, array $whitelist): array
    {
        if (array_key_exists('fieldConfigs', $action) === false) {
            return $action;
        }

        if (is_array($action['fieldConfigs']) === false) {
            unset($action['fieldConfigs']);
            return $action;
        }

        $configs = [];
        foreach ($action['fieldConfigs'] as $field => $config) {
            // A field config may only ever describe a whitelisted field — this is
            // where a "sneaked-in" field that is not in `fields` is dropped.
            if (in_array($field, $whitelist, true) === false || is_array($config) === false) {
                continue;
            }

            $entry = [];
            if (isset($config['label']) === true && is_string($config['label']) === true) {
                $entry['label'] = $config['label'];
            }

            if (isset($config['placeholder']) === true && is_string($config['placeholder']) === true) {
                $entry['placeholder'] = $config['placeholder'];
            }

            if (isset($config['help']) === true && is_string($config['help']) === true) {
                $entry['help'] = $config['help'];
            }

            foreach (['visible', 'required', 'disabled'] as $flag) {
                if (isset($config[$flag]) === true) {
                    $entry[$flag] = ($config[$flag] === true || $config[$flag] === 'true' || $config[$flag] === 1);
                }
            }

            $entry['size']   = $this->oneOf(value: ($config['size'] ?? null), allowed: self::FIELD_SIZES, default: 'medium');
            $configs[$field] = $entry;
        }//end foreach

        $action['fieldConfigs'] = $configs;
        return $action;
    }//end normaliseFieldConfigs()

    /**
     * Keep only valid `static` / `collection` option providers.
     *
     * @param array<string, mixed> $action    The action.
     * @param array<int, string>   $whitelist The action's `fields` whitelist.
     *
     * @return array<string, mixed>
     */
    private function normaliseOptionsProviders(array $action, array $whitelist): array
    {
        if (array_key_exists('optionsProviders', $action) === false) {
            return $action;
        }

        if (is_array($action['optionsProviders']) === false) {
            unset($action['optionsProviders']);
            return $action;
        }

        $providers = [];
        foreach ($action['optionsProviders'] as $field => $provider) {
            if (in_array($field, $whitelist, true) === false || is_array($provider) === false) {
                continue;
            }

            $kind = $this->oneOf(value: ($provider['type'] ?? null), allowed: self::PROVIDER_KINDS, default: '');
            if ($kind === 'static') {
                $normalised = $this->normaliseStaticProvider(provider: $provider);
            } else if ($kind === 'collection') {
                $normalised = $this->normaliseCollectionProvider(provider: $provider);
            } else {
                $normalised = null;
            }

            if ($normalised !== null) {
                $providers[$field] = $normalised;
            }
        }//end foreach

        $action['optionsProviders'] = $providers;
        return $action;
    }//end normaliseOptionsProviders()

    /**
     * Validate a `static` option provider.
     *
     * @param array<string, mixed> $provider The provider config.
     *
     * @return array<string, mixed>|null
     */
    private function normaliseStaticProvider(array $provider): ?array
    {
        if (is_array($provider['options'] ?? null) === false) {
            return null;
        }

        $options = [];
        foreach ($provider['options'] as $option) {
            if (is_array($option) === false) {
                continue;
            }

            $value = ($option['value'] ?? null);
            $label = ($option['label'] ?? null);
            if ((is_string($value) === true || is_int($value) === true) && is_string($label) === true) {
                $options[] = ['value' => (string) $value, 'label' => $label];
            }
        }

        if ($options === []) {
            return null;
        }

        return ['type' => 'static', 'options' => $options];
    }//end normaliseStaticProvider()

    /**
     * Validate a `collection` option provider. The dropdown is populated by the
     * frontend through the SUBJECT-SCOPED collection endpoint, so it can only
     * ever offer values the subject may already read.
     *
     * @param array<string, mixed> $provider The provider config.
     *
     * @return array<string, mixed>|null
     */
    private function normaliseCollectionProvider(array $provider): ?array
    {
        $keys = ['register', 'schema', 'labelField', 'valueField'];
        $out  = ['type' => 'collection'];
        foreach ($keys as $key) {
            $value = ($provider[$key] ?? null);
            if (is_string($value) === false || $value === '') {
                return null;
            }

            $out[$key] = $value;
        }

        return $out;
    }//end normaliseCollectionProvider()

    /**
     * Resolve `pages` against the trust-filtered collections/actions, dropping
     * unresolvable blocks and empty pages; synthesise defaults when none survive.
     *
     * @param mixed                            $pages       The declared pages (or null).
     * @param array<int, array<string, mixed>> $collections The sanitised collections.
     * @param array<int, array<string, mixed>> $actions     The sanitised actions.
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec openspec/changes/contribution-manifest-v3/tasks.md#T2
     */
    private function normalisePages(mixed $pages, array $collections, array $actions): array
    {
        $collectionIds = $this->ids(entries: $collections);
        $actionIds     = $this->ids(entries: $actions);

        $out = [];
        if (is_array($pages) === true) {
            foreach ($pages as $page) {
                if (is_array($page) === false) {
                    continue;
                }

                $blocks = $this->normaliseBlocks(
                    blocks: ($page['blocks'] ?? null),
                    collectionIds: $collectionIds,
                    actionIds: $actionIds
                );
                if ($blocks === []) {
                    continue;
                }

                $entry = ['blocks' => $blocks];
                $id    = ($page['id'] ?? null);
                if (is_string($id) === true && $id !== '') {
                    $entry['id'] = $id;
                } else {
                    $entry['id'] = 'page-'.(count($out) + 1);
                }

                if (isset($page['label']) === true && is_string($page['label']) === true) {
                    $entry['label'] = $page['label'];
                }

                if (isset($page['icon']) === true && is_string($page['icon']) === true) {
                    $entry['icon'] = $page['icon'];
                }

                $out[] = $entry;
            }//end foreach
        }//end if

        if ($out === []) {
            return $this->synthesiseDefaultPages(collections: $collections, actions: $actions);
        }

        return $out;
    }//end normalisePages()

    /**
     * Filter a page's blocks to the registry with resolvable references.
     *
     * @param mixed              $blocks        The declared blocks.
     * @param array<int, string> $collectionIds The valid collection ids.
     * @param array<int, string> $actionIds     The valid action ids.
     *
     * @return array<int, array<string, mixed>>
     */
    private function normaliseBlocks(mixed $blocks, array $collectionIds, array $actionIds): array
    {
        if (is_array($blocks) === false) {
            return [];
        }

        $out = [];
        foreach ($blocks as $block) {
            if (is_array($block) === false) {
                continue;
            }

            $type = ($block['type'] ?? null);
            if (in_array($type, self::BLOCK_TYPES, true) === false) {
                continue;
            }

            if ($type === 'collection' || $type === 'detail') {
                $ref = ($block['collection'] ?? null);
                if (in_array($ref, $collectionIds, true) === true) {
                    $out[] = ['type' => $type, 'collection' => $ref];
                }

                continue;
            }

            if ($type === 'action') {
                $ref = ($block['action'] ?? null);
                if (in_array($ref, $actionIds, true) === true) {
                    $out[] = ['type' => 'action', 'action' => $ref];
                }

                continue;
            }

            if ($type === 'cta') {
                $ref   = ($block['action'] ?? null);
                $label = ($block['label'] ?? null);
                if (in_array($ref, $actionIds, true) === true && is_string($label) === true && $label !== '') {
                    $out[] = ['type' => 'cta', 'action' => $ref, 'label' => $label];
                }

                continue;
            }

            // RichText block: requires non-empty string markdown.
            $markdown = ($block['markdown'] ?? null);
            if (is_string($markdown) === true && $markdown !== '') {
                $out[] = ['type' => 'richText', 'markdown' => $markdown];
            }
        }//end foreach

        return $out;
    }//end normaliseBlocks()

    /**
     * Synthesise one default page per listable collection (v2 rendering): the
     * collection's create action (when one is declared for its schema) followed
     * by the collection table.
     *
     * @param array<int, array<string, mixed>> $collections The sanitised collections.
     * @param array<int, array<string, mixed>> $actions     The sanitised actions.
     *
     * @return array<int, array<string, mixed>>
     */
    private function synthesiseDefaultPages(array $collections, array $actions): array
    {
        $pages = [];
        foreach ($collections as $collection) {
            $id = ($collection['id'] ?? null);
            if (($collection['listable'] ?? true) !== true || is_string($id) === false || $id === '') {
                continue;
            }

            $blocks    = [];
            $createRef = $this->createActionFor(schema: (string) ($collection['schema'] ?? ''), actions: $actions);
            if ($createRef !== null) {
                $blocks[] = ['type' => 'action', 'action' => $createRef];
            }

            $blocks[] = ['type' => 'collection', 'collection' => $id];

            $page = ['id' => $id, 'blocks' => $blocks];
            if (isset($collection['label']) === true && is_string($collection['label']) === true) {
                $page['label'] = $collection['label'];
            }

            $pages[] = $page;
        }//end foreach

        return $pages;
    }//end synthesiseDefaultPages()

    /**
     * Find the id of a `type: create` action for a schema, or null.
     *
     * @param string                           $schema  The collection schema.
     * @param array<int, array<string, mixed>> $actions The actions.
     *
     * @return string|null
     */
    private function createActionFor(string $schema, array $actions): ?string
    {
        if ($schema === '') {
            return null;
        }

        foreach ($actions as $action) {
            $id = ($action['id'] ?? null);
            if (($action['type'] ?? null) === 'create'
                && (string) ($action['schema'] ?? '') === $schema
                && is_string($id) === true && $id !== ''
            ) {
                return $id;
            }
        }

        return null;
    }//end createActionFor()

    /**
     * Collect the non-empty string `id`s from a list of entries.
     *
     * @param array<int, array<string, mixed>> $entries The entries.
     *
     * @return array<int, string>
     */
    private function ids(array $entries): array
    {
        $ids = [];
        foreach ($entries as $entry) {
            $id = ($entry['id'] ?? null);
            if (is_string($id) === true && $id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }//end ids()

    /**
     * Return `value` when it is one of `allowed`, else `default`.
     *
     * @param mixed              $value   The candidate.
     * @param array<int, string> $allowed The allowed set.
     * @param string             $default The fallback.
     *
     * @return string
     */
    private function oneOf(mixed $value, array $allowed, string $default): string
    {
        if (is_string($value) === true && in_array($value, $allowed, true) === true) {
            return $value;
        }

        return $default;
    }//end oneOf()

    /**
     * Whether a value is a map of string keys to scalar values.
     *
     * @param mixed $value The candidate.
     *
     * @return bool
     */
    private function isStringKeyedScalarMap(mixed $value): bool
    {
        if (is_array($value) === false || $value === []) {
            return false;
        }

        foreach ($value as $key => $item) {
            if (is_string($key) === false || (is_scalar($item) === false && $item !== null)) {
                return false;
            }
        }

        return true;
    }//end isStringKeyedScalarMap()
}//end class
