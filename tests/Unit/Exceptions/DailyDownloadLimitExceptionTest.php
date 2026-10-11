<?php

declare(strict_types=1);

namespace PhpCfdi\CfdiSatScraper\Tests\Unit\Exceptions;

use PhpCfdi\CfdiSatScraper\Exceptions\DailyDownloadLimitException;
use PhpCfdi\CfdiSatScraper\Exceptions\SatException;
use PhpCfdi\CfdiSatScraper\Tests\TestCase;
use RuntimeException;

final class DailyDownloadLimitExceptionTest extends TestCase
{
    private const PORTAL_MESSAGE = 'Ya no puedes descargar más documentos. Por seguridad únicamente '
        . 'se permite descargar un máximo de 2,000 archivos por día.';

    public function testHierarchy(): void
    {
        $exception = new DailyDownloadLimitException('message');
        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertInstanceOf(SatException::class, $exception);
    }

    public function testFromPortalResponseWithJsonLimitReached(): void
    {
        $json = json_encode([
            'd' => [
                '__type' => 'Sat.Cfdi.Iu.Contribuyente.Portal.RespuestaValidaDescarga',
                'Codigo' => 2,
                'CantidadDisponible' => 0,
                'CantidadTope' => 2000,
                'Mensaje' => self::PORTAL_MESSAGE,
            ],
        ], JSON_UNESCAPED_UNICODE) ?: '';

        $exception = DailyDownloadLimitException::fromPortalResponse($json);

        $this->assertInstanceOf(DailyDownloadLimitException::class, $exception);
        $this->assertSame(self::PORTAL_MESSAGE, $exception->getPortalMessage());
        $this->assertSame(0, $exception->getAvailable());
        $this->assertSame(2000, $exception->getLimit());
        $this->assertStringContainsString('daily download limit', $exception->getMessage());
        $this->assertStringContainsString(self::PORTAL_MESSAGE, $exception->getMessage());
    }

    public function testFromPortalResponseWithJsonWithoutWrapper(): void
    {
        $json = '{"Codigo":1,"CantidadDisponible":10,"CantidadTope":2000,"Mensaje":"Estás intentando descargar más documentos de los permitidos."}';

        $exception = DailyDownloadLimitException::fromPortalResponse($json);

        $this->assertInstanceOf(DailyDownloadLimitException::class, $exception);
        $this->assertSame(10, $exception->getAvailable());
        $this->assertSame(2000, $exception->getLimit());
    }

    public function testFromPortalResponseWithJsonAllowed(): void
    {
        $json = '{"d":{"__type":"Sat.Cfdi.Iu.Contribuyente.Portal.RespuestaValidaDescarga","Codigo":0,'
            . '"CantidadDisponible":2000,"CantidadTope":2000,"Mensaje":""}}';

        $this->assertNull(DailyDownloadLimitException::fromPortalResponse($json));
    }

    public function testFromPortalResponseWithHtmlQuotaField(): void
    {
        $html = '<input type="hidden" name="ctl00$MainContent$hfDescarga" id="hfDescarga" value="CuotaCompleta" />';

        $exception = DailyDownloadLimitException::fromPortalResponse($html);

        $this->assertInstanceOf(DailyDownloadLimitException::class, $exception);
    }

    public function testFromPortalResponseWithHtmlDailyLimitText(): void
    {
        $html = '<div>Por seguridad únicamente se permite descargar un máximo de 2,000 archivos por día.</div>';

        $exception = DailyDownloadLimitException::fromPortalResponse($html);

        $this->assertInstanceOf(DailyDownloadLimitException::class, $exception);
    }

    public function testFromPortalResponseWithRegularPageIsNotDetected(): void
    {
        // The regular consultation page always includes the "hfDescarga" field (empty) and the
        // alert text inside the JavaScript, it must not be confused with the daily limit warning.
        $html = '<input type="hidden" name="ctl00$MainContent$hfDescarga" id="hfDescarga" />'
            . '<script>newDivSuccess.innerHTML = "<strong>¡Atención! <br/></strong> Ya no puedes descargar más documentos, espera hasta mañana.";</script>';

        $this->assertNull(DailyDownloadLimitException::fromPortalResponse($html));
    }

    public function testFromPortalResponseWithUnrelatedContent(): void
    {
        $this->assertNull(DailyDownloadLimitException::fromPortalResponse(''));
        $this->assertNull(DailyDownloadLimitException::fromPortalResponse('<root/>'));
        $this->assertNull(DailyDownloadLimitException::fromPortalResponse('{"error":"whatever"}'));
    }
}
