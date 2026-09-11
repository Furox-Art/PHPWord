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

use PhpOffice\PhpWord\Element\Image;
use PhpOffice\PhpWord\Style\Frame;
use PhpOffice\PhpWord\Writer\WPS\CompatibilityReport;
use PhpOffice\PhpWord\Writer\WPS\ImagePolicy;
use PHPUnit\Framework\TestCase;

class ImagePolicyTest extends TestCase
{
    /** 10x10 PNG fixture used as a byte-stable local image source. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAE3RFWHRDb21tZW50AHByZXNlcnZlLW1lDBPzAAAAABRJREFUeJxj+M/A8J8YzDCqkL4KAZLDxzmrOvyxAAAAAElFTkSuQmCC';

    /** @var null|string */
    private $pngFile;

    protected function setUp(): void
    {
        $this->pngFile = tempnam(sys_get_temp_dir(), 'phpword-wps-image-');
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
     * @return array{facts: array, codes: array}
     */
    private function inspect(Image $image): array
    {
        $report = new CompatibilityReport();
        $facts = (new ImagePolicy())->inspect($image, 'section.image[0]', $report);

        return [
            'facts' => $facts,
            'codes' => array_column($report->getIssues(), 'code'),
        ];
    }

    private function localImage(): Image
    {
        return new Image($this->pngFile, ['width' => 20, 'height' => 20]);
    }

    public function testLocalImageIsSupportedByteForByte(): void
    {
        $binary = (string) base64_decode(self::PNG, true);
        $result = $this->inspect($this->localImage());

        self::assertSame([], $result['codes']);
        self::assertTrue($result['facts']['supported']);
        self::assertSame(Image::SOURCE_LOCAL, $result['facts']['sourceType']);
        self::assertSame('image/png', $result['facts']['mime']);
        self::assertSame($binary, $result['facts']['binary']);
        self::assertSame(hash('sha256', $binary), $result['facts']['sha256']);
        self::assertSame(strlen($binary), $result['facts']['byteLength']);
        // 20 points at 12700 EMU per point.
        self::assertSame(254000, $result['facts']['widthEmu']);
        self::assertSame(254000, $result['facts']['heightEmu']);
    }

    public function testImageResolvedInMemoryIsRejected(): void
    {
        // Raw bytes that are not a readable file make PHPWord fall back to the
        // in-memory string source, whose original link/semantics are gone.
        $binary = (string) base64_decode(self::PNG, true);
        $result = $this->inspect(new Image($binary, ['width' => 20, 'height' => 20]));

        self::assertContains('image_source_semantics_unverifiable', $result['codes']);
        self::assertFalse($result['facts']['supported']);
    }

    public function testNonPointUnitIsRejected(): void
    {
        $image = $this->localImage();
        $image->getStyle()->setUnit(Frame::UNIT_PX);

        $result = $this->inspect($image);
        self::assertContains('image_unit_unrepresentable', $result['codes']);
        self::assertNull($result['facts']['widthEmu']);
        self::assertNull($result['facts']['heightEmu']);
    }

    public function testZeroWidthIsRejected(): void
    {
        $image = $this->localImage();
        $image->getStyle()->setWidth(0);

        $result = $this->inspect($image);
        self::assertContains('image_width_missing', $result['codes']);
        self::assertNull($result['facts']['widthEmu']);
    }

    public function testZeroHeightIsRejected(): void
    {
        $image = $this->localImage();
        $image->getStyle()->setHeight(0);

        self::assertContains('image_height_missing', $this->inspect($image)['codes']);
    }

    public function testSizeBeyondTheThirtyTwoBitEmuFieldIsRejected(): void
    {
        $image = $this->localImage();
        $image->getStyle()->setWidth(200000);

        $result = $this->inspect($image);
        self::assertContains('image_width_not_exactly_representable', $result['codes']);
        self::assertNull($result['facts']['widthEmu']);
    }

    public function testFractionalSizeIsRejected(): void
    {
        $image = $this->localImage();
        $image->getStyle()->setHeight(0.0000001);

        self::assertContains('image_height_not_exactly_representable', $this->inspect($image)['codes']);
    }

    public function testNonInlineWrapIsRejected(): void
    {
        $image = $this->localImage();
        $image->getStyle()->setWrap(Frame::WRAP_SQUARE);

        self::assertContains('image_placement_unrepresentable', $this->inspect($image)['codes']);
    }

    public function testExplicitAlignmentIsRejected(): void
    {
        $image = $this->localImage();
        $image->getStyle()->setAlignment('center');

        self::assertContains('image_placement_unrepresentable', $this->inspect($image)['codes']);
    }

    public function testExplicitOffsetIsRejected(): void
    {
        $image = $this->localImage();
        $image->getStyle()->setLeft(10);

        self::assertContains('image_placement_unrepresentable', $this->inspect($image)['codes']);
    }

    public function testWrapDistanceIsRejected(): void
    {
        $image = $this->localImage();
        $image->getStyle()->setWrapDistanceTop(5);

        self::assertContains('image_placement_unrepresentable', $this->inspect($image)['codes']);
    }

    public function testExplicitPositionIsRejected(): void
    {
        $image = $this->localImage();
        $image->getStyle()->setPos(Frame::POS_ABSOLUTE);

        self::assertContains('image_placement_unrepresentable', $this->inspect($image)['codes']);
    }

    public function testNonDefaultHorizontalPositionIsRejected(): void
    {
        $image = $this->localImage();
        $image->getStyle()->setHPos(Frame::POS_CENTER);

        self::assertContains('image_placement_unrepresentable', $this->inspect($image)['codes']);
    }

    public function testNonDefaultVerticalPositionIsRejected(): void
    {
        $image = $this->localImage();
        $image->getStyle()->setVPos(Frame::POS_BOTTOM);

        self::assertContains('image_placement_unrepresentable', $this->inspect($image)['codes']);
    }

    public function testNonDefaultHorizontalRelationIsRejected(): void
    {
        $image = $this->localImage();
        $image->getStyle()->setHPosRelTo(Frame::POS_RELTO_MARGIN);

        self::assertContains('image_placement_unrepresentable', $this->inspect($image)['codes']);
    }

    public function testNonDefaultVerticalRelationIsRejected(): void
    {
        $image = $this->localImage();
        $image->getStyle()->setVPosRelTo(Frame::POS_RELTO_TEXT);

        self::assertContains('image_placement_unrepresentable', $this->inspect($image)['codes']);
    }
}
