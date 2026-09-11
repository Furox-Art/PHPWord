<?php

/**
 * This file is part of PHPWord - A pure PHP library for reading and writing
 * word processing documents.
 *
 * PHPWord is free software distributed under the terms of the GNU Lesser
 * General Public License version 3 as published by the Free Software Foundation.
 *
 * For the full copyright and license information, please read the LICENSE
 * file that was distributed with this source code.
 *
 * @see         https://github.com/PHPOffice/PHPWord
 *
 * @license     http://www.gnu.org/licenses/lgpl.txt LGPL version 3
 */

namespace PhpOffice\PhpWordTests\Writer\WPS;

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Writer\WPS\CompatibilityReport;
use PhpOffice\PhpWord\Writer\WPS\DocumentText;
use PHPUnit\Framework\TestCase;

class DocumentTextTest extends TestCase
{
    /** 10x10 PNG fixture used as a byte-stable local image source. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAE3RFWHRDb21tZW50AHByZXNlcnZlLW1lDBPzAAAAABRJREFUeJxj+M/A8J8YzDCqkL4KAZLDxzmrOvyxAAAAAElFTkSuQmCC';

    /** @var null|string */
    private $pngFile;

    protected function setUp(): void
    {
        $this->pngFile = tempnam(sys_get_temp_dir(), 'phpword-wps-text-');
        self::assertNotFalse($this->pngFile);
        self::assertNotFalse(file_put_contents($this->pngFile, (string) base64_decode(self::PNG, true)));
    }

    protected function tearDown(): void
    {
        if ($this->pngFile !== null) {
            @unlink($this->pngFile);
            $this->pngFile = null;
        }
    }

    /**
     * @return array{result: array, codes: array}
     */
    private function extract(PhpWord $phpWord): array
    {
        $report = new CompatibilityReport();
        $result = (new DocumentText())->extract($phpWord, $report);

        return [
            'result' => $result,
            'codes' => array_column($report->getIssues(), 'code'),
        ];
    }

    public function testTextRunIsRenderedAsASingleParagraph(): void
    {
        $phpWord = new PhpWord();
        $textRun = $phpWord->addSection()->addTextRun();
        $textRun->addText('ab');
        $textRun->addText('cd');

        $result = $this->extract($phpWord);
        self::assertSame([], $result['codes']);
        self::assertSame("abcd\r", $result['result']['text']);
        // Both runs share the same font, so they collapse into one range.
        self::assertCount(1, $result['result']['styles']['fontRuns']);
        self::assertSame([0, 4], [
            $result['result']['styles']['fontRuns'][0]['startUnit'],
            $result['result']['styles']['fontRuns'][0]['endUnit'],
        ]);
    }

    public function testTextBreakInsideTextRunIsKept(): void
    {
        $phpWord = new PhpWord();
        $textRun = $phpWord->addSection()->addTextRun();
        $textRun->addText('ab');
        $textRun->addTextBreak();

        self::assertSame("ab\r\r", $this->extract($phpWord)['result']['text']);
    }

    public function testTextBreakIsAnEmptyParagraph(): void
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $section->addText('before');
        $section->addTextBreak();

        self::assertSame("before\r\r", $this->extract($phpWord)['result']['text']);
    }

    public function testImageInsideTextRunIsRepresentedByAnObjectMarker(): void
    {
        $phpWord = new PhpWord();
        $textRun = $phpWord->addSection()->addTextRun();
        $textRun->addText('ab');
        $textRun->addImage($this->pngFile, ['width' => 20, 'height' => 20]);

        $result = $this->extract($phpWord);
        self::assertSame("ab\u{FFFC}\r", $result['result']['text']);
        self::assertCount(1, $result['result']['images']);
        self::assertSame(1, $result['result']['images'][0]['objectId']);
        self::assertSame(2, $result['result']['images'][0]['textUnitOffset']);
    }

    public function testUnsupportedInlineElementInsideTextRunBecomesAPlaceholder(): void
    {
        $phpWord = new PhpWord();
        $textRun = $phpWord->addSection()->addTextRun();
        $textRun->addText('ab');
        $textRun->addLink('https://example.org', 'link');

        $result = $this->extract($phpWord);
        self::assertContains('unsupported_element', $result['codes']);
        self::assertStringContainsString('unsupported Link at', $result['result']['text']);
    }

    public function testUnsupportedTableBecomesABlockPlaceholder(): void
    {
        $phpWord = new PhpWord();
        // AutoFit geometry is deliberately not approximated.
        $table = $phpWord->addSection()->addTable(['layout' => 'autofit']);
        $table->addRow(432, ['exactHeight' => true])->addCell(1440)->addText('A');

        $result = $this->extract($phpWord);
        self::assertContains('table_autofit_unsupported', $result['codes']);
        self::assertSame([], $result['result']['tables']);
        self::assertStringContainsString('table fidelity failure at section[0].element[0]', $result['result']['text']);
    }

    public function testTableObjectIdsAndStrsIdsAreSequential(): void
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $section->addText('before');

        foreach ([1440, 2880] as $width) {
            $table = $section->addTable(['layout' => 'fixed']);
            $row = $table->addRow(432, ['exactHeight' => true]);
            $row->addCell($width)->addText('cell');
        }

        $result = $this->extract($phpWord);
        self::assertSame([], $result['codes']);
        self::assertCount(2, $result['result']['tables']);
        self::assertSame([1, 2], array_column($result['result']['tables'], 'objectId'));
        self::assertSame([1, 2], array_column($result['result']['tables'], 'tableId'));
        self::assertSame([1, 2], array_column($result['result']['tables'], 'strsId'));
    }

    public function testImageFidelityFailureIsReplaceByAVisibleMarker(): void
    {
        $phpWord = new PhpWord();
        $phpWord->addSection()->addImage($this->pngFile, ['width' => 20, 'height' => 20, 'marginLeft' => 1]);

        $result = $this->extract($phpWord);
        self::assertContains('image_placement_unrepresentable', $result['codes']);
        self::assertSame([], $result['result']['images']);
        self::assertStringContainsString('image fidelity failure at section[0].element[0]', $result['result']['text']);
    }

    public function testTextIsNormalizedBeforeCountingUnits(): void
    {
        $phpWord = new PhpWord();
        $phpWord->addSection()->addText("a\r\nb\nc\0d");

        self::assertSame("a\rb\rcd\r", $this->extract($phpWord)['result']['text']);
    }

    public function testUnsupportedBlockElementBecomesAPlaceholder(): void
    {
        $phpWord = new PhpWord();
        $phpWord->addSection()->addPageBreak();

        $result = $this->extract($phpWord);
        self::assertContains('unsupported_element', $result['codes']);
        self::assertStringContainsString('unsupported PageBreak at', $result['result']['text']);
    }

    public function testEmptyTextProducesNoFontRunButKeepsTheParagraphBreak(): void
    {
        $phpWord = new PhpWord();
        $phpWord->addSection()->addText('');

        $result = $this->extract($phpWord);
        self::assertSame("\r", $result['result']['text']);
        self::assertSame([], $result['result']['styles']['fontRuns']);
        self::assertSame([], $result['result']['styles']['paragraphRuns']);
    }

    public function testEmptyFormattedTextIsDroppedWhenTheRunsAreMerged(): void
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $section->addText('', ['bold' => true]);
        $section->addText('visible');

        $result = $this->extract($phpWord);
        self::assertSame("\rvisible\r", $result['result']['text']);
        // The bold run covers no character, so merging drops it instead of
        // emitting a zero-length Works property range.
        self::assertCount(1, $result['result']['styles']['fontRuns']);
        self::assertSame(1, $result['result']['styles']['fontRuns'][0]['startUnit']);
    }
}
