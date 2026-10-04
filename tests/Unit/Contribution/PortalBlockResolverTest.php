<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalBlockResolver;
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
	 * @param string            $record The page's record collection, or ''.
	 *
	 * @return array<int, array<string, mixed>> The surviving blocks.
	 */
	private function blocks(array $blocks, string $record=''): array {
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
					[
						'id'     => 'zaken',
						'schema' => 'case',
						'kind'   => 'cases',
						'steps'  => ['label' => 'Stappen', 'provider' => 'caseSteps'],
						'documents' => ['label' => 'Documenten', 'provider' => 'caseDocuments'],
						'timeline'  => ['label' => 'Wat er is gebeurd', 'provider' => 'caseTimeline'],
					],
				],
				'actions'     => [['id' => 'createExcuseRequest', 'type' => 'create', 'schema' => 'excuse']],
				'pages'       => [
					[
						'id'     => 'overzicht',
						'label'  => 'Overzicht',
						'record' => ($record === '' ? null : ['collection' => $record]),
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

		$this->assertSame(
			[['type' => 'tasks', 'collection' => 'open']],
			$this->blocks([['type' => 'tasks', 'collection' => 'open', 'titleFields' => 'naam']]),
			'titleFields must be a list'
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
	 * A cases block names a `kind: cases` collection of its contribution and
	 * keeps `open: true`, a limit and a label.
	 *
	 * @return void
	 */
	public function testACasesBlockNamesACasesCollection(): void {
		$this->assertSame(
			[['type' => 'cases', 'collection' => 'zaken', 'open' => true, 'limit' => 4, 'label' => 'Lopende zaken']],
			$this->blocks([['type' => 'cases', 'collection' => 'zaken', 'open' => true, 'limit' => 4, 'label' => 'Lopende zaken']])
		);
		$this->assertSame(
			[['type' => 'cases', 'collection' => 'zaken']],
			$this->blocks([['type' => 'cases', 'collection' => 'zaken', 'open' => 'yes']]),
			'open is true or absent'
		);
		foreach (['vragenAanU', 'berichten', 'elders', null] as $collection) {
			$this->assertSame([], $this->blocks([['type' => 'cases', 'collection' => $collection]]));
		}
	}//end testACasesBlockNamesACasesCollection()

	/**
	 * A steps block needs a collection with a steps provider, and only stays
	 * on that collection's record page.
	 *
	 * @return void
	 */
	public function testAStepsBlockStaysOnlyOnItsRecordPage(): void {
		$onRecordPage = $this->blocks(blocks: [['type' => 'steps', 'collection' => 'zaken', 'label' => 'Waar staat uw aanvraag?']], record: 'zaken');
		$this->assertSame([['type' => 'steps', 'collection' => 'zaken', 'label' => 'Waar staat uw aanvraag?']], $onRecordPage);

		$this->assertSame([], $this->blocks(blocks: [['type' => 'steps', 'collection' => 'zaken']]), 'not a record page');
		$this->assertSame([], $this->blocks(blocks: [['type' => 'steps', 'collection' => 'zaken']], record: 'vragenAanU'), 'another record');
		$this->assertSame([], $this->blocks(blocks: [['type' => 'steps', 'collection' => 'vragenAanU']], record: 'vragenAanU'), 'no steps provider');

		$out = (new PortalManifestNormaliser())->normalise(
			[
				'collections' => [['id' => 'zaken', 'schema' => 'case', 'kind' => 'cases', 'steps' => ['provider' => 'caseSteps']]],
				'actions'     => [],
				'pages'       => [['id' => 'alleen-stappen', 'blocks' => [['type' => 'steps', 'collection' => 'zaken']]]],
			]
		);
		$this->assertNotContains('alleen-stappen', array_column($out['pages'], 'id'), 'a page left without blocks is dropped');
	}//end testAStepsBlockStaysOnlyOnItsRecordPage()

	/**
	 * A documents and a timeline block read their collection's provider of
	 * the same name, and stay only on that collection's record page
	 * (site-mijn-omgeving-components REQ-SMO-021, wave 4).
	 *
	 * @return void
	 */
	public function testADocumentsAndATimelineBlockStayOnlyOnTheirRecordPage(): void {
		$this->assertSame(
			[
				['type' => 'documents', 'collection' => 'zaken'],
				['type' => 'timeline', 'collection' => 'zaken', 'label' => 'Wat er gebeurde'],
			],
			$this->blocks(
				blocks: [
					['type' => 'documents', 'collection' => 'zaken'],
					['type' => 'timeline', 'collection' => 'zaken', 'label' => 'Wat er gebeurde'],
				],
				record: 'zaken'
			)
		);

		foreach (['documents', 'timeline'] as $type) {
			$this->assertSame([], $this->blocks(blocks: [['type' => $type, 'collection' => 'zaken']]), $type.': not a record page');
			$this->assertSame([], $this->blocks(blocks: [['type' => $type, 'collection' => 'vragenAanU']], record: 'vragenAanU'), $type.': no provider');
		}
	}//end testADocumentsAndATimelineBlockStayOnlyOnTheirRecordPage()

	/**
	 * A collection block keeps a limit of 1 to 50 and a sort on a projected
	 * field, asc or desc; anything else is dropped and the block stays
	 * (site-mijn-omgeving-components REQ-SMO-021, T10).
	 *
	 * @return void
	 */
	public function testACollectionBlockKeepsItsLimitAndSort(): void {
		$this->assertSame(
			[['type' => 'collection', 'collection' => 'vragenAanU', 'limit' => 3, 'sort' => ['field' => 'antwoordVoor', 'direction' => 'desc']]],
			$this->blocks([['type' => 'collection', 'collection' => 'vragenAanU', 'limit' => 3, 'sort' => ['field' => 'antwoordVoor', 'direction' => 'desc']]])
		);

		foreach ([['limit' => 0], ['limit' => 51], ['limit' => '3'], ['sort' => ['field' => 'geheim', 'direction' => 'asc']], ['sort' => ['field' => 'onderwerp', 'direction' => 'up']], ['sort' => 'onderwerp'], ['sort' => ['field' => 7, 'direction' => 'asc']]] as $bad) {
			$this->assertSame(
				[['type' => 'collection', 'collection' => 'vragenAanU']],
				$this->blocks([['type' => 'collection', 'collection' => 'vragenAanU'] + $bad])
			);
		}

		$this->assertSame(
			[['type' => 'detail', 'collection' => 'vragenAanU']],
			$this->blocks([['type' => 'detail', 'collection' => 'vragenAanU', 'limit' => 3]]),
			'only a collection block takes them'
		);
		$this->assertSame(
			[['type' => 'collection', 'collection' => 'open', 'sort' => ['field' => 'naam', 'direction' => 'asc']]],
			$this->blocks([['type' => 'collection', 'collection' => 'open', 'sort' => ['field' => 'naam', 'direction' => 'asc']]]),
			'a collection that projects nothing sorts on any field'
		);
	}//end testACollectionBlockKeepsItsLimitAndSort()

	/**
	 * A calendar block keeps a range of day, week or month.
	 *
	 * @return void
	 */
	public function testACalendarBlockKeepsItsRange(): void {
		$source = ['collection' => 'vragenAanU', 'startField' => 'antwoordVoor', 'titleField' => 'onderwerp'];
		foreach (['day', 'week', 'month'] as $range) {
			$this->assertSame($range, $this->blocks([['type' => 'calendar', 'sources' => [$source], 'range' => $range]])[0]['range']);
		}

		foreach (['year', '', 7, null] as $range) {
			$this->assertArrayNotHasKey('range', $this->blocks([['type' => 'calendar', 'sources' => [$source], 'range' => $range]])[0]);
		}
	}//end testACalendarBlockKeepsItsRange()

	/**
	 * A tasks block takes the record scope, lookups and an excludeWhen over
	 * one of its lookups; an inbox block takes recordField (REQ-SMO-025).
	 *
	 * @return void
	 */
	public function testATasksBlockNarrowsToTheRecordAndLeavesRowsOutByALookup(): void {
		$lookup = ['collection' => 'open', 'matchField' => 'assignment', 'valueField' => 'state', 'as' => 'submission'];
		$blocks = $this->blocks(
			[
				[
					'type'        => 'tasks',
					'collection'  => 'vragenAanU',
					'recordField' => 'zaak',
					'lookups'     => [$lookup],
					'excludeWhen' => ['lookup' => 'submission', 'in' => ['submitted', 'graded', ['nested']]],
				],
				['type' => 'tasks', 'collection' => 'vragenAanU', 'excludeWhen' => ['lookup' => 'nowhere', 'in' => ['x']]],
				['type' => 'tasks', 'collection' => 'vragenAanU', 'lookups' => [$lookup], 'excludeWhen' => ['lookup' => 'submission', 'in' => []]],
				['type' => 'inbox', 'recordField' => 'learnerRef'],
				['type' => 'inbox', 'recordField' => 'bad field!'],
			]
		);

		$this->assertSame('zaak', $blocks[0]['recordField']);
		$this->assertSame('submission', $blocks[0]['lookups'][0]['as']);
		$this->assertSame(['lookup' => 'submission', 'in' => ['submitted', 'graded']], $blocks[0]['excludeWhen']);
		$this->assertArrayNotHasKey('excludeWhen', $blocks[1], 'a lookup the block does not declare');
		$this->assertArrayNotHasKey('excludeWhen', $blocks[2], 'no values to leave out');
		$this->assertSame(['type' => 'inbox', 'recordField' => 'learnerRef'], $blocks[3]);
		$this->assertSame(['type' => 'inbox'], $blocks[4]);
	}//end testATasksBlockNarrowsToTheRecordAndLeavesRowsOutByALookup()

	/**
	 * A cta names exactly one of an action, a page or a route inside the
	 * portal; withRecord is kept only as true (REQ-SMO-024).
	 *
	 * @return void
	 */
	public function testACtaNamesExactlyOneTarget(): void {
		$this->assertSame(
			[
				['type' => 'cta', 'action' => 'createExcuseRequest', 'label' => '{title} ziek of afwezig melden', 'withRecord' => true],
				['type' => 'cta', 'page' => 'overzicht', 'label' => 'Cijfers'],
				['type' => 'cta', 'route' => '/mijn/messages', 'label' => 'Bericht sturen'],
			],
			$this->blocks(
				[
					['type' => 'cta', 'action' => 'createExcuseRequest', 'label' => '{title} ziek of afwezig melden', 'withRecord' => true],
					['type' => 'cta', 'page' => 'overzicht', 'label' => 'Cijfers', 'withRecord' => 'yes'],
					['type' => 'cta', 'route' => '/mijn/messages', 'label' => 'Bericht sturen'],
				]
			)
		);

		foreach ([['action' => 'createExcuseRequest', 'page' => 'overzicht'], [], ['page' => 'elders'], ['action' => 'nope'], ['route' => '/x', 'label' => ' ']] as $bad) {
			$this->assertSame([], $this->blocks([$bad + ['type' => 'cta', 'label' => 'Ga']]), (string)json_encode($bad));
		}
	}//end testACtaNamesExactlyOneTarget()

	/**
	 * A route outside the portal is refused: a scheme, a host or `//`.
	 *
	 * @return void
	 */
	public function testAnOutsideRouteIsDropped(): void {
		foreach (['//example.org/x', 'https://example.org', 'mijn/x', '/mijn//x', 'javascript:alert(1)', '/mijn/x?y=1'] as $route) {
			$this->assertSame([], $this->blocks([['type' => 'cta', 'route' => $route, 'label' => 'Ga']]), $route);
		}
	}//end testAnOutsideRouteIsDropped()

	/**
	 * A text block may be a template filled from the record, with words for
	 * an empty value (REQ-SMO-027); without markdown or a template it drops.
	 *
	 * @return void
	 */
	public function testATextBlockMayBeATemplate(): void {
		// The page helper leaves text blocks out, so this asks the resolver itself.
		$resolve = static fn (array $block): array => (new PortalBlockResolver())->normaliseBlocks(blocks: [$block], collectionIds: [], actionIds: []);

		$this->assertSame(
			[['type' => 'richText', 'template' => 'U heeft toegang tot {expiresAt}.', 'whenEmpty' => ['expiresAt' => 'U heeft toegang zonder einddatum.']]],
			$resolve(['type' => 'richText', 'template' => 'U heeft toegang tot {expiresAt}.', 'whenEmpty' => ['expiresAt' => 'U heeft toegang zonder einddatum.', 'x' => 7, 3 => 'y']])
		);
		$this->assertSame([['type' => 'richText', 'template' => 'Hallo']], $resolve(['type' => 'richText', 'template' => 'Hallo', 'whenEmpty' => 'x']));
		$this->assertSame([], $resolve(['type' => 'richText', 'template' => '  ']));
		$this->assertSame([['type' => 'richText', 'markdown' => 'Welkom']], $resolve(['type' => 'richText', 'markdown' => 'Welkom', 'template' => 'x']), 'markdown wins');
	}//end testATextBlockMayBeATemplate()

	/**
	 * A collection block may show cards with a progress figure, both fields
	 * projected (REQ-SMO-028).
	 *
	 * @return void
	 */
	public function testACollectionBlockMayShowCardsWithProgress(): void {
		$this->assertSame(
			[['type' => 'collection', 'collection' => 'open', 'display' => 'cards', 'progress' => ['valueField' => 'hoursDone', 'totalField' => 'hoursRequired', 'label' => 'uur']]],
			$this->blocks([['type' => 'collection', 'collection' => 'open', 'display' => 'cards', 'progress' => ['valueField' => 'hoursDone', 'totalField' => 'hoursRequired', 'label' => ' uur ']]])
		);
		$this->assertSame(
			[['type' => 'collection', 'collection' => 'vragenAanU', 'display' => 'cards']],
			$this->blocks([['type' => 'collection', 'collection' => 'vragenAanU', 'display' => 'cards', 'progress' => ['valueField' => 'geheim', 'totalField' => 'onderwerp']]]),
			'a progress on an unprojected field is dropped, the cards stay'
		);
		$this->assertSame([['type' => 'collection', 'collection' => 'open']], $this->blocks([['type' => 'collection', 'collection' => 'open', 'display' => 'table']]));
	}//end testACollectionBlockMayShowCardsWithProgress()

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
