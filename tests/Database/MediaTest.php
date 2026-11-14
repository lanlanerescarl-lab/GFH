<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Tests\Support\DatabaseTestCase;

final class MediaTest extends DatabaseTestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('The gd extension is not enabled.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }
        if (isset($this->shop)) {
            $this->shop->removeUploads();
        }
        parent::tearDown();
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gfh-up-');
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 49, 130, 189));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public function testAValidImageIsReEncodedStoredUnderARandomNameWithAThumbnail(): void
    {
        $media = $this->shop->services->media();

        $result = $media->upload($this->temporaryFile($this->png(60, 40)), '../../evil name.png', 'A blue square', null);

        $this->assertSame('image/png', $result['mime']);
        $this->assertSame(60, $result['width']);
        $row = $this->org->query('SELECT path, thumb_path, original_name, alt_text FROM media')->fetch();
        $this->assertMatchesRegularExpression('#^products/[0-9a-f]{32}\.png$#', $row['path'], 'a random name, never the uploaded one');
        $this->assertMatchesRegularExpression('#^products/[0-9a-f]{32}_t\.png$#', $row['thumb_path']);
        $this->assertSame('evil name.png', $row['original_name'], 'folders are stripped from the original name');
        $this->assertFileExists($this->shop->uploadRoot . '/test-org/' . $row['path']);
        $this->assertFileExists($this->shop->uploadRoot . '/test-org/' . $row['thumb_path']);

        $read = $media->read($result['id'], false);
        $this->assertNotNull($read);
        $this->assertStringStartsWith("\x89PNG", $read['bytes']);
        $this->assertSame('image/png', $read['mime']);
        $this->assertSame('"' . $result['id'] . '"', $read['etag']);
        $this->assertSame('"' . $result['id'] . '-t"', $media->read($result['id'], true)['etag']);
    }

    public function testHiddenPayloadsAreStrippedByReEncoding(): void
    {
        $withPayload = $this->png(20, 20) . '<?php echo "owned"; ?>';

        $result = $this->shop->services->media()->upload($this->temporaryFile($withPayload), 'photo.png', null, null);
        $stored = (string) file_get_contents($this->shop->uploadRoot . '/test-org/' . $this->org->query('SELECT path FROM media')->fetchColumn());

        $this->assertStringNotContainsString('<?php', $stored);
        $this->assertNotNull($this->shop->services->media()->read($result['id'], false));
    }

    public function testTheTypeComesFromTheContentNotTheNameOrTheDeclaredType(): void
    {
        $media = $this->shop->services->media();

        foreach (['<?php echo 1;', "GIF89a\x01\x00\x01\x00", 'just some text', ''] as $contents) {
            $error = $this->assertHttpStatus(422, fn () => $media->upload($this->temporaryFile($contents), 'holiday.jpg', null, null));
            $this->assertArrayHasKey('file', $error->fields());
        }
        $this->assertSame(0, (int) $this->shop->scalar('SELECT COUNT(*) FROM media'));
    }

    public function testFilesOverFiveMegabytesAreRefused(): void
    {
        $big = $this->temporaryFile($this->png(10, 10) . str_repeat("\0", 5 * 1024 * 1024));

        $error = $this->assertHttpStatus(422, fn () => $this->shop->services->media()->upload($big, 'big.png', null, null));

        $this->assertStringContainsString('5 MB', $error->fields()['file']);
    }

    public function testLargeImagesAreScaledDown(): void
    {
        $result = $this->shop->services->media()->upload($this->temporaryFile($this->png(3000, 100)), 'wide.png', null, null);

        $this->assertSame(2000, $result['width']);
        $this->assertSame(67, $result['height']);
    }

    public function testOnlyFilesInsideTheOrganizationsFolderAreEverRead(): void
    {
        $media = $this->shop->services->media();
        $result = $media->upload($this->temporaryFile($this->png(10, 10)), 'a.png', null, null);

        $this->org->prepare('UPDATE media SET path = ? WHERE id = ?')->execute(['../../../../etc/hostname', $result['id']]);

        $this->assertNull($media->read($result['id'], false));
        $this->assertNull($media->read('not-an-id', false));
        $this->assertNull($media->read('3f2b8c1e-9d4a-4b7e-8a61-5c0d2e9f7a10', false));
    }
}
