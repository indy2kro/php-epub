<?php

declare(strict_types=1);

namespace PhpEpub\Cleanup;

/**
 * Re-encodes JPEG and PNG images with the GD extension: scales them down (never up) and
 * recompresses them, and can turn a PNG without transparency into a JPEG. Images it cannot
 * improve or should not touch (animated PNG, CMYK JPEG, JPEG with an EXIF orientation, corrupt
 * data, very large images) are left alone.
 *
 * @internal
 */
final readonly class ImageRecompressor
{
    /**
     * Images with more pixels than this are not decoded (GD needs about 5 bytes per pixel).
     */
    private const int MAX_PIXELS = 40_000_000;

    public static function isAvailable(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatefromstring') && function_exists('imagejpeg') && function_exists('imagepng');
    }

    /**
     * The better encoding of an image, or null when there is none (or the result would not be smaller).
     *
     * @return array{data: string, mediaType: string}|null
     */
    public function recompress(string $data, ?int $maxWidth, ?int $maxHeight, int $jpegQuality, bool $convertOpaquePngToJpeg): ?array
    {
        if (! self::isAvailable()) {
            return null;
        }

        $info = @getimagesizefromstring($data);
        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            return null;
        }

        [$width, $height] = $info;
        $isJpeg = $info[2] === IMAGETYPE_JPEG;
        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS) {
            return null;
        }

        // GD cannot read CMYK JPEG faithfully, drops the EXIF orientation, and flattens animated PNG.
        if (($isJpeg && (($info['channels'] ?? 3) === 4 || $this->orientation($data) > 1)) || (! $isJpeg && str_contains($data, 'acTL'))) {
            return null;
        }

        $scale = min(1.0, $maxWidth === null ? 1.0 : $maxWidth / $width, $maxHeight === null ? 1.0 : $maxHeight / $height);
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $source = @imagecreatefromstring($data);
        if ($source === false) {
            return null;
        }

        $image = $source;
        if ($newWidth !== $width || $newHeight !== $height) {
            $image = $this->scaled($source, $newWidth, $newHeight, ! $isJpeg);
            if ($image === null) {
                return null;
            }
        }

        $candidates = [];
        if ($isJpeg) {
            $candidates[] = [$this->encodeJpeg($image, $jpegQuality), 'image/jpeg'];
        } else {
            $candidates[] = [$this->encodePng($image), 'image/png'];
            if ($convertOpaquePngToJpeg && $this->isOpaque($data, $source)) {
                $candidates[] = [$this->encodeJpeg($image, $jpegQuality), 'image/jpeg'];
            }
        }

        $best = null;
        foreach ($candidates as [$encoded, $mediaType]) {
            if ($encoded !== null && strlen($encoded) < strlen($data) && ($best === null || strlen($encoded) < strlen($best['data']))) {
                $best = ['data' => $encoded, 'mediaType' => $mediaType];
            }
        }

        return $best;
    }

    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    private function scaled(\GdImage $source, int $width, int $height, bool $keepAlpha): ?\GdImage
    {
        $target = imagecreatetruecolor($width, $height);
        if ($target === false) {
            return null;
        }

        if ($keepAlpha) {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
            if ($transparent !== false) {
                imagefilledrectangle($target, 0, 0, $width, $height, $transparent);
            }
        }

        if (! imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source))) {
            return null;
        }

        // An indexed image stays indexed: a truecolor copy would be much larger.
        if ($keepAlpha && ! imageistruecolor($source)) {
            imagetruecolortopalette($target, false, 256);
        }

        return $target;
    }

    private function encodeJpeg(\GdImage $image, int $quality): ?string
    {
        // Progressive JPEG is usually smaller.
        imageinterlace($image, true);
        ob_start();
        $written = imagejpeg($image, null, $quality);
        $data = (string) ob_get_clean();

        return $written && $data !== '' ? $data : null;
    }

    private function encodePng(\GdImage $image): ?string
    {
        imagesavealpha($image, true);
        ob_start();
        $written = imagepng($image, null, 9);
        $data = (string) ob_get_clean();

        return $written && $data !== '' ? $data : null;
    }

    /**
     * Whether no pixel of the PNG is transparent.
     */
    private function isOpaque(string $png, \GdImage $image): bool
    {
        // IHDR colour type: 4 and 6 have an alpha channel; the others only through a tRNS chunk.
        $colourType = ord($png[25] ?? "\0");
        if ($colourType !== 4 && $colourType !== 6) {
            return ! str_contains($png, 'tRNS');
        }

        $width = imagesx($image);
        $height = imagesy($image);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $colour = imagecolorat($image, $x, $y);
                if ($colour !== false && (($colour >> 24) & 0x7F) !== 0) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * The EXIF orientation of a JPEG (1 when it has none).
     */
    private function orientation(string $jpeg): int
    {
        $length = strlen($jpeg);
        $offset = 2;
        while ($offset + 4 <= $length && $jpeg[$offset] === "\xFF") {
            $marker = ord($jpeg[$offset + 1]);
            if ($marker === 0xDA || $marker === 0xD9) {
                break;
            }

            $size = $this->unsigned($jpeg, 'n', $offset + 2);
            if ($marker === 0xE1 && substr($jpeg, $offset + 4, 6) === "Exif\0\0") {
                return $this->tiffOrientation(substr($jpeg, $offset + 10, max(0, $size - 8)));
            }

            $offset += 2 + max(2, $size);
        }

        return 1;
    }

    private function tiffOrientation(string $tiff): int
    {
        if (strlen($tiff) < 8) {
            return 1;
        }

        $little = str_starts_with($tiff, 'II');
        $short = $little ? 'v' : 'n';
        $long = $little ? 'V' : 'N';
        $ifd = $this->unsigned($tiff, $long, 4);
        if ($ifd < 8 || $ifd + 2 > strlen($tiff)) {
            return 1;
        }

        $count = $this->unsigned($tiff, $short, $ifd);
        for ($i = 0; $i < $count && $ifd + 2 + ($i + 1) * 12 <= strlen($tiff); $i++) {
            $entry = $ifd + 2 + $i * 12;
            if ($this->unsigned($tiff, $short, $entry) === 0x0112) {
                return $this->unsigned($tiff, $short, $entry + 8);
            }
        }

        return 1;
    }

    private function unsigned(string $data, string $format, int $offset): int
    {
        $values = @unpack($format, $data, $offset);

        return is_array($values) && is_int($values[1] ?? null) ? $values[1] : 0;
    }
}
