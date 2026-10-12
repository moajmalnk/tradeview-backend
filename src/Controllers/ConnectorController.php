<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Db;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\AccountRepository;
use App\Repositories\AuditRepository;
use App\Repositories\ConnectorTokenRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\PendingOrderRepository;
use App\Repositories\PositionRepository;
use App\Repositories\TradeRepository;

final class ConnectorController
{
    public function __construct(
        private ConnectorTokenRepository $tokens = new ConnectorTokenRepository(),
        private AccountRepository $accounts = new AccountRepository(),
        private PositionRepository $positions = new PositionRepository(),
        private PendingOrderRepository $orders = new PendingOrderRepository(),
        private TradeRepository $trades = new TradeRepository(),
        private AuditRepository $audit = new AuditRepository(),
        private NotificationRepository $notifications = new NotificationRepository(),
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
        $ordersSynced = 0;
        $tickets = [];
        $orderTickets = [];
        $accountId = $tokenRow['account_id'] ?? null;
        $wasNew = !$accountId;
        $prevStatus = null;
        $updated = null;
        $fields = [];
        $closedPositions = [];
        $newTrades = [];
        $pdo = Db::pdo();
        $pdo->beginTransaction();

        try {
            if ($accountId) {
                $existing = $this->accounts->findForUser($userId, $accountId);
                $prevStatus = $existing['connection_status'] ?? null;
            }

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
            $updated = $this->accounts->update($userId, $accountId, $fields);

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
                $closedPositions = $this->positions->closeMissing($userId, $accountId, $tickets);
            }

            $orders = is_array($request->body['orders'] ?? null) ? $request->body['orders'] : null;
            if (is_array($orders)) {
                foreach ($orders as $order) {
                    if (!isset($order['ticket'], $order['symbol'], $order['direction'], $order['volume'])) {
                        continue;
                    }
                    $orderTickets[] = (string) $order['ticket'];
                    $this->orders->upsert($userId, $accountId, $order);
                    $ordersSynced++;
                }
                $this->orders->cancelMissing($userId, $accountId, $orderTickets);
            }

            $closed = is_array($request->body['trades'] ?? null) ? $request->body['trades'] : [];
            foreach ($closed as $trade) {
                if (!isset($trade['ticket'], $trade['symbol'], $trade['direction'], $trade['volume'], $trade['entry_price'], $trade['open_time'])) {
                    continue;
                }
                $result = $this->trades->upsert($userId, $accountId, $trade);
                $tradesSynced++;
                if ($result['is_new'] && is_array($result['row'])) {
                    $newTrades[] = $result['row'];
                }
            }

            $this->tokens->touch($tokenRow['id']);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->audit->log($userId, 'CONNECTOR_SYNC', $accountId, ['token_id' => $tokenRow['id']]);

        $label = (string) (($updated['label'] ?? null) ?: 'Account');
        $nextStatus = (string) ($fields['connection_status'] ?? 'live');
        if ($wasNew) {
            $this->notifications->create($userId, [
                'account_id' => $accountId,
                'type' => 'CONNECTION_RESTORED',
                'title' => 'Connector linked',
                'message' => "{$label} is now syncing positions and trades from your EA.",
            ]);
        } elseif ($prevStatus && $prevStatus !== 'live' && $nextStatus === 'live') {
            $this->notifications->create($userId, [
                'account_id' => $accountId,
                'type' => 'CONNECTION_RESTORED',
                'title' => 'Connector restored',
                'message' => "{$label} is receiving monitoring data again.",
            ]);
        }

        $this->emitTradeNotifications($userId, $accountId, $label, $newTrades, $closedPositions);

        Response::data([
            'ok' => true,
            'account_id' => $accountId,
            'positions_synced' => $positionsSynced,
            'orders_synced' => $ordersSynced,
            'trades_synced' => $tradesSynced,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $newTrades
     * @param list<array<string, mixed>> $closedPositions
     */
    private function emitTradeNotifications(
        string $userId,
        string $accountId,
        string $label,
        array $newTrades,
        array $closedPositions,
    ): void {
        $alertedTickets = [];

        foreach ($newTrades as $trade) {
            $ticket = (string) ($trade['ticket'] ?? '');
            $type = $this->resolveCloseNotificationType($trade, null);
            $symbol = (string) ($trade['symbol'] ?? '?');
            $profit = (float) ($trade['profit'] ?? 0);
            $dir = strtoupper((string) ($trade['direction'] ?? ''));
            $title = match ($type) {
                'SL_HIT' => 'Stop loss hit',
                'TP_HIT' => 'Take profit hit',
                default => 'Trade closed',
            };
            $this->notifications->create($userId, [
                'account_id' => $accountId,
                'type' => $type,
                'title' => $title,
                'message' => sprintf(
                    '%s · %s %s closed on %s · P/L %.2f',
                    $symbol,
                    $dir,
                    $ticket !== '' ? "#{$ticket}" : '',
                    $label,
                    $profit
                ),
            ]);
            if ($ticket !== '') {
                $alertedTickets[$ticket] = true;
            }
        }

        $tradesByTicket = [];
        foreach ($newTrades as $trade) {
            $ticket = (string) ($trade['ticket'] ?? '');
            if ($ticket !== '') {
                $tradesByTicket[$ticket] = $trade;
            }
        }

        foreach ($closedPositions as $pos) {
            $ticket = (string) ($pos['ticket'] ?? '');
            if ($ticket === '' || isset($alertedTickets[$ticket])) {
                continue;
            }
            $matched = $tradesByTicket[$ticket] ?? null;
            $type = $this->resolveCloseNotificationType($matched, $pos);
            $symbol = (string) ($pos['symbol'] ?? '?');
            $dir = strtoupper((string) ($pos['direction'] ?? ''));
            $profit = (float) (($matched['profit'] ?? null) ?? ($pos['profit'] ?? 0));
            $title = match ($type) {
                'SL_HIT' => 'Stop loss hit',
                'TP_HIT' => 'Take profit hit',
                default => 'Position closed',
            };
            $this->notifications->create($userId, [
                'account_id' => $accountId,
                'type' => $type,
                'title' => $title,
                'message' => sprintf(
                    '%s · %s #%s closed on %s · P/L %.2f',
                    $symbol,
                    $dir,
                    $ticket,
                    $label,
                    $profit
                ),
            ]);
        }
    }

    /** @param array<string, mixed>|null $trade @param array<string, mixed>|null $position */
    private function resolveCloseNotificationType(?array $trade, ?array $position): string
    {
        $meta = is_array($trade['metadata'] ?? null) ? $trade['metadata'] : [];
        $notif = strtoupper((string) ($meta['notification_type'] ?? ''));
        if (in_array($notif, ['SL_HIT', 'TP_HIT', 'TRADE_CLOSED'], true)) {
            return $notif;
        }
        $reason = strtolower((string) ($meta['close_reason'] ?? ''));
        if ($reason === 'sl' || str_contains($reason, 'stop')) {
            return 'SL_HIT';
        }
        if ($reason === 'tp' || str_contains($reason, 'take')) {
            return 'TP_HIT';
        }

        if ($trade && $position) {
            $exit = isset($trade['exit_price']) ? (float) $trade['exit_price'] : null;
            $sl = isset($position['stop_loss']) && $position['stop_loss'] !== null
                ? (float) $position['stop_loss'] : null;
            $tp = isset($position['take_profit']) && $position['take_profit'] !== null
                ? (float) $position['take_profit'] : null;
            if ($exit !== null && $sl !== null && $sl > 0 && abs($exit - $sl) / max($sl, 1e-9) < 0.0005) {
                return 'SL_HIT';
            }
            if ($exit !== null && $tp !== null && $tp > 0 && abs($exit - $tp) / max($tp, 1e-9) < 0.0005) {
                return 'TP_HIT';
            }
        }

        return 'TRADE_CLOSED';
    }
}
