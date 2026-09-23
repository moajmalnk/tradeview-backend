<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Repositories\AccountRepository;
use App\Repositories\PositionRepository;
use App\Repositories\ShareLinkRepository;
use App\Repositories\TradeRepository;
use App\Support\Validator;

final class ShareController
{
    public function __construct(
        private ShareLinkRepository $shares = new ShareLinkRepository(),
        private AccountRepository $accounts = new AccountRepository(),
        private PositionRepository $positions = new PositionRepository(),
        private TradeRepository $trades = new TradeRepository(),
    ) {
    }

    public function index(Request $request): void
    {
        Response::data($this->shares->list($request->user['id']));
    }

    public function store(Request $request): void
    {
        $v = Validator::validate($request->body, [
            'label' => 'string',
        ]);
        if (!$v['ok']) {
            Response::error('VALIDATION_ERROR', 'Invalid input.', 422, $v['errors']);

            return;
        }

        $created = $this->shares->create($request->user['id'], $request->body);
        Response::data([
            'token' => $created['plain'],
            'record' => $created['row'],
        ], 201);
    }

    public function destroy(Request $request): void
    {
        if (!$this->shares->revoke($request->user['id'], $request->params['id'])) {
            Response::error('NOT_FOUND', 'Share link not found or already revoked.', 404);

            return;
        }
        Response::data(['ok' => true]);
    }

    public function resolve(Request $request): void
    {
        $plain = $request->params['token'] ?? '';
        $share = $this->shares->findActiveByPlainToken($plain);
        if (!$share) {
            Response::error('NOT_FOUND', 'Share link invalid or expired.', 404);

            return;
        }

        $this->shares->incrementView($share['id']);
        $permissions = is_array($share['permissions']) ? $share['permissions'] : [];
        $accountIds = is_array($share['account_ids']) ? $share['account_ids'] : [];

        $allAccounts = $this->accounts->listForUser($share['user_id']);
        if ($accountIds !== []) {
            $allAccounts = array_values(array_filter(
                $allAccounts,
                static fn (array $a) => in_array($a['id'], $accountIds, true)
            ));
        }

        $accounts = array_map(function (array $account) use ($permissions) {
            $out = [
                'id' => $account['id'],
                'platform' => $account['platform'],
                'currency' => $account['currency'],
                'connection_status' => $account['connection_status'],
            ];
            if ($permissions['accountName'] ?? false) {
                $out['label'] = $account['label'];
            }
            if ($permissions['broker'] ?? false) {
                $out['broker'] = $account['broker'];
            }
            if ($permissions['balance'] ?? false) {
                $out['balance'] = $account['balance'];
            }
            if ($permissions['equity'] ?? false) {
                $out['equity'] = $account['equity'];
            }
            if ($permissions['pnl'] ?? false) {
                $out['margin'] = $account['margin'];
                $out['free_margin'] = $account['free_margin'];
                $out['margin_level'] = $account['margin_level'];
            }

            return $out;
        }, $allAccounts);

        $positions = [];
        $trades = [];
        foreach ($allAccounts as $account) {
            foreach ($this->positions->listOpen($share['user_id'], $account['id']) as $pos) {
                $positions[] = $this->filterPosition($pos, $permissions);
            }
            if ($permissions['tradeHistory'] ?? false) {
                foreach ($this->trades->list($share['user_id'], $account['id'], 50, 0) as $trade) {
                    $trades[] = $this->filterTrade($trade, $permissions);
                }
            }
        }

        Response::data([
            'label' => $share['label'],
            'visibility' => $share['visibility'],
            'permissions' => $permissions,
            'accounts' => $accounts,
            'positions' => $positions,
            'trades' => $trades,
        ]);
    }

    private function filterPosition(array $pos, array $permissions): array
    {
        $out = [
            'id' => $pos['id'],
            'account_id' => $pos['account_id'],
            'symbol' => $pos['symbol'],
            'direction' => $pos['direction'],
            'status' => $pos['status'],
            'open_time' => $pos['open_time'],
        ];
        if ($permissions['volume'] ?? false) {
            $out['volume'] = $pos['volume'];
        }
        if ($permissions['entry'] ?? false) {
            $out['entry_price'] = $pos['entry_price'];
            $out['current_price'] = $pos['current_price'];
        }
        if ($permissions['stopLoss'] ?? false) {
            $out['stop_loss'] = $pos['stop_loss'];
        }
        if ($permissions['takeProfit'] ?? false) {
            $out['take_profit'] = $pos['take_profit'];
        }
        if ($permissions['pnl'] ?? false) {
            $out['profit'] = $pos['profit'];
            $out['swap'] = $pos['swap'];
            $out['commission'] = $pos['commission'];
        }

        return $out;
    }

    private function filterTrade(array $trade, array $permissions): array
    {
        $out = [
            'id' => $trade['id'],
            'account_id' => $trade['account_id'],
            'symbol' => $trade['symbol'],
            'direction' => $trade['direction'],
            'open_time' => $trade['open_time'],
            'close_time' => $trade['close_time'],
            'status' => $trade['status'],
        ];
        if ($permissions['volume'] ?? false) {
            $out['volume'] = $trade['volume'];
        }
        if ($permissions['entry'] ?? false) {
            $out['entry_price'] = $trade['entry_price'];
            $out['exit_price'] = $trade['exit_price'];
        }
        if ($permissions['pnl'] ?? false) {
            $out['profit'] = $trade['profit'];
            $out['commission'] = $trade['commission'];
            $out['swap'] = $trade['swap'];
        }

        return $out;
    }
}
