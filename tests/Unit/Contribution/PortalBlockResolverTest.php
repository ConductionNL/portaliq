<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * A contributed page may hold a `tasks` and an `inbox` block
 * (site-mijn-omgeving-components REQ-SMO-021). Driven through
 * PortalManifestNormaliser, the one entry point the registry uses, so the
 * test also proves the page resolver hands the blocks their collections.
 *
 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-use-the-tasks-inbox-cases-steps-documents-and-timeline-blocks-req-smo-021
 */
class PortalBlockResolverTest extends TestCase {
	/**
	 * Normalise one page with these blocks, next to a projected question
	 * collection, an inbox and a collection that projects every field.
	 *
	 * @param array<int, mixed> $blocks The declared blocks.
	 *
	 * @return array<int, array<string, mixed>> The surviving blocks.
	 */
	private function blocks(array $blocks): array {
		$out = (new PortalManifestNormaliser())->normalise(
			[
				'collections' => [
					[
						'id'     => 'vragenAanU',
						'schema' => 'question',
						'fields' => ['onderwerp', 'antwoordVoor', 'zaak'],
					],
					['id' => 'berichten', 'schema' => 'message', 'kind' => 'inbox'],
					['id' => 'open', 'schema' => 'thing'],
				],
				'actions'     => [],
				'pages'       => [
					[
						'id'     => 'overzicht',
						'label'  => 'Overzicht',
						// A rich text block keeps the page alive when every
						// block under test drops.
						'blocks' => array_merge($blocks, [['type' => 'richText', 'markdown' => 'Welkom']]),
					],
				],
			]
		);

		$page = $out['pages'][0];
		$this->assertSame('overzicht', $page['id'], 'the declared page survives, not a synthesised default');
		return array_values(array_filter($page['blocks'], static fn (array $b): bool => $b['type'] !== 'richText'));
	}//end blocks()

	/**
	 * A tasks block names a collection of its own contribution and keeps its
	 * projected due and title fields, a limit and a label.
	 *
	 * @return void
	 */
	public function testATasksBlockNamesAContributedCollection(): void {
		$blocks = $this->blocks(
			[
				[
					'type'        => 'tasks',
					'collection'  => 'vragenAanU',
					'dueField'    => 'antwoordVoor',
					'titleFields' => ['onderwerp'],
					'limit'       => 5,
					'label'       => ' Dit moet u nog doen ',
				],
			]
		);

		$this->assertSame(
			[
				[
					'type'        => 'tasks',
					'collection'  => 'vragenAanU',
					'dueField'    => 'antwoordVoor',
					'titleFields' => ['onderwerp'],
					'limit'       => 5,
					'label'       => 'Dit moet u nog doen',
				],
			],
			$blocks
		);

		$this->assertSame([], $this->blocks([['type' => 'tasks', 'collection' => 'elders']]), 'another contribution\'s collection');
		$this->assertSame([], $this->blocks([['type' => 'tasks']]), 'no collection at all');
	}//end testATasksBlockNamesAContributedCollection()

	/**
	 * A due or title field the collection does not project is dropped, the
	 * block stays; a collection that projects nothing accepts any field.
	 *
	 * @return void
	 */
	public function testATasksBlockKeepsOnlyProjectedFields(): void {
		$blocks = $this->blocks(
			[
				[
					'type'        => 'tasks',
					'collection'  => 'vragenAanU',
					'dueField'    => 'geheim',
					'titleFields' => ['geheim', 'onderwerp', 7, ''],
				],
				['type' => 'tasks', 'collection' => 'open', 'dueField' => 'deadline', 'titleFields' => ['naam']],
			]
		);

		$this->assertSame(['type' => 'tasks', 'collection' => 'vragenAanU', 'titleFields' => ['onderwerp']], $blocks[0]);
		$this->assertSame(
			['type' => 'tasks', 'collection' => 'open', 'dueField' => 'deadline', 'titleFields' => ['naam']],
			$blocks[1]
		);
	}//end testATasksBlockKeepsOnlyProjectedFields()

	/**
	 * An out-of-range limit or a blank, overlong or non-string label is
	 * dropped, and the block stays as it was without it.
	 *
	 * @return void
	 */
	public function testAnOutOfRangeLimitOrLabelIsDropped(): void {
		foreach ([0, 51, -1, '5', 2.5, null] as $limit) {
			$blocks = $this->blocks([['type' => 'inbox', 'limit' => $limit, 'label' => str_repeat('x', 121)]]);
			$this->assertSame([['type' => 'inbox']], $blocks);
		}

		$this->assertSame(
			[['type' => 'inbox', 'limit' => 1], ['type' => 'inbox', 'limit' => 50]],
			$this->blocks([['type' => 'inbox', 'limit' => 1, 'label' => '  '], ['type' => 'inbox', 'limit' => 50, 'label' => 7]])
		);
	}//end testAnOutOfRangeLimitOrLabelIsDropped()

	/**
	 * An inbox block may name a `kind: inbox` collection; naming any other
	 * collection drops the block instead of widening it to every inbox.
	 *
	 * @return void
	 */
	public function testAnInboxBlockMayNameAnInboxCollection(): void {
		$this->assertSame(
			[['type' => 'inbox', 'collection' => 'berichten', 'limit' => 2]],
			$this->blocks([['type' => 'inbox', 'collection' => 'berichten', 'limit' => 2]])
		);
		$this->assertSame([['type' => 'inbox']], $this->blocks([['type' => 'inbox']]), 'no collection reads every inbox');

		foreach (['vragenAanU', 'elders', '', 7, null] as $collection) {
			$this->assertSame([], $this->blocks([['type' => 'inbox', 'collection' => $collection]]));
		}
	}//end testAnInboxBlockMayNameAnInboxCollection()

	/**
	 * The placeholder names the app lanes used before the names were fixed
	 * are not blocks.
	 *
	 * @return void
	 */
	public function testAPlaceholderNameIsDropped(): void {
		foreach (['actionList', 'messageList', 'caseCards', 'Tasks', 'task'] as $type) {
			$this->assertSame([], $this->blocks([['type' => $type, 'collection' => 'vragenAanU']]));
		}
	}//end testAPlaceholderNameIsDropped()
}//end class
