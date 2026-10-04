<?php

/**
 * ActionConfigNormaliser: the presentation hints of an action field
 * (site-multi-step-forms wave 2, REQ-SMF-005).
 *
 * Runs the real normaliser chain (PortalManifestNormaliser) with a schema
 * reader double, so the widget hints meet the real option providers and
 * input hints they depend on.
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

use OCA\Portaliq\Contribution\FieldWidgetNormaliser;
use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use OCA\Portaliq\Service\PortalSchemaReader;
use PHPUnit\Framework\TestCase;

/**
 * The widget hints on an action field config.
 */
class ActionConfigNormaliserTest extends TestCase {
	/**
	 * `choices` and `dateChoices` are kept; any other widget is dropped and
	 * the field falls back to its default input.
	 *
	 * @return void
	 */
	public function testAWidgetHintIsKeptOnlyWhenKnown(): void {
		$configs = $this->absenceConfigs(
			[
				'reasonKind' => ['label' => 'Waarom?', 'widget' => 'choices'],
				'dateFrom'   => ['widget' => 'dateChoices'],
				'dateTo'     => ['widget' => 'slider'],
				'reason'     => ['widget' => ['choices']],
			]
		);

		$this->assertSame('choices', $configs['reasonKind']['widget']);
		$this->assertSame('Waarom?', $configs['reasonKind']['label']);
		$this->assertSame('dateChoices', $configs['dateFrom']['widget']);
		$this->assertSame(2, $configs['dateFrom']['dateChoices'], 'two named days by default');
		$this->assertArrayNotHasKey('widget', $configs['dateTo']);
		$this->assertSame('date', $configs['dateTo']['input'], 'the field keeps its date input');
		$this->assertArrayNotHasKey('widget', $configs['reason']);
	}//end testAWidgetHintIsKeptOnlyWhenKnown()

	/**
	 * `choiceOptions` keeps only values among the field's options, in the
	 * declared order; `otherLabel` travels with it.
	 *
	 * @return void
	 */
	public function testChoiceOptionsAreASubsetOfTheOptions(): void {
		$configs = $this->absenceConfigs(
			[
				'reasonKind' => [
					'widget'        => 'choices',
					'choiceOptions' => ['medical-appointment', 'iban', 'illness', 'illness', 7, ['x']],
					'otherLabel'    => 'Een andere reden',
				],
			]
		);

		$this->assertSame(['medical-appointment', 'illness'], $configs['reasonKind']['choiceOptions']);
		$this->assertSame('Een andere reden', $configs['reasonKind']['otherLabel']);
	}//end testChoiceOptionsAreASubsetOfTheOptions()

	/**
	 * A subset with nothing usable goes, and its `otherLabel` with it; an
	 * `otherLabel` without a subset is never kept.
	 *
	 * @return void
	 */
	public function testAnEmptySubsetTakesItsOtherLabelAlong(): void {
		$none = $this->absenceConfigs(
			['reasonKind' => ['widget' => 'choices', 'choiceOptions' => ['iban'], 'otherLabel' => 'Anders']]
		);
		$this->assertSame('choices', $none['reasonKind']['widget']);
		$this->assertArrayNotHasKey('choiceOptions', $none['reasonKind']);
		$this->assertArrayNotHasKey('otherLabel', $none['reasonKind']);

		$label = $this->absenceConfigs(['reasonKind' => ['widget' => 'choices', 'otherLabel' => 'Anders']]);
		$this->assertArrayNotHasKey('otherLabel', $label['reasonKind']);

		$blank = $this->absenceConfigs(
			['reasonKind' => ['widget' => 'choices', 'choiceOptions' => ['illness'], 'otherLabel' => '  ']]
		);
		$this->assertSame(['illness'], $blank['reasonKind']['choiceOptions']);
		$this->assertArrayNotHasKey('otherLabel', $blank['reasonKind']);
	}//end testAnEmptySubsetTakesItsOtherLabelAlong()

	/**
	 * A widget that does not fit its field is dropped: choice cards on a field
	 * without options, named days on a field that is no date.
	 *
	 * @return void
	 */
	public function testAWidgetThatDoesNotFitItsFieldIsDropped(): void {
		$configs = $this->absenceConfigs(
			[
				'reason'     => ['widget' => 'choices', 'choiceOptions' => ['illness']],
				'reasonKind' => ['widget' => 'dateChoices', 'dateChoices' => 3],
			]
		);

		$this->assertArrayNotHasKey('widget', $configs['reason']);
		$this->assertArrayNotHasKey('choiceOptions', $configs['reason']);
		$this->assertArrayNotHasKey('widget', $configs['reasonKind']);
		$this->assertArrayNotHasKey('dateChoices', $configs['reasonKind']);
	}//end testAWidgetThatDoesNotFitItsFieldIsDropped()

	/**
	 * The named-day count is an integer from 1 to 5; anything else reads 2.
	 *
	 * @return void
	 */
	public function testTheNamedDayCountIsClamped(): void {
		foreach ([[1, 1], [5, 5], [0, 2], [6, 2], ['3', 2], [null, 2]] as [$declared, $kept]) {
			$configs = $this->absenceConfigs(['dateFrom' => ['widget' => 'dateChoices', 'dateChoices' => $declared]]);
			$this->assertSame($kept, $configs['dateFrom']['dateChoices'], var_export($declared, true));
		}
	}//end testTheNamedDayCountIsClamped()

	/**
	 * Choice cards on a collection-backed field are kept; their subset cannot
	 * be checked before the options arrive, so the site filters it.
	 *
	 * @return void
	 */
	public function testACollectionFieldKeepsItsSubsetForTheSiteToFilter(): void {
		$out = (new PortalManifestNormaliser($this->schemaReader()))->normalise(
			[
				'collections' => [],
				'actions'     => [
					[
						'id'               => 'createExcuseRequest',
						'type'             => 'create',
						'schema'           => 'excuse-request',
						'fields'           => ['learnerRef'],
						'optionsProviders' => [
							'learnerRef' => [
								'type'       => 'collection',
								'register'   => 'learniq',
								'schema'     => 'learner-profile',
								'valueField' => 'id',
								'labelField' => 'name',
							],
						],
						'fieldConfigs'     => [
							'learnerRef' => ['widget' => 'choices', 'choiceOptions' => ['vera-1']],
						],
					],
				],
			]
		);

		$config = $out['actions'][0]['fieldConfigs']['learnerRef'];
		$this->assertSame('choices', $config['widget']);
		$this->assertSame(['vera-1'], $config['choiceOptions']);
	}//end testACollectionFieldKeepsItsSubsetForTheSiteToFilter()

	/**
	 * A subset is capped at twenty cards and keeps whole numbers as text.
	 *
	 * @return void
	 */
	public function testASubsetIsCappedAtTwentyCards(): void {
		$entry = (new FieldWidgetNormaliser())->apply(
			entry: [],
			source: ['widget' => 'choices', 'choiceOptions' => range(1, 25)]
		);

		$this->assertCount(20, $entry['choiceOptions']);
		$this->assertSame('1', $entry['choiceOptions'][0]);
		$this->assertSame('20', $entry['choiceOptions'][19]);
	}//end testASubsetIsCappedAtTwentyCards()

	/**
	 * `requiredMessage` is kept as text, like a label; anything else is
	 * dropped, and it never makes a field required (REQ-SMF-006, REQ-SMF-023).
	 *
	 * @return void
	 */
	public function testARequiredMessageIsKeptAsText(): void {
		$configs = $this->absenceConfigs(
			[
				'dateTo'     => ['required' => true, 'requiredMessage' => 'Kies de laatste dag dat Vera afwezig is'],
				'reasonKind' => ['requiredMessage' => ['Kies een reden']],
				'reason'     => ['requiredMessage' => 'Vertel ons waarom'],
			]
		);

		$this->assertSame('Kies de laatste dag dat Vera afwezig is', $configs['dateTo']['requiredMessage']);
		$this->assertTrue($configs['dateTo']['required']);
		$this->assertArrayNotHasKey('requiredMessage', $configs['reasonKind']);
		$this->assertSame('Vertel ons waarom', $configs['reason']['requiredMessage']);
		// `reason` is schema-required, so a create marks it required (REQ-SMF-023);
		// the words themselves never do: see testWordsAloneNeverRequireAField.
		$this->assertTrue($configs['reason']['required']);
	}//end testARequiredMessageIsKeptAsText()

	/**
	 * A step naming a field the action lacks is dropped, and its other
	 * fields go to the last step, before the review (REQ-SMF-020).
	 *
	 * @return void
	 */
	public function testStepsNamingUnknownFieldsAreDropped(): void {
		$action = $this->wooAction(
			[
				'steps' => [
					['id' => 'vraag', 'title' => 'Uw vraag', 'fields' => ['onderwerp', 'omschrijving']],
					['id' => 'betalen', 'title' => 'Betalen', 'fields' => ['periodeVan', 'iban']],
					['id' => 'controle', 'title' => 'Controleren en versturen', 'review' => true],
					['id' => 'tweede', 'title' => 'Dubbel', 'fields' => ['onderwerp']],
					'not a step',
				],
			]
		);

		$this->assertSame(['vraag', 'more', 'controle'], array_column($action['steps'], 'id'));
		$this->assertSame(['periodeVan'], $action['steps'][1]['fields']);
		$this->assertSame('', $action['steps'][1]['title']);
		$this->assertTrue($action['steps'][2]['review']);
	}//end testStepsNamingUnknownFieldsAreDropped()

	/**
	 * A step without an id gets one; a review that names fields, a step
	 * without fields and steps that keep no field leave a one-page form.
	 *
	 * @return void
	 */
	public function testMalformedStepsFallBackToOnePage(): void {
		$named = $this->wooAction(['steps' => [['title' => 'Uw vraag', 'fields' => ['onderwerp']], ['id' => 'more', 'fields' => ['omschrijving']]]]);
		$this->assertSame(['step-1', 'step-2', 'more'], array_column($named['steps'], 'id'));

		$none = $this->wooAction(
			[
				'steps' => [
					['id' => 'controle', 'review' => true, 'fields' => ['onderwerp']],
					['id' => 'leeg', 'fields' => []],
					['id' => 'raar', 'fields' => 'onderwerp'],
					['id' => 'onbekend', 'fields' => ['iban']],
				],
			]
		);
		$this->assertArrayNotHasKey('steps', $none);
	}//end testMalformedStepsFallBackToOnePage()

	/**
	 * `draft.retentionDays` is an integer clamped to 1 to 90; anything else
	 * drops the draft.
	 *
	 * @return void
	 */
	public function testDraftRetentionIsClampedTo1To90(): void {
		foreach ([[30, 30], [0, 1], [400, 90]] as [$declared, $kept]) {
			$action = $this->wooAction(['draft' => ['retentionDays' => $declared]]);
			$this->assertSame(['retentionDays' => $kept], $action['draft']);
		}

		$this->assertArrayNotHasKey('draft', $this->wooAction(['draft' => ['retentionDays' => '30']]));
		$this->assertArrayNotHasKey('draft', $this->wooAction(['draft' => true]));
	}//end testDraftRetentionIsClampedTo1To90()

	/**
	 * A confirmation keeps its title, body and next as text, and nothing else;
	 * without a title it goes.
	 *
	 * @return void
	 */
	public function testConfirmationKeepsOnlyText(): void {
		$action = $this->wooAction(
			[
				'confirmation' => [
					'title' => 'Wij hebben uw verzoek ontvangen',
					'body'  => 'Uw zaaknummer is {identifier}.',
					'next'  => ['<script>'],
					'html'  => '<b>x</b>',
				],
			]
		);
		$this->assertSame(
			['title' => 'Wij hebben uw verzoek ontvangen', 'body' => 'Uw zaaknummer is {identifier}.'],
			$action['confirmation']
		);
		$this->assertArrayNotHasKey('confirmation', $this->wooAction(['confirmation' => ['body' => 'Geen titel']]));
	}//end testConfirmationKeepsOnlyText()

	/**
	 * Steps, draft and confirmation live on a create action and an endpoint
	 * action with fields; an update action loses them.
	 *
	 * @return void
	 */
	public function testOnlyCreateAndEndpointActionsRunInSteps(): void {
		$flow = [
			'steps'        => [['id' => 'een', 'title' => 'Een', 'fields' => ['reason']]],
			'confirmation' => ['title' => 'Ontvangen'],
		];
		$create = $this->absenceAction($flow);
		$this->assertSame(['een', 'more'], array_column($create['steps'], 'id'));
		$this->assertSame(['title' => 'Ontvangen'], $create['confirmation']);

		$update = $this->absenceAction(array_merge($flow, ['type' => 'update']));
		$this->assertArrayNotHasKey('steps', $update);
		$this->assertArrayNotHasKey('confirmation', $update);

		$plain = $this->wooAction(['fields' => [], 'steps' => $flow['steps']]);
		$this->assertArrayNotHasKey('steps', $plain, 'an endpoint action without fields has nothing to step through');
	}//end testOnlyCreateAndEndpointActionsRunInSteps()

	/**
	 * A cta with `withRecord` presets one field to the open record: the
	 * action keeps `recordField` only when it names one of its own fields,
	 * and never a file field (site-mijn-omgeving-components REQ-SMO-024).
	 *
	 * @return void
	 */
	public function testARecordFieldMustBeOneOfTheActionsOwnFields(): void {
		$kept = $this->wooAction(['recordField' => 'onderwerp']);
		$this->assertSame('onderwerp', $kept['recordField']);

		foreach (['collectionId', '', 7, ['onderwerp']] as $declared) {
			$this->assertArrayNotHasKey(
				'recordField',
				$this->wooAction(['recordField' => $declared]),
				var_export($declared, true)
			);
		}

		$file = $this->absenceAction(
			['fields' => ['reason', 'attachmentRef'], 'fieldConfigs' => ['attachmentRef' => ['type' => 'file']], 'recordField' => 'attachmentRef']
		);
		$this->assertArrayNotHasKey('recordField', $file);
	}//end testARecordFieldMustBeOneOfTheActionsOwnFields()

	/**
	 * dossiq's Woo endpoint action, normalised.
	 *
	 * @param array<string, mixed> $overrides Keys to declare.
	 *
	 * @return array<string, mixed> The normalised action.
	 */
	private function wooAction(array $overrides): array {
		$out = (new PortalManifestNormaliser($this->schemaReader()))->normalise(
			[
				'collections' => [],
				'actions'     => [
					array_merge(
						[
							'id'       => 'startWooVerzoek',
							'endpoint' => '/index.php/apps/dossiq/api/portal/woo-verzoek',
							'method'   => 'POST',
							'fields'   => ['onderwerp', 'omschrijving', 'periodeVan'],
						],
						$overrides
					),
				],
			]
		);

		return $out['actions'][0];
	}//end wooAction()

	/**
	 * learniq's absence create action, normalised.
	 *
	 * @param array<string, mixed> $overrides Keys to declare.
	 *
	 * @return array<string, mixed> The normalised action.
	 */
	private function absenceAction(array $overrides): array {
		$out = (new PortalManifestNormaliser($this->schemaReader()))->normalise(
			[
				'collections' => [],
				'actions'     => [
					array_merge(
						[
							'id'     => 'createExcuseRequest',
							'type'   => 'create',
							'schema' => 'excuse-request',
							'fields' => ['dateFrom', 'reason'],
						],
						$overrides
					),
				],
			]
		);

		return $out['actions'][0];
	}//end absenceAction()

	/**
	 * The normalised field configs of learniq's absence action.
	 *
	 * @param array<string, array<string, mixed>> $fieldConfigs The declared field configs.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function absenceConfigs(array $fieldConfigs): array {
		$out = (new PortalManifestNormaliser($this->schemaReader()))->normalise(
			[
				'collections' => [],
				'actions'     => [
					[
						'id'           => 'createExcuseRequest',
						'type'         => 'create',
						'schema'       => 'excuse-request',
						'fields'       => ['dateFrom', 'dateTo', 'reason', 'reasonKind'],
						'fieldConfigs' => $fieldConfigs,
					],
				],
			]
		);

		return $out['actions'][0]['fieldConfigs'];
	}//end absenceConfigs()

	/**
	 * A schema reader that answers learniq's excuse-request schema.
	 *
	 * @return PortalSchemaReader
	 */
	private function schemaReader(): PortalSchemaReader {
		$reader = $this->createMock(PortalSchemaReader::class);
		$reader->method('readSchema')->willReturn(
			[
				'required'   => ['dateFrom', 'dateTo', 'reason', 'reasonKind'],
				'properties' => [
					'dateFrom'   => ['type' => 'string', 'format' => 'date'],
					'dateTo'     => ['type' => 'string', 'format' => 'date'],
					'reason'     => ['type' => 'string'],
					'reasonKind' => [
						'type' => 'string',
						'enum' => ['illness', 'medical-appointment', 'bereavement', 'religious', 'family', 'other'],
					],
					'learnerRef' => ['type' => 'string'],
				],
			]
		);
		return $reader;
	}//end schemaReader()
}//end class
