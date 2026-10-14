<?php

declare(strict_types=1);

namespace App\Support;

/**
 * THE profit rules — defined once, used everywhere (pure PHP for single rows,
 * matching SQL fragments for aggregates; tests assert both agree).
 *
 *   Revenue (net)  = amount paid − refunds
 *   MANUAL order   : gross profit = revenue            (100 % counts as profit before ad costs)
 *   AUTO order     : gross profit = revenue − provider cost
 *   Net profit     = gross profit − advertisement cost
 *
 * The provider cost is counted exactly once, on the order that incurred it, and
 * ad cost is counted exactly once, from qualified visitors — never both inside
 * the same figure. All values are integer paise.
 */
final class Profit
{
    /** SQL: net revenue of a paid order alias `o`. */
    public const SQL_REVENUE = '(o.amount_paise - o.refunded_paise)';

    /** SQL: provider cost that counts against profit (AUTO orders only). */
    public const SQL_COST = "(CASE WHEN o.mode = 'AUTO' THEN o.provider_cost_paise ELSE 0 END)";

    /** SQL: gross profit of a paid order alias `o`. */
    public const SQL_GROSS = "(o.amount_paise - o.refunded_paise - CASE WHEN o.mode = 'AUTO' THEN o.provider_cost_paise ELSE 0 END)";

    /**
     * @param array{mode:string,amount_paise:int|string,refunded_paise:int|string,provider_cost_paise:int|string} $o
     * @return array{revenue:int,cost:int,gross:int}
     */
    public static function forOrder(array $o): array
    {
        $revenue = (int) $o['amount_paise'] - (int) $o['refunded_paise'];
        $cost = $o['mode'] === Status::AUTO ? (int) $o['provider_cost_paise'] : 0;
        return ['revenue' => $revenue, 'cost' => $cost, 'gross' => $revenue - $cost];
    }

    /**
     * The "REVENUE − SMM COST − ADS COST = NET PROFIT" card.
     *
     * @return array{revenue:int,smm:int,ads:int,gross:int,net:int,margin_tenths:int,margin:string}
     */
    public static function breakdown(int $revenue, int $smmCost, int $adsCost): array
    {
        $gross = $revenue - $smmCost;
        $net = $gross - $adsCost;
        $m = Money::pctTenths($net, $revenue);
        return [
            'revenue' => $revenue, 'smm' => $smmCost, 'ads' => $adsCost, 'gross' => $gross, 'net' => $net,
            'margin_tenths' => $m, 'margin' => Money::tenthsToString($m),
        ];
    }

    /**
     * Service profit preview (Admin → Services editor).
     *
     * @return array{price:int,provider_cost:int,gross:int,est_ad_cost:int,net:int,margin_tenths:int,margin:string}
     */
    public static function servicePreview(int $pricePaise, int $providerCostPaise, string $mode, int $estimatedAdCostPaise): array
    {
        $cost = $mode === Status::AUTO ? $providerCostPaise : 0;
        $gross = $pricePaise - $cost;
        $net = $gross - $estimatedAdCostPaise;
        $m = Money::pctTenths($net, $pricePaise);
        return [
            'price' => $pricePaise, 'provider_cost' => $cost, 'gross' => $gross, 'est_ad_cost' => $estimatedAdCostPaise,
            'net' => $net, 'margin_tenths' => $m, 'margin' => Money::tenthsToString($m),
        ];
    }
}
