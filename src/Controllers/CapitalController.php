<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Repositories\AccountRepository;
use App\Repositories\CapitalMovementRepository;
use App\Repositories\PositionRepository;
use App\Repositories\TradeRepository;
use App\Support\Validator;

final class CapitalController
{
    public function __construct(
        private CapitalMovementRepository $movements = new CapitalMovementRepository(),
        private AccountRepository $accounts = new AccountRepository(),
        private TradeRepository $trades = new TradeRepository(),
        private PositionRepository $positions = new PositionRepository(),
    ) {
    }

    public function index(Request $request): void
    {
        $accountId = isset($request->query['account_id']) ? (string) $request->query['account_id'] : null;
        $limit = $request->intQuery('limit', 100, 1, 200);
        $offset = $request->intQuery('offset', 0, 0);
        Response::data($this->movements->list($request->user['id'], $accountId, $limit, $offset));
    }

    public function show(Request $request): void
    {
        $row = $this->movements->find($request->user['id'], $request->params['id']);
        if (!$row) {
            Response::error('NOT_FOUND', 'Capital movement not found.', 404);

            return;
        }
        Response::data($row);
    }

    public function store(Request $request): void
    {
        $v = Validator::validate($request->body, [
            'type' => 'required|enum:deposit_to_trader,return_from_trader,broker_deposit,broker_withdrawal,fee,other',
            'amount' => 'required|number',
            'currency' => 'string',
            'paid_at' => 'string',
            'method' => 'enum:upi,bank,cash,other',
        ]);
        if (!$v['ok']) {
            Response::error('VALIDATION_ERROR', 'Invalid input.', 422, $v['errors']);

            return;
        }

        $accountId = isset($request->body['account_id']) ? (string) $request->body['account_id'] : null;
        if ($accountId && !$this->accounts->findForUser($request->user['id'], $accountId)) {
            Response::error('NOT_FOUND', 'Account not found.', 404);

            return;
        }

        $row = $this->movements->create($request->user['id'], $request->body);
        Response::data($row, 201);
    }

    public function update(Request $request): void
    {
        $accountId = isset($request->body['account_id']) ? (string) $request->body['account_id'] : null;
        if ($accountId && !$this->accounts->findForUser($request->user['id'], $accountId)) {
            Response::error('NOT_FOUND', 'Account not found.', 404);

            return;
        }

        if (isset($request->body['type'])) {
            $v = Validator::validate($request->body, [
                'type' => 'enum:deposit_to_trader,return_from_trader,broker_deposit,broker_withdrawal,fee,other',
            ]);
            if (!$v['ok']) {
                Response::error('VALIDATION_ERROR', 'Invalid input.', 422, $v['errors']);

                return;
            }
        }

        $row = $this->movements->update($request->user['id'], $request->params['id'], $request->body);
        if (!$row) {
            Response::error('NOT_FOUND', 'Capital movement not found.', 404);

            return;
        }
        Response::data($row);
    }

    public function destroy(Request $request): void
    {
        if (!$this->movements->delete($request->user['id'], $request->params['id'])) {
            Response::error('NOT_FOUND', 'Capital movement not found.', 404);

            return;
        }
        Response::data(['ok' => true]);
    }

    public function summary(Request $request): void
    {
        $accountId = isset($request->query['account_id']) ? (string) $request->query['account_id'] : null;
        $funded = $this->movements->fundedTotals($request->user['id'], $accountId);
        $tradeNetPnl = $this->trades->netClosedPnl($request->user['id'], $accountId);
        $unrealized = $this->positions->unrealizedPnl($request->user['id'], $accountId);
        $netFunded = (float) $funded['net_funded'];
        $totalPnl = $tradeNetPnl + $unrealized;
        // (Closed + floating) ÷ net funded. A negative net funded is still a
        // real base (more returned than paid in), so only a zero base is blank.
        $roi = $netFunded != 0.0 ? round(($totalPnl / $netFunded) * 100, 2) : null;

        Response::data([
            'capital_in' => $funded['capital_in'],
            'capital_out' => $funded['capital_out'],
            'net_funded' => $netFunded,
            'trade_net_pnl' => $tradeNetPnl,
            'unrealized_pnl' => $unrealized,
            'total_pnl' => round($totalPnl, 2),
            'roi_pct' => $roi,
            'currency' => 'USD',
        ]);
    }
}
