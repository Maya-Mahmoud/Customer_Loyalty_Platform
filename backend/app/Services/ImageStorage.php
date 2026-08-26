<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Storing an uploaded picture — an avatar or a store logo.
 *
 * The file is never stored as it arrived. It is decoded, resized and re-encoded, so
 * what ends up on disk is bytes this application wrote. That is the whole point:
 * an uploaded image is the classic way to place a payload on a web root, and a
 * re-encode drops everything that is not pixels — a PHP tail after the image data,
 * a crafted SVG, EXIF with a script in it.
 *
 * It also keeps the files small. An avatar shown at 40 pixels does not need the
 * four megabytes a phone camera produces, and a store on a slow connection (BRD
 * RSK-04) pays for every one of them.
 */
class ImageStorage
{
    /** Long enough for a retina avatar, small enough to never matter. */
    private const MAX_DIMENSION = 512;

    private const QUALITY = 82;

    /**
     * Stores the image and returns its path on the public disk. Any previous file
     * at $replacing is deleted afterwards, so an account that changes its picture
     * ten times leaves one file behind rather than ten.
     */
    public function store(UploadedFile $file, string $directory, ?string $replacing = null): string
    {
        $image = $this->decode($file);

        [$width, $height] = $this->fit(imagesx($image), imagesy($image));

        $resized = imagescale($image, $width, $height);
        imagedestroy($image);

        if ($resized === false) {
            throw ValidationException::withMessages([
                'image' => __('This image could not be processed. Try another one.'),
            ]);
        }

        $path = $directory . '/' . Str::uuid() . '.jpg';

        // JPEG on a white canvas: a transparent PNG flattened onto black is the
        // kind of surprise a shop owner discovers only after uploading their logo.
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $resized, 0, 0, 0, 0, $width, $height);
        imagedestroy($resized);

        ob_start();
        imagejpeg($canvas, null, self::QUALITY);
        $encoded = (string) ob_get_clean();
        imagedestroy($canvas);

        Storage::disk('public')->put($path, $encoded);

        $this->delete($replacing);

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        Storage::disk('public')->delete($path);
    }

    /**
     * Decodes the upload with GD rather than trusting the declared type. A file
     * named .jpg that GD cannot read is not an image, whatever the request said.
     */
    private function decode(UploadedFile $file): \GdImage
    {
        $contents = file_get_contents($file->getRealPath());

        $image = $contents === false ? false : @imagecreatefromstring($contents);

        if ($image === false) {
            throw ValidationException::withMessages([
                'image' => __('This file is not a readable image.'),
            ]);
        }

        return $image;
    }

    /**
     * Scales the longer side down to the cap and leaves smaller images alone —
     * enlarging a small picture only makes it blurry and bigger.
     *
     * @return array{int, int}
     */
    private function fit(int $width, int $height): array
    {
        $longest = max($width, $height);

        if ($longest <= self::MAX_DIMENSION) {
            return [$width, $height];
        }

        $ratio = self::MAX_DIMENSION / $longest;

        return [max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio))];
    }
}
