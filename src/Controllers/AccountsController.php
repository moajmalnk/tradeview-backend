<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Repositories\AccountRepository;
use App\Repositories\NotificationRepository;
use App\Support\Validator;

final class AccountsController
{
    public function __construct(
        private AccountRepository $accounts = new AccountRepository(),
        private NotificationRepository $notifications = new NotificationRepository(),
    ) {
    }

    public function index(Request $request): void
    {
        $userId = $request->user['id'];
        foreach ($this->accounts->markStaleConnections($userId, 120) as $stale) {
            $label = (string) ($stale['label'] ?? 'Account');
            $this->notifications->create($userId, [
                'account_id' => $stale['id'],
                'type' => 'CONNECTION_LOST',
                'title' => 'Connector silent',
                'message' => "{$label} has not synced for over 2 minutes. Check that the MT5 EA is running.",
            ]);
        }
        Response::data($this->accounts->listForUser($userId));
    }

    public function show(Request $request): void
    {
        $row = $this->accounts->findForUser($request->user['id'], $request->params['id']);
        if (!$row) {
            Response::error('NOT_FOUND', 'Account not found.', 404);

            return;
        }
        Response::data($row);
    }

    public function store(Request $request): void
    {
        $v = Validator::validate($request->body, [
            'label' => 'required|string',
            'platform' => 'required|enum:mt4,mt5,tradingview,ctrader,ibkr,binance',
        ]);
        if (!$v['ok']) {
            Response::error('VALIDATION_ERROR', 'Invalid input.', 422, $v['errors']);

            return;
        }
        $row = $this->accounts->create($request->user['id'], $request->body);
        $label = (string) ($row['label'] ?? 'Account');
        $platform = strtoupper((string) ($row['platform'] ?? ''));
        $this->notifications->create($request->user['id'], [
            'account_id' => $row['id'],
            'type' => 'CONNECTION_RESTORED',
            'title' => 'Account added',
            'message' => "{$label} ({$platform}) is ready for monitoring. Generate a connector token to sync live data.",
        ]);
        Response::data($row, 201);
    }

    public function update(Request $request): void
    {
        $before = $this->accounts->findForUser($request->user['id'], $request->params['id']);
        $row = $this->accounts->update($request->user['id'], $request->params['id'], $request->body);
        if (!$row) {
            Response::error('NOT_FOUND', 'Account not found.', 404);

            return;
        }

        $prevStatus = (string) ($before['connection_status'] ?? '');
        $nextStatus = (string) ($row['connection_status'] ?? '');
        if ($prevStatus !== $nextStatus && $nextStatus !== '') {
            $label = (string) ($row['label'] ?? 'Account');
            if ($nextStatus === 'live') {
                $this->notifications->create($request->user['id'], [
                    'account_id' => $row['id'],
                    'type' => 'CONNECTION_RESTORED',
                    'title' => 'Connector restored',
                    'message' => "{$label} is receiving monitoring data again.",
                ]);
            } elseif (in_array($nextStatus, ['disconnected', 'error'], true)) {
                $this->notifications->create($request->user['id'], [
                    'account_id' => $row['id'],
                    'type' => 'CONNECTION_LOST',
                    'title' => 'Connection lost',
                    'message' => "{$label} stopped reporting. Check your connector or Expert Advisor.",
                ]);
            }
        }

        Response::data($row);
    }

    public function destroy(Request $request): void
    {
        if (!$this->accounts->delete($request->user['id'], $request->params['id'])) {
            Response::error('NOT_FOUND', 'Account not found.', 404);

            return;
        }
        Response::data(['ok' => true]);
    }
}
