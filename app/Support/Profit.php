<?php

declare(strict_types=1);

namespace App\Support;

/**
 * THE profit rules — defined once, used everywhere (pure PHP for single rows, matching SQL fragments for aggregates; tests assert both agree).
 * The full definitions, and the labels that say how complete a figure is, are in App\Services\Finance and docs/PROFIT-INTELLIGENCE.md.
 *
 *   Revenue (net)  = amount paid − refunds
 *   AUTO order     : gross profit = revenue − provider cost − the order's cost lines (payment fee, other)
 *   MANUAL order   : gross profit = revenue − the order's cost lines (fulfilment, payment fee, other)   — NOT 100 %: a manual order costs something,
 *                    and where that cost is not known the order is labelled "incomplete" instead of being counted as free
 *   Net profit     = gross profit − chargebacks − business expenses − advertising
 *
 * The provider cost is counted exactly once, on the order that incurred it, and advertising is counted exactly once, as the actual spend of a day or,
 * for a day with none, the CPC estimate — never both. All values are integer paise.
 */
final class Profit
{
    /** SQL: net revenue of a paid order alias `o`. */
    public const SQL_REVENUE = '(o.amount_paise - o.refunded_paise)';

    /** SQL: provider cost that counts against profit (AUTO orders only). */
    public const SQL_COST = "(CASE WHEN o.mode = 'AUTO' THEN o.provider_cost_paise ELSE 0 END)";

    /** SQL: the live cost lines of a paid order alias `o` (fulfilment + payment fee + other; ended lines are history, not cost). */
    public const SQL_LINES = '(COALESCE((SELECT SUM(lc.amount_paise) FROM order_costs lc WHERE lc.order_id = o.id AND lc.superseded_at IS NULL), 0))';

    /** SQL: gross profit of a paid order alias `o`. */
    public const SQL_GROSS = "(o.amount_paise - o.refunded_paise - CASE WHEN o.mode = 'AUTO' THEN o.provider_cost_paise ELSE 0 END - COALESCE((SELECT SUM(lc.amount_paise) FROM order_costs lc WHERE lc.order_id = o.id AND lc.superseded_at IS NULL), 0))";

    /**
     * @param array{mode:string,amount_paise:int|string,refunded_paise:int|string,provider_cost_paise:int|string} $o
     * @param int $lineCosts the sum of the order's live cost lines (fulfilment, payment fee, other)
     * @return array{revenue:int,cost:int,lines:int,gross:int}
     */
    public static function forOrder(array $o, int $lineCosts = 0): array
    {
        $revenue = (int) $o['amount_paise'] - (int) $o['refunded_paise'];
        $cost = $o['mode'] === Status::AUTO ? (int) $o['provider_cost_paise'] : 0;
        return ['revenue' => $revenue, 'cost' => $cost, 'lines' => $lineCosts, 'gross' => $revenue - $cost - $lineCosts];
    }

    /**
     * The "REVENUE − SMM COST − OTHER COSTS − ADS COST = NET PROFIT" card.
     *
     * @param int $otherCosts fulfilment + payment fees + other order costs
     * @param int $overheads chargebacks + business expenses (site-wide: pass 0 for a filtered view)
     * @return array{revenue:int,smm:int,ads:int,gross:int,net:int,margin_tenths:int,margin:string,other_costs:int,overheads:int}
     */
    public static function breakdown(int $revenue, int $smmCost, int $adsCost, int $otherCosts = 0, int $overheads = 0): array
    {
        $gross = $revenue - $smmCost - $otherCosts;
        $net = $gross - $overheads - $adsCost;
        $m = Money::pctTenths($net, $revenue);
        return [
            'revenue' => $revenue, 'smm' => $smmCost, 'ads' => $adsCost, 'gross' => $gross, 'net' => $net,
            'margin_tenths' => $m, 'margin' => Money::tenthsToString($m), 'other_costs' => $otherCosts, 'overheads' => $overheads,
        ];
    }

    /**
     * Service profit preview (Admin → Services editor).
     *
     * @param int|null $fulfilCostPaise what one purchase of a MANUAL service costs us directly; null = not stated, which is never counted as free in the
     *                                  reports (the orders are labelled incomplete) — the preview just cannot include a number it does not have
     * @return array{price:int,provider_cost:int,fulfil_cost:int,fulfil_known:bool,gross:int,est_ad_cost:int,net:int,margin_tenths:int,margin:string}
     */
    public static function servicePreview(int $pricePaise, int $providerCostPaise, string $mode, int $estimatedAdCostPaise, ?int $fulfilCostPaise = null): array
    {
        $cost = $mode === Status::AUTO ? $providerCostPaise : 0;
        $fulfil = $mode === Status::AUTO ? 0 : (int) $fulfilCostPaise;
        $gross = $pricePaise - $cost - $fulfil;
        $net = $gross - $estimatedAdCostPaise;
        $m = Money::pctTenths($net, $pricePaise);
        return [
            'price' => $pricePaise, 'provider_cost' => $cost, 'fulfil_cost' => $fulfil, 'fulfil_known' => $mode === Status::AUTO || $fulfilCostPaise !== null,
            'gross' => $gross, 'est_ad_cost' => $estimatedAdCostPaise,
            'net' => $net, 'margin_tenths' => $m, 'margin' => Money::tenthsToString($m),
        ];
    }
}
