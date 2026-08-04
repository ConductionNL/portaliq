<?php

/**
 * Portaliq Portal Page Resolver (contribution-manifest-v3)
 *
 * Resolves a contribution's declared `pages` against the ALREADY trust-filtered
 * and sanitised collections/actions of the same contribution, and synthesises
 * the v2 default layout when nothing survives.
 *
 * SECURITY: every block reference resolves against the filtered contribution,
 * so a trust-dropped collection or action can never be reached through a
 * surviving page block. A block of an unknown type, or one whose reference
 * does not resolve, is dropped; a page whose blocks all drop is dropped.
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
 * @spec openspec/changes/contribution-manifest-v3/tasks.md#T2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Resolves and synthesises a contribution's pages, fail-closed.
 *
 * @spec openspec/changes/contribution-manifest-v3/tasks.md#T2
 */
class PortalPageResolver
{
    /**
     * Constructor.
     *
     * @param PortalBlockResolver $blocks The block-level registry + reference rules.
     */
    public function __construct(
        private readonly PortalBlockResolver $blocks,
    ) {
    }//end __construct()

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
    public function normalisePages(mixed $pages, array $collections, array $actions): array
    {
        $out = [];
        if (is_array($pages) === true) {
            $collectionIds = $this->ids(entries: $collections);
            $actionIds     = $this->ids(entries: $actions);

            foreach ($pages as $page) {
                $entry = $this->normalisePage(
                    page: $page,
                    collectionIds: $collectionIds,
                    actionIds: $actionIds,
                    index: count($out)
                );
                if ($entry !== null) {
                    $out[] = $entry;
                }
            }
        }//end if

        if ($out === []) {
            return $this->synthesiseDefaultPages(collections: $collections, actions: $actions);
        }

        return $out;
    }//end normalisePages()

    /**
     * Resolve ONE declared page, or null when it is malformed or every one of
     * its blocks dropped.
     *
     * @param mixed              $page          The declared page.
     * @param array<int, string> $collectionIds The valid collection ids.
     * @param array<int, string> $actionIds     The valid action ids.
     * @param int                $index         How many pages already survived
     *                                          (drives the synthesised id).
     *
     * @return array<string, mixed>|null
     */
    private function normalisePage(mixed $page, array $collectionIds, array $actionIds, int $index): ?array
    {
        if (is_array($page) === false) {
            return null;
        }

        $blocks = $this->blocks->normaliseBlocks(
            blocks: ($page['blocks'] ?? null),
            collectionIds: $collectionIds,
            actionIds: $actionIds
        );
        if ($blocks === []) {
            return null;
        }

        $entry       = ['blocks' => $blocks];
        $entry['id'] = 'page-'.($index + 1);

        $id = ($page['id'] ?? null);
        if (is_string($id) === true && $id !== '') {
            $entry['id'] = $id;
        }

        foreach (['label', 'icon'] as $textKey) {
            if (isset($page[$textKey]) === true && is_string($page[$textKey]) === true) {
                $entry[$textKey] = $page[$textKey];
            }
        }

        return $entry;
    }//end normalisePage()

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
}//end class
