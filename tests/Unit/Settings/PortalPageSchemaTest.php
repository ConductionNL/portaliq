<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * A contribution authored as a portalPage RECORD may declare what the
 * resolvers accept (site-mijn-omgeving-components waves 1 to 5). Measured
 * on :8090: the register refused `kind: cases` (enum ["inbox"]) and would
 * have dropped every key it did not declare, in silence, so the e2e seeds
 * could never run. Every seed the e2e specs post
 * (tests/e2e/fixtures/mijn-omgeving-pages.json) is validated here against
 * the real portalPage fragment, then run through PortalManifestNormaliser,
 * whose answer must still carry the keys.
 *
 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-use-the-tasks-inbox-cases-steps-documents-and-timeline-blocks-req-smo-021
 */
class PortalPageSchemaTest extends TestCase {
	/**
	 * The portalPage schema fragment as JSON Schema.
	 *
	 * @var object
	 */
	private static object $schema;

	/**
	 * The e2e seeds, by name.
	 *
	 * @var array<string, mixed>
	 */
	private static array $fixtures = [];

	public static function setUpBeforeClass(): void {
		$register = (array)json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/portaliq_register.json'), true);
		$page = $register['components']['schemas']['portalPage'];
		self::$schema = json_decode((string)json_encode(['type' => 'object', 'required' => $page['required'], 'properties' => $page['properties']]), false);

		$json = (string)file_get_contents(__DIR__ . '/../../e2e/fixtures/mijn-omgeving-pages.json');
		$json = str_replace(['{stamp}', '{audience}'], ['1700000000000', 'client'], $json);
		self::$fixtures = (array)json_decode($json, true);
		unset(self::$fixtures['_comment']);
	}//end setUpBeforeClass()

	/**
	 * Whether a record fits the portalPage schema.
	 *
	 * @param array<string, mixed> $record The record.
	 *
	 * @return bool
	 */
	private function fits(array $record): bool {
		return (new Validator())->validate(json_decode((string)json_encode($record), false), self::$schema)->isValid();
	}//end fits()

	/**
	 * A minimal record with one collection and one page, plus the given parts.
	 *
	 * @param array<string, mixed> $collection Extra collection keys.
	 * @param array<string, mixed> $page       Extra page keys.
	 * @param array<string, mixed> $block      The block.
	 *
	 * @return array<string, mixed>
	 */
	private function record(array $collection=[], array $page=[], array $block=['type' => 'collection', 'collection' => 'zaken']): array {
		return [
			'label'       => 'Mijn omgeving',
			'audience'    => 'client',
			'status'      => 'active',
			// The extras come first, so they win over the defaults.
			'collections' => [$collection + ['id' => 'zaken', 'register' => 'portaliq', 'schema' => 'portalCase', 'kind' => 'cases']],
			'pages'       => [$page + ['id' => 'p', 'label' => 'P', 'blocks' => [$block]]],
		];
	}//end record()

	/**
	 * Every e2e seed fits the schema.
	 *
	 * @return void
	 */
	public function testEveryE2eSeedFitsTheSchema(): void {
		$this->assertSame(['case-cards', 'action-rows', 'description-list', 'omgeving-live', 'switching'], array_keys(self::$fixtures));
		foreach (self::$fixtures as $name => $record) {
			$this->assertTrue($this->fits(record: $record), $name . ' fits the portalPage schema');
		}
	}//end testEveryE2eSeedFitsTheSchema()

	/**
	 * Every key the waves added fits, one payload per key; an unknown kind,
	 * block type or range is refused.
	 *
	 * @return void
	 */
	public function testEachNewKeyFitsAndAnUnknownValueIsRefused(): void {
		$collections = [
			['kind' => 'timedTask'],
			['steps' => ['label' => 'Waar staat uw aanvraag?', 'provider' => 'caseSteps']],
			['dueField' => 'withdrawnAt', 'turnField' => 'toelichting'],
			['fieldConfigs' => ['toelichting' => ['valueLabels' => ['resident' => 'U bent aan zet']]]],
			['caseTypeSource' => ['register' => 'dossiq', 'schema' => 'caseType'], 'caseTypeField' => 'zaaktype'],
			['closedField' => 'withdrawnAt', 'statusLabelField' => 'status', 'groupByField' => 'status'],
			['timeline' => ['label' => 'x', 'provider' => 'caseTimeline'], 'documents' => ['label' => 'x', 'provider' => 'caseDocuments']],
		];
		foreach ($collections as $extra) {
			$this->assertTrue($this->fits(record: $this->record(collection: $extra)), (string)json_encode($extra));
		}

		$pages = [['home' => true], ['menu' => false], ['group' => 'Zaken'], ['record' => ['collection' => 'zaken']], ['records' => ['collection' => 'zaken', 'titleFields' => ['reference']]], ['records' => 'zaken'], ['record' => ['collection' => 'zaken'], 'perRecord' => 'zaken']];
		foreach ($pages as $extra) {
			$this->assertTrue($this->fits(record: $this->record(page: $extra)), (string)json_encode($extra));
		}

		$blocks = [
			['type' => 'collection', 'collection' => 'zaken', 'limit' => 3, 'sort' => ['field' => 'reference', 'direction' => 'desc']],
			['type' => 'calendar', 'sources' => [['collection' => 'zaken', 'startField' => 'withdrawnAt', 'titleField' => 'reference']], 'range' => 'week'],
			['type' => 'tasks', 'collection' => 'zaken', 'dueField' => 'withdrawnAt', 'titleFields' => ['reference'], 'label' => 'Dit moet u nog doen', 'limit' => 5],
			['type' => 'inbox', 'limit' => 2],
			['type' => 'cases', 'collection' => 'zaken', 'open' => true],
			['type' => 'steps', 'collection' => 'zaken'],
			['type' => 'documents', 'collection' => 'zaken'],
			['type' => 'timeline', 'collection' => 'zaken'],
			['type' => 'kpi', 'collection' => 'zaken', 'cards' => [['field' => 'a', 'label' => 'A']]],
			['type' => 'news', 'limit' => 3],
			['type' => 'collection', 'collection' => 'zaken', 'recordField' => 'kind', 'lookups' => []],
		];
		foreach ($blocks as $block) {
			$this->assertTrue($this->fits(record: $this->record(block: $block)), (string)json_encode($block));
		}

		$this->assertFalse($this->fits(record: $this->record(collection: ['kind' => 'zaken'])), 'an unknown kind is refused');
		$this->assertFalse($this->fits(record: $this->record(block: ['type' => 'caseCards', 'collection' => 'zaken'])), 'a placeholder block type is refused');
		$this->assertFalse($this->fits(record: $this->record(block: ['type' => 'calendar', 'sources' => [], 'range' => 'year'])), 'an unknown range is refused');
	}//end testEachNewKeyFitsAndAnUnknownValueIsRefused()

	/**
	 * Every key a seed uses is DECLARED by the schema. The fragment allows
	 * extra keys, so validation alone cannot see one OpenRegister would drop
	 * in silence when it stores the record.
	 *
	 * @return void
	 */
	public function testEveryKeyTheSeedsUseIsDeclared(): void {
		$register = (array)json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/portaliq_register.json'), true);
		$page = $register['components']['schemas']['portalPage']['properties'];
		$declared = [
			'record'     => array_keys($page),
			'collection' => array_keys($page['collections']['items']['properties']),
			'page'       => array_keys($page['pages']['items']['properties']),
			'block'      => array_keys($page['pages']['items']['properties']['blocks']['items']['properties']),
		];

		foreach (self::$fixtures as $name => $record) {
			$this->assertSame([], array_values(array_diff(array_keys($record), $declared['record'])), $name);
			foreach ($record['collections'] as $collection) {
				$this->assertSame([], array_values(array_diff(array_keys($collection), $declared['collection'])), $name . ' collection');
			}

			foreach ($record['pages'] as $entry) {
				$this->assertSame([], array_values(array_diff(array_keys($entry), $declared['page'])), $name . ' page');
				foreach ($entry['blocks'] as $block) {
					$this->assertSame([], array_values(array_diff(array_keys($block), $declared['block'])), $name . ' block');
				}
			}
		}
	}//end testEveryKeyTheSeedsUseIsDeclared()

	/**
	 * The seeds survive the normaliser with the keys they declare: a key the
	 * schema accepts but the normaliser drops would make a silent no-op.
	 *
	 * @return void
	 */
	public function testTheSeedsKeepTheirKeysThroughTheNormaliser(): void {
		$live = (new PortalManifestNormaliser())->normalise(self::$fixtures['omgeving-live']);
		$zaken = $live['collections'][0];
		$this->assertSame('cases', $zaken['kind']);
		$this->assertSame('withdrawnAt', $zaken['dueField']);
		$this->assertSame('toelichting', $zaken['turnField']);
		$this->assertSame('U bent aan zet', $zaken['fieldConfigs']['toelichting']['valueLabels']['resident']);
		$this->assertSame('inbox', $live['collections'][2]['kind']);

		[$home, $hidden] = $live['pages'];
		$this->assertTrue($home['home']);
		$this->assertSame(['tasks', 'cases', 'inbox'], array_column($home['blocks'], 'type'));
		$this->assertSame('term', $home['blocks'][0]['dueField']);
		$this->assertTrue($home['blocks'][1]['open']);
		$this->assertFalse($hidden['menu']);

		$switching = (new PortalManifestNormaliser())->normalise(self::$fixtures['switching']);
		$this->assertSame(['collection' => 'kind-1700000000000', 'titleFields' => ['reference']], $switching['pages'][0]['records']);

		$cards = (new PortalManifestNormaliser())->normalise(self::$fixtures['case-cards']);
		$this->assertSame('withdrawnAt', $cards['collections'][0]['closedField']);
		$this->assertSame('cases', $cards['pages'][0]['blocks'][0]['type']);
	}//end testTheSeedsKeepTheirKeysThroughTheNormaliser()
}//end class
