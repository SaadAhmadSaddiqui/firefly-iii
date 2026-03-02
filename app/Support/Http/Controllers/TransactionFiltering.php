<?php

declare(strict_types=1);

namespace FireflyIII\Support\Http\Controllers;

use FireflyIII\Support\Search\SearchInterface;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

trait TransactionFiltering
{
    private static array $filterMapping = [
        'filter_description' => 'description_contains',
        'filter_amount_min'  => 'amount_more',
        'filter_amount_max'  => 'amount_less',
        'filter_date_from'   => 'date_after',
        'filter_date_to'     => 'date_before',
        'filter_source'      => 'source_account_contains',
        'filter_destination' => 'destination_account_contains',
        'filter_category'    => 'category_contains',
        'filter_budget'      => 'budget_contains',
    ];

    protected function hasTransactionFilters(Request $request): bool
    {
        foreach (array_keys(self::$filterMapping) as $param) {
            if ('' !== trim((string) $request->get($param, ''))) {
                return true;
            }
        }

        return '' !== trim((string) $request->get('filter_query', ''));
    }

    protected function getTransactionFilters(Request $request): array
    {
        $values = [];
        foreach (array_keys(self::$filterMapping) as $param) {
            $values[$param] = trim((string) $request->get($param, ''));
        }
        $values['filter_query'] = trim((string) $request->get('filter_query', ''));

        return $values;
    }

    /**
     * Builds a search operator query string from filter_* request params
     * combined with base operator constraints from the parent controller.
     *
     * @param array<int, string> $baseOperators pre-built operator tokens like "transaction_type:withdrawal"
     */
    protected function buildFilterQuery(Request $request, array $baseOperators = []): string
    {
        $parts = $baseOperators;

        foreach (self::$filterMapping as $param => $operator) {
            $value = trim((string) $request->get($param, ''));
            if ('' === $value) {
                continue;
            }
            if (str_contains($value, ' ')) {
                $value = '"' . $value . '"';
            }
            $parts[] = $operator . ':' . $value;
        }

        $rawQuery = trim((string) $request->get('filter_query', ''));
        if ('' !== $rawQuery) {
            $parts[] = $rawQuery;
        }

        return implode(' ', $parts);
    }

    protected function getFilteredTransactions(
        Request $request,
        int $page,
        int $pageSize,
        array $baseOperators = []
    ): LengthAwarePaginator {
        /** @var SearchInterface $searcher */
        $searcher = app(SearchInterface::class);
        $query    = $this->buildFilterQuery($request, $baseOperators);

        $searcher->parseQuery($query);
        $searcher->setPage($page);
        $searcher->setLimit($pageSize);

        return $searcher->searchTransactions();
    }
}
