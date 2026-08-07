<?php

namespace App\Support;

/**
 * The one definition of what a sale earned.
 *
 * The sales list, the till's stats endpoint and the reports screen all have to
 * answer "kitna bacha?" with the same number. Holding the SQL in one place is
 * what stops the reports quietly drifting from the figure printed next to the
 * ticket — a shop shown two different profits for the same day trusts neither.
 *
 * Every expression is a SUM over `sale_items` (joined to `sales` when the
 * caller needs a date window), written so a caller can wrap its own window test
 * around it by rewriting the leading `CASE WHEN` — see
 * SaleController::profitByWindow.
 *
 * Prices and quantities are held per *base* unit — grams, millilitres or
 * pieces — so these multiply out to rupees unchanged for weighed goods.
 */
final class SaleProfit
{
    /**
     * Line margin, net of units already handed back.
     *
     * A line with no `unit_cost` has an *unknown* margin, not a zero one — the
     * shop simply never recorded what it paid for the item. Folding those lines
     * into the profit figure would quietly report the whole sale price as
     * profit, so they are excluded here and reported separately (see
     * UNKNOWN_REVENUE_SUM) instead of being guessed at.
     */
    public const PROFIT_SUM = 'SUM(CASE WHEN sale_items.unit_cost IS NULL THEN 0
        ELSE (sale_items.unit_price - sale_items.unit_cost) * (sale_items.quantity - sale_items.refunded_quantity) END)';

    /** Takings the profit figure above cannot account for. */
    public const UNKNOWN_REVENUE_SUM = 'SUM(CASE WHEN sale_items.unit_cost IS NULL
        THEN sale_items.unit_price * (sale_items.quantity - sale_items.refunded_quantity) ELSE 0 END)';

    public const UNKNOWN_LINES_SUM = 'SUM(CASE WHEN sale_items.unit_cost IS NULL THEN 1 ELSE 0 END)';

    /**
     * What the goods actually cost the shop, over exactly the lines the margin
     * above is drawn from. Uncosted lines contribute nothing here either, which
     * is why cost of goods sold may only be set against revenue once
     * UNKNOWN_REVENUE_SUM has been taken off the top.
     */
    public const COST_SUM = 'SUM(CASE WHEN sale_items.unit_cost IS NULL THEN 0
        ELSE sale_items.unit_cost * (sale_items.quantity - sale_items.refunded_quantity) END)';

    /** Line takings, costed or not, net of returns. */
    public const REVENUE_SUM = 'SUM(sale_items.unit_price * (sale_items.quantity - sale_items.refunded_quantity))';
}
