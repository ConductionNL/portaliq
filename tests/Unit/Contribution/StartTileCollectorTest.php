<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\StartTileCollector;
use PHPUnit\Framework\TestCase;

/**
 * site-nlds-widget-palette T10: the start tiles of the serving portal are
 * the actions that declare a summary, each with its label, summary,
 * audiences and the route of the page that offers it; nothing else.
 *
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-contribution-contract/spec.md#requirement-an-action-may-offer-itself-as-a-start-tile-with-a-summary-and-its-audiences-req-snw-020
 */
class StartTileCollectorTest extends TestCase {
	/**
	 * dossiq's contribution as the normaliser hands it over.
	 *
	 * @return array<string, mixed>
	 */
	private function dossiq(): array {
		return [
			'app'     => 'dossiq',
			'actions' => [
				[
					'id'        => 'createBezwaar',
					'label'     => 'Bezwaar maken',
					'endpoint'  => '/apps/dossiq/api/portal/bezwaar',
					'fields'    => ['decisionRef', 'reden'],
					'summary'   => 'Bent u het niet eens met een besluit? Maak binnen zes weken bezwaar.',
					'audiences' => ['citizen'],
				],
				['id' => 'createKlacht', 'label' => 'Klacht indienen', 'type' => 'create', 'summary' => 'Niet tevreden over hoe u bent behandeld?'],
				['id' => 'withoutSummary', 'label' => 'Iets anders', 'type' => 'create'],
				['id' => 'withoutPage', 'label' => 'Nergens', 'type' => 'create', 'summary' => 'Staat op geen pagina.'],
			],
			'pages'   => [
				['id' => 'overzicht', 'label' => 'Overzicht', 'blocks' => [['type' => 'collection', 'collection' => 'zaken']]],
				['id' => 'bezwaar', 'label' => 'Bezwaar', 'blocks' => [['type' => 'action', 'action' => 'createBezwaar'], ['type' => 'action', 'action' => 'createKlacht']]],
				['id' => 'klacht', 'label' => 'Klacht', 'blocks' => [['type' => 'action', 'action' => 'createKlacht']]],
			],
		];
	}//end dossiq()

	/**
	 * Only actions with a summary on a page become tiles, carrying only
	 * label, summary, audiences and route.
	 *
	 * @return void
	 */
	public function testATileCarriesOnlyLabelSummaryAudiencesAndRoute(): void {
		$tiles = new StartTileCollector();
		$tiles->add(contribution: $this->dossiq());

		$this->assertSame(
			[
				[
					'label'     => 'Bezwaar maken',
					'summary'   => 'Bent u het niet eens met een besluit? Maak binnen zes weken bezwaar.',
					'audiences' => ['citizen'],
					'route'     => '/mijn/dossiq/bezwaar',
				],
				[
					'label'     => 'Klacht indienen',
					'summary'   => 'Niet tevreden over hoe u bent behandeld?',
					'audiences' => [],
					'route'     => '/mijn/dossiq/bezwaar',
				],
			],
			$tiles->all()
		);
		$this->assertStringNotContainsString('/apps/dossiq/api', (string)json_encode($tiles->all()), 'no app endpoint leaves');
		$this->assertStringNotContainsString('decisionRef', (string)json_encode($tiles->all()), 'no field name leaves');
	}//end testATileCarriesOnlyLabelSummaryAudiencesAndRoute()

	/**
	 * The same action read for a second audience is one tile with both
	 * audiences.
	 *
	 * @return void
	 */
	public function testTheSameActionForTwoAudiencesIsOneTile(): void {
		$business = $this->dossiq();
		$business['actions'][0]['audiences'] = ['business'];

		$tiles = new StartTileCollector();
		$tiles->add(contribution: $this->dossiq());
		$tiles->add(contribution: $business);

		$this->assertCount(2, $tiles->all());
		$this->assertSame(['citizen', 'business'], $tiles->all()[0]['audiences']);
	}//end testTheSameActionForTwoAudiencesIsOneTile()
}//end class
