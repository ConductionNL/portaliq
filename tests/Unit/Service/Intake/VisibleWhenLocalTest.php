<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\VisibleWhenLocal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * intake-conditional-questions-and-drafts T02 (REQ-ICQ-002): the server's
 * evaluator of a field's local-mode visibleWhen answers every case of the
 * shared fixture exactly as nextcloud-vue's evaluateVisibleWhenLocal does.
 * tests/visible-when-local.spec.mjs runs the same file against the JS
 * predicate, so a case that changes in one evaluator fails in the other.
 *
 * @spec openspec/changes/intake-conditional-questions-and-drafts/specs/portal-intake-form/spec.md#requirement-the-server-skips-a-hidden-field-req-icq-002
 */
class VisibleWhenLocalTest extends TestCase {

	/**
	 * Every case of tests/fixtures/visible-when-local.json, decoded the way a
	 * published form reaches the resolver: JSON objects as PHP arrays.
	 *
	 * @return array<string, array{0: mixed, 1: array<string, mixed>, 2: bool}>
	 */
	public static function fixtureCases(): array {
		$fixture = json_decode(
			(string)file_get_contents(__DIR__.'/../../../fixtures/visible-when-local.json'),
			true,
			512,
			JSON_THROW_ON_ERROR
		);

		$cases = [];
		foreach ($fixture['cases'] as $case) {
			$cases[(string)$case['name']] = [$case['condition'], (array)$case['data'], (bool)$case['visible']];
		}

		return $cases;

	}//end fixtureCases()

	/**
	 * @param mixed $condition The visibleWhen condition.
	 * @param array<string, mixed> $data The answers so far.
	 * @param bool $visible What nextcloud-vue answers.
	 *
	 * @return void
	 */
	#[DataProvider('fixtureCases')]
	public function testTheServerAnswersAsTheScreenDoes(mixed $condition, array $data, bool $visible): void {
		$this->assertSame($visible, (new VisibleWhenLocal())->isVisible(condition: $condition, answers: $data));

	}//end testTheServerAnswersAsTheScreenDoes()

	public function testTheFixtureCarriesCases(): void {
		$this->assertGreaterThan(40, count(self::fixtureCases()));

	}//end testTheFixtureCarriesCases()

	/**
	 * A condition that asks a server (endpoint, source) or the clock cannot be
	 * replayed on submit, so the form that carries it is refused (REQ-ICQ-003).
	 *
	 * @return void
	 */
	public function testOnlyAConditionOnTheAnswersIsDecidable(): void {
		$evaluator = new VisibleWhenLocal();

		$this->assertTrue($evaluator->isDecidable(condition: null));
		$this->assertTrue($evaluator->isDecidable(condition: ['field' => 'together', 'op' => 'eq', 'value' => 'Yes']));
		$this->assertTrue($evaluator->isDecidable(condition: ['all' => [['field' => 'a', 'value' => 1], ['field' => 'b', 'value' => '@object.a']]]));
		$this->assertFalse($evaluator->isDecidable(condition: ['endpoint' => '/x', 'field' => 'a']));
		$this->assertFalse($evaluator->isDecidable(condition: ['source' => ['register' => 'r', 'schema' => 's'], 'field' => 'a']));
		$this->assertFalse($evaluator->isDecidable(condition: ['any' => [['field' => 'a', 'value' => 1], ['endpoint' => '/x']]]));
		$this->assertFalse($evaluator->isDecidable(condition: ['field' => 'birthDate', 'op' => 'lt', 'value' => '@today']));
		$this->assertFalse($evaluator->isDecidable(condition: ['field' => 'deadline', 'op' => 'gte', 'value' => '@today+7d']));
		$this->assertFalse($evaluator->isDecidable(condition: ['field' => 'at', 'op' => 'lt', 'value' => '@now']));

	}//end testOnlyAConditionOnTheAnswersIsDecidable()
}//end class
