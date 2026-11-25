<?php

namespace Beeralex\Catalog\Location\Contracts;

interface LocationDataParserContract
{
    /**
     * Parses the data from the location API into a structured array of variants.
     *
     * @param array $suggestions The data part of the suggestion from the API.
     * @param string|null $location The original query string (for coordinates, pass null).
     * @return array Returns an array with settlement, city, area, and region variants.
     */
    public function parse(array $suggestions, ?string $location = null): array;
}
