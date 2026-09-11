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

use PhpOffice\PhpWord\ComplexType\TblWidth as TblWidthComplexType;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Style\Shading;
use PhpOffice\PhpWord\Style\Table as TableStyle;
use PhpOffice\PhpWord\Style\TablePosition;
use PhpOffice\PhpWord\Writer\WPS\CompatibilityReport;
use PhpOffice\PhpWord\Writer\WPS\TablePolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TablePolicyTest extends TestCase
{
    /** 1x1 PNG fixture reused by the "unsupported element" cases. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAE3RFWHRDb21tZW50AHByZXNlcnZlLW1lDBPzAAAAABRJREFUeJxj+M/A8J8YzDCqkL4KAZLDxzmrOvyxAAAAAElFTkSuQmCC';

    /** @var null|string */
    private $pngFile;

    protected function setUp(): void
    {
        $this->pngFile = tempnam(sys_get_temp_dir(), 'phpword-wps-policy-');
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
     * Run the policy and return the facts together with the emitted issue codes.
     *
     * @return array{facts: array, codes: array, report: CompatibilityReport}
     */
    private function inspect(Table $table, string $path = 'section.table[0]'): array
    {
        $report = new CompatibilityReport();
        $facts = (new TablePolicy())->inspect($table, $path, $report);

        return [
            'facts' => $facts,
            'codes' => array_column($report->getIssues(), 'code'),
            'report' => $report,
        ];
    }

    /**
     * Element\Table::getStyle() is documented as Table|string; every fixture here
     * always builds the style object form.
     */
    private function tableStyle(Table $table): TableStyle
    {
        $style = $table->getStyle();
        if (!$style instanceof TableStyle) {
            throw new RuntimeException('The fixture table is expected to use a style object.');
        }

        return $style;
    }

    private function supportedTable(int $width = 1440, int $height = 432): Table
    {
        $table = new Table(['layout' => TableStyle::LAYOUT_FIXED]);
        $row = $table->addRow($height, ['exactHeight' => true]);
        $row->addCell($width)->addText('A');
        $row->addCell($width)->addText('B');

        return $table;
    }

    private function assertCode(array $result, string $code): void
    {
        self::assertContains($code, $result['codes'], implode(', ', $result['codes']) ?: 'no issue emitted');
        self::assertFalse($result['facts']['supported']);
    }

    public function testTableWithoutRowsIsRejected(): void
    {
        $this->assertCode($this->inspect(new Table()), 'table_empty');
    }

    public function testRowWithoutCellsIsRejected(): void
    {
        $table = new Table(['layout' => TableStyle::LAYOUT_FIXED]);
        $table->addRow(432, ['exactHeight' => true]);

        $this->assertCode($this->inspect($table), 'table_empty_row');
    }

    public function testMoreThanHundredCellsIsRejected(): void
    {
        $table = new Table(['layout' => TableStyle::LAYOUT_FIXED]);
        for ($row = 0; $row < 11; ++$row) {
            $rowElement = $table->addRow(432, ['exactHeight' => true]);
            for ($column = 0; $column < 10; ++$column) {
                $rowElement->addCell(1440);
            }
        }

        $this->assertCode($this->inspect($table), 'table_cell_limit');
    }

    public function testNamedTableStyleIsRejected(): void
    {
        $table = new Table('NamedTableStyle');
        $row = $table->addRow(432, ['exactHeight' => true]);
        $row->addCell(1440)->addText('A');

        $this->assertCode($this->inspect($table), 'table_named_style_unresolved');
    }

    public function testTableWithoutStyleIsInspectedFurther(): void
    {
        // A null table style short-circuits the style inspection, the geometry
        // checks still run and the table stays supported.
        $table = new Table();
        $row = $table->addRow(432, ['exactHeight' => true]);
        $row->addCell(1440)->addText('A');

        $result = $this->inspect($table);
        self::assertSame([], $result['codes']);
        self::assertTrue($result['facts']['supported']);
        self::assertSame(1, $result['facts']['rows']);
    }

    public function testAutoFitLayoutIsRejected(): void
    {
        $table = $this->supportedTable();
        $this->tableStyle($table)->setLayout(TableStyle::LAYOUT_AUTO);

        $this->assertCode($this->inspect($table), 'table_autofit_unsupported');
    }

    public function testTableAlignmentIsRejected(): void
    {
        $table = $this->supportedTable();
        $this->tableStyle($table)->setAlignment('center');

        $this->assertCode($this->inspect($table), 'table_alignment_unsupported');
    }

    public function testEmptyTableAlignmentIsAccepted(): void
    {
        $table = $this->supportedTable();
        $this->tableStyle($table)->setAlignment('');

        self::assertSame([], $this->inspect($table)['codes']);
    }

    public function testCellSpacingIsRejected(): void
    {
        $table = $this->supportedTable();
        $this->tableStyle($table)->setCellSpacing(10);

        $this->assertCode($this->inspect($table), 'table_cell_spacing_unsupported');
    }

    public function testFloatingTablePositionIsRejected(): void
    {
        $table = $this->supportedTable();
        $this->tableStyle($table)->setPosition(new TablePosition());

        $this->assertCode($this->inspect($table), 'table_position_unsupported');
    }

    public function testTableIndentIsRejected(): void
    {
        $table = $this->supportedTable();
        $this->tableStyle($table)->setIndent(new TblWidthComplexType(720));

        $this->assertCode($this->inspect($table), 'table_indent_unsupported');
    }

    public function testTableShadingIsRejected(): void
    {
        $table = $this->supportedTable();
        $this->tableStyle($table)->setShading(new Shading());

        $this->assertCode($this->inspect($table), 'table_shading_unsupported');
    }

    public function testTableBorderIsRejected(): void
    {
        $table = $this->supportedTable();
        $this->tableStyle($table)->setBorderSize(8);

        $this->assertCode($this->inspect($table), 'table_border_unsupported');
    }

    public function testZeroTableBorderIsAccepted(): void
    {
        $table = $this->supportedTable();
        $this->tableStyle($table)->setBorderSize(0);

        self::assertSame([], $this->inspect($table)['codes']);
    }

    public function testCellMarginIsRejected(): void
    {
        $table = $this->supportedTable();
        $this->tableStyle($table)->setCellMarginTop(10);

        $this->assertCode($this->inspect($table), 'table_cell_margin_unsupported');
    }

    public function testNonRectangularTableIsRejected(): void
    {
        $table = new Table(['layout' => TableStyle::LAYOUT_FIXED]);
        $row = $table->addRow(432, ['exactHeight' => true]);
        $row->addCell(1440);
        $row->addCell(1440);
        $row = $table->addRow(432, ['exactHeight' => true]);
        $row->addCell(1440);

        $result = $this->inspect($table);
        self::assertContains('table_non_rectangular', $result['codes']);
        self::assertStringContainsString('.row[1]', $result['report']->getIssues()[0]['path']);
    }

    public function testAutomaticRowHeightIsRejected(): void
    {
        $table = new Table(['layout' => TableStyle::LAYOUT_FIXED]);
        $row = $table->addRow(432);
        $row->addCell(1440)->addText('A');

        $this->assertCode($this->inspect($table), 'table_row_height_not_exact');
    }

    public function testNonPositiveRowHeightIsRejected(): void
    {
        $table = new Table(['layout' => TableStyle::LAYOUT_FIXED]);
        $row = $table->addRow(0, ['exactHeight' => true]);
        $row->addCell(1440)->addText('A');

        $this->assertCode($this->inspect($table), 'table_row_height_not_exact');
    }

    public function testRepeatingHeaderRowIsRejected(): void
    {
        $table = new Table(['layout' => TableStyle::LAYOUT_FIXED]);
        $row = $table->addRow(432, ['exactHeight' => true, 'tblHeader' => true]);
        $row->addCell(1440)->addText('A');

        $this->assertCode($this->inspect($table), 'table_repeating_header_unsupported');
    }

    public function testCantSplitRowIsRejected(): void
    {
        $table = new Table(['layout' => TableStyle::LAYOUT_FIXED]);
        $row = $table->addRow(432, ['exactHeight' => true, 'cantSplit' => true]);
        $row->addCell(1440)->addText('A');

        $this->assertCode($this->inspect($table), 'table_cant_split_unsupported');
    }

    public function testMissingCellWidthIsRejected(): void
    {
        $table = new Table(['layout' => TableStyle::LAYOUT_FIXED]);
        $row = $table->addRow(432, ['exactHeight' => true]);
        $row->addCell();

        $this->assertCode($this->inspect($table), 'table_cell_width_missing');
    }

    public function testInconsistentColumnWidthIsRejected(): void
    {
        $table = new Table(['layout' => TableStyle::LAYOUT_FIXED]);
        $row = $table->addRow(432, ['exactHeight' => true]);
        $row->addCell(1440);
        $row->addCell(1440);
        $row = $table->addRow(432, ['exactHeight' => true]);
        $row->addCell(2880);
        $row->addCell(2880);

        $this->assertCode($this->inspect($table), 'table_column_width_inconsistent');
    }

    public function testTotalWidthBeyondThirtyTwoBitRangeIsRejected(): void
    {
        // Every single column is representable (400000 twips = 254000000 EMU),
        // but the sum of ten of them exceeds the 32-bit EMU field.
        $table = new Table(['layout' => TableStyle::LAYOUT_FIXED]);
        $row = $table->addRow(432, ['exactHeight' => true]);
        for ($column = 0; $column < 10; ++$column) {
            $row->addCell(400000);
        }

        $this->assertCode($this->inspect($table), 'table_dimensions_out_of_range');
    }

    public function testFractionalCellWidthIsRejected(): void
    {
        $table = new Table(['layout' => TableStyle::LAYOUT_FIXED]);
        $row = $table->addRow(432, ['exactHeight' => true]);
        // Element widths are documented as twips; a caller can still hand over a
        // fractional value, which the policy must reject instead of silently rounding.
        // @phpstan-ignore-next-line argument.type
        $row->addCell(1440.5);

        $this->assertCode($this->inspect($table), 'table_dimension_not_exactly_representable');
    }

    public function testRowHeightBeyondThirtyTwoBitRangeIsRejected(): void
    {
        $table = new Table(['layout' => TableStyle::LAYOUT_FIXED]);
        $row = $table->addRow(4000000, ['exactHeight' => true]);
        $row->addCell(1440);

        $this->assertCode($this->inspect($table), 'table_dimension_not_exactly_representable');
    }

    public function testColumnSpanIsRejected(): void
    {
        $table = $this->supportedTable();
        $table->getRows()[0]->getCells()[0]->getStyle()->setGridSpan(2);

        $this->assertCode($this->inspect($table), 'table_colspan_unsupported');
    }

    public function testRowSpanIsRejected(): void
    {
        $table = $this->supportedTable();
        $table->getRows()[0]->getCells()[0]->getStyle()->setVMerge('restart');

        $this->assertCode($this->inspect($table), 'table_rowspan_unsupported');
    }

    public function testCellTextDirectionIsRejected(): void
    {
        $table = $this->supportedTable();
        $table->getRows()[0]->getCells()[0]->getStyle()->setTextDirection('btLr');

        $this->assertCode($this->inspect($table), 'table_text_direction_unsupported');
    }

    public function testNonTopVerticalAlignmentIsRejected(): void
    {
        $table = $this->supportedTable();
        $table->getRows()[0]->getCells()[0]->getStyle()->setVAlign('center');

        $this->assertCode($this->inspect($table), 'table_vertical_alignment_unsupported');
    }

    public function testTopVerticalAlignmentIsAccepted(): void
    {
        $table = $this->supportedTable();
        $table->getRows()[0]->getCells()[0]->getStyle()->setVAlign('top');

        self::assertSame([], $this->inspect($table)['codes']);
    }

    public function testCellShadingIsRejected(): void
    {
        $table = $this->supportedTable();
        $table->getRows()[0]->getCells()[0]->getStyle()->setShading(new Shading());

        $this->assertCode($this->inspect($table), 'table_cell_shading_unsupported');
    }

    public function testCellPaddingIsRejected(): void
    {
        $table = $this->supportedTable();
        $table->getRows()[0]->getCells()[0]->getStyle()->setPaddingTop(4);

        $this->assertCode($this->inspect($table), 'table_cell_padding_unsupported');
    }

    public function testEmptyCellIsEncodedWithTheFillerByte(): void
    {
        $result = $this->inspect($this->supportedTable());
        $cells = $result['facts']['cells'];

        self::assertSame("A\rB", $result['facts']['zoneText']);
        self::assertSame(3, $result['facts']['zoneUnits']);
        self::assertSame([1, 3], $result['facts']['cellEndUnitOffsets']);
        // Two 1440-twip columns: 1440 * 635 EMU per twip each.
        self::assertSame(1828800, $result['facts']['widthEmu']);
        self::assertSame(274320, $result['facts']['heightEmu']);
        self::assertSame([914400, 914400], $result['facts']['columnWidthsEmu']);
        self::assertSame([274320], $result['facts']['rowHeightsEmu']);

        self::assertSame(0, $cells[0]['leftEmu']);
        self::assertSame(914400, $cells[1]['leftEmu']);
        self::assertSame(0, $cells[0]['topEmu']);
        self::assertSame(274320, $cells[0]['bottomEmu']);
        self::assertSame(914400, $cells[0]['rightEmu']);

        // A cell without text is still one UTF-16 unit long, filled with \x01.
        $table = new Table(['layout' => TableStyle::LAYOUT_FIXED]);
        $row = $table->addRow(432, ['exactHeight' => true]);
        $row->addCell(1440);

        $result = $this->inspect($table);
        self::assertSame("\x01", $result['facts']['zoneText']);
        self::assertSame(1, $result['facts']['zoneUnits']);
        self::assertSame('', $result['facts']['cells'][0]['text']);
        self::assertSame("\x01", $result['facts']['cells'][0]['encodedText']);
    }

    public function testCellTextIsNormalizedAndCountedInUtf16Units(): void
    {
        $table = new Table(['layout' => TableStyle::LAYOUT_FIXED]);
        $row = $table->addRow(432, ['exactHeight' => true]);
        $row->addCell(1440)->addText("a\r\nb\nc\0d\u{1F600}");

        $result = $this->inspect($table);
        // CR-LF and LF collapse to CR, NUL is dropped, the emoji costs two units.
        self::assertSame("a\rb\rcd\u{1F600}", $result['facts']['zoneText']);
        self::assertSame(8, $result['facts']['zoneUnits']);
        self::assertSame(8, $result['facts']['cells'][0]['endUnitOffset']);
    }

    public function testMultipleCellParagraphsAreSeparatedByCarriageReturn(): void
    {
        $table = new Table(['layout' => TableStyle::LAYOUT_FIXED]);
        $row = $table->addRow(432, ['exactHeight' => true]);
        $cell = $row->addCell(1440);
        $cell->addText('one');
        $cell->addText('two');

        $result = $this->inspect($table);
        self::assertSame("one\rtwo", $result['facts']['zoneText']);
        self::assertSame(7, $result['facts']['zoneUnits']);
    }

    public function testTextBreakInsideCellIsAnEmptyParagraph(): void
    {
        $table = new Table(['layout' => TableStyle::LAYOUT_FIXED]);
        $row = $table->addRow(432, ['exactHeight' => true]);
        $cell = $row->addCell(1440);
        $cell->addText('one');
        $cell->addTextBreak();

        $result = $this->inspect($table);
        self::assertSame("one\r", $result['facts']['zoneText']);
    }

    public function testUnsupportedCellElementBecomesAVisiblePlaceholder(): void
    {
        $table = new Table(['layout' => TableStyle::LAYOUT_FIXED]);
        $row = $table->addRow(432, ['exactHeight' => true]);
        $row->addCell(1440)->addImage($this->pngFile);

        $result = $this->inspect($table);
        self::assertContains('table_cell_element_unsupported', $result['codes']);
        // The placeholder is reported but the table itself stays representable.
        self::assertTrue($result['facts']['supported']);
        self::assertStringContainsString('unsupported Image at', $result['facts']['zoneText']);
    }

    public function testUnsupportedInlineElementInsideTextRunBecomesAPlaceholder(): void
    {
        $table = new Table(['layout' => TableStyle::LAYOUT_FIXED]);
        $row = $table->addRow(432, ['exactHeight' => true]);
        $textRun = $row->addCell(1440)->addTextRun();
        $textRun->addText('before');
        $textRun->addImage($this->pngFile);

        $result = $this->inspect($table);
        self::assertContains('table_cell_inline_element_unsupported', $result['codes']);
        self::assertStringContainsString('before', $result['facts']['zoneText']);
        self::assertStringContainsString('unsupported Image at', $result['facts']['zoneText']);
    }

    public function testTextRunWithBreakIsKept(): void
    {
        $table = new Table(['layout' => TableStyle::LAYOUT_FIXED]);
        $row = $table->addRow(432, ['exactHeight' => true]);
        $textRun = $row->addCell(1440)->addTextRun();
        $textRun->addText('ab');
        $textRun->addTextBreak();

        self::assertSame("ab\r", $this->inspect($table)['facts']['zoneText']);
    }

    public function testAdjacentIdenticalFontRunsAreMerged(): void
    {
        $table = new Table(['layout' => TableStyle::LAYOUT_FIXED]);
        $row = $table->addRow(432, ['exactHeight' => true]);
        $textRun = $row->addCell(1440)->addTextRun();
        $textRun->addText('ab');
        $textRun->addText('cd');

        $result = $this->inspect($table);
        self::assertCount(1, $result['facts']['fontRuns']);
        self::assertSame(0, $result['facts']['fontRuns'][0]['startUnit']);
        self::assertSame(4, $result['facts']['fontRuns'][0]['endUnit']);
    }

    public function testAdjacentDifferentFontRunsAreNotMerged(): void
    {
        $table = new Table(['layout' => TableStyle::LAYOUT_FIXED]);
        $row = $table->addRow(432, ['exactHeight' => true]);
        $textRun = $row->addCell(1440)->addTextRun();
        $textRun->addText('ab', ['bold' => true]);
        $textRun->addText('cd');

        self::assertCount(2, $this->inspect($table)['facts']['fontRuns']);
    }

    public function testNonDefaultParagraphAlignmentInsideCellIsRecorded(): void
    {
        $table = new Table(['layout' => TableStyle::LAYOUT_FIXED]);
        $row = $table->addRow(432, ['exactHeight' => true]);
        $row->addCell(1440)->addText('centered', null, ['alignment' => 'center']);

        $result = $this->inspect($table);
        self::assertCount(1, $result['facts']['paragraphRuns']);
        self::assertSame(0, $result['facts']['paragraphRuns'][0]['startUnit']);
        self::assertSame(8, $result['facts']['paragraphRuns'][0]['endUnit']);
        self::assertSame(2, $result['facts']['paragraphRuns'][0]['paragraph']['alignment']);
    }

    public function testEmptyParagraphProducesNoParagraphRun(): void
    {
        $table = new Table(['layout' => TableStyle::LAYOUT_FIXED]);
        $row = $table->addRow(432, ['exactHeight' => true]);
        $row->addCell(1440)->addText('', null, ['alignment' => 'center']);

        self::assertSame([], $this->inspect($table)['facts']['paragraphRuns']);
    }

    public function testCellRunsAreOffsetByTheZoneStartUnit(): void
    {
        $table = new Table(['layout' => TableStyle::LAYOUT_FIXED]);
        $row = $table->addRow(432, ['exactHeight' => true]);
        $row->addCell(1440)->addText('AA');
        $row->addCell(1440)->addText('BB', ['bold' => true]);

        $result = $this->inspect($table);
        $runs = $result['facts']['fontRuns'];
        self::assertCount(2, $runs);
        self::assertSame([0, 2], [$runs[0]['startUnit'], $runs[0]['endUnit']]);
        self::assertSame([3, 5], [$runs[1]['startUnit'], $runs[1]['endUnit']]);
    }

    public function testSeparateFirstRowStyleIsReportedAsUnsupported(): void
    {
        // The second constructor argument of the table style is the separate
        // first-row formatting Works has no equivalent for.
        $style = new TableStyle(['layout' => TableStyle::LAYOUT_FIXED], ['bgColor' => 'FF0000']);
        $table = new Table($style);
        $table->addRow(432, ['exactHeight' => true])->addCell(1440)->addText('A');

        self::assertContains('table_first_row_style_unsupported', $this->inspect($table)['codes']);
    }

    public function testCellBorderIsReportedAsUnsupported(): void
    {
        $table = new Table(['layout' => TableStyle::LAYOUT_FIXED]);
        $row = $table->addRow(432, ['exactHeight' => true]);
        $row->addCell(1440, ['borderSize' => 1])->addText('A');

        self::assertContains('table_cell_border_unsupported', $this->inspect($table)['codes']);
    }
}
