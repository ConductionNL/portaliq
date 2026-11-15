<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Theme;

use OCA\Portaliq\Service\Theme\CustomSetJudge;
use PHPUnit\Framework\TestCase;

/**
 * A custom token set is judged by the theme app's own validator: the
 * tokens of its one :root block are handed over by name, and a refusal
 * comes back as a reason.
 *
 * @covers \OCA\Portaliq\Service\Theme\CustomSetJudge
 * @uses   \OCA\Portaliq\Service\Theme\CssStatements
 */
class CustomSetJudgeTest extends TestCase {

	/**
	 * A validator double with the theme app's method names.
	 *
	 * @param ?string $passes   What validateDeclarations() answers.
	 * @param bool    $disallow What hasDisallowedSelector() answers.
	 * @param mixed   $last     What getLastError() answers.
	 *
	 * @return object
	 */
	private static function validator(?string $passes, bool $disallow = false, mixed $last = null): object {
		return new class ($passes, $disallow, $last) {
			/**
			 * @var array<int, array{0: array<string, string>, 1: string}>
			 */
			public array $validated = [];

			public function __construct(private ?string $passes, private bool $disallow, private mixed $last) {
			}

			public function hasDisallowedSelector(string $css): bool {
				return $this->disallow;
			}

			public function validateDeclarations(array $declarations, string $name): ?string {
				$this->validated[] = [$declarations, $name];
				return $this->passes;
			}

			public function getLastError(): mixed {
				return $this->last;
			}
		};
	}//end validator()

	public function testTheTokensOfTheBlockAreValidatedUnderTheSetsOwnName(): void {
		$validator = self::validator(passes: ':root{}');
		$css = "/* gemeente; huisstijl */\n:root {\n  --nldesign-color-text: #1a1a1a;\n  color: red;\n  --font: \"Fira; Sans\";\n  no-colon-here;\n}\n";

		$this->assertNull((new CustomSetJudge($validator))->verdict('custom-gemeente', $css));
		$this->assertSame(
			[[['--nldesign-color-text' => '#1a1a1a', '--font' => '"Fira; Sans"'], 'gemeente']],
			$validator->validated
		);
	}

	public function testABodyWithoutBracesIsReadAsTheBlockItself(): void {
		$validator = self::validator(passes: 'ok');

		$this->assertNull((new CustomSetJudge($validator))->verdict('custom-x', '--a: 1; --b: 2'));
		$this->assertSame(['--a' => '1', '--b' => '2'], $validator->validated[0][0]);
	}

	public function testASecondRootBlockIsRefusedBeforeValidation(): void {
		$validator = self::validator(passes: 'ok', disallow: true);

		$this->assertSame('the file holds more than one :root block of tokens', (new CustomSetJudge($validator))->verdict('custom-x', ':root{--a:1}'));
		$this->assertSame([], $validator->validated);
	}

	public function testAFileWithoutTokensIsRefused(): void {
		$validator = self::validator(passes: 'ok');

		$this->assertSame('the file declares no tokens', (new CustomSetJudge($validator))->verdict('custom-x', ':root { color: red; }'));
		$this->assertSame([], $validator->validated);
	}

	public function testARefusalCarriesTheValidatorsMessageOrAGenericOne(): void {
		$css = ':root { --a: nope; }';

		$this->assertSame('--a is not a colour', (new CustomSetJudge(self::validator(null, last: ['message' => '--a is not a colour'])))->verdict('custom-x', $css));
		$this->assertSame('a declaration was refused', (new CustomSetJudge(self::validator(null, last: ['message' => 42])))->verdict('custom-x', $css));
		$this->assertSame('a declaration was refused', (new CustomSetJudge(self::validator(null, last: 'text')))->verdict('custom-x', $css));
	}

	public function testAValidatorWithoutTheOptionalMethodsStillJudges(): void {
		$minimal = new class {
			public function validateDeclarations(array $declarations, string $name): ?string {
				return null;
			}
		};

		$this->assertSame('a declaration was refused', (new CustomSetJudge($minimal))->verdict('custom-x', ':root { --a: 1; } :root { --b: 2; }'));
	}
}
