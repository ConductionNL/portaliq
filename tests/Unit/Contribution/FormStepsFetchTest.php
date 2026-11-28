<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\FormStepFetch;
use OCA\Portaliq\Contribution\FormStepsNormaliser;
use OCA\Portaliq\Contribution\FormStepsRun;
use OCA\Portaliq\Contribution\FormText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * data-lookups-and-checks-in-forms REQ-DIF-005: a step keeps each fetch that
 * is whole and names only fields the form has; a malformed one is dropped on
 * its own and the step stays.
 *
 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t05
 */
#[CoversClass(FormStepFetch::class)]
#[UsesClass(FormStepsNormaliser::class)]
#[UsesClass(FormStepsRun::class)]
#[UsesClass(FormText::class)]
#[UsesClass(\OCA\Portaliq\Contribution\FormStepDecision::class)]
class FormStepsFetchTest extends TestCase {

	private const KNOWN = ['postcode', 'huisnummer', 'eigenaar', 'naam'];

	private function fetch(array $over=[]): array {
		return $over + [
			'id' => 'eigenaar',
			'source' => 'kadaster-eigendom',
			'inputs' => ['postcode' => 'postcode', 'huisnummer' => 'huisnummer'],
			'outputs' => ['eigenaarNaam' => 'eigenaar'],
			'check' => ['label' => 'Wij controleren of u eigenaar bent', 'output' => 'isEigenaar', 'lines' => ['true' => 'Volgens het Kadaster staat dit pand op uw naam.']],
		];
	}

	private function steps(mixed $fetch): array {
		return (new FormStepsNormaliser())->steps(
			steps: [
				['id' => 'adres', 'title' => 'Adres', 'fields' => ['postcode', 'huisnummer']],
				['id' => 'pand', 'title' => 'Pand', 'fields' => ['eigenaar', 'naam'], 'fetch' => $fetch],
			],
			known: self::KNOWN
		);
	}

	public function testAWholeFetchIsKeptAsDeclared(): void {
		$steps = $this->steps([$this->fetch()]);

		$this->assertSame([$this->fetch()], $steps[1]['fetch']);
		$this->assertArrayNotHasKey('fetch', $steps[0]);
	}

	public function testAMalformedFetchIsDroppedAndTheStepStays(): void {
		foreach ([
			['id' => 'has space'],
			['source' => 7],
			['inputs' => ['postcode' => 'nietBestaand']],
			['outputs' => ['x' => 'nietBestaand']],
			['inputs' => 'postcode'],
			['outputs' => [], 'check' => null],
			['outputs' => [], 'check' => ['output' => 'isEigenaar', 'lines' => []]],
		] as $bad) {
			$steps = $this->steps([$this->fetch($bad)]);
			$this->assertArrayNotHasKey('fetch', $steps[1], json_encode($bad));
			$this->assertSame('pand', $steps[1]['id'], 'the step itself stays');
		}
	}

	public function testOnlyTheBadFetchGoesAndADuplicateIdIsDropped(): void {
		$second = $this->fetch(['id' => 'naam', 'source' => 'brp-naam', 'outputs' => ['naam' => 'naam'], 'check' => null]);
		$steps  = $this->steps([$this->fetch(['source' => 'x y']), $second, $this->fetch(['id' => 'naam'])]);

		$this->assertCount(1, $steps[1]['fetch']);
		$this->assertSame('brp-naam', $steps[1]['fetch'][0]['source']);
		$this->assertArrayNotHasKey('check', $steps[1]['fetch'][0], 'a fetch without a check line has none');
	}

	public function testACheckKeepsOnlyItsTextLines(): void {
		$fetch = (new FormStepFetch())->normalise(
			fetches: [$this->fetch(['check' => ['label' => 5, 'output' => 'isEigenaar', 'lines' => ['true' => 'Ja.', 'false' => ['nee'], 'x' => '']]])],
			known: self::KNOWN
		);

		$this->assertSame(['label' => '', 'output' => 'isEigenaar', 'lines' => ['true' => 'Ja.']], $fetch[0]['check']);
	}

	public function testANonListIsNoFetch(): void {
		$this->assertSame([], (new FormStepFetch())->normalise(fetches: ['eigenaar' => $this->fetch()], known: self::KNOWN));
		$this->assertSame([], (new FormStepFetch())->normalise(fetches: 'eigenaar', known: self::KNOWN));
	}
}
