<?php

declare(strict_types=1);

namespace PhpCfdi\CfdiSatScraper\Exceptions;

use JsonException;
use RuntimeException;
use Throwable;

/**
 * This exception is thrown when the SAT portal reports that the daily download limit was reached.
 *
 * The portal enforces a maximum of 2,000 documents per day. It reports this condition through the
 * `ValidarDescarga` web method as a JSON document (inside the `d` property) that looks like this:
 *
 * ```json
 * {
 *   "d": {
 *     "__type": "Sat.Cfdi.Iu.Contribuyente.Portal.RespuestaValidaDescarga",
 *     "Codigo": 2,
 *     "CantidadDisponible": 0,
 *     "CantidadTope": 2000,
 *     "Mensaje": "Ya no puedes descargar más documentos. Por seguridad únicamente se permite descargar un máximo de 2,000 archivos por día."
 *   }
 * }
 * ```
 */
class DailyDownloadLimitException extends RuntimeException implements SatException
{
    public function __construct(
        string $message,
        private readonly string $portalMessage = '',
        private readonly int $available = 0,
        private readonly int $limit = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Inspect a portal response (JSON web method or HTML page) and build an exception
     * when the daily download limit was reached. Return null when the limit was not found.
     */
    public static function fromPortalResponse(string $content): ?self
    {
        $validation = self::extractValidationData($content);
        if (null !== $validation) {
            if (0 === $validation['code']) {
                return null;
            }

            $portalMessage = $validation['portalMessage'];
            $message = 'The SAT portal reports that the daily download limit was reached';
            if ('' !== $portalMessage) {
                $message .= ': ' . $portalMessage;
            } elseif ($validation['limit'] > 0) {
                $message .= sprintf(' (a maximum of %d documents per day)', $validation['limit']);
            }

            return new self($message, $portalMessage, $validation['available'], $validation['limit']);
        }

        if (self::looksLikeDailyDownloadLimit($content)) {
            return new self('The SAT portal reports that the daily download limit was reached (2,000 documents per day)');
        }

        return null;
    }

    /**
     * The message as it was written by the SAT portal (it can be empty)
     */
    public function getPortalMessage(): string
    {
        return $this->portalMessage;
    }

    /**
     * The amount of documents that are still available to download (0 when the limit was reached)
     */
    public function getAvailable(): int
    {
        return $this->available;
    }

    /**
     * The daily limit of documents established by the SAT portal (2,000)
     */
    public function getLimit(): int
    {
        return $this->limit;
    }

    /**
     * @return array{code: int, available: int, limit: int, portalMessage: string}|null
     */
    private static function extractValidationData(string $content): ?array
    {
        if (false === stripos($content, 'RespuestaValidaDescarga') && false === stripos($content, '"Codigo"')) {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        /** @var mixed $data */
        $data = $decoded['d'] ?? $decoded;
        if (! is_array($data) || ! array_key_exists('Codigo', $data)) {
            return null;
        }

        return [
            'code' => self::intValue($data['Codigo'] ?? 0),
            'available' => self::intValue($data['CantidadDisponible'] ?? 0),
            'limit' => self::intValue($data['CantidadTope'] ?? 0),
            'portalMessage' => self::stringValue($data['Mensaje'] ?? ''),
        ];
    }

    private static function intValue(mixed $value): int
    {
        if (! is_numeric($value)) {
            return 0;
        }
        return (int) $value;
    }

    private static function stringValue(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }
        return trim(strval($value));
    }

    private static function looksLikeDailyDownloadLimit(string $content): bool
    {
        // The message that includes the daily limit text is not present on the regular pages,
        // it is only included by the portal when the limit was reached.
        if (false !== stripos($content, '2,000 archivos por d')) {
            return true;
        }

        // The portal pages expose a hidden field that is filled with "CuotaCompleta" or "CuotaParcial"
        // when the download was rejected because of the daily quota.
        return 1 === preg_match(
            '/id=["\']hfDescarga["\'][^>]*value=["\'](?:CuotaCompleta|CuotaParcial)["\']/i',
            $content,
        );
    }
}
