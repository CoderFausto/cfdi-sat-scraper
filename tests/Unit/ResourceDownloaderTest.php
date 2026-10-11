<?php

declare(strict_types=1);

namespace PhpCfdi\CfdiSatScraper\Tests\Unit;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use PhpCfdi\CfdiSatScraper\Contracts\ResourceDownloaderPromiseHandlerInterface;
use PhpCfdi\CfdiSatScraper\Contracts\ResourceDownloadHandlerInterface;
use PhpCfdi\CfdiSatScraper\Exceptions\DailyDownloadLimitException;
use PhpCfdi\CfdiSatScraper\Exceptions\LogicException;
use PhpCfdi\CfdiSatScraper\Internal\ResourceDownloaderPromiseHandler;
use PhpCfdi\CfdiSatScraper\Metadata;
use PhpCfdi\CfdiSatScraper\MetadataList;
use PhpCfdi\CfdiSatScraper\ResourceDownloader;
use PhpCfdi\CfdiSatScraper\ResourceType;
use PhpCfdi\CfdiSatScraper\SatHttpGateway;
use PhpCfdi\CfdiSatScraper\Tests\Fakes\FakeResourceDownloaderPromiseHandler;
use PhpCfdi\CfdiSatScraper\Tests\TestCase;
use PhpCfdi\CfdiSatScraper\URLS;
use PHPUnit\Framework\MockObject\MockObject;
use Traversable;

final class ResourceDownloaderTest extends TestCase
{
    private const QUOTA_ALLOWED_RESPONSE = '{"d":{"__type":"Sat.Cfdi.Iu.Contribuyente.Portal.RespuestaValidaDescarga",'
        . '"Codigo":0,"CantidadDisponible":2000,"CantidadTope":2000,"Mensaje":""}}';

    private const QUOTA_REACHED_RESPONSE = '{"d":{"__type":"Sat.Cfdi.Iu.Contribuyente.Portal.RespuestaValidaDescarga",'
        . '"Codigo":2,"CantidadDisponible":0,"CantidadTope":2000,'
        . '"Mensaje":"Ya no puedes descargar más documentos. Por seguridad únicamente se permite '
        . 'descargar un máximo de 2,000 archivos por día."}}';

    /**
     * @return SatHttpGateway&MockObject
     */
    private function createSatHttpGateway(): SatHttpGateway
    {
        return $this->createMock(SatHttpGateway::class);
    }

    public function testConstructDefaults(): void
    {
        $downloader = new ResourceDownloader($this->createSatHttpGateway(), ResourceType::xml());
        $this->assertFalse($downloader->hasMetadataList());
        $this->assertSame(ResourceDownloader::DEFAULT_CONCURRENCY, $downloader->getConcurrency());
    }

    public function testConstructWithValues(): void
    {
        $concurrency = ResourceDownloader::DEFAULT_CONCURRENCY + 10;
        $metadataList = new MetadataList([]);
        $downloader = new ResourceDownloader($this->createSatHttpGateway(), ResourceType::xml(), $metadataList, $concurrency);
        $this->assertTrue($downloader->hasMetadataList());
        $this->assertSame($metadataList, $downloader->getMetadataList());
        $this->assertSame($concurrency, $downloader->getConcurrency());
    }

    public function testGetMetadataListWithoutPropertySet(): void
    {
        $downloader = new ResourceDownloader($this->createSatHttpGateway(), ResourceType::xml());
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The metadata list has not been set');
        $downloader->getMetadataList();
    }

    public function testMetadataList(): void
    {
        $metadataList = new MetadataList([]);
        $downloader = new ResourceDownloader($this->createSatHttpGateway(), ResourceType::xml());
        $this->assertSame($downloader, $downloader->setMetadataList($metadataList), 'setMetadataList must be fluent');
        $this->assertSame($metadataList, $downloader->getMetadataList());
    }

    public function testSetConcurrency(): void
    {
        $downloader = new ResourceDownloader($this->createSatHttpGateway(), ResourceType::xml());
        $this->assertSame($downloader, $downloader->setConcurrency(100), 'setConcurrency must be fluent');
        $this->assertSame(100, $downloader->getConcurrency());
    }

    public function testSetConcurrencyLowerThanOne(): void
    {
        $downloader = new ResourceDownloader($this->createSatHttpGateway(), ResourceType::xml());
        $downloader->setConcurrency(0);
        $this->assertSame(1, $downloader->getConcurrency());
    }

    public function testMakePromises(): void
    {
        $medataArray = [
            $uuidWithoutLink = $this->fakes()->faker()->uuid => new Metadata($uuidWithoutLink),
        ] + $this->createMetadataArrayUsingUuids(...[
            $uuidWithUrlXml1 = $this->fakes()->faker()->uuid,
            $uuidWithUrlXml2 = $this->fakes()->faker()->uuid,
        ]);
        $downloader = new class ($this->createSatHttpGateway(), ResourceType::xml()) extends ResourceDownloader {
            public function makePromises(): Traversable
            {
                return parent::makePromises();
            }
        };
        $metadataList = new MetadataList($medataArray);
        $downloader->setMetadataList($metadataList);

        $generated = iterator_to_array($downloader->makePromises());
        /** @phpstan-ignore method.alreadyNarrowedType */
        $this->assertContainsOnlyInstancesOf(PromiseInterface::class, $generated);
        $this->assertCount(2, $generated);
        $this->assertArrayHasKey($uuidWithUrlXml1, $generated);
        $this->assertArrayHasKey($uuidWithUrlXml2, $generated);
        $this->assertArrayNotHasKey($uuidWithoutLink, $generated);
    }

    public function testMakePromiseHandler(): void
    {
        $resourceType = ResourceType::cancelVoucher();
        $downloader = new class ($this->createSatHttpGateway(), $resourceType) extends ResourceDownloader {
            public function makePromiseHandler(ResourceDownloadHandlerInterface $handler): ResourceDownloaderPromiseHandlerInterface
            {
                return parent::makePromiseHandler($handler);
            }
        };

        $downloadHandler = $this->createMock(ResourceDownloadHandlerInterface::class);
        $promiseHandler = $downloader->makePromiseHandler($downloadHandler);
        $this->assertInstanceOf(ResourceDownloaderPromiseHandler::class, $promiseHandler);
        $this->assertSame($resourceType, $promiseHandler->getResourceType());
        $this->assertSame($downloadHandler, $promiseHandler->getHandler());
    }

    public function testDownload(): void
    {
        // given this metadataList
        $metadataList = new MetadataList($this->createMetadataArrayUsingUuids(...[
            $uuidWithUrlXml0 = $this->fakes()->faker()->uuid,
            $this->fakes()->faker()->uuid,
            $uuidWithUrlXml2 = $this->fakes()->faker()->uuid,
        ]));

        // given a SatHttpGateway that allows the daily download quota
        $satHttpGateway = $this->createSatHttpGateway();
        $satHttpGateway->method('postValidateDownload')->willReturn(self::QUOTA_ALLOWED_RESPONSE);
        $satHttpGateway->method('getAsync')->willReturnCallback(
            fn (string $link): PromiseInterface => Create::promiseFor(new Response(200, body: '<cfdi UUID="x"/>')),
        );

        // given a well known behavior for a fake ResourceDownloaderPromiseHandlerInterface
        // the behavior is that only even entries are downloaded, odd entries are rejected
        $downloader = new class ($satHttpGateway, ResourceType::xml()) extends ResourceDownloader {
            /** @noinspection PhpMissingParentCallCommonInspection */
            public function makePromiseHandler(ResourceDownloadHandlerInterface $handler): ResourceDownloaderPromiseHandlerInterface
            {
                return new FakeResourceDownloaderPromiseHandler();
            }
        };
        $downloader->setMetadataList($metadataList);
        $downloader->setConcurrency(1);

        // when running download
        /** @var ResourceDownloadHandlerInterface&MockObject $downloadHandler */
        $downloadHandler = $this->createMock(ResourceDownloadHandlerInterface::class);
        $downloaded = $downloader->download($downloadHandler);

        // then will get only the odd uuids
        $expectedUuids = [$uuidWithUrlXml0, $uuidWithUrlXml2];
        $this->assertSame($expectedUuids, $downloaded);
    }

    public function testDownloadThrowsWhenDailyDownloadLimitWasReached(): void
    {
        $metadataList = new MetadataList($this->createMetadataArrayUsingUuids(
            $this->fakes()->faker()->uuid,
            $this->fakes()->faker()->uuid,
        ));

        $satHttpGateway = $this->createSatHttpGateway();
        $satHttpGateway->expects($this->once())
            ->method('postValidateDownload')
            ->willReturn(self::QUOTA_REACHED_RESPONSE);
        $satHttpGateway->expects($this->never())->method('getAsync');

        $downloader = new ResourceDownloader($satHttpGateway, ResourceType::xml(), $metadataList);

        $this->expectException(DailyDownloadLimitException::class);
        $this->expectExceptionMessage('daily download limit');
        $downloader->download($this->createMock(ResourceDownloadHandlerInterface::class));
    }

    public function testDownloadThrowsDailyDownloadLimitWhenResponseIsNotCfdi(): void
    {
        $metadataList = new MetadataList($this->createMetadataArrayUsingUuids($this->fakes()->faker()->uuid));

        $satHttpGateway = $this->createSatHttpGateway();
        $satHttpGateway->method('postValidateDownload')->willReturn(self::QUOTA_ALLOWED_RESPONSE);
        $satHttpGateway->method('getAsync')->willReturn(
            Create::promiseFor(new Response(200, body: 'Por seguridad únicamente se permite descargar un máximo de 2,000 archivos por día.')),
        );

        $downloader = new ResourceDownloader($satHttpGateway, ResourceType::xml(), $metadataList);

        $this->expectException(DailyDownloadLimitException::class);
        $downloader->download($this->createMock(ResourceDownloadHandlerInterface::class));
    }

    public function testDownloadSkipsQuotaValidationWhenThereAreNoLinks(): void
    {
        $metadataList = new MetadataList(['uuid' => new Metadata('uuid')]);

        $satHttpGateway = $this->createSatHttpGateway();
        $satHttpGateway->expects($this->never())->method('postValidateDownload');

        $downloader = new ResourceDownloader($satHttpGateway, ResourceType::xml(), $metadataList);
        $downloaded = $downloader->download($this->createMock(ResourceDownloadHandlerInterface::class));

        $this->assertSame([], $downloaded);
    }

    public function testValidateDailyDownloadQuotaDoesNotRunForCancelResourceType(): void
    {
        $metadataList = new MetadataList([
            'uuid' => new Metadata('uuid', [ResourceType::cancelVoucher()->value() => 'https://example.com/x']),
        ]);

        $satHttpGateway = $this->createSatHttpGateway();
        $satHttpGateway->expects($this->never())->method('postValidateDownload');

        $downloader = new ResourceDownloader($satHttpGateway, ResourceType::cancelVoucher(), $metadataList);
        $downloader->validateDailyDownloadQuota();
    }

    public function testGetAndSetValidateDownloadUrl(): void
    {
        $downloader = new ResourceDownloader($this->createSatHttpGateway(), ResourceType::xml());
        $this->assertSame(URLS::PORTAL_CFDI_VALIDATE_DOWNLOAD_EMISOR, $downloader->getValidateDownloadUrl());
        $this->assertSame($downloader, $downloader->setValidateDownloadUrl('foo'));
        $this->assertSame('foo', $downloader->getValidateDownloadUrl());
    }
}
