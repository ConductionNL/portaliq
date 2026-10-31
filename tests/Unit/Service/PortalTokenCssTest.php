<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalTokenCss;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * REQ-PTB-002: a portal's token overrides paint when valid and are dropped,
 * never escaped, when they could do more than set a token.
 *
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-a-portal-must-be-able-to-override-its-themes-tokens-safely-req-ptb-002
 */
#[CoversClass(PortalTokenCss::class)]
class PortalTokenCssTest extends TestCase {

	public function testAValidOverrideIsRenderedAsOneRootBlock(): void {
		$css = (new PortalTokenCss())->css(['--nldesign-header-link-color' => '#f36c21', '--utrecht-link-color' => 'rgb(1, 2, 3)']);

		$this->assertSame(':root{--nldesign-header-link-color:#f36c21;--utrecht-link-color:rgb(1, 2, 3)}', $css);
	}//end testAValidOverrideIsRenderedAsOneRootBlock()

	public function testAValueThatClosesTheRuleIsDropped(): void {
		$css = (new PortalTokenCss())->css([
			'--nldesign-bad' => '#111; } body { display:none } .x {',
			'--nldesign-good' => '#222',
		]);

		$this->assertStringNotContainsString('display', $css);
		$this->assertSame(':root{--nldesign-good:#222}', $css);
	}//end testAValueThatClosesTheRuleIsDropped()

	public function testAnUnknownFamilyIsDropped(): void {
		$this->assertSame('', (new PortalTokenCss())->css(['--evil-background' => 'red', 'color' => 'red', '--nldesign' => 'red']));
	}//end testAnUnknownFamilyIsDropped()

	/**
	 * @dataProvider hostileValues
	 */
	public function testAHostileValueIsDropped(string $value): void {
		$this->assertSame('', (new PortalTokenCss())->css(['--c-token' => $value]));
	}//end testAHostileValueIsDropped()

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function hostileValues(): array {
		return [
			'url' => ['url(https://evil.example/x.png)'],
			'url upper' => ['URL(x)'],
			'expression' => ['expression(alert(1))'],
			'javascript' => ['javascript:alert(1)'],
			'data' => ['data:text/html,x'],
			'import' => ['@import "x"'],
			'backslash' => ['red\\0'],
			'empty' => ['  '],
			'long' => [str_repeat('a', 300)],
		];
	}//end hostileValues()

	public function testNothingButAMapRenders(): void {
		$this->assertSame('', (new PortalTokenCss())->css(null));
		$this->assertSame('', (new PortalTokenCss())->css('--nldesign-x: red'));
		$this->assertSame('', (new PortalTokenCss())->css(['--nldesign-x' => ['red']]));
	}//end testNothingButAMapRenders()
}//end class
