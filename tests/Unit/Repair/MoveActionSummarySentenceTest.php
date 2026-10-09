<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Repair;

use OCA\Portaliq\Repair\MoveActionSummarySentence;
use OCA\Portaliq\Service\PortalRegisterContext;
use OCP\Migration\IOutput;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Decision 127: an action's `summary` is the start tile's string. A
 * data-provisioned contribution (portalPage) stored before that keeps its
 * answer sentence under `summary` as an object; this step moves it to
 * `answerSummary`, and the rewritten record must fit the real portalPage
 * schema. A string summary, a page without actions and a second run write
 * nothing.
 *
 * @spec openspec/changes/action-summary-sentence/specs/portal-contribution-contract/spec.md#requirement-an-action-may-sum-up-the-answers-in-one-sentence
 */
class MoveActionSummarySentenceTest extends TestCase {
	/**
	 * A store of portalPage records that pages like OpenRegister and records each save.
	 *
	 * @param array<int, array<string, mixed>> $rows The stored records.
	 *
	 * @return object
	 */
	private function store(array $rows): object {
		return new class($rows) {
			/**
			 * Each save, in order.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $saves = [];

			/**
			 * The store.
			 *
			 * @param array<int, array<string, mixed>> $rows The records.
			 */
			public function __construct(public array $rows) {
			}

			/**
			 * One page of records.
			 *
			 * @param array<string, mixed> $config The paging.
			 * @param bool $_rbac Ignored.
			 * @param bool $_multitenancy Ignored.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return array_slice($this->rows, (int)($config['offset'] ?? 0), (int)($config['limit'] ?? 100));
			}

			/**
			 * Save one record.
			 *
			 * @param array<string, mixed> $object The record.
			 * @param mixed $register The register.
			 * @param mixed $schema The schema.
			 * @param string|null $uuid The uuid.
			 * @param bool $_rbac Ignored.
			 * @param bool $_multitenancy Ignored.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->saves[] = ['uuid' => $uuid, 'schema' => $schema, 'object' => $object];
				foreach ($this->rows as $index => $row) {
					if (($row['@self']['uuid'] ?? null) === $uuid) {
						$this->rows[$index] = $object + ['@self' => $row['@self']];
					}
				}

				return $object;
			}
		};
	}//end store()

	/**
	 * The step over a store.
	 *
	 * @param object $store The store.
	 *
	 * @return MoveActionSummarySentence
	 */
	private function step(object $store): MoveActionSummarySentence {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$context = $this->createMock(PortalRegisterContext::class);
		$context->method('apply')->willReturn(true);

		return new MoveActionSummarySentence($container, $context, $this->createMock(LoggerInterface::class));
	}//end step()

	/**
	 * A stored contribution as the old sentence shape left it.
	 *
	 * @param string $uuid The uuid.
	 * @param array<int, array<string, mixed>> $actions The actions.
	 *
	 * @return array<string, mixed>
	 */
	private function page(string $uuid, array $actions): array {
		return [
			'label'       => 'Afwezigheid',
			'audience'    => 'guardian',
			'status'      => 'active',
			'collections' => [],
			'actions'     => $actions,
			'@self'       => ['uuid' => $uuid],
		];
	}//end page()

	/**
	 * The old object moves to `answerSummary`, the record fits the schema,
	 * and a second run writes nothing.
	 *
	 * @return void
	 */
	public function testTheOldSentenceObjectMovesAndTheRecordFitsTheSchema(): void {
		$sentence = ['label' => 'U meldt', 'template' => '{learner} is {when} ziek.', 'phrases' => ['when' => ['today' => 'vandaag']]];
		$store    = $this->store(
			[
				$this->page('p-1', [['id' => 'createExcuseRequest', 'type' => 'create', 'label' => 'Ziek melden', 'fields' => ['learner', 'when'], 'summary' => $sentence]]),
				$this->page('p-2', [['id' => 'createBezwaar', 'type' => 'create', 'label' => 'Bezwaar maken', 'summary' => 'Maak binnen zes weken bezwaar.']]),
				$this->page('p-3', []),
			]
		);

		$this->step($store)->run($this->createMock(IOutput::class));

		$this->assertCount(1, $store->saves, 'only the record with the old shape is written');
		$saved = $store->saves[0];
		$this->assertSame('p-1', $saved['uuid']);
		$this->assertSame('portalPage', $saved['schema']);
		$this->assertSame($sentence, $saved['object']['actions'][0]['answerSummary']);
		$this->assertArrayNotHasKey('summary', $saved['object']['actions'][0]);
		$this->assertArrayNotHasKey('@self', $saved['object']);
		$this->assertTrue($this->fitsSchema(record: $saved['object']), 'the rewritten record fits the real portalPage schema');
		$this->assertFalse($this->fitsSchema(record: $this->page('x', [['id' => 'a', 'type' => 'create', 'label' => 'A', 'summary' => $sentence]])), 'the old object shape no longer fits');
		$this->assertTrue($this->fitsSchema(record: $this->page('y', [['id' => 'b', 'type' => 'create', 'label' => 'B', 'summary' => 'Maak bezwaar.', 'audiences' => ['citizen']]])), 'a tile summary fits');

		$this->step($store)->run($this->createMock(IOutput::class));
		$this->assertCount(1, $store->saves, 'a second run writes nothing');
	}//end testTheOldSentenceObjectMovesAndTheRecordFitsTheSchema()

	/**
	 * When both keys are stored, the newer `answerSummary` stays and the old
	 * object is removed.
	 *
	 * @return void
	 */
	public function testAnExistingAnswerSummaryWins(): void {
		$store = $this->store(
			[
				$this->page('p-1', [['id' => 'a', 'type' => 'create', 'label' => 'A', 'summary' => ['template' => 'Oud {x}.'], 'answerSummary' => ['template' => 'Nieuw {x}.']]]),
			]
		);

		$this->step($store)->run($this->createMock(IOutput::class));

		$this->assertSame(['template' => 'Nieuw {x}.'], $store->saves[0]['object']['actions'][0]['answerSummary']);
		$this->assertArrayNotHasKey('summary', $store->saves[0]['object']['actions'][0]);
	}//end testAnExistingAnswerSummaryWins()

	/**
	 * Whether a record fits the portalPage schema of the shipped register.
	 *
	 * @param array<string, mixed> $record The record without `@self`.
	 *
	 * @return bool
	 */
	private function fitsSchema(array $record): bool {
		unset($record['@self']);
		$register = (array)json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/portaliq_register.json'), true);
		$page     = $register['components']['schemas']['portalPage'];
		$schema   = json_decode((string)json_encode(['type' => 'object', 'required' => $page['required'], 'properties' => $page['properties']]), false);

		return (new Validator())->validate(json_decode((string)json_encode($record), false), $schema)->isValid();
	}//end fitsSchema()
}//end class
