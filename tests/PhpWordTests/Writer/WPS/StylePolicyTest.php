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

use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Style;
use PhpOffice\PhpWord\Style\Font;
use PhpOffice\PhpWord\Style\Paragraph;
use PhpOffice\PhpWord\Writer\WPS\CompatibilityReport;
use PhpOffice\PhpWord\Writer\WPS\StylePolicy;
use PHPUnit\Framework\TestCase;

class StylePolicyTest extends TestCase
{
    protected function tearDown(): void
    {
        Style::resetStyles();
    }

    /**
     * @param mixed $style
     */
    private function inspectFont($style): array
    {
        $report = new CompatibilityReport();
        $facts = (new StylePolicy())->inspectFont($style, 'section.text[0].font', $report);

        return [
            'facts' => $facts,
            'codes' => array_column($report->getIssues(), 'code'),
        ];
    }

    /**
     * @param mixed $style
     */
    private function inspectParagraph($style): array
    {
        $report = new CompatibilityReport();
        $facts = (new StylePolicy())->inspectParagraph($style, 'section.text[0].paragraph', $report);

        return [
            'facts' => $facts,
            'codes' => array_column($report->getIssues(), 'code'),
        ];
    }

    public function testMissingFontStyleFallsBackToTheDefaults(): void
    {
        $result = $this->inspectFont(null);

        self::assertSame([], $result['codes']);
        self::assertSame(Settings::getDefaultFontName(), $result['facts']['name']);
        self::assertSame((int) Settings::getDefaultFontSize(), $result['facts']['size']);
        self::assertSame(strtoupper(Settings::getDefaultFontColor()), $result['facts']['color']);
        self::assertFalse($result['facts']['bold']);
        self::assertFalse($result['facts']['italic']);
        self::assertSame(0, $result['facts']['underline']);
    }

    public function testMissingParagraphStyleFallsBackToLeftAlignment(): void
    {
        $result = $this->inspectParagraph(null);

        self::assertSame([], $result['codes']);
        self::assertSame(['alignment' => 0], $result['facts']);
    }

    public function testSupportedCharacterPropertiesAreProjected(): void
    {
        $font = new Font();
        $font->setName('Courier New');
        $font->setSize(14);
        $font->setColor('ff0000');
        $font->setBold(true);
        $font->setItalic(true);
        $font->setUnderline(Font::UNDERLINE_DOUBLE);

        $result = $this->inspectFont($font);
        self::assertSame([], $result['codes']);
        self::assertSame('Courier New', $result['facts']['name']);
        self::assertSame(14, $result['facts']['size']);
        self::assertSame('FF0000', $result['facts']['color']);
        self::assertTrue($result['facts']['bold']);
        self::assertTrue($result['facts']['italic']);
        self::assertSame(3, $result['facts']['underline']);
    }

    public function testNonAsciiFontNameIsRejected(): void
    {
        $font = new Font();
        $font->setName('Ünicode');

        $result = $this->inspectFont($font);
        self::assertContains('font_name_unrepresentable', $result['codes']);
        self::assertSame(Settings::getDefaultFontName(), $result['facts']['name']);
    }

    public function testNonIntegerFontSizeIsRejected(): void
    {
        $font = new Font();
        $font->setSize(10.5);

        $result = $this->inspectFont($font);
        self::assertContains('font_size_unrepresentable', $result['codes']);
        self::assertSame((int) Settings::getDefaultFontSize(), $result['facts']['size']);
    }

    public function testOutOfRangeFontSizeIsRejected(): void
    {
        $font = new Font();
        $font->setSize(0);

        self::assertContains('font_size_unrepresentable', $this->inspectFont($font)['codes']);
    }

    public function testNonRgbFontColorIsRejected(): void
    {
        $font = new Font();
        $font->setColor('red');

        $result = $this->inspectFont($font);
        self::assertContains('font_color_unrepresentable', $result['codes']);
        self::assertSame(strtoupper(Settings::getDefaultFontColor()), $result['facts']['color']);
    }

    public function testUnmappedUnderlineIsRejected(): void
    {
        $font = new Font();
        $font->setUnderline('bogus');

        self::assertContains('font_underline_unrepresentable', $this->inspectFont($font)['codes']);
    }

    public function testUnmappedParagraphAlignmentIsRejected(): void
    {
        $paragraph = new Paragraph();
        $paragraph->setAlignment('distribute');

        self::assertContains('paragraph_alignment_unrepresentable', $this->inspectParagraph($paragraph)['codes']);
    }

    public function testEveryMappedAlignmentIsProjected(): void
    {
        $expected = [
            '' => 0, 'start' => 0, 'left' => 0,
            'end' => 1, 'right' => 1,
            'center' => 2,
            'both' => 3, 'justify' => 3,
        ];
        foreach ($expected as $alignment => $code) {
            $paragraph = new Paragraph();
            $paragraph->setAlignment($alignment);

            self::assertSame($code, $this->inspectParagraph($paragraph)['facts']['alignment'], $alignment);
        }
    }

    public function testUnresolvableNamedStyleIsRejected(): void
    {
        self::assertContains('font_named_style_unresolved', $this->inspectFont('DoesNotExist')['codes']);
        self::assertContains('paragraph_named_style_unresolved', $this->inspectParagraph('DoesNotExist')['codes']);
    }

    public function testNamedStyleOfTheRightTypeIsResolved(): void
    {
        Style::addFontStyle('MyFont', ['name' => 'Courier New']);

        $result = $this->inspectFont('MyFont');
        self::assertSame([], $result['codes']);
        self::assertSame('Courier New', $result['facts']['name']);
    }

    public function testUnexpectedStyleObjectTypeIsRejected(): void
    {
        self::assertContains('font_style_type_unsupported', $this->inspectFont(new Paragraph())['codes']);
        self::assertContains('paragraph_style_type_unsupported', $this->inspectParagraph(new Font())['codes']);
    }

    public function testParagraphInheritanceOtherThanNormalIsRejected(): void
    {
        $paragraph = new Paragraph();
        $paragraph->setBasedOn('Heading1');

        self::assertContains('paragraph_inheritance_unsupported', $this->inspectParagraph($paragraph)['codes']);
    }

    public function testUnsupportedParagraphPropertyIsRejected(): void
    {
        $paragraph = new Paragraph();
        $paragraph->setIndentation(['left' => 720]);

        self::assertContains('paragraph_indentation_unsupported', $this->inspectParagraph($paragraph)['codes']);
    }

    public function testDisabledWidowControlIsRejected(): void
    {
        $paragraph = new Paragraph();
        $paragraph->setWidowControl(false);

        self::assertContains('paragraph_widow_control_unsupported', $this->inspectParagraph($paragraph)['codes']);
    }

    public function testParagraphBorderIsRejected(): void
    {
        $paragraph = new Paragraph();
        $paragraph->setBorderSize(1);

        self::assertContains('paragraph_border_unsupported', $this->inspectParagraph($paragraph)['codes']);
    }

    public function testUnsupportedCharacterPropertyIsRejected(): void
    {
        $font = new Font();
        $font->setStrikethrough(true);

        self::assertContains('font_strike_unsupported', $this->inspectFont($font)['codes']);
    }
}
