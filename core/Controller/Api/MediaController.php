<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Api;

use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;

/** Photo upload (Admin) and the public media route that serves stored files. */
final class MediaController extends ApiController
{
    private const UPLOAD_ERRORS = [
        UPLOAD_ERR_INI_SIZE => 'The file is larger than the server allows.',
        UPLOAD_ERR_FORM_SIZE => 'The file is larger than the form allows.',
        UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Please try again.',
        UPLOAD_ERR_NO_FILE => 'Choose an image to upload.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server has no place to keep the upload.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not save the upload.',
    ];

    /** POST /{org}/api/v1/admin/media (multipart form, field "file", optional "alt_text") */
    public function upload(Request $request): Response
    {
        $services = $this->authorize($request, 'products', 'edit');
        $tenant = $this->tenant($request);

        $file = $request->files()['file'] ?? null;
        if (!is_array($file) || !isset($file['tmp_name'], $file['error']) || is_array($file['tmp_name'])) {
            throw HttpException::unprocessable(['file' => 'Choose an image to upload.']);
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw HttpException::unprocessable(['file' => self::UPLOAD_ERRORS[$file['error']] ?? 'The upload failed.']);
        }
        // Only a file PHP itself received from the upload counts; a path cannot be supplied by the client.
        if (!is_uploaded_file((string) $file['tmp_name'])) {
            throw HttpException::unprocessable(['file' => 'The upload could not be verified.']);
        }

        $media = $services->media()->upload((string) $file['tmp_name'], (string) ($file['name'] ?? 'upload'), $this->text($request, 'alt_text'), $this->actorId($request));
        $base = $request->basePath() . '/media/' . $tenant->organization->slug . '/' . $media['id'];

        return Response::ok($media + ['url' => $base, 'thumb_url' => $base . '?thumb=1'], 201);
    }

    /**
     * GET /media/{org}/{id}[?thumb=1]
     * Streams a stored photo with long cache headers and an ETag, so browsers and the POS service worker can keep it.
     */
    public function serve(Request $request): Response
    {
        $services = $this->publicServices($request);
        $thumbnail = ($request->query()['thumb'] ?? '') === '1';

        $media = $services->media()->read((string) $request->param('id', ''), $thumbnail);
        if ($media === null) {
            throw HttpException::notFound('That image could not be found.');
        }

        $headers = [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => $media['etag'],
            'Content-Disposition' => 'inline',
        ];

        if ($request->header('if-none-match') === $media['etag']) {
            return Response::notModified($headers);
        }

        return Response::bytes($media['bytes'], $media['mime'], 200, $headers);
    }
}
