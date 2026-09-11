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

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Writer\WPS;
use PhpOffice\PhpWord\Writer\WPS\CompatibilityReport;
use PhpOffice\PhpWord\Writer\WPS\DocumentText;
use PhpOffice\PhpWord\Writer\WPS\Utf16;
use PhpOffice\PhpWord\Writer\WPS\Validator;
use PHPUnit\Framework\TestCase;

/**
 * The validator re-reads a written .wps file and proves that the bytes on disk
 * still match the projection the writer intended. Its guard clauses are only
 * reachable with a structurally broken container, so most of these tests take a
 * valid document and patch a single field before re-validating it.
 */
class ValidatorTest extends TestCase
{
    /** 10x10 PNG fixture used as a byte-stable local image source. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAE3RFWHRDb21tZW50AHByZXNlcnZlLW1lDBPzAAAAABRJREFUeJxj+M/A8J8YzDCqkL4KAZLDxzmrOvyxAAAAAElFTkSuQmCC';

    /** @var string[] */
    private $files = [];

    /**
     * Byte-level fixture shared by the guard tests: the exact container the
     * writer produced plus the projection the validator has to recognise.
     *
     * @var null|array{bytes: string, expected: array}
     */
    private static $fixture;

    /** @var string[] */
    private static $fixtureFiles = [];

    /**
     * Global defaults restored after the class, see setUpBeforeClass().
     *
     * @var string
     */
    private static $fontName = '';

    /** @var null|float|int */
    private static $fontSize;

    /** @var string */
    private static $fontColor = '';

    /** @var bool */
    private static $escaping = false;

    /**
     * Builds one document that exercises every structural layer at once: three
     * paragraphs (one with character formatting, one with paragraph
     * formatting), an inline image and a two-cell table.
     */
    public static function setUpBeforeClass(): void
    {
        // The guards below are recorded as byte offsets, so the fixture has to be
        // reproducible byte for byte. Three of PHPWord's global defaults leak into
        // the emitted container, and another test can leave them dirty, so pin
        // them for the duration of this class.
        self::$fontName = Settings::getDefaultFontName();
        self::$fontSize = Settings::getDefaultFontSize();
        self::$fontColor = Settings::getDefaultFontColor();
        self::$escaping = Settings::isOutputEscapingEnabled();
        Settings::setDefaultFontName('Arial');
        Settings::setDefaultFontSize(10);
        Settings::setDefaultFontColor('000000');
        Settings::setOutputEscapingEnabled(false);

        $png = tempnam(sys_get_temp_dir(), 'phpword-wps-guard-');
        self::assertNotFalse($png);
        file_put_contents($png, (string) base64_decode(self::PNG, true));
        self::$fixtureFiles[] = $png;

        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $section->addText('Hello');
        $section->addText('Bold', ['bold' => true]);
        $section->addText('Centered', null, ['alignment' => Jc::CENTER]);
        $section->addImage($png, ['width' => 20, 'height' => 20]);
        $table = $section->addTable(['layout' => 'fixed']);
        $row = $table->addRow(432, ['exactHeight' => true]);
        $row->addCell(1440)->addText('A');
        $row->addCell(1440)->addText('B');

        // Same projection the writer hands to the validator, so the fixture is
        // a round trip that is valid before any byte is patched.
        $projection = (new DocumentText())->extract($phpWord, new CompatibilityReport());

        $file = tempnam(sys_get_temp_dir(), 'phpword-wps-guard-');
        self::assertNotFalse($file);
        IOFactory::createWriter($phpWord, 'WPS')->save($file);
        self::$fixtureFiles[] = $file;

        self::$fixture = [
            'bytes' => (string) file_get_contents($file),
            'expected' => [
                'text' => $projection['text'],
                'images' => $projection['images'],
                'tables' => $projection['tables'],
                'styles' => $projection['styles'],
            ],
        ];
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$fixtureFiles as $file) {
            @unlink($file);
        }
        self::$fixtureFiles = [];
        self::$fixture = null;

        Settings::setDefaultFontName(self::$fontName);
        Settings::setDefaultFontSize(self::$fontSize);
        Settings::setDefaultFontColor(self::$fontColor);
        Settings::setOutputEscapingEnabled(self::$escaping);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        $this->files = [];
    }

    private function write(PhpWord $phpWord): string
    {
        $file = tempnam(sys_get_temp_dir(), 'phpword-wps-validate-');
        self::assertNotFalse($file);
        IOFactory::createWriter($phpWord, 'WPS')->save($file);
        $this->files[] = $file;

        return $file;
    }

    private function temp(string $bytes): string
    {
        $file = tempnam(sys_get_temp_dir(), 'phpword-wps-patched-');
        self::assertNotFalse($file);
        file_put_contents($file, $bytes);
        $this->files[] = $file;

        return $file;
    }

    /**
     * @param null|array|string $expected
     */
    private function validate(string $file, $expected = null): array
    {
        return (new Validator())->validateFile($file, $expected);
    }

    private function simpleDocument(): PhpWord
    {
        $phpWord = new PhpWord();
        $phpWord->addSection()->addText('Hello Works');

        return $phpWord;
    }

    private function styledDocument(): PhpWord
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $section->addText('Bold', ['bold' => true]);
        $section->addText('Centered', null, ['alignment' => Jc::CENTER]);

        return $phpWord;
    }

    /**
     * Replace four bytes at $offset with an unsigned little-endian 32-bit value.
     */
    private function patchU32(string $bytes, int $offset, int $value): string
    {
        return substr_replace($bytes, pack('V', $value), $offset, 4);
    }

    private function patchU16(string $bytes, int $offset, int $value): string
    {
        return substr_replace($bytes, pack('v', $value), $offset, 2);
    }

    private function entryOffset(string $bytes, string $name): int
    {
        $position = strpos($bytes, Utf16::encodeLe($name));
        self::assertNotFalse($position, 'directory entry "' . $name . '" not found');

        return $position;
    }

    public function testValidDocumentRoundTrips(): void
    {
        $phpWord = $this->simpleDocument();
        $file = $this->write($phpWord);

        $writer = new WPS($phpWord);
        $writer->save($file);
        $result = $writer->getLastValidation();

        self::assertTrue($result['valid'], implode('; ', $result['errors']));
        self::assertSame("Hello Works\r", $result['text']);
        self::assertSame(['Arial'], $result['styles']['fontNames']);
    }

    public function testUnreadableFileIsReportedAsFailure(): void
    {
        $result = $this->validate(tempnam(sys_get_temp_dir(), 'phpword-wps-missing-') . '.does-not-exist');

        self::assertFalse($result['valid']);
        self::assertSame(['Cannot read output file.'], $result['errors']);
        self::assertNull($result['text']);
    }

    public function testInvalidCfbSignatureIsRejected(): void
    {
        $result = $this->validate($this->temp('Not a CFB container at all'));

        self::assertFalse($result['valid']);
        self::assertSame(['Invalid CFB signature.'], $result['errors']);
    }

    public function testUnsupportedSectorSizeIsRejected(): void
    {
        $bytes = (string) file_get_contents($this->write($this->simpleDocument()));
        $result = $this->validate($this->temp($this->patchU32($bytes, 28, 0x000C0000)));

        self::assertFalse($result['valid']);
        self::assertSame(['Unsupported CFB byte order or sector size.'], $result['errors']);
    }

    public function testUnexpectedMiniStreamCutoffIsRejected(): void
    {
        $bytes = (string) file_get_contents($this->write($this->simpleDocument()));
        $result = $this->validate($this->temp($this->patchU32($bytes, 56, 1024)));

        self::assertFalse($result['valid']);
        self::assertSame(['Unexpected CFB Mini Stream cutoff.'], $result['errors']);
    }

    public function testInvalidFatCountIsRejected(): void
    {
        $bytes = (string) file_get_contents($this->write($this->simpleDocument()));
        $result = $this->validate($this->temp($this->patchU32($bytes, 44, 0)));

        self::assertFalse($result['valid']);
        self::assertSame(['Invalid or unsupported FAT count.'], $result['errors']);
    }

    public function testMissingFatSectorInDifatIsRejected(): void
    {
        $bytes = (string) file_get_contents($this->write($this->simpleDocument()));
        $result = $this->validate($this->temp($this->patchU32($bytes, 76, 0xFFFFFFFF)));

        self::assertFalse($result['valid']);
        self::assertSame(['Missing FAT sector in DIFAT.'], $result['errors']);
    }

    public function testInvalidDirectoryNameLengthIsRejected(): void
    {
        $bytes = (string) file_get_contents($this->write($this->simpleDocument()));
        // The root entry name is stored as UTF-16LE including its terminator.
        $root = $this->entryOffset($bytes, 'Root Entry');
        $result = $this->validate($this->temp($this->patchU16($bytes, $root + 64, 3)));

        self::assertFalse($result['valid']);
        self::assertStringStartsWith('Invalid CFB directory name length', $result['errors'][0]);
    }

    public function testInvalidRootDirectoryEntryIsRejected(): void
    {
        $bytes = (string) file_get_contents($this->write($this->simpleDocument()));
        $root = $this->entryOffset($bytes, 'Root Entry');
        $bytes[$root + 66] = "\x02"; // stream instead of root storage

        $result = $this->validate($this->temp($bytes));
        self::assertFalse($result['valid']);
        self::assertSame(['Invalid CFB root directory entry.'], $result['errors']);
    }

    public function testMissingContentsStreamIsRejected(): void
    {
        $bytes = (string) file_get_contents($this->write($this->simpleDocument()));
        $contents = $this->entryOffset($bytes, 'CONTENTS');
        $bytes = substr_replace($bytes, Utf16::encodeLe('CONTENTX'), $contents, 16);

        $result = $this->validate($this->temp($bytes));
        self::assertFalse($result['valid']);
        self::assertSame(['CFB CONTENTS stream was not found.'], $result['errors']);
    }

    public function testMiniStreamContentsIsRejected(): void
    {
        $bytes = (string) file_get_contents($this->write($this->simpleDocument()));
        $contents = $this->entryOffset($bytes, 'CONTENTS');
        // Directory entry layout: start at +116, size (u64) at +120.
        $bytes = substr_replace($bytes, pack('V', 128) . pack('V', 0), $contents + 120, 8);

        $result = $this->validate($this->temp($bytes));
        self::assertFalse($result['valid']);
        self::assertSame(['Mini Stream CONTENTS is outside the supported writer subset.'], $result['errors']);
    }

    public function testInvalidWorksMagicIsRejected(): void
    {
        $bytes = (string) file_get_contents($this->write($this->simpleDocument()));
        $position = strpos($bytes, 'CHNKWKS');
        self::assertNotFalse($position);
        $bytes = substr_replace($bytes, 'XHNKWKS', $position, 7);

        $result = $this->validate($this->temp($bytes));
        self::assertFalse($result['valid']);
        self::assertSame(['Invalid Works 7/8 CONTENTS magic.'], $result['errors']);
    }

    public function testIncompleteContentsIndexIsRejected(): void
    {
        $bytes = (string) file_get_contents($this->write($this->simpleDocument()));
        $position = strpos($bytes, 'CHNKWKS');
        self::assertNotFalse($position);
        $bytes = $this->patchU16($bytes, $position + 0x0C, 1);

        $result = $this->validate($this->temp($bytes));
        self::assertFalse($result['valid']);
        self::assertSame(['Works CONTENTS index is incomplete.'], $result['errors']);
    }

    public function testTextMismatchIsReported(): void
    {
        $result = $this->validate($this->write($this->simpleDocument()), "Something else\r");

        self::assertFalse($result['valid']);
        self::assertContains('Main TEXT zone does not exactly match the expected document projection.', $result['errors']);
    }

    public function testMissingFdpcZoneIsReportedWhenStylesAreExpected(): void
    {
        // A bare line break carries no character formatting, so the writer
        // emits no FDPC zone at all.
        $phpWord = new PhpWord();
        $phpWord->addSection()->addTextBreak();

        $result = $this->validate($this->write($phpWord), [
            'text' => "\r",
            'styles' => ['fontRuns' => [['startUnit' => 0, 'endUnit' => 1, 'font' => []]]],
        ]);

        self::assertFalse($result['valid']);
        self::assertContains('Missing FDPC zone required for inline objects or character styles.', $result['errors']);
    }

    public function testUnexpectedFdpcZoneIsReportedWhenNoStylesAreExpected(): void
    {
        $result = $this->validate($this->write($this->styledDocument()));

        self::assertFalse($result['valid']);
        self::assertContains('Unexpected FDPC zone is present in a projection without objects or character styles.', $result['errors']);
    }

    public function testMissingFdppZoneIsReportedWhenParagraphStylesAreExpected(): void
    {
        $result = $this->validate($this->write($this->simpleDocument()), [
            'text' => "Hello Works\r",
            'styles' => ['paragraphRuns' => [['startUnit' => 0, 'endUnit' => 1, 'paragraph' => ['alignment' => 2]]]],
        ]);

        self::assertFalse($result['valid']);
        self::assertContains('Missing FDPP zone required for paragraph styles.', $result['errors']);
    }

    public function testUnexpectedFdppZoneIsReportedWhenNoParagraphStylesAreExpected(): void
    {
        // A centered paragraph is the only paragraph property the writer emits,
        // so expecting nothing at all makes the FDPP zone surplus.
        $phpWord = new PhpWord();
        $phpWord->addSection()->addText('Centered', null, ['alignment' => Jc::CENTER]);

        $result = $this->validate($this->write($phpWord));
        self::assertFalse($result['valid']);
        self::assertContains('Unexpected FDPP zone is present in a projection without paragraph styles.', $result['errors']);
    }

    public function testMissingEobjZoneIsReportedWhenObjectsAreExpected(): void
    {
        $result = $this->validate($this->write($this->simpleDocument()), [
            'text' => "Hello Works\r",
            'images' => [[
                'objectId' => 1,
                'textUnitOffset' => 0,
                'binary' => 'not-an-image',
                'sha256' => str_repeat('0', 64),
                'byteLength' => 12,
            ]],
        ]);

        self::assertFalse($result['valid']);
        self::assertContains('Missing EOBJ PLC zone required for inline objects.', $result['errors']);
    }

    public function testUnexpectedEobjZoneIsReportedWhenNoObjectsAreExpected(): void
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $table = $section->addTable(['layout' => 'fixed']);
        $table->addRow(432, ['exactHeight' => true])->addCell(1440)->addText('A');

        $result = $this->validate($this->write($phpWord), ['text' => "\u{FFFC}"]);
        self::assertFalse($result['valid']);
        self::assertContains('Unexpected EOBJ zone is present in an object-free projection.', $result['errors']);
    }

    public function testGuardFixtureIsAValidBaseline(): void
    {
        $fixture = self::$fixture;
        self::assertNotNull($fixture);

        $result = $this->validate($this->temp($fixture['bytes']), $fixture['expected']);
        self::assertTrue($result['valid'], implode('; ', $result['errors']));
    }

    /**
     * @return array{bytes: string, expected: array}
     */
    private function fixture(): array
    {
        $fixture = self::$fixture;
        self::assertNotNull($fixture);

        return $fixture;
    }

    /**
     * The fixture container with one expected table field replaced.
     */
    private function expectedWithTable(array $patch, int $index = 0): array
    {
        $expected = $this->fixture()['expected'];
        $expected['tables'][$index] = array_merge($expected['tables'][$index], $patch);

        return $expected;
    }

    public function testUnexpectedInlineObjectCountIsReported(): void
    {
        $fixture = $this->fixture();
        $expected = $fixture['expected'];
        $expected['images'][] = $expected['images'][0];

        $result = $this->validate($this->temp($fixture['bytes']), $expected);
        self::assertFalse($result['valid']);
        self::assertContains('EOBJ object count does not match the expected inline-object count.', $result['errors']);
    }

    public function testFramMcldTableCountMismatchIsReported(): void
    {
        $fixture = $this->fixture();
        $expected = $fixture['expected'];
        $expected['tables'][] = $expected['tables'][0];

        $result = $this->validate($this->temp($fixture['bytes']), $expected);
        self::assertFalse($result['valid']);
        self::assertContains('FRAM/MCLD table count does not match the expected table count.', $result['errors']);
    }

    public function testStrsTableTextZoneLengthMismatchIsReported(): void
    {
        $fixture = $this->fixture();

        $result = $this->validate($this->temp($fixture['bytes']), $this->expectedWithTable(['zoneUnits' => 99]));
        self::assertFalse($result['valid']);
        self::assertContains('STRS table text-zone length mismatch for table 1.', $result['errors']);
    }

    public function testExpectedCharacterRunWithoutFontIsSkipped(): void
    {
        $fixture = $this->fixture();
        $expected = $fixture['expected'];
        $expected['styles']['fontRuns'][] = ['startUnit' => 0, 'endUnit' => 1];

        // A run that carries no font payload cannot be compared, so it is
        // dropped instead of being reported as a mismatch.
        $result = $this->validate($this->temp($fixture['bytes']), $expected);
        self::assertTrue($result['valid'], implode('; ', $result['errors']));
    }

    public function testAdjacentExpectedRunsWithTheSameFontAreMerged(): void
    {
        $fixture = $this->fixture();
        $expected = $fixture['expected'];
        self::assertNotEmpty($expected['styles']['fontRuns']);

        $first = $expected['styles']['fontRuns'][0];
        self::assertGreaterThanOrEqual($first['startUnit'] + 2, $first['endUnit']);
        $middle = $first['startUnit'] + 1;
        array_splice($expected['styles']['fontRuns'], 0, 1, [
            ['startUnit' => $first['startUnit'], 'endUnit' => $middle, 'font' => $first['font']],
            ['startUnit' => $middle, 'endUnit' => $first['endUnit'], 'font' => $first['font']],
        ]);

        // Splitting a run in two must normalise back to the original projection.
        $result = $this->validate($this->temp($fixture['bytes']), $expected);
        self::assertTrue($result['valid'], implode('; ', $result['errors']));
    }

    public function testMcldCellCountMismatchIsReported(): void
    {
        $fixture = $this->fixture();
        $expected = $fixture['expected'];
        $expected['tables'][0]['cells'][] = $expected['tables'][0]['cells'][0];

        $result = $this->validate($this->temp($fixture['bytes']), $expected);
        self::assertFalse($result['valid']);
        self::assertContains('MCLD cell count mismatch for table 1.', $result['errors']);
    }

    /**
     * Every entry is one byte of the fixture container plus the guard message
     * that byte protects. Patching it must make the validator reject the file
     * with exactly that message, which pins the guard to a reproducible input.
     *
     * @return array<string, array{0: int, 1: int<0, 255>, 2: string}>
     */
    public static function guardProvider(): array
    {
        $mutations = [
            'Truncated Works property tag.' => [876, 0x05],
            'Truncated 16-bit Works property.' => [1086, 0x07],
            'Truncated FDPC property tag.' => [1000, 0x05],
            'Truncated FDPC 16-bit property.' => [1054, 0x07],
            'Unexpected extra data in FDPC font selector.' => [1012, 0x0A],
            'Truncated FRAM 0x12 property.' => [1168, 0x05],
            'Truncated FRAM 0x22 property.' => [1168, 0x1F],
            'Truncated FRAM object-id array.' => [1168, 0x0A],
            'CFB CONTENTS stream was not found.' => [8832, 0x00],
            'CFB directory tree points outside the directory table.' => [9036, 0xFF],
            'CFB sector is outside FAT.' => [48, 0xFF],
            'CFB sector points outside file.' => [76, 0xFF],
            'CFB storage for image object 1 was not found.' => [8960, 0x00],
            'CFB stream chain ended before its declared size.' => [8952, 0xFF],
            'Cell separator is not CR in table 1, after cell 0.' => [848, 0x00],
            'Cell text mismatch for table 1, cell 0.' => [846, 0x00],
            'Cell text mismatch for table 1, cell 1.' => [850, 0x00],
            'Character style mismatch at run 0.' => [1022, 0xFF],
            'Character style mismatch at run 1.' => [1050, 0xFF],
            'Character style mismatch at run 2.' => [1022, 0xFF],
            'Character style mismatch at run 3.' => [1022, 0xFF],
            'Character style mismatch at run 4.' => [1022, 0xFF],
            'Character style run count mismatch.' => [642, 0x00],
            'EOBJ object type mismatch for image object 1.' => [1090, 0xFF],
            'EOBJ object type mismatch for table object 2.' => [1116, 0xFF],
            'EOBJ physical dimensions mismatch for image object 1.' => [1094, 0x00],
            'EOBJ physical dimensions mismatch for table object 2.' => [1120, 0x00],
            'EOBJ text position mismatch for image object 1.' => [1074, 0x00],
            'EOBJ text position mismatch for table object 2.' => [1078, 0x00],
            'EOBJ zone is too short.' => [684, 0x00],
            'FDPC contains an unsupported special font type.' => [1060, 0xFF],
            'FDPC does not mark image object 1 at the exact TEXT position.' => [642, 0x00],
            'FDPC does not mark table object 2 at the exact TEXT position.' => [642, 0x00],
            'FDPC final text boundary does not match TEXT.' => [584, 0x00],
            'FDPC font size is not an exact integer point size.' => [1006, 0x00],
            'FDPC positions are not monotonic.' => [924, 0xFF],
            'FDPC property offset is outside the property region.' => [976, 0xFF],
            'FDPC references a FONT id outside the table.' => [1018, 0xFF],
            'FDPC style boundary is outside aligned TEXT bytes.' => [924, 0x00],
            'FDPC zone is too short.' => [660, 0x00],
            'FDPP final text boundary does not match TEXT.' => [1150, 0xFF],
            'FDPP positions are not monotonic.' => [1142, 0xFF],
            'FDPP property offset is outside the property region.' => [1154, 0xFF],
            'FDPP style boundary is outside aligned TEXT bytes.' => [1142, 0x00],
            'FDPP zone is too short.' => [708, 0x00],
            'FONT name is outside the emitted ASCII subset.' => [786, 0x00],
            'FONT record offset table is inconsistent.' => [780, 0x00],
            'FRAM does not link table object 2.' => [1182, 0x00],
            'FRAM object/STRS/MCLD linkage mismatch for table object 2.' => [1172, 0x00],
            'FRAM zone is too short.' => [732, 0x00],
            'FRAM/MCLD table zone has the wrong Works type.' => [724, 0x00],
            'Image Ole10Native stream unexpectedly uses Mini Stream storage.' => [9209, 0x00],
            'Invalid CFB directory name length at entry 0.' => [8768, 0x00],
            'Invalid CFB directory name length at entry 1.' => [48, 0x00],
            'Invalid CFB directory name length at entry 2.' => [9024, 0x00],
            'Invalid CFB directory name length at entry 3.' => [9152, 0x00],
            'Invalid CFB root directory entry.' => [8704, 0x00],
            'Invalid CFB signature.' => [0, 0x00],
            'Invalid EOBJ PLC header.' => [680, 0x00],
            'Invalid FDPC FOD count.' => [656, 0x00],
            'Invalid FDPC font selector array.' => [1012, 0x00],
            'Invalid FDPC property size.' => [1000, 0x00],
            'Invalid FDPP FOD count.' => [704, 0x00],
            'Invalid FONT name length.' => [784, 0x00],
            'Invalid FRAM object-id array.' => [1178, 0x00],
            'Invalid FRAM record size.' => [728, 0xFF],
            'Invalid MCLD header.' => [752, 0x00],
            'Invalid STRS PLC header.' => [608, 0x00],
            'Invalid TCD PLC header or length.' => [632, 0x00],
            'Invalid Works 7/8 CONTENTS magic.' => [512, 0x00],
            'Invalid Works FONT table header.' => [560, 0x00],
            'Invalid Works index entry size.' => [544, 0x00],
            'Invalid Works local index count.' => [538, 0x00],
            'Invalid Works structured record size.' => [876, 0x00],
            'Invalid or cyclic CFB sector chain.' => [9216, 0x00],
            'Invalid or duplicate STRS zone.' => [604, 0x00],
            'Invalid or unsupported FAT count.' => [44, 0x00],
            'MCLD bottomEmu mismatch for table 1, cell 0.' => [1244, 0x00],
            'MCLD bottomEmu mismatch for table 1, cell 1.' => [1284, 0x00],
            'MCLD cell misses geometry field 0.' => [1230, 0xFF],
            'MCLD cell misses geometry field 1.' => [1236, 0x00],
            'MCLD cell misses geometry field 2.' => [1242, 0x00],
            'MCLD cell misses geometry field 3.' => [1248, 0x00],
            'MCLD cell misses geometry field 4.' => [1254, 0x00],
            'MCLD cell misses geometry field 5.' => [1260, 0x00],
            'MCLD heightEmu mismatch for table 1, cell 0.' => [1262, 0x00],
            'MCLD heightEmu mismatch for table 1, cell 1.' => [1302, 0x00],
            'MCLD leftEmu mismatch for table 1, cell 0.' => [1238, 0xFF],
            'MCLD leftEmu mismatch for table 1, cell 1.' => [1278, 0x00],
            'MCLD rightEmu mismatch for table 1, cell 0.' => [1250, 0x00],
            'MCLD rightEmu mismatch for table 1, cell 1.' => [1290, 0x00],
            'MCLD table definition 1 is missing.' => [1214, 0x00],
            'MCLD table has an invalid cell count.' => [1222, 0x00],
            'MCLD topEmu mismatch for table 1, cell 0.' => [1232, 0xFF],
            'MCLD topEmu mismatch for table 1, cell 1.' => [1272, 0xFF],
            'MCLD widthEmu mismatch for table 1, cell 0.' => [1256, 0x00],
            'MCLD widthEmu mismatch for table 1, cell 1.' => [1296, 0x00],
            'MCLD zone is too short.' => [756, 0x00],
            'Mini Stream CONTENTS is outside the supported writer subset.' => [8953, 0x00],
            'Missing EOBJ PLC zone required for inline objects.' => [666, 0x00],
            'Missing EOBJ record for image object 1.' => [666, 0x00],
            'Missing EOBJ record for table object 2.' => [666, 0x00],
            'Missing FDPC zone required for inline objects or character styles.' => [642, 0x00],
            'Missing FDPP zone required for paragraph styles.' => [690, 0x00],
            'Missing STRS/FRAM/MCLD zone required for Works tables.' => [594, 0x00],
            'Missing or invalid required Works FONT zone.' => [546, 0x00],
            'Missing or invalid required Works TEXT zone.' => [570, 0x00],
            'Multiple main text subdivisions are outside the emitted subset.' => [892, 0x01],
            'Ole10Native payload is not byte-for-byte identical for image object 1.' => [4608, 0xFF],
            'Ole10Native payload size is invalid.' => [4608, 0x00],
            'Ole10Native stream for image object 1 was not found.' => [9036, 0x00],
            'Paragraph style mismatch at run 0.' => [1164, 0x00],
            'Paragraph style run count mismatch.' => [690, 0x00],
            'STRS does not define a main text subdivision.' => [882, 0x00],
            'STRS does not define expected type-5 text zone 1 for table 1.' => [892, 0x00],
            'STRS final incremental sentinel is not zero.' => [872, 0xFF],
            'STRS record does not contain a text-zone type.' => [880, 0xFF],
            'STRS subdivisions do not exactly consume the TEXT zone.' => [612, 0xFF],
            'STRS text subdivision is outside TEXT.' => [588, 0x00],
            'STRS zone is too short.' => [612, 0x00],
            'TCD cell boundaries are not strictly increasing.' => [908, 0xFF],
            'TCD cell boundaries do not exactly match for table 1.' => [908, 0x00],
            'TCD cell-boundary zone is missing for table 1.' => [618, 0x00],
            'TCD final sentinel does not repeat the final cell end.' => [912, 0x00],
            'TCD zone is too short.' => [636, 0x00],
            'Truncated 32-bit Works property.' => [876, 0x08],
            'Truncated EOBJ position table.' => [1062, 0xFF],
            'Truncated FDPC 32-bit property.' => [1000, 0x08],
            'Truncated FRAM record.' => [1166, 0xFF],
            'Truncated MCLD cell count.' => [756, 0x10],
            'Truncated STRS pointer table.' => [852, 0xFF],
            'Truncated Works structured record.' => [1222, 0x03],
            'Unexpected CFB Mini Stream cutoff.' => [56, 0xFF],
            'Unexpected FDPC character property in emitted subset.' => [1004, 0x00],
            'Unexpected FDPC font selector child.' => [1016, 0xFF],
            'Unexpected FDPP main property value.' => [1160, 0xFF],
            'Unexpected FDPP paragraph property tag.' => [1162, 0x00],
            'Unexpected FDPP property size.' => [1158, 0x00],
            'Unexpected FRAM object-id array content.' => [1180, 0xFF],
            'Unexpected nonzero main value in emitted structured record.' => [878, 0xFF],
            'Unexpected property type 0x0 in emitted structured record.' => [881, 0x00],
            'Unexpected property type 0xff in emitted structured record.' => [881, 0xFF],
            'Unexpected trailing bytes in EOBJ zone.' => [684, 0xFF],
            'Unexpected trailing bytes in FONT table.' => [764, 0x00],
            'Unexpected trailing bytes in FRAM zone.' => [728, 0x00],
            'Unexpected trailing bytes in MCLD zone.' => [756, 0xFF],
            'Unsupported CFB byte order or sector size.' => [28, 0x00],
            'Unsupported FDPC property type in emitted style subset.' => [1005, 0x00],
            'Unsupported FDPP paragraph alignment.' => [1164, 0xFF],
            'Unsupported FRAM field in emitted subset.' => [1173, 0x00],
            'Works CONTENTS index is incomplete.' => [524, 0x00],
            'Works index ended or moved backwards before all entries were read.' => [524, 0xFF],
            'Works zone points outside CONTENTS.' => [561, 0xFF],
        ];

        $cases = [];
        foreach ($mutations as $message => $mutation) {
            $cases[$message] = [$mutation[0], $mutation[1], $message];
        }

        return $cases;
    }

    /**
     * @param int<0, 255> $value the byte the provider patches in
     *
     * @dataProvider guardProvider
     */
    public function testGuardIsReported(int $offset, int $value, string $message): void
    {
        $fixture = self::$fixture;
        self::assertNotNull($fixture);

        $bytes = $fixture['bytes'];
        $bytes[$offset] = chr($value & 0xFF);

        $result = $this->validate($this->temp($bytes), $fixture['expected']);

        self::assertFalse(
            $result['valid'],
            'byte ' . $offset . ' set to 0x' . strtoupper(dechex($value)) . ' was still accepted'
        );
        self::assertContains(
            $message,
            $result['errors'],
            'byte ' . $offset . ' set to 0x' . strtoupper(dechex($value)) . ' reported: ' . implode('; ', $result['errors'])
        );
    }
}
