<?php

/**
 * PortalBlockResolver: one block of every registered type, resolved or
 * dropped, in one list.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Contribution
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalBlockResolver;
use PHPUnit\Framework\TestCase;

/**
 * The block list as a whole, as recorded before the type dispatch was split.
 */
class PortalBlockResolverGoldenTest extends TestCase {
	/**
	 * Every block type in one list keeps its order; junk, unknown types and
	 * unresolved references are dropped.
	 *
	 * @return void
	 */
	public function testEveryBlockTypeResolvesAsRecorded(): void {
		$in = [
			'collectionIds' => ['cases', 'tasks', 'messages'],
			'actionIds' => ['ask'],
			'pageIds' => ['home', 'help'],
			'collections' => [
				['id' => 'cases', 'kind' => 'cases', 'fields' => ['title', 'status']],
				['id' => 'tasks', 'kind' => 'tasks', 'fields' => ['title']],
				['id' => 'messages', 'kind' => 'inbox', 'fields' => ['subject']],
			],
			'blocks' => [
				'junk',
				['type' => 'unknown'],
				['type' => 'collection', 'collection' => 'cases', 'limit' => 5, 'sort' => 'title'],
				['type' => 'collection', 'collection' => 'nope'],
				['type' => 'detail', 'collection' => 'cases'],
				['type' => 'citizenCase', 'collection' => 'cases'],
				['type' => 'action', 'action' => 'ask'],
				['type' => 'action', 'action' => 'nope'],
				['type' => 'cta', 'label' => 'Go', 'action' => 'ask'],
				['type' => 'cta', 'label' => 'Help', 'page' => 'help'],
				['type' => 'greeting', 'text' => 'Hello'],
				['type' => 'richText', 'markdown' => '# Hi'],
				['type' => 'richText', 'template' => 'Hi {title}'],
				['type' => 'richText'],
				['type' => 'kpi', 'collection' => 'cases', 'label' => 'Open'],
				['type' => 'calendar', 'collection' => 'cases', 'dateField' => 'title'],
				['type' => 'news'],
				['type' => 'tasks', 'collection' => 'tasks'],
				['type' => 'inbox', 'collection' => 'messages'],
				['type' => 'cases', 'collection' => 'cases'],
				['type' => 'steps', 'collection' => 'cases'],
				['type' => 'documents', 'collection' => 'cases'],
				['type' => 'timeline', 'collection' => 'cases'],
			],
		];

		$expected = <<<'JSON'
[
    {
        "type": "collection",
        "collection": "cases",
        "limit": 5
    },
    {
        "type": "detail",
        "collection": "cases"
    },
    {
        "type": "citizenCase",
        "collection": "cases"
    },
    {
        "type": "action",
        "action": "ask"
    },
    {
        "type": "cta",
        "action": "ask",
        "label": "Go"
    },
    {
        "type": "cta",
        "page": "help",
        "label": "Help"
    },
    {
        "type": "greeting",
        "showDate": true
    },
    {
        "type": "richText",
        "markdown": "# Hi"
    },
    {
        "type": "richText",
        "template": "Hi {title}"
    },
    {
        "type": "news",
        "limit": 3
    },
    {
        "type": "tasks",
        "collection": "tasks"
    },
    {
        "type": "inbox",
        "collection": "messages"
    },
    {
        "type": "cases",
        "collection": "cases"
    }
]
JSON;

		$this->assertSame(
			json_decode($expected, true),
			(new PortalBlockResolver())->normaliseBlocks($in['blocks'], $in['collectionIds'], $in['actionIds'], $in['collections'], $in['pageIds'])
		);
	}//end testEveryBlockTypeResolvesAsRecorded()

	/**
	 * A cta names a page only among the pages of its own list, so a later
	 * call does not see an earlier call's pages.
	 *
	 * @return void
	 */
	public function testEachListSeesOnlyItsOwnPages(): void {
		$resolver = new PortalBlockResolver();
		$block    = ['type' => 'cta', 'label' => 'Help', 'page' => 'help'];

		$this->assertSame([['type' => 'cta', 'page' => 'help', 'label' => 'Help']], $resolver->normaliseBlocks([$block], [], [], [], ['help']));
		$this->assertSame((new PortalBlockResolver())->normaliseBlocks([$block], [], []), $resolver->normaliseBlocks([$block], [], []));
		$this->assertSame([], $resolver->normaliseBlocks('x', [], [], [], ['help']));
	}//end testEachListSeesOnlyItsOwnPages()
}//end class
