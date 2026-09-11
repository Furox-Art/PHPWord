<?php

/**
 * This file is part of PHPWord - A pure PHP library for reading and writing
 * word processing documents.
 *
 * PHPWord is free software distributed under the terms of the GNU Lesser
 * General Public License version 3 as published by the Free Software Foundation.
 *
 * For the full copyright and license information, please read the LICENSE
 * file that was distributed with this source code. For the full list of
 * contributors, visit https://github.com/PHPOffice/PHPWord/contributors.
 *
 * @see         https://github.com/PHPOffice/PHPWord
 *
 * @license     http://www.gnu.org/licenses/lgpl.txt LGPL version 3
 */

namespace PhpOffice\PhpWordTests\Writer\WPS;

use PhpOffice\PhpWord\Writer\WPS\Contents;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for the Works CONTENTS encoder. The writer feeds it an already
 * vetted projection, so the guards that protect against an impossible
 * projection are exercised by calling the encoder directly.
 */
class ContentsTest extends TestCase
{
    private function encode(string $text, array $images = [], array $tables = [], array $styles = []): string
    {
        return (new Contents())->encode($text, $images, $tables, $styles);
    }

    private function font(array $override = []): array
    {
        return array_merge([
            'name' => 'Arial',
            'size' => 10,
            'color' => '000000',
            'bold' => false,
            'italic' => false,
            'underline' => 0,
        ], $override);
    }

    private function table(array $override = []): array
    {
        $cell = [
            'index' => 0,
            'row' => 0,
            'column' => 0,
            'text' => 'A',
            'encodedText' => 'A',
            'widthEmu' => 914400,
            'heightEmu' => 274320,
            'topEmu' => 0,
            'leftEmu' => 0,
            'bottomEmu' => 274320,
            'rightEmu' => 914400,
            'endUnitOffset' => 1,
        ];

        return array_merge([
            'supported' => true,
            'objectId' => 2,
            'tableId' => 1,
            'strsId' => 1,
            'textUnitOffset' => 2,
            'rows' => 1,
            'columns' => 1,
            'widthEmu' => 914400,
            'heightEmu' => 274320,
            'zoneText' => 'A',
            'zoneUnits' => 1,
            'cellEndUnitOffsets' => [1],
            'cells' => [$cell],
            'fontRuns' => [],
            'paragraphRuns' => [],
        ], $override);
    }

    private function image(int $objectId, int $textUnitOffset): array
    {
        return [
            'objectId' => $objectId,
            'textUnitOffset' => $textUnitOffset,
            'widthEmu' => 254000,
            'heightEmu' => 254000,
        ];
    }

    public function testPlainTextEncodesToAWorksContainer(): void
    {
        self::assertSame(4096, strlen($this->encode("Hello\r")));
    }

    public function testInlineObjectsAreOrderedByTextPosition(): void
    {
        // The two object records are declared out of order; the encoder has to
        // sort them so EOBJ and FDPC agree on the object positions.
        $forward = $this->encode('abcdefg', [$this->image(1, 1), $this->image(2, 3)]);
        $reverse = $this->encode('abcdefg', [$this->image(2, 3), $this->image(1, 1)]);

        self::assertSame($forward, $reverse);
    }

    public function testInlineObjectOutsideTheMainTextIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Inline object position is outside the main TEXT zone.');

        $this->encode('abc', [$this->image(1, 99)]);
    }

    public function testCharacterStyleRangeOutsideTheTextIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Character style range is outside the Works TEXT zone.');

        $this->encode('abc', [], [], ['fontRuns' => [
            ['startUnit' => 0, 'endUnit' => 99, 'font' => $this->font()],
        ]]);
    }

    public function testParagraphStyleRangeOutsideTheTextIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Paragraph style range is outside the Works TEXT zone.');

        $this->encode('abc', [], [], ['paragraphRuns' => [
            ['startUnit' => 0, 'endUnit' => 99, 'paragraph' => ['alignment' => 2]],
        ]]);
    }

    public function testUnrepresentableFontNameIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FONT table received an unrepresentable font name.');

        $this->encode('abc', [], [], ['fontRuns' => [
            ['startUnit' => 0, 'endUnit' => 3, 'font' => $this->font(['name' => ''])],
        ]]);
    }

    public function testInvalidFontColourIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid RGB font color.');

        $this->encode('abc', [], [], ['fontRuns' => [
            ['startUnit' => 0, 'endUnit' => 3, 'font' => $this->font(['color' => 'zzz'])],
        ]]);
    }

    public function testTcdNeedsAtLeastOneCellBoundary(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A TCD table must contain at least one cell boundary.');

        $this->encode('abc', [], [$this->table(['cellEndUnitOffsets' => []])]);
    }

    public function testTcdBoundariesMustBeStrictlyIncreasing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TCD cell boundaries must be strictly increasing.');

        $this->encode('abc', [], [$this->table(['cellEndUnitOffsets' => [2, 1]])]);
    }

    public function testStyleRunWithoutExtentIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid Works style run.');

        $this->encode('abc', [], [], ['fontRuns' => [
            ['startUnit' => 1, 'endUnit' => 1, 'font' => $this->font()],
        ]]);
    }

    public function testOverlappingStyleRunsAreRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Overlapping Works style runs are outside the deterministic subset.');

        $this->encode('abcdef', [], [], ['fontRuns' => [
            ['startUnit' => 0, 'endUnit' => 3, 'font' => $this->font()],
            ['startUnit' => 1, 'endUnit' => 4, 'font' => $this->font()],
        ]]);
    }

    public function testAdjacentRunsWithTheSameStyleAreMerged(): void
    {
        $font = $this->font();
        $merged = $this->encode('abcdef', [], [], ['fontRuns' => [
            ['startUnit' => 0, 'endUnit' => 6, 'font' => $font],
        ]]);
        $split = $this->encode('abcdef', [], [], ['fontRuns' => [
            ['startUnit' => 0, 'endUnit' => 2, 'font' => $font],
            ['startUnit' => 2, 'endUnit' => 6, 'font' => $font],
        ]]);

        self::assertSame($merged, $split);
    }
}
