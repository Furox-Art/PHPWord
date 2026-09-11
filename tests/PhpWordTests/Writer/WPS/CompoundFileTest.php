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

use InvalidArgumentException;
use PhpOffice\PhpWord\Writer\WPS\CompoundFile;
use PhpOffice\PhpWord\Writer\WPS\Utf16;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CompoundFileTest extends TestCase
{
    private const CFB_SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    private function encodeContents(string $contents, array $images = []): string
    {
        return (new CompoundFile())->encodeContents($contents, $images);
    }

    public function testContentsStreamIsWrappedInAValidCfbContainer(): void
    {
        $bytes = $this->encodeContents(str_repeat('C', 4096));

        self::assertSame(self::CFB_SIGNATURE, substr($bytes, 0, 8));
        self::assertStringContainsString(Utf16::encodeLe('CONTENTS'), $bytes);
        self::assertStringContainsString(Utf16::encodeLe('Root Entry'), $bytes);
    }

    public function testContentsBelowTheMiniStreamCutoffIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CONTENTS must be at least 4096 bytes');

        $this->encodeContents(str_repeat('C', 4095));
    }

    public function testImageWithoutObjectIdOrBinaryIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Image record is missing objectId or binary data.');

        $this->encodeContents(str_repeat('C', 4096), [['binary' => 'image-bytes']]);
    }

    public function testNonPositiveObjectIdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Image objectId must be positive.');

        $this->encodeContents(str_repeat('C', 4096), [['objectId' => 0, 'binary' => 'image-bytes']]);
    }

    public function testEmptyImageBinaryIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Image binary data must not be empty.');

        $this->encodeContents(str_repeat('C', 4096), [['objectId' => 1, 'binary' => '']]);
    }

    public function testInlineImagesAreStoredInAscendingObjectIdOrder(): void
    {
        // The directory tree is walked by object id, so records arriving out of
        // order have to be sorted before the storage entries are emitted.
        $bytes = $this->encodeContents(str_repeat('C', 4096), [
            ['objectId' => 2, 'binary' => 'second'],
            ['objectId' => 1, 'binary' => 'first'],
        ]);

        self::assertSame(self::CFB_SIGNATURE, substr($bytes, 0, 8));
        $first = strpos($bytes, Utf16::encodeLe('Object 1'));
        $second = strpos($bytes, Utf16::encodeLe('Object 2'));
        self::assertNotFalse($first);
        self::assertNotFalse($second);
        self::assertLessThan($second, $first);
    }

    public function testFileTooLargeForTheSingleFatSectorEncoderIsRejected(): void
    {
        // 109 FAT sectors cover 109 * 128 * 512 bytes of payload; anything
        // larger would need a DIFAT chain the writer does not emit.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('WPS file is too large for the current no-DIFAT encoder.');

        $this->encodeContents(str_repeat('C', 110 * 128 * 512));
    }
}
