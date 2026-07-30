<?php

namespace App\Services;

use App\Models\CashEntry;
use Illuminate\Support\Collection;

class ReportAggregator
{
    /**
     * @return array{
     *     total_income: float,
     *     total_expense: float,
     *     net: float,
     *     income_by_category: list<array{category: string, amount: float}>,
     *     expense_by_category: list<array{category: string, amount: float}>,
     *     by_category: list<array{category: string, amount: float}>
     * }
     */
    public function aggregate(int $userId, string $from, string $to): array
    {
        /** @var Collection<int, CashEntry> $entries */
        $entries = CashEntry::query()
            ->with('category:id,name,slug,kind')
            ->where('user_id', $userId)
            ->whereDate('entry_date', '>=', $from)
            ->whereDate('entry_date', '<=', $to)
            ->get();

        $totalIncome = 0.0;
        $totalExpense = 0.0;
        /** @var array<int, array{category: string, amount: float}> $incomeByCategory */
        $incomeByCategory = [];
        /** @var array<int, array{category: string, amount: float}> $expenseByCategory */
        $expenseByCategory = [];

        foreach ($entries as $entry) {
            $amount = (float) $entry->amount;
            $categoryId = (int) $entry->category_id;
            $categoryName = $entry->category?->name ?? 'Unknown';

            if ($entry->type === 'income') {
                $totalIncome += $amount;
                if (! isset($incomeByCategory[$categoryId])) {
                    $incomeByCategory[$categoryId] = ['category' => $categoryName, 'amount' => 0.0];
                }
                $incomeByCategory[$categoryId]['amount'] += $amount;
            } else {
                $totalExpense += $amount;
                if (! isset($expenseByCategory[$categoryId])) {
                    $expenseByCategory[$categoryId] = ['category' => $categoryName, 'amount' => 0.0];
                }
                $expenseByCategory[$categoryId]['amount'] += $amount;
            }
        }

        $incomeRows = $this->formatCategoryRows($incomeByCategory);
        $expenseRows = $this->formatCategoryRows($expenseByCategory);

        return [
            'total_income' => round($totalIncome, 2),
            'total_expense' => round($totalExpense, 2),
            'net' => round($totalIncome - $totalExpense, 2),
            'income_by_category' => $incomeRows,
            'expense_by_category' => $expenseRows,
            'by_category' => $expenseRows,
        ];
    }

    /**
     * @param  array<int, array{category: string, amount: float}>  $rows
     * @return list<array{category: string, amount: float}>
     */
    private function formatCategoryRows(array $rows): array
    {
        $sorted = array_values($rows);
        usort($sorted, fn (array $a, array $b) => $b['amount'] <=> $a['amount']);

        return array_map(
            fn (array $row) => [
                'category' => $row['category'],
                'amount' => round($row['amount'], 2),
            ],
            $sorted
        );
    }
}
