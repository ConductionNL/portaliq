<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use OCA\Portaliq\Contribution\TimedTaskConfigNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * The timed-task block and the subjectField key (portal-take-assessment),
 * through PortalManifestNormaliser, the one entry point the registry uses.
 *
 * @spec openspec/changes/portal-take-assessment/specs/portal-contribution-contract/spec.md#requirement-a-collection-must-be-able-to-declare-a-timed-task-driven-by-five-endpoint-actions
 */
class TimedTaskConfigNormaliserTest extends TestCase {
	/**
	 * The five endpoint actions learniq declares.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function actions(): array {
		$actions = [];
		foreach (['listTests' => '', 'startTest' => '/start', 'saveTestAnswer' => '/answer', 'submitTest' => '/submit', 'readTestResult' => '/result'] as $id => $suffix) {
			$actions[] = [
				'id' => $id,
				'endpoint' => '/apps/learniq/api/portal/assessments' . $suffix,
				'method' => 'POST',
				'fields' => [],
				'subjectField' => 'learnerRef',
				'scopeClaim' => 'learnerRef',
			];
		}

		return $actions;
	}//end actions()

	/**
	 * A timed-task collection naming the given step actions.
	 *
	 * @param array<string, mixed> $block The timedTask block.
	 *
	 * @return array<string, mixed>
	 */
	private function collection(array $block): array {
		return [
			'id' => 'studentTests',
			'kind' => 'timedTask',
			'register' => 'learniq',
			'schema' => 'assessment-result',
			'scopeField' => 'learnerRef',
			'listable' => true,
			'timedTask' => $block,
		];
	}//end collection()

	/**
	 * The sound block learniq will declare.
	 *
	 * @return array<string, string>
	 */
	private function soundBlock(): array {
		return ['available' => 'listTests', 'start' => 'startTest', 'answer' => 'saveTestAnswer', 'submit' => 'submitTest', 'result' => 'readTestResult'];
	}//end soundBlock()

	/**
	 * Normalise one contribution.
	 *
	 * @param array<int, array<string, mixed>> $collections The collections.
	 * @param array<int, array<string, mixed>> $actions The actions.
	 *
	 * @return array<string, mixed>
	 */
	private function normalise(array $collections, array $actions): array {
		return (new PortalManifestNormaliser())->normalise(['collections' => $collections, 'actions' => $actions]);
	}//end normalise()

	/**
	 * A block naming five endpoint actions is kept as declared.
	 *
	 * @return void
	 */
	public function testASoundTimedTaskIsKept(): void {
		$out = $this->normalise([$this->collection(array_merge($this->soundBlock(), ['extra' => 'dropped']))], $this->actions());

		$this->assertSame('timedTask', $out['collections'][0]['kind']);
		$this->assertSame($this->soundBlock(), $out['collections'][0]['timedTask']);
		$this->assertCount(5, $out['actions']);
		$this->assertSame('learnerRef', $out['actions'][1]['subjectField']);
	}//end testASoundTimedTaskIsKept()

	/**
	 * A missing step, a step naming an absent (for instance trust-dropped)
	 * action, a step naming an action without an endpoint and a block that is
	 * not an array all fall back to a plain list.
	 *
	 * @return void
	 */
	public function testABrokenTimedTaskFallsBackToAList(): void {
		$missing = $this->soundBlock();
		unset($missing['submit']);
		$absent = array_merge($this->soundBlock(), ['submit' => 'droppedByTrust']);
		$noEndpoint = array_merge($this->soundBlock(), ['answer' => 'createAnswer']);
		$external = array_merge($this->soundBlock(), ['result' => 'externalResult']);

		$actions = array_merge(
			$this->actions(),
			[
				['id' => 'createAnswer', 'type' => 'create', 'register' => 'learniq', 'schema' => 'x', 'fields' => ['a']],
				['id' => 'externalResult', 'endpoint' => 'https://elsewhere.example/result', 'method' => 'POST'],
			]
		);

		foreach (['missing' => $missing, 'absent' => $absent, 'noEndpoint' => $noEndpoint, 'external' => $external] as $label => $block) {
			$out = $this->normalise([$this->collection($block)], $actions);
			$this->assertArrayNotHasKey('kind', $out['collections'][0], $label);
			$this->assertArrayNotHasKey('timedTask', $out['collections'][0], $label);
			$this->assertSame('studentTests', $out['collections'][0]['id'], $label . ': the list itself stays');
		}

		$notArray = $this->collection([]);
		$notArray['timedTask'] = 'listTests';
		$this->assertArrayNotHasKey('kind', $this->normalise([$notArray], $this->actions())['collections'][0]);

		$strayBlock = $this->collection($this->soundBlock());
		unset($strayBlock['kind']);
		$this->assertArrayNotHasKey('timedTask', $this->normalise([$strayBlock], $this->actions())['collections'][0], 'a block without the kind is dropped');
	}//end testABrokenTimedTaskFallsBackToAList()

	/**
	 * A malformed subjectField removes the action, which then also breaks a
	 * timed task naming it.
	 *
	 * @spec openspec/changes/portal-take-assessment/specs/portal-contribution-contract/spec.md#requirement-an-endpoint-action-must-be-able-to-receive-the-subjects-scope-from-the-server
	 *
	 * @return void
	 */
	public function testAMalformedSubjectFieldRemovesTheAction(): void {
		$actions = $this->actions();
		$actions[2]['subjectField'] = 'learner ref; drop';
		$actions[3]['subjectField'] = ['learnerRef'];

		$out = $this->normalise([$this->collection($this->soundBlock())], $actions);

		$this->assertSame(['listTests', 'startTest', 'readTestResult'], array_column($out['actions'], 'id'));
		$this->assertArrayNotHasKey('kind', $out['collections'][0]);
	}//end testAMalformedSubjectFieldRemovesTheAction()

	/**
	 * Every collection keeps, loses or never had its timed task exactly as recorded.
	 *
	 * @return void
	 */
	public function testEveryCollectionIsAsRecorded(): void {
		$n = new TimedTaskConfigNormaliser();
		$actions = [
			['id' => 'a1', 'endpoint' => '/x'],
			['id' => 'a2', 'endpoint' => '/y'],
			['id' => 'a3', 'endpoint' => '/z'],
			['id' => 'query', 'endpoint' => '/z?u=http://q'],
			['id' => 'a4', 'endpoint' => '/w'],
			['id' => 'a5', 'endpoint' => '/v'],
			['id' => 'far', 'endpoint' => 'https://elsewhere/x'],
			['id' => 'proto', 'endpoint' => '//elsewhere/x'],
			['id' => 'rel', 'endpoint' => 'x/y'],
			['id' => '', 'endpoint' => '/e'],
			['id' => 7, 'endpoint' => '/e'],
			['id' => 'noend'],
			['endpoint' => '/e'],
		];
		$good = ['available' => 'a1', 'start' => 'a2', 'answer' => 'a3', 'submit' => 'a4', 'result' => 'a5', 'extra' => 'x'];
		$collections = [
			3 => ['id' => 'c0', 'kind' => 'list'],
			['id' => 'c1', 'kind' => 'timedTask', 'timedTask' => $good],
			['id' => 'c2', 'kind' => 'timedTask', 'timedTask' => ['start' => 'a2'] + $good],
			['id' => 'c3', 'kind' => 'timedTask', 'timedTask' => ['answer' => 'far'] + $good],
			['id' => 'c4', 'kind' => 'timedTask', 'timedTask' => ['submit' => 'proto'] + $good],
			['id' => 'c5', 'kind' => 'timedTask', 'timedTask' => ['result' => 'rel'] + $good],
			['id' => 'c6', 'kind' => 'timedTask', 'timedTask' => ['available' => 7] + $good],
			['id' => 'c7', 'kind' => 'timedTask', 'timedTask' => array_diff_key($good, ['result' => 1])],
			['id' => 'c8', 'kind' => 'timedTask', 'timedTask' => 'x'],
			['id' => 'c9', 'kind' => 'timedTask'],
			['id' => 'c10', 'kind' => 'list', 'timedTask' => $good],
			['id' => 'c11', 'timedTask' => null],
			['id' => 'c12', 'kind' => 'timedTask', 'timedTask' => ['start' => 'noend'] + $good],
			['id' => 'c13', 'kind' => 'timedTask', 'timedTask' => ['start' => 'query'] + $good],
		];

		$this->assertSame(
			'{"3":{"id":"c0","kind":"list"},"4":{"id":"c1","kind":"timedTask","timedTask":{"available":"a1","start":"a2","answer":"a3","submit":"a4","result":"a5"}},"5":{"id":"c2","kind":"timedTask","timedTask":{"available":"a1","start":"a2","answer":"a3","submit":"a4","result":"a5"}},"6":{"id":"c3"},"7":{"id":"c4"},"8":{"id":"c5"},"9":{"id":"c6"},"10":{"id":"c7"},"11":{"id":"c8"},"12":{"id":"c9"},"13":{"id":"c10","kind":"list"},"14":{"id":"c11"},"15":{"id":"c12"},"16":{"id":"c13"}}',
			json_encode($n->resolve(collections: $collections, actions: $actions))
		);
	}//end testEveryCollectionIsAsRecorded()
}//end class
