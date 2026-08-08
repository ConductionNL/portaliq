<?php

/**
 * Portaliq appinfo dependency invariants.
 *
 * Holds `appinfo/info.xml`'s declared Nextcloud range against the Nextcloud
 * versions this repository's own CI actually runs. The App Store enforces
 * `min-version` at install time, so the range in info.xml is a PROMISE to
 * users; a promise no job in this repository exercises is an advertised range
 * the app cannot deliver (openconnector#1172/#1173).
 *
 * This file exists because that pair drifted twice in two days and nothing
 * noticed: #56 raised the floor to 32 AND removed the stable31 CI leg, then
 * #58 reverted only the floor — one file, one line — on the stated grounds
 * that "this repo tests stable31", which by then it did not. Neither PR could
 * fail a test, because no test read either value.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit
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

namespace OCA\Portaliq\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Asserts the declared Nextcloud range is covered by the CI matrix.
 */
class AppInfoDependenciesTest extends TestCase
{

    /**
     * Repository root, derived from this file's own location.
     *
     * @return string
     */
    private function repoRoot(): string
    {
        return dirname(__DIR__, 2);

    }//end repoRoot()

    /**
     * The `<nextcloud>` element's min/max attributes from appinfo/info.xml.
     *
     * Parsed with SimpleXML rather than grepped, so a commented-out element
     * cannot be mistaken for a live one — a grep for `min-version` matches the
     * explanatory comment directly above the tag just as happily as the tag.
     *
     * @return array{min:int, max:int}
     */
    private function declaredRange(): array
    {
        $path = $this->repoRoot().'/appinfo/info.xml';
        $this->assertFileExists($path, 'appinfo/info.xml is missing');

        $xml = simplexml_load_file($path);
        if ($xml === false) {
            throw new RuntimeException('appinfo/info.xml is not parseable XML');
        }

        $nodes = $xml->xpath('/info/dependencies/nextcloud');
        $this->assertNotEmpty($nodes, 'appinfo/info.xml declares no <nextcloud> dependency');

        $min = (string) $nodes[0]['min-version'];
        $max = (string) $nodes[0]['max-version'];

        $this->assertNotSame('', $min, '<nextcloud> has no min-version');
        $this->assertNotSame('', $max, '<nextcloud> has no max-version');

        return [
            'min' => (int) $min,
            'max' => (int) $max,
        ];

    }//end declaredRange()

    /**
     * The Nextcloud major versions the quality workflow actually tests, read
     * from `nextcloud-test-refs` in .github/workflows/code-quality.yml.
     *
     * Returns the integers behind `stableNN`. An empty result is treated as a
     * failure by the callers rather than as "no constraint" — a matrix this
     * function could not read must not silently satisfy every assertion.
     *
     * @return int[]
     */
    private function testedNextcloudMajors(): array
    {
        $path = $this->repoRoot().'/.github/workflows/code-quality.yml';
        $this->assertFileExists($path, 'code-quality.yml is missing');

        $lines = file($path, (FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $majors = [];
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            // Only a live key, never a commented-out one: the block above the
            // key is prose explaining why stable31 was removed, and it names
            // "stable31" repeatedly.
            if (str_starts_with($trimmed, '#') === true) {
                continue;
            }

            if (str_starts_with($trimmed, 'nextcloud-test-refs:') === false) {
                continue;
            }

            if (preg_match_all('/stable(\d+)/', $trimmed, $matches) === 0) {
                continue;
            }

            foreach ($matches[1] as $major) {
                $majors[] = (int) $major;
            }
        }

        sort($majors);

        return array_values(array_unique($majors));

    }//end testedNextcloudMajors()

    /**
     * The matrix must be readable and non-empty, otherwise every other
     * assertion in this file would pass vacuously.
     *
     * @return void
     */
    public function testCiMatrixIsReadable(): void
    {
        $majors = $this->testedNextcloudMajors();

        $this->assertNotEmpty(
            $majors,
            'Could not read any stableNN from nextcloud-test-refs in code-quality.yml. '
            .'Every other assertion in this file depends on this list, so an unreadable '
            .'matrix is a failure, not an exemption.'
        );

    }//end testCiMatrixIsReadable()

    /**
     * The declared floor must not be BELOW the oldest Nextcloud CI runs.
     *
     * Declaring a lower floor advertises App Store support for versions no job
     * in this repository ever exercises.
     *
     * @return void
     */
    public function testFloorIsNotBelowTheOldestTestedVersion(): void
    {
        $range  = $this->declaredRange();
        $majors = $this->testedNextcloudMajors();
        $this->assertNotEmpty($majors, 'CI matrix unreadable');

        $oldestTested = min($majors);

        $this->assertGreaterThanOrEqual(
            $oldestTested,
            $range['min'],
            sprintf(
                'appinfo/info.xml declares min-version="%d" but the oldest Nextcloud this repo tests is %d '
                .'(nextcloud-test-refs = %s). NC %d-%d would be advertised in the App Store with no CI '
                .'coverage at all. Either raise min-version to %d or add the missing leg(s) to the matrix.',
                $range['min'],
                $oldestTested,
                implode(', ', $majors),
                $range['min'],
                ($oldestTested - 1),
                $oldestTested
            )
        );

    }//end testFloorIsNotBelowTheOldestTestedVersion()

    /**
     * Every Nextcloud version CI tests must fall inside the declared range.
     *
     * The mirror of the previous assertion: a matrix leg above `max-version`
     * tests a configuration the app tells the App Store it does not support.
     *
     * @return void
     */
    public function testEveryTestedVersionIsInsideTheDeclaredRange(): void
    {
        $range  = $this->declaredRange();
        $majors = $this->testedNextcloudMajors();
        $this->assertNotEmpty($majors, 'CI matrix unreadable');

        foreach ($majors as $major) {
            $this->assertGreaterThanOrEqual(
                $range['min'],
                $major,
                sprintf('CI tests NC %d, below the declared min-version="%d".', $major, $range['min'])
            );
            $this->assertLessThanOrEqual(
                $range['max'],
                $major,
                sprintf('CI tests NC %d, above the declared max-version="%d".', $major, $range['max'])
            );
        }

    }//end testEveryTestedVersionIsInsideTheDeclaredRange()

    /**
     * The PHP floor stays at 8.3, which is what the NC 32 baseline is for.
     *
     * @return void
     */
    public function testPhpFloorIsDeclared(): void
    {
        $path = $this->repoRoot().'/appinfo/info.xml';
        $xml  = simplexml_load_file($path);
        if ($xml === false) {
            throw new RuntimeException('appinfo/info.xml is not parseable XML');
        }

        $nodes = $xml->xpath('/info/dependencies/php');
        $this->assertNotEmpty($nodes, 'appinfo/info.xml declares no <php> dependency');
        $this->assertSame('8.3', (string) $nodes[0]['min-version'], 'PHP floor must stay 8.3');

    }//end testPhpFloorIsDeclared()

}//end class
