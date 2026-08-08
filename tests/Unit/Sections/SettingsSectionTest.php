<?php

/**
 * Portaliq admin settings-section identity.
 *
 * `SettingsSection::getName()` is the label every administrator sees in
 * Nextcloud's Administration settings sidebar. It returned 'App Template' —
 * the scaffolding name of the template this app was generated from — so
 * Portaliq's own settings section was listed under a different app's name,
 * while the class docblock two lines up said "the Portaliq section".
 *
 * The assertion is against `<name>` in appinfo/info.xml rather than a literal
 * 'Portaliq' repeated here: a test that hardcodes the same string as the code
 * only proves the two strings match each other, and would keep passing if both
 * were changed back to a placeholder together.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Sections
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

namespace OCA\Portaliq\Tests\Unit\Sections;

use OCA\Portaliq\Sections\SettingsSection;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Asserts the admin section identifies itself as this app.
 */
class SettingsSectionTest extends TestCase
{

    /**
     * Build the section with an IL10N that returns the key unchanged, so the
     * assertions read the string the code actually passes to `t()` rather than
     * a translation.
     *
     * @return SettingsSection
     */
    private function section(): SettingsSection
    {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(
            static function (string $text, array $parameters=[]): string {
                return vsprintf($text, $parameters);
            }
        );

        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('imagePath')->willReturn('/apps/portaliq/img/app-dark.svg');

        return new SettingsSection($l10n, $urlGenerator);

    }//end section()

    /**
     * The app's display name, read from appinfo/info.xml.
     *
     * @return string
     */
    private function appDisplayName(): string
    {
        $path = dirname(__DIR__, 3).'/appinfo/info.xml';
        $this->assertFileExists($path, 'appinfo/info.xml is missing');

        $xml = simplexml_load_file($path);
        if ($xml === false) {
            throw new RuntimeException('appinfo/info.xml is not parseable XML');
        }

        $nodes = $xml->xpath('/info/name');
        $this->assertNotEmpty($nodes, 'appinfo/info.xml declares no <name>');

        return trim((string) $nodes[0]);

    }//end appDisplayName()

    /**
     * The section id is the app id — this is what the settings URL is built
     * from, and it was already correct. Asserted so the test below cannot be
     * the only thing standing between a rename and a broken settings route.
     *
     * @return void
     */
    public function testIdIsTheAppId(): void
    {
        $this->assertSame('portaliq', $this->section()->getID());

    }//end testIdIsTheAppId()

    /**
     * The sidebar label must be the app's own display name.
     *
     * @return void
     */
    public function testNameMatchesTheAppDisplayName(): void
    {
        $expected = $this->appDisplayName();
        $this->assertNotSame('', $expected, 'appinfo/info.xml <name> is empty');

        $this->assertSame(
            $expected,
            $this->section()->getName(),
            'The Administration settings sidebar label must be this app\'s own name from appinfo/info.xml. '
            .'It shipped as "App Template" — the name of the scaffolding template — so admins saw '
            .'Portaliq\'s settings listed under another app.'
        );

    }//end testNameMatchesTheAppDisplayName()

    /**
     * No scaffolding placeholder may reach an administrator's screen.
     *
     * Separate from the assertion above on purpose: that one pins the label to
     * one specific value, this one rejects a whole family of leftovers, so a
     * future rename to another placeholder still fails.
     *
     * @return void
     */
    public function testNameIsNotAScaffoldingPlaceholder(): void
    {
        $name = $this->section()->getName();

        foreach (['App Template', 'AppTemplate', 'app_template', 'Nextcloud App Template'] as $placeholder) {
            $this->assertStringNotContainsStringIgnoringCase(
                $placeholder,
                $name,
                sprintf('Admin settings section is labelled with the scaffolding placeholder "%s".', $placeholder)
            );
        }

    }//end testNameIsNotAScaffoldingPlaceholder()

}//end class
