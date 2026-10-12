<?php
declare(strict_types=1);

namespace Neos\Api\Tests\Functional\Endpoint;

use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Psr7\UploadedFile;
use GuzzleHttp\Psr7\Utils;
use Neos\Media\Domain\Model\AssetCollection;
use Neos\Media\Domain\Model\Tag;
use Neos\Media\Domain\Repository\AssetCollectionRepository;
use Neos\Media\Domain\Repository\TagRepository;
use Neos\Neos\Domain\Service\UserService;
use Neos\Utility\ObjectAccess;
use PHPUnit\Framework\Attributes\Test;

class AssetsTest extends MediaTestCase
{
    private string $reportId;

    private string $flyerId;

    private string $logoId;

    private string $pressId;

    private string $financeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->objectManager->get(UserService::class)->createUser('editor', 'password', 'Edith', 'Editor', ['Neos.Neos:Editor']);
        $this->addMachineClient('editor-machine', 'editor');

        $finance = new Tag('Finance');
        $this->objectManager->get(TagRepository::class)->add($finance);
        $press = new AssetCollection('Press');
        $this->objectManager->get(AssetCollectionRepository::class)->add($press);

        $report = $this->addDocument('annual-report.pdf');
        $report->setTitle('Annual report');
        $report->setCaption('The figures of 2025');
        $report->addTag($finance);
        $press->addAsset($report);
        $flyer = $this->addDocument('flyer.pdf');
        $logo = $this->addImage('logo.png');
        $logo->setCopyrightNotice('Neos');
        foreach ([[$report, '2026-01-01'], [$flyer, '2026-03-01'], [$logo, '2026-02-01']] as [$asset, $lastModified]) {
            ObjectAccess::setProperty($asset, 'lastModified', new \DateTime($lastModified . 'T12:00:00+00:00'), true);
        }
        $this->persistenceManager->persistAll();

        $this->reportId = $this->persistenceManager->getIdentifierByObject($report);
        $this->flyerId = $this->persistenceManager->getIdentifierByObject($flyer);
        $this->logoId = $this->persistenceManager->getIdentifierByObject($logo);
        $this->pressId = $this->persistenceManager->getIdentifierByObject($press);
        $this->financeId = $this->persistenceManager->getIdentifierByObject($finance);
    }

    #[Test]
    public function listsTheAssetSources(): void
    {
        $response = $this->get('/api/assets', $this->token('editor-machine', 'assets.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $neos = self::json($response)['data'][0];
        self::assertSame('neos', $neos['id']);
        self::assertFalse($neos['isReadOnly']);
        self::assertTrue($neos['supportsTagging']);
        self::assertTrue($neos['supportsCollections']);
        self::assertTrue($neos['supportsSorting']);
        self::assertSame(count(self::json($response)['data']), self::json($response)['meta']['total']);
    }

    #[Test]
    public function listsTheAssetsNewestFirst(): void
    {
        $response = $this->get('/api/assets/neos', $this->token('editor-machine', 'assets.read'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(['total' => 3], self::json($response)['meta']);
        self::assertSame([$this->flyerId, $this->logoId, $this->reportId], array_column(self::json($response)['data'], 'id'));
        $report = self::json($response)['data'][2];
        self::assertSame([
            'assetSourceId' => 'neos',
            'id' => $this->reportId,
            'localAssetId' => $this->reportId,
            'label' => 'Annual report',
            'filename' => 'annual-report.pdf',
            'mediaType' => 'application/pdf',
            'assetType' => 'document',
            'width' => null,
            'height' => null,
            'isReadOnly' => false,
            'title' => 'Annual report',
            'caption' => 'The figures of 2025',
            'copyrightNotice' => '',
            'tags' => [['id' => $this->financeId, 'label' => 'Finance']],
            'collections' => [['id' => $this->pressId, 'title' => 'Press']],
            'originalAssetId' => null,
            'crop' => null,
            'iptc' => null,
        ], array_diff_key($report, ['fileSize' => true, 'lastModified' => true, 'thumbnailUri' => true, 'previewUri' => true]));
        // stored without its time zone, read in the server's
        self::assertStringStartsWith('2026-01-01T12:00:00', $report['lastModified']);
        $logo = self::json($response)['data'][1];
        self::assertSame(['image', 1, 1], [$logo['assetType'], $logo['width'], $logo['height']]);
    }

    #[Test]
    public function filtersTheAssets(): void
    {
        $token = $this->token('editor-machine', 'assets.read');
        $ids = fn (string $query) => array_column(self::json($this->get('/api/assets/neos?' . $query, $token))['data'], 'id');

        self::assertSame([$this->logoId], $ids('filterByAssetType=image'));
        self::assertSame([$this->flyerId, $this->reportId], $ids('filterByAssetType=document'));
        self::assertSame([$this->reportId], $ids('filterByAssetCollection=' . $this->pressId));
        self::assertSame([$this->reportId], $ids('filterByTag=' . $this->financeId));
        self::assertSame([$this->flyerId, $this->logoId], $ids('filterByUntagged=true'));
        self::assertSame([$this->reportId], $ids('search=figures'));
        self::assertSame([$this->flyerId], $ids('search=flyer&filterByAssetType=document'));
    }

    #[Test]
    public function sortsAndPagesTheAssets(): void
    {
        $token = $this->token('editor-machine', 'assets.read');

        $byName = self::json($this->get('/api/assets/neos?sort=name', $token));
        self::assertSame([$this->reportId, $this->flyerId, $this->logoId], array_column($byName['data'], 'id'));

        $page = self::json($this->get('/api/assets/neos?sort=lastModified&offset=1&limit=1', $token));
        self::assertSame([$this->logoId], array_column($page['data'], 'id'));
        self::assertSame(['total' => 3], $page['meta']);
        self::assertNotNull($page['links']['next']);
    }

    #[Test]
    public function rejectsInvalidListings(): void
    {
        $token = $this->token('editor-machine', 'assets.read');

        self::assertSame(400, $this->get('/api/assets/neos?search=x&filterByTag=' . $this->financeId, $token)->getStatusCode());
        self::assertSame(400, $this->get('/api/assets/neos?filterByUntagged=true&filterByTag=' . $this->financeId, $token)->getStatusCode());
        self::assertSame(400, $this->get('/api/assets/neos?sort=size', $token)->getStatusCode());
        self::assertSame(400, $this->get('/api/assets/neos?filterByTag=' . self::UNKNOWN_ID, $token)->getStatusCode());
        self::assertSame(400, $this->get('/api/assets/neos?filterByAssetCollection=' . self::UNKNOWN_ID, $token)->getStatusCode());
        self::assertSame(400, $this->get('/api/assets/neos?filterByAssetType=pdf', $token)->getStatusCode());
        self::assertSame(404, $this->get('/api/assets/unknown', $token)->getStatusCode());
    }

    #[Test]
    public function getsAnAsset(): void
    {
        $token = $this->token('editor-machine', 'assets.read');

        // after an image filter, the neos source would find no documents if its filters weren't reset
        $this->get('/api/assets/neos?filterByAssetType=image', $token);
        $response = $this->get('/api/assets/neos/' . $this->reportId, $token);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('Annual report', self::json($response)['label']);

        self::assertSame(404, $this->get('/api/assets/neos/' . self::UNKNOWN_ID, $token)->getStatusCode());
        self::assertSame(404, $this->get('/api/assets/unknown/' . $this->reportId, $token)->getStatusCode());
    }

    #[Test]
    public function needsTheScope(): void
    {
        self::assertSame(403, $this->get('/api/assets/neos', $this->token('editor-machine', 'assettags.read'))->getStatusCode());
    }

    #[Test]
    public function listsTheUsagesTheAccountMayRead(): void
    {
        $this->requireContentRepository();
        $token = $this->token('editor-machine', 'assets.read');

        $none = $this->get('/api/assets/neos/' . $this->reportId . '/usage', $token);
        self::assertSame(200, $none->getStatusCode(), (string)$none->getBody());
        self::assertSame(['data' => [], 'meta' => ['total' => 0, 'inaccessible' => 0]], self::json($none));

        // a usage in a workspace that doesn't exist (or the account may not read) is one it can't see
        $this->objectManager->get(EntityManagerInterface::class)->getConnection()->insert('neos_asset_usage', [
            'contentrepositoryid' => 'default',
            'assetid' => $this->reportId,
            'workspacename' => 'someone-elses',
            'nodeaggregateid' => 'some-node',
            'origindimensionspacepoint' => '{}',
            'origindimensionspacepointhash' => '',
            'propertyname' => 'document',
        ]);
        $hidden = $this->get('/api/assets/neos/' . $this->reportId . '/usage', $token);
        self::assertSame(200, $hidden->getStatusCode(), (string)$hidden->getBody());
        self::assertSame(['data' => [], 'meta' => ['total' => 0, 'inaccessible' => 1]], self::json($hidden));

        self::assertSame(404, $this->get('/api/assets/neos/' . self::UNKNOWN_ID . '/usage', $token)->getStatusCode());
    }

    #[Test]
    public function updatesTheMetadataOfAnAsset(): void
    {
        $this->requireContentRepository();
        $token = $this->token('editor-machine', 'assets.update');

        $response = $this->patch('/api/assets/neos/' . $this->flyerId, $token, ['title' => 'Flyer', 'copyrightNotice' => 'ACME', 'tagIds' => [$this->financeId, $this->financeId], 'assetCollectionIds' => [$this->pressId]]);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $flyer = self::json($response);
        self::assertSame(['Flyer', 'Flyer', '', 'ACME'], [$flyer['label'], $flyer['title'], $flyer['caption'], $flyer['copyrightNotice']]);
        self::assertSame([['id' => $this->financeId, 'label' => 'Finance']], $flyer['tags']);
        self::assertSame([['id' => $this->pressId, 'title' => 'Press']], $flyer['collections']);

        $cleared = self::json($this->patch('/api/assets/neos/' . $this->flyerId, $token, ['tagIds' => [], 'assetCollectionIds' => []]));
        self::assertSame([[], [], 'Flyer'], [$cleared['tags'], $cleared['collections'], $cleared['title']]);

        $this->persistenceManager->clearState();
        $read = self::json($this->get('/api/assets/neos/' . $this->flyerId, $this->token('editor-machine', 'assets.read')));
        self::assertSame([[], [], 'ACME'], [$read['tags'], $read['collections'], $read['copyrightNotice']]);
        self::assertSame(1, self::json($this->get('/api/assetcollections/' . $this->pressId, $this->token('editor-machine', 'assetcollections.read')))['assetCount']);
    }

    #[Test]
    public function rejectsInvalidUpdates(): void
    {
        $token = $this->token('editor-machine', 'assets.update');

        self::assertSame(422, $this->patch('/api/assets/neos/' . $this->flyerId, $token, ['tagIds' => [self::UNKNOWN_ID]])->getStatusCode());
        self::assertSame(422, $this->patch('/api/assets/neos/' . $this->flyerId, $token, ['assetCollectionIds' => [self::UNKNOWN_ID]])->getStatusCode());
        self::assertSame(404, $this->patch('/api/assets/neos/' . self::UNKNOWN_ID, $token, ['title' => 'x'])->getStatusCode());
        self::assertSame(404, $this->patch('/api/assets/unknown/' . $this->flyerId, $token, ['title' => 'x'])->getStatusCode());
    }

    #[Test]
    public function tagsAndUntagsAnAsset(): void
    {
        $this->requireContentRepository();
        $token = $this->token('editor-machine', 'assets.update');
        $path = '/api/assets/neos/' . $this->flyerId . '/tags/' . $this->financeId;

        $tagged = $this->put($path, $token, []);
        self::assertSame(200, $tagged->getStatusCode(), (string)$tagged->getBody());
        self::assertSame([$this->financeId], array_column(self::json($tagged)['tags'], 'id'));
        self::assertSame(200, $this->put($path, $token, [])->getStatusCode());

        $untagged = $this->delete($path, $token);
        self::assertSame(200, $untagged->getStatusCode(), (string)$untagged->getBody());
        self::assertSame([], self::json($untagged)['tags']);
        self::assertSame(404, $this->delete($path, $token)->getStatusCode());
        self::assertSame(404, $this->put('/api/assets/neos/' . $this->flyerId . '/tags/' . self::UNKNOWN_ID, $token, [])->getStatusCode());
    }

    #[Test]
    public function addsAndRemovesAnAssetFromACollection(): void
    {
        $this->requireContentRepository();
        $token = $this->token('editor-machine', 'assets.update');
        $path = '/api/assets/neos/' . $this->flyerId . '/collections/' . $this->pressId;

        $added = $this->put($path, $token, []);
        self::assertSame(200, $added->getStatusCode(), (string)$added->getBody());
        self::assertSame([$this->pressId], array_column(self::json($added)['collections'], 'id'));
        self::assertSame(200, $this->put($path, $token, [])->getStatusCode());

        $removed = $this->delete($path, $token);
        self::assertSame(200, $removed->getStatusCode(), (string)$removed->getBody());
        self::assertSame([], self::json($removed)['collections']);
        self::assertSame(404, $this->delete($path, $token)->getStatusCode());
        self::assertSame(404, $this->put('/api/assets/neos/' . $this->flyerId . '/collections/' . self::UNKNOWN_ID, $token, [])->getStatusCode());

        $this->persistenceManager->clearState();
        self::assertSame([$this->reportId], array_column(self::json($this->get('/api/assets/neos?filterByAssetCollection=' . $this->pressId, $this->token('editor-machine', 'assets.read')))['data'], 'id'));
    }

    #[Test]
    public function deletesAnAssetThatIsNotInUse(): void
    {
        $this->requireContentRepository();
        $token = $this->token('editor-machine', 'assets.delete');

        $this->objectManager->get(EntityManagerInterface::class)->getConnection()->insert('neos_asset_usage', [
            'contentrepositoryid' => 'default',
            'assetid' => $this->reportId,
            'workspacename' => 'live',
            'nodeaggregateid' => 'some-node',
            'origindimensionspacepoint' => '{}',
            'origindimensionspacepointhash' => '',
            'propertyname' => 'document',
        ]);
        self::assertSame(409, $this->delete('/api/assets/neos/' . $this->reportId, $token)->getStatusCode());

        $response = $this->delete('/api/assets/neos/' . $this->flyerId, $token);
        self::assertSame(204, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(404, $this->delete('/api/assets/neos/' . $this->flyerId, $token)->getStatusCode());
    }

    #[Test]
    public function createsAnImageVariant(): void
    {
        $token = $this->token('editor-machine', 'assets.create');

        $response = $this->post('/api/assets/neos/' . $this->logoId . '/variants', $token, ['crop' => ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1]]);
        self::assertSame(201, $response->getStatusCode(), (string)$response->getBody());
        $variant = self::json($response);
        self::assertSame('../' . $variant['id'], $response->getHeaderLine('Location'));
        self::assertSame(['neos', 'image', $this->logoId, ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1]], [$variant['assetSourceId'], $variant['assetType'], $variant['originalAssetId'], $variant['crop']]);

        self::assertSame(422, $this->post('/api/assets/neos/' . $variant['id'] . '/variants', $token, ['crop' => ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1]])->getStatusCode());
        self::assertSame(422, $this->post('/api/assets/neos/' . $this->logoId . '/variants', $token, ['crop' => ['x' => 1, 'y' => 0, 'width' => 1, 'height' => 1]])->getStatusCode());
        self::assertSame(422, $this->post('/api/assets/neos/' . $this->flyerId . '/variants', $token, ['crop' => ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1]])->getStatusCode());
        self::assertSame(400, $this->post('/api/assets/neos/' . $this->logoId . '/variants', $token, ['crop' => ['x' => 0, 'y' => 0, 'width' => 0, 'height' => 1]])->getStatusCode());

        // variants aren't listed, they belong to their image
        self::assertSame(['total' => 3], self::json($this->get('/api/assets/neos', $this->token('editor-machine', 'assets.read')))['meta']);
    }

    #[Test]
    public function importsOnlyRemoteAssets(): void
    {
        $token = $this->token('editor-machine', 'assets.create');

        self::assertSame(422, $this->post('/api/assets/neos/' . $this->flyerId . '/import', $token, [])->getStatusCode());
        self::assertSame(404, $this->post('/api/assets/unknown/' . $this->flyerId . '/import', $token, [])->getStatusCode());
    }

    #[Test]
    public function uploadsAnAsset(): void
    {
        $token = $this->token('editor-machine', 'assets.create');

        $response = $this->postMultipart('/api/assets/neos', $token, ['title' => 'Price list', 'assetCollectionId' => $this->pressId, 'tagIds' => [$this->financeId]], ['resource' => self::file('%PDF-1.4 prices', 'prices.pdf')]);
        self::assertSame(201, $response->getStatusCode(), (string)$response->getBody());
        $asset = self::json($response);
        self::assertSame('neos/' . $asset['id'], $response->getHeaderLine('Location'));
        self::assertSame(['neos', 'document', 'prices.pdf', 'application/pdf', 'Price list'], [$asset['assetSourceId'], $asset['assetType'], $asset['filename'], $asset['mediaType'], $asset['title']]);
        self::assertSame([$this->financeId], array_column($asset['tags'], 'id'));
        self::assertSame([$this->pressId], array_column($asset['collections'], 'id'));
        self::assertSame(['total' => 4], self::json($this->get('/api/assets/neos', $this->token('editor-machine', 'assets.read')))['meta']);
    }

    #[Test]
    public function uploadingAFileTwiceAddsItOnce(): void
    {
        $this->requireContentRepository();
        $token = $this->token('editor-machine', 'assets.create');

        // the content of the flyer of setUp()
        $response = $this->postMultipart('/api/assets/neos', $token, ['tagIds' => [$this->financeId]], ['resource' => self::file('%PDF-1.4 flyer.pdf', 'copy.pdf')]);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame($this->flyerId, self::json($response)['id']);
        self::assertSame([$this->financeId], array_column(self::json($response)['tags'], 'id'));
        self::assertSame(['total' => 3], self::json($this->get('/api/assets/neos', $this->token('editor-machine', 'assets.read')))['meta']);
    }

    #[Test]
    public function rejectsInvalidUploads(): void
    {
        $token = $this->token('editor-machine', 'assets.create');
        $file = static fn () => ['resource' => self::file('%PDF-1.4 new', 'new.pdf')];

        self::assertSame(400, $this->postMultipart('/api/assets/neos', $token, ['title' => 'No file'], [])->getStatusCode());
        self::assertSame(400, $this->postMultipart('/api/assets/neos', $token, [], ['resource' => new UploadedFile(Utils::streamFor(''), 0, UPLOAD_ERR_INI_SIZE, 'big.pdf')])->getStatusCode());
        self::assertSame(400, $this->postMultipart('/api/assets/neos', $token, ['tagIds' => 'not-a-list'], $file())->getStatusCode());
        self::assertSame(400, $this->postMultipart('/api/assets/neos', $token, ['assetCollectionId' => 'not-an-id'], $file())->getStatusCode());
        self::assertSame(422, $this->postMultipart('/api/assets/neos', $token, ['tagIds' => [self::UNKNOWN_ID]], $file())->getStatusCode());
        self::assertSame(422, $this->postMultipart('/api/assets/neos', $token, ['assetCollectionId' => self::UNKNOWN_ID], $file())->getStatusCode());
        self::assertSame(404, $this->postMultipart('/api/assets/unknown', $token, [], $file())->getStatusCode());
        self::assertSame(['total' => 3], self::json($this->get('/api/assets/neos', $this->token('editor-machine', 'assets.read')))['meta']);
    }

    #[Test]
    public function replacesTheFileOfAnAsset(): void
    {
        $this->requireContentRepository();
        $token = $this->token('editor-machine', 'assets.update');
        $path = '/api/assets/neos/' . $this->reportId . '/resource';

        $response = $this->postMultipart($path, $token, [], ['resource' => self::file('%PDF-1.4 the new report', 'report-2026.pdf')]);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame([$this->reportId, 'report-2026.pdf', 'Annual report'], [self::json($response)['id'], self::json($response)['filename'], self::json($response)['title']]);

        $kept = $this->postMultipart($path, $token, ['keepOriginalFilename' => 'true'], ['resource' => self::file('%PDF-1.4 once more', 'other.pdf')]);
        self::assertSame('report-2026.pdf', self::json($kept)['filename']);

        self::assertSame(422, $this->postMultipart('/api/assets/neos/' . $this->logoId . '/resource', $token, [], ['resource' => self::file('%PDF-1.4', 'logo.pdf')])->getStatusCode());
        self::assertSame(400, $this->postMultipart($path, $token, ['keepOriginalFilename' => 'maybe'], ['resource' => self::file('%PDF-1.4', 'x.pdf')])->getStatusCode());
        self::assertSame(400, $this->postMultipart($path, $token, [], [])->getStatusCode());
        self::assertSame(404, $this->postMultipart('/api/assets/neos/' . self::UNKNOWN_ID . '/resource', $token, [], ['resource' => self::file('%PDF-1.4', 'x.pdf')])->getStatusCode());
    }

    private static function file(string $content, string $filename): UploadedFile
    {
        return new UploadedFile(Utils::streamFor($content), strlen($content), UPLOAD_ERR_OK, $filename);
    }
}
