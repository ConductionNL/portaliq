<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use OCA\Portaliq\Service\PortalSchemaReader;
use PHPUnit\Framework\TestCase;

/**
 * Tests the fail-closed v3 UI-configuration normaliser: it sanitises collection
 * columns/detail/defaults + action fieldConfigs/optionsProviders, resolves page
 * blocks against the (trust-filtered) contribution, synthesises default pages,
 * and — the security-critical part — NEVER widens data access (a fieldConfig for
 * a non-whitelisted field is dropped; a column for a projected-away field is kept
 * but carries no data because projection is the authority elsewhere).
 *
 * @spec openspec/changes/archive/2026-09-29-contribution-manifest-v3/tasks.md#T1
 * @spec openspec/changes/archive/2026-09-29-contribution-manifest-v3/tasks.md#T2
 */
class PortalManifestNormaliserTest extends TestCase {

	private function normaliser(): PortalManifestNormaliser {
		return new PortalManifestNormaliser();
	}

	public function testColumnsAreSanitisedAndUnknownRenderFallsBackToText(): void {
		$out = $this->normaliser()->normalise(
			[
				'collections' => [
					[
						'id' => 'c1',
						'schema' => 'exampleDocument',
						'columns' => [
							['field' => 'title', 'label' => 'Onderwerp'],
							['field' => 'status', 'render' => 'badge'],
							['field' => 'weird', 'render' => 'not-a-kind'],
							['label' => 'no field — dropped'],
							'not-an-array',
						],
					],
				],
				'actions' => [],
			]
		);

		$columns = $out['collections'][0]['columns'];
		$this->assertCount(3, $columns);
		$this->assertSame(['field' => 'title', 'label' => 'Onderwerp', 'render' => 'text'], $columns[0]);
		$this->assertSame('badge', $columns[1]['render']);
		// Unknown render kind normalises to text (fail-safe, not dropped).
		$this->assertSame('text', $columns[2]['render']);

	}//end testColumnsAreSanitisedAndUnknownRenderFallsBackToText()

	/**
	 * A column may declare `render: "user"` (contribution-user-display-name),
	 * and the normaliser keeps it.
	 *
	 * @return void
	 */
	public function testAUserColumnKeepsItsRender(): void {
		$out = $this->normaliser()->normalise(
			[
				'collections' => [
					[
						'id' => 'c1',
						'schema' => 's',
						'columns' => [['field' => 'handledBy', 'label' => 'Leerkracht', 'render' => 'user']],
					],
				],
				'actions' => [],
			]
		);

		$this->assertSame(['field' => 'handledBy', 'label' => 'Leerkracht', 'render' => 'user'], $out['collections'][0]['columns'][0]);

	}//end testAUserColumnKeepsItsRender()

	public function testDetailAndDefaultsAreValidatedFailClosed(): void {
		$out = $this->normaliser()->normalise(
			[
				'collections' => [
					[
						'id' => 'c1',
						'schema' => 's',
						'detail' => ['layout' => 'timeline', 'fields' => ['title', 3, '']],
						'defaultSort' => ['field' => 'createdAt', 'direction' => 'sideways'],
						'defaultFilters' => ['status' => 'open'],
					],
					[
						'id' => 'c2',
						'schema' => 's',
						'detail' => 'garbage',
						'defaultSort' => ['direction' => 'desc'],
						'defaultFilters' => ['bad' => ['nested']],
					],
				],
				'actions' => [],
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
	 *
	 * With no PortalSchemaReader injected (the default, no-arg normaliser used
	 * throughout this file), the WMEBV data-minimisation guard (T07) has no
	 * schema to resolve `title` against — it fails closed and `required` is
	 * dropped, exactly like an unresolvable schema. That guard's positive path
	 * (required preserved on a genuinely mandatory field) is covered by the
	 * dedicated tests below.
	 */
	public function testFieldConfigForNonWhitelistedFieldIsDropped(): void {
		$out = $this->normaliser()->normalise(
			[
				'collections' => [],
				'actions' => [
					[
						'id' => 'create',
						'type' => 'create',
						'fields' => ['title'],
						'fieldConfigs' => [
							'title' => ['label' => 'Onderwerp', 'required' => true, 'size' => 'large'],
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
		// No schema reader → the WMEBV guard cannot confirm 'title' is
		// genuinely mandatory, so `required` is dropped fail-closed.
		$this->assertArrayNotHasKey('required', $configs['title']);
		$this->assertSame('large', $configs['title']['size']);
		// The action's fields whitelist is never mutated.
		$this->assertSame(['title'], $out['actions'][0]['fields']);

	}//end testFieldConfigForNonWhitelistedFieldIsDropped()

	/**
	 * WMEBV data-minimisation (wmebv-submission-receipts, T07): `required:
	 * true` on a field the action's schema does NOT mandate is dropped
	 * fail-closed — an electronic form may never require a non-mandatory
	 * field.
	 */
	public function testRequiredIsDroppedOnANonMandatoryField(): void {
		$schemaReader = $this->createMock(PortalSchemaReader::class);
		$schemaReader->method('readSchema')->with('exampleDocument')->willReturn(['required' => ['title']]);

		$out = (new PortalManifestNormaliser($schemaReader))->normalise(
			[
				'collections' => [],
				'actions' => [
					[
						'id' => 'create',
						'type' => 'create',
						'schema' => 'exampleDocument',
						'fields' => ['title', 'status'],
						'fieldConfigs' => [
							'status' => ['label' => 'Status', 'required' => true],
						],
					],
				],
			]
		);

		// 'status' is NOT in the schema's required set — the flag is dropped,
		// the rest of the field config survives.
		$configs = $out['actions'][0]['fieldConfigs'];
		$this->assertArrayNotHasKey('required', $configs['status']);
		$this->assertSame('Status', $configs['status']['label']);

	}//end testRequiredIsDroppedOnANonMandatoryField()

	/**
	 * WMEBV data-minimisation: `required: true` on a field the schema DOES
	 * mandate is preserved.
	 */
	public function testRequiredIsPreservedOnAGenuinelyMandatoryField(): void {
		$schemaReader = $this->createMock(PortalSchemaReader::class);
		$schemaReader->method('readSchema')->with('exampleDocument')->willReturn(['required' => ['title']]);

		$out = (new PortalManifestNormaliser($schemaReader))->normalise(
			[
				'collections' => [],
				'actions' => [
					[
						'id' => 'create',
						'type' => 'create',
						'schema' => 'exampleDocument',
						'fields' => ['title'],
						'fieldConfigs' => [
							'title' => ['label' => 'Onderwerp', 'required' => true],
						],
					],
				],
			]
		);

		$this->assertTrue($out['actions'][0]['fieldConfigs']['title']['required']);

	}//end testRequiredIsPreservedOnAGenuinelyMandatoryField()

	/**
	 * WMEBV data-minimisation: when the action's schema cannot be resolved
	 * (reader returns null — e.g. unknown slug), `required` is dropped rather
	 * than elevated on a guess.
	 */
	public function testRequiredIsDroppedWhenSchemaIsUnresolvable(): void {
		$schemaReader = $this->createMock(PortalSchemaReader::class);
		$schemaReader->method('readSchema')->willReturn(null);

		$out = (new PortalManifestNormaliser($schemaReader))->normalise(
			[
				'collections' => [],
				'actions' => [
					[
						'id' => 'create',
						'type' => 'create',
						'schema' => 'unknownSchema',
						'fields' => ['title'],
						'fieldConfigs' => [
							'title' => ['label' => 'Onderwerp', 'required' => true],
						],
					],
				],
			]
		);

		$this->assertArrayNotHasKey('required', $out['actions'][0]['fieldConfigs']['title']);

	}//end testRequiredIsDroppedWhenSchemaIsUnresolvable()

	/**
	 * WMEBV data-minimisation: an action with no `schema` key at all (e.g. a
	 * malformed/legacy manifest entry) also fails closed — no slug means no
	 * lookup, so `required` is dropped.
	 */
	public function testRequiredIsDroppedWhenActionHasNoSchemaKey(): void {
		$schemaReader = $this->createMock(PortalSchemaReader::class);
		$schemaReader->expects($this->never())->method('readSchema');

		$out = (new PortalManifestNormaliser($schemaReader))->normalise(
			[
				'collections' => [],
				'actions' => [
					[
						'id' => 'create',
						'type' => 'create',
						'fields' => ['title'],
						'fieldConfigs' => [
							'title' => ['label' => 'Onderwerp', 'required' => true],
						],
					],
				],
			]
		);

		$this->assertArrayNotHasKey('required', $out['actions'][0]['fieldConfigs']['title']);

	}//end testRequiredIsDroppedWhenActionHasNoSchemaKey()

	public function testOptionsProvidersValidateStaticAndCollectionAndDropMalformed(): void {
		$out = $this->normaliser()->normalise(
			[
				'collections' => [],
				'actions' => [
					[
						'id' => 'create',
						'type' => 'create',
						'fields' => ['category', 'contract', 'broken', 'notlisted'],
						'optionsProviders' => [
							'category' => ['type' => 'static', 'options' => [['value' => 'billing', 'label' => 'Facturatie'], ['value' => 1, 'label' => 'One'], ['label' => 'no value']]],
							'contract' => ['type' => 'collection', 'register' => 'procest', 'schema' => 'supplierContract', 'labelField' => 'name', 'valueField' => 'id'],
							'broken' => ['type' => 'collection', 'register' => 'procest', 'schema' => 'supplierContract', 'labelField' => 'name'],
							'ghost' => ['type' => 'static', 'options' => [['value' => 'x', 'label' => 'y']]],
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

	public function testPageBlocksResolveWithinContributionAndUnknownAreDropped(): void {
		$out = $this->normaliser()->normalise(
			[
				'collections' => [['id' => 'tickets', 'schema' => 'request', 'listable' => true]],
				'actions' => [['id' => 'createTicket', 'type' => 'create', 'schema' => 'request', 'fields' => ['title']]],
				'pages' => [
					[
						'id' => 'support',
						'label' => 'Support',
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

	public function testAbsentPagesSynthesiseOneDefaultPerListableCollection(): void {
		$out = $this->normaliser()->normalise(
			[
				'collections' => [
					['id' => 'tickets', 'schema' => 'request', 'label' => 'Tickets', 'listable' => true],
					['id' => 'archive', 'schema' => 'oldRequest', 'listable' => false],
				],
				'actions' => [
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

	public function testASynthesisedPageCarriesADetailBlockSoARowCanBeOpened(): void {
		// portaliq#723: a page synthesised for a contribution that declares no
		// pages held only the create form and the table. Selecting a row
		// stored the choice and nothing rendered it, so a resident could not
		// open their own case.
		$out = $this->normaliser()->normalise(
			[
				'collections' => [['id' => 'mijnZaken', 'schema' => 'case', 'label' => 'Mijn zaken', 'listable' => true]],
				'actions' => [],
			]
		);

		$this->assertSame(
			[
				['type' => 'collection', 'collection' => 'mijnZaken'],
				['type' => 'detail', 'collection' => 'mijnZaken'],
			],
			$out['pages'][0]['blocks']
		);

	}//end testASynthesisedPageCarriesADetailBlockSoARowCanBeOpened()

	public function testATimelineIsKeptOnlyWhenItNamesAProviderMethod(): void {
		$out = $this->normaliser()->normalise(
			[
				'collections' => [
					['id' => 'c1', 'schema' => 's', 'timeline' => ['label' => 'Wat er is gebeurd', 'provider' => 'caseTimeline']],
					['id' => 'c2', 'schema' => 's', 'timeline' => ['provider' => 'caseTimeline', 'label' => 7]],
					['id' => 'c3', 'schema' => 's', 'timeline' => ['label' => 'Geen provider']],
					['id' => 'c4', 'schema' => 's', 'timeline' => ['provider' => '__construct']],
					['id' => 'c5', 'schema' => 's', 'timeline' => ['provider' => 'case-timeline']],
					['id' => 'c6', 'schema' => 's', 'timeline' => 'caseTimeline'],
					['id' => 'c7', 'schema' => 's', 'timeline' => ['provider' => 'getContribution']],
				],
			]
		);

		$byId = array_column($out['collections'], null, 'id');
		$this->assertSame(['label' => 'Wat er is gebeurd', 'provider' => 'caseTimeline'], $byId['c1']['timeline']);
		// A label that is not text falls back to none; the provider stands.
		$this->assertSame(['label' => '', 'provider' => 'caseTimeline'], $byId['c2']['timeline']);
		foreach (['c3', 'c4', 'c5', 'c6', 'c7'] as $id) {
			$this->assertArrayNotHasKey('timeline', $byId[$id], $id);
		}

	}//end testATimelineIsKeptOnlyWhenItNamesAProviderMethod()

	/**
	 * cases-my-cases-page REQ-CMC-002: a collection's closed marker is kept
	 * only when it names a field the collection projects; an unprojected or
	 * malformed one is dropped, so the portal never guesses what "closed" means.
	 *
	 * @spec openspec/specs/portal-my-cases/spec.md#requirement-open-and-closed-cases-are-told-apart-by-a-declared-field-req-cmc-002
	 */
	public function testAClosedFieldIsKeptOnlyWhenItNamesAProjectedField(): void {
		$out = $this->normaliser()->normalise(
			[
				'collections' => [
					['id' => 'c1', 'schema' => 's', 'kind' => 'cases', 'fields' => ['title', 'endDate'], 'closedField' => 'endDate'],
					['id' => 'c2', 'schema' => 's', 'kind' => 'cases', 'fields' => ['title'], 'closedField' => 'endDate'],
					['id' => 'c3', 'schema' => 's', 'kind' => 'cases', 'fields' => ['title']],
					['id' => 'c4', 'schema' => 's', 'kind' => 'cases', 'closedField' => 'endDate'],
					['id' => 'c5', 'schema' => 's', 'kind' => 'cases', 'closedField' => ['endDate']],
					['id' => 'c6', 'schema' => 's', 'kind' => 'cases', 'closedField' => ''],
				],
			]
		);

		$byId = array_column($out['collections'], null, 'id');
		$this->assertSame('endDate', $byId['c1']['closedField']);
		// Without a projection every field reaches the row, so the name stands.
		$this->assertSame('endDate', $byId['c4']['closedField']);
		foreach (['c2', 'c3', 'c5', 'c6'] as $id) {
			$this->assertArrayNotHasKey('closedField', $byId[$id], $id);
		}

	}//end testAClosedFieldIsKeptOnlyWhenItNamesAProjectedField()

	/**
	 * collection-group-by-field T2: `groupByField` stays only when it names a
	 * projected field, so the portal never groups on a field the rows lack.
	 *
	 * @spec openspec/changes/collection-group-by-field/tasks.md#T2
	 */
	public function testAGroupByFieldIsKeptOnlyWhenItNamesAProjectedField(): void {
		$out = $this->normaliser()->normalise(
			[
				'collections' => [
					['id' => 'c1', 'schema' => 's', 'fields' => ['learnerRef', 'value'], 'groupByField' => 'learnerRef'],
					['id' => 'c2', 'schema' => 's', 'fields' => ['value'], 'groupByField' => 'learnerRef'],
					['id' => 'c3', 'schema' => 's', 'groupByField' => 'learnerRef'],
					['id' => 'c4', 'schema' => 's', 'groupByField' => ['learnerRef']],
					['id' => 'c5', 'schema' => 's', 'groupByField' => ''],
				],
			]
		);

		$byId = array_column($out['collections'], null, 'id');
		$this->assertSame('learnerRef', $byId['c1']['groupByField']);
		$this->assertSame('learnerRef', $byId['c3']['groupByField']);
		foreach (['c2', 'c4', 'c5'] as $id) {
			$this->assertArrayNotHasKey('groupByField', $byId[$id], $id);
		}

	}//end testAGroupByFieldIsKeptOnlyWhenItNamesAProjectedField()

	/**
	 * signin-eherkenning-branch REQ-SEB-002 (T03): `branchField` stays only
	 * when it names a projected field.
	 */
	public function testABranchFieldIsKeptOnlyWhenItNamesAProjectedField(): void {
		$out = $this->normaliser()->normalise(
			[
				'collections' => [
					['id' => 'c1', 'schema' => 's', 'fields' => ['title', 'vestiging'], 'branchField' => 'vestiging'],
					['id' => 'c2', 'schema' => 's', 'fields' => ['title'], 'branchField' => 'vestiging'],
					['id' => 'c3', 'schema' => 's', 'branchField' => 'vestiging'],
					['id' => 'c4', 'schema' => 's', 'branchField' => ['vestiging']],
					['id' => 'c5', 'schema' => 's', 'branchField' => ''],
				],
			]
		);

		$byId = array_column($out['collections'], null, 'id');
		$this->assertSame('vestiging', $byId['c1']['branchField']);
		$this->assertSame('vestiging', $byId['c3']['branchField']);
		foreach (['c2', 'c4', 'c5'] as $id) {
			$this->assertArrayNotHasKey('branchField', $byId[$id], $id);
		}

	}//end testABranchFieldIsKeptOnlyWhenItNamesAProjectedField()

	/**
	 * ADDITIVE-COMPAT: a pure v2 manifest round-trips with collections + actions
	 * byte-identical; only an additive synthesised `pages` array appears.
	 */
	public function testV2ManifestRoundTripsWithOnlyAdditivePages(): void {
		$v2 = [
			'app' => 'demo',
			'label' => 'Demo',
			'collections' => [['id' => 'c1', 'register' => 'r', 'schema' => 's', 'scopeField' => 'subjectRef', 'label' => 'C', 'listable' => true]],
			'actions' => [['id' => 'a1', 'type' => 'create', 'register' => 'r', 'schema' => 's', 'fields' => ['title']]],
		];

		$out = $this->normaliser()->normalise($v2);

		$this->assertSame($v2['collections'], $out['collections']);
		$this->assertSame($v2['actions'], $out['actions']);
		$this->assertArrayHasKey('pages', $out);
		$this->assertSame('c1', $out['pages'][0]['id']);

	}//end testV2ManifestRoundTripsWithOnlyAdditivePages()

	public function testGarbageInputNeverThrowsAndFailsClosed(): void {
		$out = $this->normaliser()->normalise(
			[
				'collections' => ['not-an-array', ['id' => 'c1', 'schema' => 's', 'columns' => 'nope', 'detail' => 5, 'listable' => true]],
				'actions' => ['garbage', ['id' => 'a', 'fields' => 'notalist', 'fieldConfigs' => 'x', 'optionsProviders' => 7]],
				'pages' => 'not-a-list',
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
	public function testSetKeepsOnlyWhitelistedScalarTransitionValues(): void {
		$out = $this->normaliser()->normalise(
			[
				'collections' => [],
				'actions' => [
					[
						'id' => 'close',
						'type' => 'update',
						'fields' => ['status'],
						'set' => ['status' => 'closed', 'subjectRef' => 'HACKER', 'meta' => ['nested']],
					],
				],
			]
		);

		// Only the whitelisted scalar survives; the smuggled scope field and the
		// non-scalar value are dropped.
		$this->assertSame(['status' => 'closed'], $out['actions'][0]['set']);
	}//end testSetKeepsOnlyWhitelistedScalarTransitionValues()

	public function testMalformedSetIsDropped(): void {
		$out = $this->normaliser()->normalise(
			[
				'collections' => [],
				'actions' => [['id' => 'u', 'type' => 'update', 'fields' => ['status'], 'set' => 'not-a-map']],
			]
		);

		$this->assertArrayNotHasKey('set', $out['actions'][0]);
	}//end testMalformedSetIsDropped()

	/**
	 * A collection's `rowActions` resolve only to `type: update` actions in the
	 * SAME contribution; a create action, an unknown id, or a foreign id is
	 * dropped, and an empty result removes the key.
	 */
	public function testRowActionsResolveOnlyToUpdateActionsInContribution(): void {
		$out = $this->normaliser()->normalise(
			[
				'collections' => [
					['id' => 'tickets', 'schema' => 'request', 'listable' => true, 'rowActions' => ['close', 'createTicket', 'ghost']],
					['id' => 'other', 'schema' => 'x', 'listable' => true, 'rowActions' => ['createTicket']],
				],
				'actions' => [
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

	public function testFilesUploadIsCoercedToAStrictBoolean(): void {
		$out = $this->normaliser()->normalise(
			[
				'collections' => [
					['id' => 'a', 'schema' => 's', 'filesUpload' => true],
					['id' => 'b', 'schema' => 's', 'filesUpload' => 'true'],
					['id' => 'c', 'schema' => 's', 'filesUpload' => 1],
					['id' => 'd', 'schema' => 's'],
				],
				'actions' => [],
			]
		);

		// Only explicit true / "true" enable it; a truthy 1 does NOT.
		$this->assertTrue($out['collections'][0]['filesUpload']);
		$this->assertTrue($out['collections'][1]['filesUpload']);
		$this->assertFalse($out['collections'][2]['filesUpload']);
		$this->assertArrayNotHasKey('filesUpload', $out['collections'][3]);

	}//end testFilesUploadIsCoercedToAStrictBoolean()

	/**
	 * portal-document-download: `filesDownload` is normalised exactly like
	 * `filesUpload` — default false, malformed → false, true preserved.
	 */
	public function testFilesDownloadIsCoercedToAStrictBoolean(): void {
		$out = $this->normaliser()->normalise(
			[
				'collections' => [
					['id' => 'a', 'schema' => 's', 'filesDownload' => true],
					['id' => 'b', 'schema' => 's', 'filesDownload' => 'true'],
					['id' => 'c', 'schema' => 's', 'filesDownload' => 1],
					['id' => 'd', 'schema' => 's'],
				],
				'actions' => [],
			]
		);

		// Only explicit true / "true" enable it; a truthy 1 does NOT.
		$this->assertTrue($out['collections'][0]['filesDownload']);
		$this->assertTrue($out['collections'][1]['filesDownload']);
		$this->assertFalse($out['collections'][2]['filesDownload']);
		$this->assertArrayNotHasKey('filesDownload', $out['collections'][3]);

	}//end testFilesDownloadIsCoercedToAStrictBoolean()

	/**
	 * portal-page-provisioning: `anonymous` is coerced to a strict boolean,
	 * exactly like `filesUpload`/`filesDownload` — default false, malformed →
	 * false, `true`/`"true"` preserved. Covers both collections and actions.
	 */
	public function testAnonymousIsCoercedToAStrictBoolean(): void {
		$out = $this->normaliser()->normalise(
			[
				'collections' => [
					['id' => 'a', 'schema' => 's', 'anonymous' => true],
					['id' => 'b', 'schema' => 's', 'anonymous' => 'true'],
					['id' => 'c', 'schema' => 's', 'anonymous' => 1],
					['id' => 'd', 'schema' => 's'],
				],
				'actions' => [
					['id' => 'x', 'type' => 'create', 'anonymous' => true],
					['id' => 'y', 'type' => 'create'],
				],
			]
		);

		$this->assertTrue($out['collections'][0]['anonymous']);
		$this->assertTrue($out['collections'][1]['anonymous']);
		$this->assertFalse($out['collections'][2]['anonymous']);
		$this->assertArrayNotHasKey('anonymous', $out['collections'][3]);

		$this->assertTrue($out['actions'][0]['anonymous']);
		$this->assertArrayNotHasKey('anonymous', $out['actions'][1]);

	}//end testAnonymousIsCoercedToAStrictBoolean()

	/**
	 * portal-page-provisioning (spec: "Anonymous and elevated trust MUST NOT
	 * combine on one entry"): an entry declaring BOTH `anonymous: true` AND a
	 * non-`low` `minTrust` has `anonymous` dropped — fail-closed, the entry
	 * falls back to requiring an authenticated, trust-checked bearer, never
	 * the reverse. The entry itself (and its `minTrust`) is NOT removed —
	 * only the contradictory `anonymous` flag is.
	 */
	public function testAnonymousIsDroppedWhenCombinedWithElevatedMinTrust(): void {
		$out = $this->normaliser()->normalise(
			[
				'collections' => [
					['id' => 'gated', 'schema' => 's', 'anonymous' => true, 'minTrust' => 'substantial'],
				],
				'actions' => [
					['id' => 'gatedAction', 'type' => 'create', 'anonymous' => true, 'minTrust' => 'high'],
				],
			]
		);

		$collection = $out['collections'][0];
		$this->assertArrayNotHasKey('anonymous', $collection);
		$this->assertSame('substantial', $collection['minTrust']);

		$action = $out['actions'][0];
		$this->assertArrayNotHasKey('anonymous', $action);
		$this->assertSame('high', $action['minTrust']);

	}//end testAnonymousIsDroppedWhenCombinedWithElevatedMinTrust()

	/**
	 * An absent or explicit `minTrust: low` does NOT conflict with
	 * `anonymous: true` — only a HIGHER-than-low minTrust trips the
	 * exclusion (design.md).
	 */
	public function testAnonymousSurvivesWithNoOrLowMinTrust(): void {
		$out = $this->normaliser()->normalise(
			[
				'collections' => [
					['id' => 'a', 'schema' => 's', 'anonymous' => true],
					['id' => 'b', 'schema' => 's', 'anonymous' => true, 'minTrust' => 'low'],
				],
				'actions' => [],
			]
		);

		$this->assertTrue($out['collections'][0]['anonymous']);
		$this->assertTrue($out['collections'][1]['anonymous']);

	}//end testAnonymousSurvivesWithNoOrLowMinTrust()

	/**
	 * #804, T03: an action whose `scopeClaim` names one of the nine frozen
	 * assertion claims is dropped, so its value can never stand in for `sub`,
	 * `iss` or any other of them; an ordinary claim name keeps the action.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-28-case-actions-sign-a-document/tasks.md#T03
	 */
	public function testReservedScopeClaimNameIsDropped(): void {
		$action = static fn (string $id, string $claim): array => [
			'id' => $id,
			'type' => 'endpoint',
			'endpoint' => '/apps/filinq/api/sign',
			'method' => 'POST',
			'scopeClaim' => $claim,
		];
		$out = (new PortalManifestNormaliser())->normalise(
			[
				'collections' => [],
				'actions' => [
					$action('sign', 'filinq.signerEmail'),
					$action('hijackSub', 'filinq.sub'),
					$action('hijackIss', 'iss'),
				],
			]
		);

		$this->assertSame(['sign'], array_column($out['actions'], 'id'));
	}//end testReservedScopeClaimNameIsDropped()

	/**
	 * A guest action without a `tokenField` has nowhere to carry the signed
	 * token, and one aimed off the instance would forward it elsewhere: both
	 * are dropped, a well-formed one keeps its guest keys.
	 *
	 * @spec openspec/changes/archive/2026-10-01-identity-guest-page-for-signed-links/tasks.md#T01
	 */
	public function testGuestActionNeedsATokenField(): void {
		$out = $this->normaliser()->normalise(
			[
				'collections' => [],
				'actions' => [
					['id' => 'withdraw', 'guest' => true, 'endpoint' => '/apps/shillinq/api/withdraw', 'tokenField' => 'token', 'label' => 'Withdraw from contract here', 'previewEndpoint' => '/apps/shillinq/api/withdraw/preview', 'confirmText' => 'Withdraw?'],
					['id' => 'noToken', 'guest' => true, 'endpoint' => '/apps/shillinq/api/x'],
					['id' => 'badToken', 'guest' => true, 'endpoint' => '/apps/shillinq/api/x', 'tokenField' => 'a b'],
					['id' => 'remote', 'guest' => true, 'endpoint' => 'https://evil.example/x', 'tokenField' => 'token'],
					['id' => 'remotePreview', 'guest' => true, 'endpoint' => '/apps/shillinq/api/x', 'tokenField' => 'token', 'previewEndpoint' => '//evil.example/p'],
				],
			]
		);

		$this->assertSame(['withdraw'], array_column($out['actions'], 'id'));
		$this->assertTrue($out['actions'][0]['guest']);
		$this->assertSame('token', $out['actions'][0]['tokenField']);
		$this->assertSame('/apps/shillinq/api/withdraw/preview', $out['actions'][0]['previewEndpoint']);
		$this->assertSame('Withdraw from contract here', $out['actions'][0]['label']);
	}//end testGuestActionNeedsATokenField()

	/**
	 * A guest is never more than `low`: a guest action asking for more is
	 * dropped, not offered to a visitor who cannot have it (REQ-GST-001).
	 *
	 * @spec openspec/changes/archive/2026-10-01-identity-guest-page-for-signed-links/tasks.md#T01
	 */
	public function testGuestActionAboveLowTrustIsDropped(): void {
		$out = $this->normaliser()->normalise(
			[
				'collections' => [],
				'actions' => [
					['id' => 'withdraw', 'guest' => true, 'endpoint' => '/apps/shillinq/api/withdraw', 'tokenField' => 'token', 'minTrust' => 'substantial'],
					['id' => 'pay', 'guest' => true, 'endpoint' => '/apps/shillinq/api/pay', 'tokenField' => 'payToken', 'minTrust' => 'low'],
					['id' => 'resident', 'endpoint' => '/apps/shillinq/api/r', 'minTrust' => 'substantial'],
				],
			]
		);

		$this->assertSame(['pay', 'resident'], array_column($out['actions'], 'id'));
	}//end testGuestActionAboveLowTrustIsDropped()
}
