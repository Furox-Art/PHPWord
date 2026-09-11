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

use PhpOffice\PhpWord\Writer\WPS\Utf16;
use PHPUnit\Framework\TestCase;

class Utf16Test extends TestCase
{
    public function testEncodesAsciiAsSingleLeCodeUnit(): void
    {
        self::assertSame("\x41\x00", Utf16::encodeLe('A'));
        self::assertSame('', Utf16::encodeLe(''));
    }

    public function testEncodesTwoByteSequence(): void
    {
        // U+00E9 (é) stays inside the BMP.
        self::assertSame("\xE9\x00", Utf16::encodeLe("\xC3\xA9"));
    }

    public function testEncodesThreeByteSequence(): void
    {
        // U+20AC (€) stays inside the BMP.
        self::assertSame("\xAC\x20", Utf16::encodeLe("\xE2\x82\xAC"));
    }

    public function testEncodesFourByteSequenceAsSurrogatePair(): void
    {
        // U+1F600 needs a UTF-16 surrogate pair.
        self::assertSame("\x3D\xD8\x00\xDE", Utf16::encodeLe("\xF0\x9F\x98\x80"));
    }

    public function testEncodesCodePointAboveUnicodeRangeAsReplacement(): void
    {
        // F5 80 80 80 is a well-formed four-byte lead but decodes above U+10FFFF.
        self::assertSame("\xFD\xFF", Utf16::encodeLe("\xF5\x80\x80\x80"));
    }

    public function testEncodesTruncatedLeadByteAsReplacement(): void
    {
        // A two-byte lead with no continuation byte falls through to U+FFFD.
        self::assertSame("\xFD\xFF", Utf16::encodeLe("\xC3"));
    }

    public function testEncodesInvalidLeadByteAsReplacement(): void
    {
        self::assertSame("\xFD\xFF", Utf16::encodeLe("\xFF"));
    }

    public function testEncodesSurrogateCodePointAsReplacement(): void
    {
        // ED A0 80 is the CESU-8 spelling of U+D800, which UTF-16 cannot hold.
        self::assertSame("\xFD\xFF", Utf16::encodeLe("\xED\xA0\x80"));
    }

    public function testRoundTripsAsciiLatinAndAstralText(): void
    {
        $text = "Aé€\u{1F600}\u{10FFFF}";
        self::assertSame($text, Utf16::decodeLe(Utf16::encodeLe($text)));
    }

    public function testDecodesSurrogatePair(): void
    {
        self::assertSame("\u{1F600}", Utf16::decodeLe("\x3D\xD8\x00\xDE"));
    }

    public function testDecodesHighSurrogateWithoutLowSurrogate(): void
    {
        // The pair is broken: the high surrogate is emitted on its own and the
        // following code unit is then decoded as an ordinary BMP character.
        self::assertSame("\xED\xA0\xBDA", Utf16::decodeLe("\x3D\xD8\x41\x00"));
    }

    public function testDecodesBmpCodeUnits(): void
    {
        self::assertSame('', Utf16::decodeLe(''));
        self::assertSame('A', Utf16::decodeLe("\x41\x00"));
        self::assertSame("\xC3\xA9", Utf16::decodeLe("\xE9\x00"));
        self::assertSame("\xE2\x82\xAC", Utf16::decodeLe("\xAC\x20"));
    }

    public function testDecodingIgnoresTrailingOddByte(): void
    {
        self::assertSame('A', Utf16::decodeLe("A\x00B"));
    }
}
