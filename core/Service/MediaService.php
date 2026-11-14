<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Http\HttpException;
use Gfh\Core\Repository\MediaRepository;
use Gfh\Core\Support\Uuid;

/**
 * Product photo uploads and reads.
 *
 * Uploads are checked by their content, not their name or declared type: at most 5 MB, a real JPEG, PNG or WebP,
 * at most 24 megapixels. Every image is decoded and re-encoded with GD, which drops metadata and any hidden
 * payload, resized to at most 2000 px, and saved under a random name in storage/uploads/{org}/products/ with a
 * 400 px thumbnail. A media row records each file. Files are served only through /media/{org}/{id}.
 */
final class MediaService
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_PIXELS = 24000000;
    public const MAX_SIDE = 2000;
    public const THUMB_SIDE = 400;

    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(
        private readonly MediaRepository $repository,
        private readonly string $uploadRoot,
        private readonly string $organizationSlug,
    ) {
    }

    /**
     * Validates, re-encodes and stores an uploaded file.
     *
     * @param string $temporaryPath the uploaded file's temporary path (the controller has checked is_uploaded_file)
     * @return array{id: string, mime: string, width: int, height: int, bytes: int}
     * @throws HttpException 422 with a message on the "file" field
     */
    public function upload(string $temporaryPath, string $originalName, ?string $altText, ?string $uploadedBy): array
    {
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('The gd PHP extension is not enabled, so images cannot be processed.');
        }

        $size = is_file($temporaryPath) ? filesize($temporaryPath) : false;
        if ($size === false || $size === 0) {
            throw HttpException::unprocessable(['file' => 'The file is empty or could not be read.']);
        }
        if ($size > self::MAX_BYTES) {
            throw HttpException::unprocessable(['file' => 'The file is larger than 5 MB.']);
        }

        // The type comes from the file's own bytes.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($temporaryPath);
        if (!isset(self::TYPES[$mime]) || ($mime === 'image/webp' && !function_exists('imagewebp'))) {
            throw HttpException::unprocessable(['file' => 'Upload a JPEG, PNG or WebP image.']);
        }

        $info = @getimagesize($temporaryPath);
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw HttpException::unprocessable(['file' => 'That file is not a valid image.']);
        }
        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            throw HttpException::unprocessable(['file' => 'The image is too large (24 megapixels at most).']);
        }

        $image = @imagecreatefromstring((string) file_get_contents($temporaryPath));
        if ($image === false) {
            throw HttpException::unprocessable(['file' => 'That file is not a valid image.']);
        }

        $image = $this->fit($image, self::MAX_SIDE);
        $thumb = $this->fit($image, self::THUMB_SIDE, true);
        $width = imagesx($image);
        $height = imagesy($image);

        $main = $this->encode($image, $mime);
        $small = $this->encode($thumb, $mime);

        $extension = self::TYPES[$mime];
        $name = bin2hex(random_bytes(16));
        $directory = rtrim($this->uploadRoot, '/\\') . '/' . $this->organizationSlug . '/products';
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('The upload folder could not be created.');
        }

        $path = 'products/' . $name . '.' . $extension;
        $thumbPath = 'products/' . $name . '_t.' . $extension;
        $base = rtrim($this->uploadRoot, '/\\') . '/' . $this->organizationSlug . '/';
        if (file_put_contents($base . $path, $main, LOCK_EX) === false || file_put_contents($base . $thumbPath, $small, LOCK_EX) === false) {
            throw new \RuntimeException('The image could not be saved.');
        }

        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', '', basename(str_replace('\\', '/', $originalName))));
        $clean = $clean === '' ? 'upload.' . $extension : mb_substr($clean, 0, 150);
        $alt = $altText === null || trim($altText) === '' ? null : mb_substr(trim($altText), 0, 200);

        $id = $this->repository->insert($path, $thumbPath, $clean, $mime, strlen($main), $width, $height, $alt, $uploadedBy);

        return ['id' => $id, 'mime' => $mime, 'width' => $width, 'height' => $height, 'bytes' => strlen($main)];
    }

    /**
     * The bytes of a stored file for /media/{org}/{id}.
     *
     * @return array{bytes: string, mime: string, etag: string}|null null when the id is unknown or the file is gone
     */
    public function read(string $mediaId, bool $thumbnail): ?array
    {
        if (!Uuid::isValid($mediaId)) {
            return null;
        }

        $media = $this->repository->find($mediaId);
        if ($media === null) {
            return null;
        }

        $relative = $thumbnail && $media['thumb_path'] !== null ? (string) $media['thumb_path'] : (string) $media['path'];
        $base = realpath(rtrim($this->uploadRoot, '/\\') . '/' . $this->organizationSlug);
        $file = $base === false ? false : realpath($base . '/' . $relative);

        // The path comes from our own table, but it is still checked to stay inside this organization's folder.
        if ($base === false || $file === false || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file)) {
            return null;
        }

        $bytes = file_get_contents($file);
        if ($bytes === false) {
            return null;
        }

        return ['bytes' => $bytes, 'mime' => (string) $media['mime'], 'etag' => '"' . $mediaId . ($thumbnail ? '-t' : '') . '"'];
    }

    /**
     * Scales down so the longest side is at most $max. Returns the same image when it already fits
     * (unless $copy, which always returns a separate image).
     */
    private function fit(\GdImage $image, int $max, bool $copy = false): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        if (max($width, $height) <= $max) {
            if (!$copy) {
                return $image;
            }
            $same = imagecreatetruecolor($width, $height);
            imagealphablending($same, false);
            imagesavealpha($same, true);
            imagecopy($same, $image, 0, 0, 0, 0, $width, $height);

            return $same;
        }

        $scale = $max / max($width, $height);
        $scaled = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)), IMG_BICUBIC);
        if ($scaled === false) {
            throw new \RuntimeException('The image could not be resized.');
        }
        imagesavealpha($scaled, true);

        return $scaled;
    }

    private function encode(\GdImage $image, string $mime): string
    {
        ob_start();
        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($image, null, 85),
            'image/png' => imagepng($image, null, 6),
            default => imagewebp($image, null, 82),
        };
        $bytes = (string) ob_get_clean();

        if (!$ok || $bytes === '') {
            throw new \RuntimeException('The image could not be encoded.');
        }

        return $bytes;
    }
}
