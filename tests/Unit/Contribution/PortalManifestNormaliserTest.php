<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * Tests the fail-closed v3 UI-configuration normaliser: it sanitises collection
 * columns/detail/defaults + action fieldConfigs/optionsProviders, resolves page
 * blocks against the (trust-filtered) contribution, synthesises default pages,
 * and — the security-critical part — NEVER widens data access (a fieldConfig for
 * a non-whitelisted field is dropped; a column for a projected-away field is kept
 * but carries no data because projection is the authority elsewhere).
 *
 * @spec openspec/changes/contribution-manifest-v3/tasks.md#T1
 * @spec openspec/changes/contribution-manifest-v3/tasks.md#T2
 */
class PortalManifestNormaliserTest extends TestCase
{

    private function normaliser(): PortalManifestNormaliser
    {
        return new PortalManifestNormaliser();
    }

    public function testColumnsAreSanitisedAndUnknownRenderFallsBackToText(): void
    {
        $out = $this->normaliser()->normalise(
            [
                'collections' => [
                    [
                        'id'      => 'c1',
                        'schema'  => 'exampleDocument',
                        'columns' => [
                            ['field' => 'title', 'label' => 'Onderwerp'],
                            ['field' => 'status', 'render' => 'badge'],
                            ['field' => 'weird', 'render' => 'not-a-kind'],
                            ['label' => 'no field — dropped'],
                            'not-an-array',
                        ],
                    ],
                ],
                'actions'     => [],
            ]
        );

        $columns = $out['collections'][0]['columns'];
        $this->assertCount(3, $columns);
        $this->assertSame(['field' => 'title', 'label' => 'Onderwerp', 'render' => 'text'], $columns[0]);
        $this->assertSame('badge', $columns[1]['render']);
        // Unknown render kind normalises to text (fail-safe, not dropped).
        $this->assertSame('text', $columns[2]['render']);

    }//end testColumnsAreSanitisedAndUnknownRenderFallsBackToText()

    public function testDetailAndDefaultsAreValidatedFailClosed(): void
    {
        $out = $this->normaliser()->normalise(
            [
                'collections' => [
                    [
                        'id'             => 'c1',
                        'schema'         => 's',
                        'detail'         => ['layout' => 'timeline', 'fields' => ['title', 3, '']],
                        'defaultSort'    => ['field' => 'createdAt', 'direction' => 'sideways'],
                        'defaultFilters' => ['status' => 'open'],
                    ],
                    [
                        'id'             => 'c2',
                        'schema'         => 's',
                        'detail'         => 'garbage',
                        'defaultSort'    => ['direction' => 'desc'],
                        'defaultFilters' => ['bad' => ['nested']],
                    ],
                ],
                'actions'     => [],
            ]
        );

        $c1 = $out['collections'][0];
        $this->assertSame('timeline', $c1['detail']['layout']);
        // Non-string field entries filtered out.
        $this->assertSame(['title'], $c1['detail']['fields']);
        // Unknown direction falls back to asc.
        $this->assertSame('asc', $c1['defaultSort']['direction']);
        $this->assertSame(['status' => 'open'], $c1['defaultFilters']);

        $c2 = $out['collections'][1];
        // Malformed detail dropped; defaultSort without field dropped; nested filter dropped.
        $this->assertArrayNotHasKey('detail', $c2);
        $this->assertArrayNotHasKey('defaultSort', $c2);
        $this->assertArrayNotHasKey('defaultFilters', $c2);

    }//end testDetailAndDefaultsAreValidatedFailClosed()

    /**
     * SECURITY: a fieldConfig may only describe a WHITELISTED field. A config for
     * a field outside the action's `fields` is dropped — it can never be used to
     * coax a non-whitelisted field into a form/submit.
     */
    public function testFieldConfigForNonWhitelistedFieldIsDropped(): void
    {
        $out = $this->normaliser()->normalise(
            [
                'collections' => [],
                'actions'     => [
                    [
                        'id'           => 'create',
                        'type'         => 'create',
                        'fields'       => ['title'],
                        'fieldConfigs' => [
                            'title'  => ['label' => 'Onderwerp', 'required' => true, 'size' => 'large'],
                            'status' => ['label' => 'Sneaked in', 'visible' => true],
                        ],
                    ],
                ],
            ]
        );

        $configs = $out['actions'][0]['fieldConfigs'];
        $this->assertArrayHasKey('title', $configs);
        // The non-whitelisted field config is gone — the whitelist is unchanged.
        $this->assertArrayNotHasKey('status', $configs);
        $this->assertTrue($configs['title']['required']);
        $this->assertSame('large', $configs['title']['size']);
        // The action's fields whitelist is never mutated.
        $this->assertSame(['title'], $out['actions'][0]['fields']);

    }//end testFieldConfigForNonWhitelistedFieldIsDropped()

    public function testOptionsProvidersValidateStaticAndCollectionAndDropMalformed(): void
    {
        $out = $this->normaliser()->normalise(
            [
                'collections' => [],
                'actions'     => [
                    [
                        'id'               => 'create',
                        'type'             => 'create',
                        'fields'           => ['category', 'contract', 'broken', 'notlisted'],
                        'optionsProviders' => [
                            'category' => ['type' => 'static', 'options' => [['value' => 'billing', 'label' => 'Facturatie'], ['value' => 1, 'label' => 'One'], ['label' => 'no value']]],
                            'contract' => ['type' => 'collection', 'register' => 'procest', 'schema' => 'supplierContract', 'labelField' => 'name', 'valueField' => 'id'],
                            'broken'   => ['type' => 'collection', 'register' => 'procest', 'schema' => 'supplierContract', 'labelField' => 'name'],
                            'ghost'    => ['type' => 'static', 'options' => [['value' => 'x', 'label' => 'y']]],
                        ],
                    ],
                ],
            ]
        );

        $providers = $out['actions'][0]['optionsProviders'];
        // static keeps the two valid options, drops the value-less one, stringifies value.
        $this->assertSame([['value' => 'billing', 'label' => 'Facturatie'], ['value' => '1', 'label' => 'One']], $providers['category']['options']);
        // collection keeps all four required keys.
        $this->assertSame('supplierContract', $providers['contract']['schema']);
        // collection missing valueField is dropped.
        $this->assertArrayNotHasKey('broken', $providers);
        // provider for a non-whitelisted field ('ghost' not in fields) is dropped.
        $this->assertArrayNotHasKey('ghost', $providers);

    }//end testOptionsProvidersValidateStaticAndCollectionAndDropMalformed()

    public function testPageBlocksResolveWithinContributionAndUnknownAreDropped(): void
    {
        $out = $this->normaliser()->normalise(
            [
                'collections' => [['id' => 'tickets', 'schema' => 'request', 'listable' => true]],
                'actions'     => [['id' => 'createTicket', 'type' => 'create', 'schema' => 'request', 'fields' => ['title']]],
                'pages'       => [
                    [
                        'id'     => 'support',
                        'label'  => 'Support',
                        'blocks' => [
                            ['type' => 'richText', 'markdown' => '## Hi'],
                            ['type' => 'action', 'action' => 'createTicket'],
                            ['type' => 'collection', 'collection' => 'tickets'],
                            ['type' => 'action', 'action' => 'doesNotExist'],
                            ['type' => 'collection', 'collection' => 'foreignApp'],
                            ['type' => 'mystery', 'collection' => 'tickets'],
                            ['type' => 'cta', 'action' => 'createTicket', 'label' => 'New'],
                            ['type' => 'cta', 'action' => 'createTicket'],
                        ],
                    ],
                    ['id' => 'empty', 'blocks' => [['type' => 'action', 'action' => 'nope']]],
                ],
            ]
        );

        $pages = $out['pages'];
        // The empty page (all blocks unresolved) is dropped.
        $this->assertCount(1, $pages);
        $blocks = $pages[0]['blocks'];
        // Kept: richText, action(createTicket), collection(tickets), cta(with label). = 4
        $this->assertCount(4, $blocks);
        $this->assertSame('richText', $blocks[0]['type']);
        $this->assertSame('createTicket', $blocks[1]['action']);
        $this->assertSame('tickets', $blocks[2]['collection']);
        $this->assertSame(['type' => 'cta', 'action' => 'createTicket', 'label' => 'New'], $blocks[3]);

    }//end testPageBlocksResolveWithinContributionAndUnknownAreDropped()

    public function testAbsentPagesSynthesiseOneDefaultPerListableCollection(): void
    {
        $out = $this->normaliser()->normalise(
            [
                'collections' => [
                    ['id' => 'tickets', 'schema' => 'request', 'label' => 'Tickets', 'listable' => true],
                    ['id' => 'archive', 'schema' => 'oldRequest', 'listable' => false],
                ],
                'actions'     => [
                    ['id' => 'createTicket', 'type' => 'create', 'schema' => 'request', 'fields' => ['title']],
                ],
            ]
        );

        $pages = $out['pages'];
        // Only the listable collection yields a default page.
        $this->assertCount(1, $pages);
        $this->assertSame('tickets', $pages[0]['id']);
        $this->assertSame('Tickets', $pages[0]['label']);
        // The create action for that schema is prepended, then the collection table.
        $this->assertSame(['type' => 'action', 'action' => 'createTicket'], $pages[0]['blocks'][0]);
        $this->assertSame(['type' => 'collection', 'collection' => 'tickets'], $pages[0]['blocks'][1]);

    }//end testAbsentPagesSynthesiseOneDefaultPerListableCollection()

    /**
     * ADDITIVE-COMPAT: a pure v2 manifest round-trips with collections + actions
     * byte-identical; only an additive synthesised `pages` array appears.
     */
    public function testV2ManifestRoundTripsWithOnlyAdditivePages(): void
    {
        $v2 = [
            'app'         => 'demo',
            'label'       => 'Demo',
            'collections' => [['id' => 'c1', 'register' => 'r', 'schema' => 's', 'scopeField' => 'subjectRef', 'label' => 'C', 'listable' => true]],
            'actions'     => [['id' => 'a1', 'type' => 'create', 'register' => 'r', 'schema' => 's', 'fields' => ['title']]],
        ];

        $out = $this->normaliser()->normalise($v2);

        $this->assertSame($v2['collections'], $out['collections']);
        $this->assertSame($v2['actions'], $out['actions']);
        $this->assertArrayHasKey('pages', $out);
        $this->assertSame('c1', $out['pages'][0]['id']);

    }//end testV2ManifestRoundTripsWithOnlyAdditivePages()

    public function testGarbageInputNeverThrowsAndFailsClosed(): void
    {
        $out = $this->normaliser()->normalise(
            [
                'collections' => ['not-an-array', ['id' => 'c1', 'schema' => 's', 'columns' => 'nope', 'detail' => 5, 'listable' => true]],
                'actions'     => ['garbage', ['id' => 'a', 'fields' => 'notalist', 'fieldConfigs' => 'x', 'optionsProviders' => 7]],
                'pages'       => 'not-a-list',
            ]
        );

        // Malformed collection entries dropped; the one valid collection kept, its
        // bad keys stripped.
        $this->assertCount(1, $out['collections']);
        $this->assertArrayNotHasKey('columns', $out['collections'][0]);
        $this->assertArrayNotHasKey('detail', $out['collections'][0]);
        // Malformed action keys stripped, action itself kept.
        $this->assertCount(1, $out['actions']);
        $this->assertArrayNotHasKey('fieldConfigs', $out['actions'][0]);
        $this->assertArrayNotHasKey('optionsProviders', $out['actions'][0]);
        // Non-list pages → defaults synthesised from the listable collection.
        $this->assertCount(1, $out['pages']);
        $this->assertSame('c1', $out['pages'][0]['id']);

    }//end testGarbageInputNeverThrowsAndFailsClosed()

    /**
     * SECURITY: an update action's `set` (server-enforced transition target) may
     * only fix WHITELISTED fields with scalar values — a key outside `fields` or
     * a non-scalar value is dropped, so `set` can never write a field the action
     * is not entitled to.
     */
    public function testSetKeepsOnlyWhitelistedScalarTransitionValues(): void
    {
        $out = $this->normaliser()->normalise(
            [
                'collections' => [],
                'actions'     => [
                    [
                        'id'     => 'close',
                        'type'   => 'update',
                        'fields' => ['status'],
                        'set'    => ['status' => 'closed', 'subjectRef' => 'HACKER', 'meta' => ['nested']],
                    ],
                ],
            ]
        );

        // Only the whitelisted scalar survives; the smuggled scope field and the
        // non-scalar value are dropped.
        $this->assertSame(['status' => 'closed'], $out['actions'][0]['set']);
    }//end testSetKeepsOnlyWhitelistedScalarTransitionValues()

    public function testMalformedSetIsDropped(): void
    {
        $out = $this->normaliser()->normalise(
            [
                'collections' => [],
                'actions'     => [['id' => 'u', 'type' => 'update', 'fields' => ['status'], 'set' => 'not-a-map']],
            ]
        );

        $this->assertArrayNotHasKey('set', $out['actions'][0]);
    }//end testMalformedSetIsDropped()

    /**
     * A collection's `rowActions` resolve only to `type: update` actions in the
     * SAME contribution; a create action, an unknown id, or a foreign id is
     * dropped, and an empty result removes the key.
     */
    public function testRowActionsResolveOnlyToUpdateActionsInContribution(): void
    {
        $out = $this->normaliser()->normalise(
            [
                'collections' => [
                    ['id' => 'tickets', 'schema' => 'request', 'listable' => true, 'rowActions' => ['close', 'createTicket', 'ghost']],
                    ['id' => 'other', 'schema' => 'x', 'listable' => true, 'rowActions' => ['createTicket']],
                ],
                'actions'     => [
                    ['id' => 'close', 'type' => 'update', 'schema' => 'request', 'fields' => ['status'], 'set' => ['status' => 'closed']],
                    ['id' => 'createTicket', 'type' => 'create', 'schema' => 'request', 'fields' => ['title']],
                ],
            ]
        );

        // Only the update action id survives; the create id and unknown id are dropped.
        $this->assertSame(['close'], $out['collections'][0]['rowActions']);
        // A collection left with no resolvable row actions loses the key.
        $this->assertArrayNotHasKey('rowActions', $out['collections'][1]);
    }//end testRowActionsResolveOnlyToUpdateActionsInContribution()
}//end class
