<?php

declare(strict_types=1);

namespace PhpCfdi\CfdiSatScraper\Internal;

use DateTimeImmutable;
use Generator;
use PhpCfdi\CfdiSatScraper\Contracts\MetadataMessageHandler;
use PhpCfdi\CfdiSatScraper\Contracts\QueryInterface;
use PhpCfdi\CfdiSatScraper\Exceptions\LogicException;
use PhpCfdi\CfdiSatScraper\Exceptions\SatHttpGatewayException;
use PhpCfdi\CfdiSatScraper\Filters\DownloadType;
use PhpCfdi\CfdiSatScraper\Filters\Options\UuidOption;
use PhpCfdi\CfdiSatScraper\Inputs\InputsByFiltersIssued;
use PhpCfdi\CfdiSatScraper\Inputs\InputsByFiltersReceived;
use PhpCfdi\CfdiSatScraper\Inputs\InputsByUuid;
use PhpCfdi\CfdiSatScraper\Inputs\InputsInterface;
use PhpCfdi\CfdiSatScraper\MetadataList;
use PhpCfdi\CfdiSatScraper\QueryByFilters;
use PhpCfdi\CfdiSatScraper\QueryByUuid;

/**
 * Class MetadataDownloader contains the logic to manipulate queries to obtain metadata
 * Depends on QueryResolver to retrieve the contents
 * Has a copy of callable to raise when limit is reached
 *
 * @see QueryResolver
 *
 * @internal
 */
class MetadataDownloader
{
    /** @var int Maximum amount of records that SAT returns on a single query -*/
    public const RECORDS_LIMIT = 500;

    /** @internal */
    public function __construct(
        private readonly QueryResolver $queryResolver,
        private readonly MetadataMessageHandler $messageHandler,
    ) {}

    public function getQueryResolver(): QueryResolver
    {
        return $this->queryResolver;
    }

    public function getMessageHandler(): MetadataMessageHandler
    {
        return $this->messageHandler;
    }

    /**
     * @param  string[]  $uuids
     *
     * @throws SatHttpGatewayException
     */
    public function downloadByUuids(array $uuids, DownloadType $downloadType): MetadataList
    {
        $uuids = array_keys(array_change_key_case(array_flip($uuids), CASE_LOWER));
        $result = new MetadataList([]);
        foreach ($uuids as $uuid) {
            $uuidResult = $this->downloadByUuid(new UuidOption($uuid), $downloadType);
            $result = $result->merge($uuidResult);
        }

        return $result;
    }

    /**
     * @throws SatHttpGatewayException
     */
    public function downloadByUuid(UuidOption $uuid, DownloadType $downloadType): MetadataList
    {
        $query = new QueryByUuid($uuid, $downloadType);

        return $this->resolveQuery($query);
    }

    /**
     * @throws SatHttpGatewayException
     */
    public function downloadByDate(QueryByFilters $query): MetadataList
    {
        /** @var DateTimeImmutable $startDate set this type definition as setTime can return FALSE */
        $startDate = $query->getStartDate()->setTime(0, 0, 0);
        /** @var DateTimeImmutable $endDate set this type definition as setTime can return FALSE */
        $endDate = $query->getEndDate()->setTime(23, 59, 59);

        $query = clone $query;
        $query->setPeriod($startDate, $endDate);

        return $this->downloadByDateTime($query);
    }

    /**
     * @throws SatHttpGatewayException
     */
    public function downloadByDateTime(QueryByFilters $query): MetadataList
    {
        // SAT only allows to set a final date on "emitidos" queries, the "recibidos" form
        // has a single date and a range of hours, therefore it must be queried day by day
        if (! $query->getDownloadType()->isEmitidos()) {
            $result = new MetadataList([]);
            foreach ($this->splitQueryByFiltersByDays($query) as $current) {
                $list = $this->downloadQuery($current);
                $this->messageHandler->date($current->getStartDate(), $current->getEndDate(), $list->count());
                $result = $result->merge($list);
            }

            return $result;
        }

        return $this->downloadPeriod($query, $query->getStartDate(), $query->getEndDate());
    }

    /**
     * Resolve a period, when the records limit is reached the period is divided by days until
     * it can be resolved or until it is contained on a single day, then it is resolved by seconds.
     *
     * @throws SatHttpGatewayException
     *
     * @see MetadataDownloader::downloadQuery()
     */
    private function downloadPeriod(QueryByFilters $query, DateTimeImmutable $since, DateTimeImmutable $until): MetadataList
    {
        if ($since->format('Y-m-d') === $until->format('Y-m-d')) {
            $list = $this->downloadQuery((clone $query)->setPeriod($since, $until));
            $this->messageHandler->date($since, $until, $list->count());

            return $list;
        }

        $list = $this->resolveQuery((clone $query)->setPeriod($since, $until));
        if ($list->count() < self::RECORDS_LIMIT) {
            $this->messageHandler->resolved($since, $until, $list->count());
            $this->messageHandler->date($since, $until, $list->count());

            return $list;
        }

        $this->messageHandler->divide($since, $until);
        $middle = $this->lastMomentOfMiddleDay($since, $until);

        return $this->downloadPeriod($query, $since, $middle)
            ->merge($this->downloadPeriod($query, $middle->modify('midnight +1 day'), $until));
    }

    /**
     * Return the last second of the day where a period that spans several days must be divided
     */
    private function lastMomentOfMiddleDay(DateTimeImmutable $since, DateTimeImmutable $until): DateTimeImmutable
    {
        $firstDay = $since->modify('midnight');
        $days = intval($firstDay->diff($until->modify('midnight'))->days);
        /** @var DateTimeImmutable $middle set this type definition as setTime can return FALSE */
        $middle = $firstDay->modify(sprintf('+%d days', intdiv($days, 2)))->setTime(23, 59, 59);

        return $middle;
    }

    /**
     * @throws SatHttpGatewayException
     */
    public function downloadQuery(QueryByFilters $query): MetadataList
    {
        $finalList = new MetadataList([]);
        $day = $query->getStartDate()->modify('midnight');
        $lowerBound = intval($query->getStartDate()->format('U')) - intval($day->format('U'));
        $upperBound = intval($query->getEndDate()->format('U')) - intval($day->format('U'));
        $secondInitial = $lowerBound;
        $maximumWindow = $upperBound - $lowerBound + 1;
        $window = $maximumWindow;

        while ($secondInitial <= $upperBound) {
            $secondEnd = min($secondInitial + $window - 1, $upperBound);
            $currentQuery = $this->newQueryWithSeconds($query, $secondInitial, $secondEnd);
            $list = $this->resolveQuery($currentQuery);
            $result = $list->count();

            if ($result >= self::RECORDS_LIMIT && $secondEnd > $secondInitial) {
                $this->messageHandler->divide($currentQuery->getStartDate(), $currentQuery->getEndDate());
                $window = intdiv($secondEnd - $secondInitial, 2) + 1;

                continue;
            }

            if ($result >= self::RECORDS_LIMIT) {
                $this->messageHandler->maximum($currentQuery->getStartDate());
            }

            $this->messageHandler->resolved($currentQuery->getStartDate(), $currentQuery->getEndDate(), $result);
            $finalList = $finalList->merge($list);
            $secondInitial = $secondEnd + 1;

            // the window worked and there is room to spare, try with a bigger window on the next segment
            if (2 * $result < self::RECORDS_LIMIT) {
                $window = min(2 * $window, $maximumWindow);
            }
        }

        return $finalList;
    }

    public function newQueryWithSeconds(QueryByFilters $query, int $startSec, int $endSec): QueryByFilters
    {
        return (clone $query)->setPeriod(
            $this->buildDateWithDayAndSeconds($query->getStartDate(), $startSec),
            $this->buildDateWithDayAndSeconds($query->getEndDate(), $endSec),
        );
    }

    /**
     * @throws SatHttpGatewayException
     *
     * @see QueryResolver
     */
    public function resolveQuery(QueryInterface $query): MetadataList
    {
        $inputs = $this->createInputsFromQuery($query);

        return $this->getQueryResolver()->resolve($inputs);
    }

    public function buildDateWithDayAndSeconds(DateTimeImmutable $day, int $seconds): DateTimeImmutable
    {
        return $day->modify(sprintf('midnight + %d seconds', $seconds));
    }

    public function createInputsFromQuery(QueryInterface $query): InputsInterface
    {
        if ($query instanceof QueryByFilters) {
            if ($query->getDownloadType()->isEmitidos()) {
                return new InputsByFiltersIssued($query);
            }

            return new InputsByFiltersReceived($query);
        }
        if ($query instanceof QueryByUuid) {
            return new InputsByUuid($query);
        }
        throw LogicException::generic(sprintf('Unable to create input filters from query type %s', $query::class));
    }

    /**
     * Generates a clone of this query split by day
     *
     * @return Generator<QueryByFilters>
     */
    public function splitQueryByFiltersByDays(QueryByFilters $query): Generator
    {
        $endDate = $query->getEndDate();
        for ($date = $query->getStartDate(); $date <= $endDate; $date = $date->modify('midnight +1 day')) {
            $partial = clone $query;
            /** @var DateTimeImmutable $dateOnLastSecond set this type definition as setTime can return FALSE */
            $dateOnLastSecond = $date->setTime(23, 59, 59);
            $partial->setPeriod($date, min($dateOnLastSecond, $endDate));
            yield $partial;
        }
    }
}
