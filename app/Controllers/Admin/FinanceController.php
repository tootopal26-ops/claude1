<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Services\Finance;
use App\Services\FinanceReport;
use App\Support\Permissions;

/**
 * Profit Intelligence: the overview, the cost lines of an order, expenses and chargebacks, the reconciliation view and the fee model.
 * Everything that shows money needs `finance.view`; everything that changes a figure needs `finance.adjust` (owner, manager). Every change is audited.
 */
final class FinanceController extends BaseController
{
    /** @return array<string,bool> */
    private function can(Request $req): array
    {
        $role = (string) $this->admin($req)['role'];
        return ['adjust' => Permissions::can($role, 'finance.adjust')];
    }

    /** GET /admin/api/finance?range=&from=&to=&platform=&service=&mode=&payment= */
    public function overview(Request $req, array $p): Response
    {
        $this->authorize($req, 'finance.view');
        $f = [];
        foreach (['range', 'from', 'to', 'platform', 'service', 'mode', 'payment'] as $k) {
            $f[$k] = $req->queryString($k, '', 64);
        }
        if ($f['range'] === '') {
            $f['range'] = '30d';
        }
        return $this->ok(['finance' => FinanceReport::overview($f), 'can' => $this->can($req)]);
    }

    /** GET /admin/api/finance/reconciliation */
    public function reconciliation(Request $req, array $p): Response
    {
        $this->authorize($req, 'finance.view');
        return $this->ok(FinanceReport::reconciliation() + ['can' => $this->can($req), 'fee' => ['mode' => Finance::settings()['fee_mode'], 'description' => Finance::feeDescription()]]);
    }

    /** POST /admin/api/finance/reconciliation/fix  { action: backfill_fees|backfill_fulfilment, dry_run? } */
    public function fix(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'finance.adjust');
        $in = $req->json();
        return $this->once($req, 'finance-fix', static fn(): array => ['result' => FinanceReport::fix((string) ($in['action'] ?? ''), ($in['dry_run'] ?? false) === true, $req, $admin)]);
    }

    /** GET /admin/api/finance/orders/{id} — one order's money, line by line. */
    public function order(Request $req, array $p): Response
    {
        $this->authorize($req, 'finance.view');
        return $this->ok(['finance' => Finance::orderView($this->id($p)), 'can' => $this->can($req)]);
    }

    /** POST /admin/api/finance/orders/{id}/costs  { type, amount (₹), note, estimate? } */
    public function recordCost(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'finance.adjust');
        $id = $this->id($p);
        $in = $req->json();
        return $this->once($req, 'finance-cost', static fn(): array => [
            'finance' => Finance::recordLine($id, (string) ($in['type'] ?? ''), $in['amount'] ?? null, (string) ($in['note'] ?? ''), ($in['estimate'] ?? false) === true, $req, $admin),
        ]);
    }

    /** POST /admin/api/finance/costs/{id}/void  { reason } — ends a wrong "other" cost line. */
    public function voidCost(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'finance.adjust');
        $id = $this->id($p);
        $in = $req->json();
        return $this->once($req, 'finance-cost-void', static fn(): array => ['finance' => Finance::voidLine($id, (string) ($in['reason'] ?? ''), $req, $admin)]);
    }

    /** GET /admin/api/finance/expenses?from=&to=&category=&include_voided=&page= */
    public function expenses(Request $req, array $p): Response
    {
        $this->authorize($req, 'finance.view');
        return $this->ok(Finance::expenses([
            'from' => $req->queryString('from', '', 10), 'to' => $req->queryString('to', '', 10), 'category' => $req->queryString('category', '', 16),
            'include_voided' => $req->queryString('include_voided') === '1', 'page' => $req->queryInt('page', 1, 1, 100000),
        ]) + ['can' => $this->can($req), 'categories' => Finance::EXPENSE_CATEGORIES]);
    }

    /** POST /admin/api/finance/expenses  { day, category, amount (₹), description?, reference?, order? } */
    public function addExpense(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'finance.adjust');
        $in = $req->json();
        return $this->once($req, 'finance-expense', static fn(): array => ['expense' => Finance::addExpense($in, $req, $admin)]);
    }

    /** POST /admin/api/finance/expenses/{id}/void  { reason } */
    public function voidExpense(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'finance.adjust');
        $id = $this->id($p);
        $in = $req->json();
        return $this->once($req, 'finance-expense-void', static fn(): array => ['expense' => Finance::voidExpense($id, (string) ($in['reason'] ?? ''), $req, $admin)]);
    }

    /** GET /admin/api/finance/settings */
    public function settings(Request $req, array $p): Response
    {
        $this->authorize($req, 'finance.view');
        $s = Finance::settings();
        return $this->ok(['settings' => $s + ['fee_percent' => rtrim(rtrim(number_format($s['fee_percent_bps'] / 100, 2, '.', ''), '0'), '.'), 'fee_fixed' => \App\Support\Money::plain($s['fee_fixed_paise']), 'description' => Finance::feeDescription($s)], 'can' => $this->can($req)]);
    }

    /** PUT /admin/api/finance/settings  { fee_mode, fee_percent?, fee_fixed?, ad_gst? } */
    public function saveSettings(Request $req, array $p): Response
    {
        $admin = $this->authorize($req, 'finance.adjust');
        $in = $req->json();
        return $this->once($req, 'finance-settings', static function () use ($in, $req, $admin): array {
            $s = Finance::saveSettings($in, $req, $admin);
            return ['settings' => $s + ['fee_percent' => rtrim(rtrim(number_format($s['fee_percent_bps'] / 100, 2, '.', ''), '0'), '.'), 'fee_fixed' => \App\Support\Money::plain($s['fee_fixed_paise']), 'description' => Finance::feeDescription($s)]];
        });
    }
}
