<?php

/**
 * DestinationRules unit tests
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Service\Intake
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

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\DestinationIntake;
use OCA\Portaliq\Service\Intake\DestinationMapping;
use OCA\Portaliq\Service\Intake\DestinationRules;
use OCA\Portaliq\Service\Intake\DestinationSubmit;
use OCA\Portaliq\Service\PortalSchemaReader;
use OCA\Portaliq\Tests\Unit\Service\Intake\Fakes\FormSubmitFake;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Rules that pick a binding's destination from the answers (REQ-FGV-002).
 *
 * @covers \OCA\Portaliq\Service\Intake\DestinationRules
 * @covers \OCA\Portaliq\Service\Intake\DestinationIntake
 *
 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-each-form-names-where-its-submissions-go-req-fgv-002
 */
class DestinationRulesTest extends TestCase {

	/**
	 * A Woo binding: by default to dossiq cases, environment questions to an environment register.
	 *
	 * @param array<int, mixed> $rules The rules.
	 *
	 * @return array<string, mixed> The binding.
	 */
	private static function binding(array $rules): array {
		return [
			'portal' => 'zuid',
			'route' => 'woo',
			'destination' => ['register' => 'dossiq', 'schema' => 'case', 'subjectField' => 'portalSubject'],
			'destinationRules' => $rules,
		];
	}//end binding()

	/**
	 * The environment rule.
	 *
	 * @return array<string, mixed> The rule.
	 */
	private static function environment(): array {
		return ['when' => ['field' => 'onderwerp', 'op' => 'eq', 'value' => 'milieu'], 'destination' => ['register' => 'milieu', 'schema' => 'melding']];
	}//end environment()

	/**
	 * The first rule that holds decides; with no match the form's own destination stays.
	 *
	 * @return void
	 */
	public function testTheFirstRuleThatHoldsDecides(): void {
		$rules   = new DestinationRules();
		$second  = ['when' => ['field' => 'onderwerp', 'op' => 'notEmpty'], 'destination' => ['register' => 'algemeen', 'schema' => 'vraag']];
		$binding = self::binding(rules: [self::environment(), $second]);

		$this->assertSame(expected: self::environment()['destination'], actual: $rules->routed(binding: $binding, answers: ['onderwerp' => 'milieu'])['destination']);
		$this->assertSame(expected: $second['destination'], actual: $rules->routed(binding: $binding, answers: ['onderwerp' => 'verkeer'])['destination']);
		$this->assertSame(expected: $binding['destination'], actual: $rules->routed(binding: $binding, answers: [])['destination']);
		$plain = self::binding(rules: []);
		$this->assertSame(expected: $plain, actual: $rules->routed(binding: $plain, answers: ['onderwerp' => 'milieu']));
	}//end testTheFirstRuleThatHoldsDecides()

	/**
	 * A rule without condition, with a clock condition or without a full destination never routes and is a finding.
	 *
	 * @return void
	 */
	public function testABrokenRuleNeverRoutesAndIsAFinding(): void {
		$rules  = new DestinationRules();
		$broken = [
			['destination' => ['register' => 'x', 'schema' => 'y']],
			['when' => ['field' => 'datum', 'op' => 'gt', 'value' => '@today'], 'destination' => ['register' => 'x', 'schema' => 'y']],
			['when' => ['field' => 'onderwerp', 'op' => 'eq', 'value' => 'milieu'], 'destination' => ['register' => 'x']],
			'not a rule',
			self::environment(),
		];
		$binding = self::binding(rules: $broken);

		$routed = $rules->routed(binding: $binding, answers: ['onderwerp' => 'milieu', 'datum' => '2099-01-01']);
		$this->assertSame(expected: self::environment()['destination'], actual: $routed['destination']);

		$alternatives = $rules->alternatives(binding: $binding);
		$this->assertSame(
			expected: ['destinationRules.0', 'destinationRules.1', 'destinationRules.2', 'destinationRules.3'],
			actual: array_column($alternatives['findings'], 'property')
		);
		$this->assertSame(expected: [DestinationRules::INVALID_RULE], actual: array_values(array_unique(array_column($alternatives['findings'], 'code'))));
		$this->assertCount(expectedCount: 1, haystack: $alternatives['bindings']);
		$this->assertSame(expected: self::environment()['destination'], actual: $alternatives['bindings'][0]['destination']);
		$this->assertSame(expected: ['bindings' => [], 'findings' => []], actual: $rules->alternatives(binding: ['destinationRules' => 'nope']));
	}//end testABrokenRuleNeverRoutesAndIsAFinding()

	/**
	 * The submit goes to the destination a rule picked; the publish check holds every rule's destination too.
	 *
	 * @return void
	 */
	public function testTheSubmitGoesWhereTheRuleSendsItAndEveryDestinationIsChecked(): void {
		$fake    = new FormSubmitFake(outcome: ['reference' => 'M-1', 'id' => 'u-1']);
		$schemas = $this->createMock(originalClassName: PortalSchemaReader::class);
		$schemas->method('readSchema')->willReturn(['properties' => ['onderwerp' => ['type' => 'string']]]);
		$intake = new DestinationIntake(
			submitter: new DestinationSubmit($fake->container(), $this->createMock(originalClassName: LoggerInterface::class)),
			mapping: new DestinationMapping(),
			schemas: $schemas
		);

		$fields = ['fields' => [['name' => 'onderwerp', 'type' => 'text', 'label' => 'Onderwerp']]];
		$result = $intake->submit(binding: self::binding(rules: [self::environment()]), render: $fields, answers: ['onderwerp' => 'milieu']);
		$this->assertTrue(condition: $result['ok']);
		$this->assertSame(expected: ['register' => 'milieu', 'schema' => 'melding'], actual: $fake->calls[0]['destination']);
		$this->assertSame(expected: 'zuid/woo', actual: $fake->calls[0]['scope']);

		$intake->submit(binding: self::binding(rules: [self::environment()]), render: $fields, answers: ['onderwerp' => 'verkeer']);
		$this->assertSame(expected: ['register' => 'dossiq', 'schema' => 'case'], actual: $fake->calls[1]['destination']);

		// Without OpenRegister's validator each destination is unchecked: one finding for the form's own, one for the rule's.
		$broken   = ['when' => ['field' => 'onderwerp', 'op' => 'eq', 'value' => 'x']];
		$findings = $intake->check(binding: self::binding(rules: [self::environment(), $broken]), render: $fields);
		$this->assertSame(
			expected: [DestinationIntake::UNCHECKED, DestinationRules::INVALID_RULE, DestinationIntake::UNCHECKED],
			actual: array_column($findings, 'code')
		);
		$this->assertStringContainsString(needle: 'milieu/melding', haystack: $findings[2]['message']);
	}//end testTheSubmitGoesWhereTheRuleSendsItAndEveryDestinationIsChecked()
}//end class
