<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalManifestNormaliser;
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
}//end class
