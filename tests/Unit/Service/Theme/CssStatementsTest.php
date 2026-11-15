<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Theme;

use OCA\Portaliq\Service\Theme\CssStatements;
use PHPUnit\Framework\TestCase;

/**
 * A declaration block splits on its top-level semicolons only: a semicolon
 * inside a quoted string or inside parentheses stays part of its statement.
 *
 * @covers \OCA\Portaliq\Service\Theme\CssStatements
 */
class CssStatementsTest extends TestCase {

	public function testTopLevelSemicolonsSplitTheStatements(): void {
		$this->assertSame(
			[' --a: #fff', ' --b: 1px', ''],
			(new CssStatements())->statementsOf(body: ' --a: #fff; --b: 1px;')
		);
	}

	public function testASemicolonInsideQuotesOrParenthesesDoesNotSplit(): void {
		$this->assertSame(
			[
				'--font: "Fira; Sans", \'x;y\'',
				' --bg: url(data:image/svg+xml;base64,AA==)',
				' --c: calc((1px + 2px); 3)',
			],
			(new CssStatements())->statementsOf(body: '--font: "Fira; Sans", \'x;y\'; --bg: url(data:image/svg+xml;base64,AA==); --c: calc((1px + 2px); 3)')
		);
	}

	public function testAQuoteOfTheOtherKindInsideAStringDoesNotCloseIt(): void {
		$this->assertSame(
			['--q: "it\'s; fine"', ' --r: 1'],
			(new CssStatements())->statementsOf(body: '--q: "it\'s; fine"; --r: 1')
		);
	}

	public function testAStrayClosingParenthesisNeverMakesTheDepthNegative(): void {
		$this->assertSame(
			['--a: x)', ' --b: y'],
			(new CssStatements())->statementsOf(body: '--a: x); --b: y')
		);
	}

	public function testParenthesesInsideAStringDoNotCount(): void {
		$this->assertSame(
			['--a: "("', ' --b: 2'],
			(new CssStatements())->statementsOf(body: '--a: "("; --b: 2')
		);
	}

	public function testAnEmptyBodyIsOneEmptyStatement(): void {
		$this->assertSame([''], (new CssStatements())->statementsOf(body: ''));
	}
}
