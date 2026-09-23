<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Db;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\AccountRepository;
use App\Repositories\AuditRepository;
use App\Repositories\ConnectorTokenRepository;
use App\Repositories\PositionRepository;
use App\Repositories\TradeRepository;

final class ConnectorController
{
    public function __construct(
        private ConnectorTokenRepository $tokens = new ConnectorTokenRepository(),
        private AccountRepository $accounts = new AccountRepository(),
        private PositionRepository $positions = new PositionRepository(),
        private TradeRepository $trades = new TradeRepository(),
        private AuditRepository $audit = new AuditRepository(),
    ) {
    }

    public function sync(Request $request): void
    {
        $plain = $request->bearerToken() ?? (string) ($request->body['token'] ?? '');
        if ($plain === '') {
            Response::error('UNAUTHORIZED', 'Connector token required.', 401);

            return;
        }

        $tokenRow = $this->tokens->findActiveByPlainToken($plain);
        if (!$tokenRow) {
            Response::error('UNAUTHORIZED', 'Invalid connector token.', 401);

            return;
        }

        if (!is_array($request->body['account'] ?? null)) {
            Response::error('VALIDATION_ERROR', 'account object is required.', 422);

            return;
        }

        $accountPayload = $request->body['account'];
        $userId = $tokenRow['user_id'];
        $positionsSynced = 0;
        $tradesSynced = 0;
        $tickets = [];
        $pdo = Db::pdo();
        $pdo->beginTransaction();

        try {
            $accountId = $tokenRow['account_id'];

            if (!$accountId) {
                $account = $this->accounts->create($userId, [
                    'label' => $accountPayload['label'] ?? 'Connected account',
                    'platform' => $accountPayload['platform'] ?? $tokenRow['platform'],
                    'broker' => $accountPayload['broker'] ?? null,
                    'account_identifier' => $accountPayload['account_identifier'] ?? null,
                    'currency' => $accountPayload['currency'] ?? 'USD',
                    'balance' => $accountPayload['balance'] ?? 0,
                    'equity' => $accountPayload['equity'] ?? 0,
                    'margin' => $accountPayload['margin'] ?? 0,
                    'free_margin' => $accountPayload['free_margin'] ?? 0,
                    'margin_level' => $accountPayload['margin_level'] ?? null,
                    'connection_status' => 'live',
                    'is_demo' => $accountPayload['is_demo'] ?? false,
                ]);
                $accountId = $account['id'];
                $this->tokens->bindAccount($tokenRow['id'], $accountId);
            }

            $fields = [
                'server_time' => $accountPayload['server_time'] ?? date('Y-m-d H:i:s'),
                'last_seen_at' => date('Y-m-d H:i:s'),
                'connection_status' => $accountPayload['connection_status'] ?? 'live',
            ];
            foreach ([
                'label', 'broker', 'account_identifier', 'currency',
                'balance', 'equity', 'margin', 'free_margin', 'margin_level', 'is_demo',
            ] as $key) {
                if (array_key_exists($key, $accountPayload)) {
                    $fields[$key] = $accountPayload[$key];
                }
            }
            $this->accounts->update($userId, $accountId, $fields);

            $positions = is_array($request->body['positions'] ?? null) ? $request->body['positions'] : null;
            if (is_array($positions)) {
                foreach ($positions as $pos) {
                    if (!isset($pos['ticket'], $pos['symbol'], $pos['direction'], $pos['volume'], $pos['entry_price'])) {
                        continue;
                    }
                    $tickets[] = (string) $pos['ticket'];
                    $this->positions->upsert($userId, $accountId, $pos);
                    $positionsSynced++;
                }
                $this->positions->closeMissing($userId, $accountId, $tickets);
            }

            $closed = is_array($request->body['trades'] ?? null) ? $request->body['trades'] : [];
            foreach ($closed as $trade) {
                if (!isset($trade['ticket'], $trade['symbol'], $trade['direction'], $trade['volume'], $trade['entry_price'], $trade['open_time'])) {
                    continue;
                }
                $this->trades->upsert($userId, $accountId, $trade);
                $tradesSynced++;
            }

            $this->tokens->touch($tokenRow['id']);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->audit->log($userId, 'CONNECTOR_SYNC', $accountId, ['token_id' => $tokenRow['id']]);

        Response::data([
            'ok' => true,
            'account_id' => $accountId,
            'positions_synced' => $positionsSynced,
            'trades_synced' => $tradesSynced,
        ]);
    }
}
