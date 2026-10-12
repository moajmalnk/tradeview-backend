<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Repositories\AccountRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\TradeRepository;
use App\Support\Uuid;
use App\Support\Validator;

final class TradesController
{
    public function __construct(
        private TradeRepository $trades = new TradeRepository(),
        private AccountRepository $accounts = new AccountRepository(),
        private NotificationRepository $notifications = new NotificationRepository(),
    ) {
    }

    public function index(Request $request): void
    {
        $accountId = isset($request->query['account_id']) ? (string) $request->query['account_id'] : null;
        $limit = $request->intQuery('limit', 50, 1, 100);
        $offset = $request->intQuery('offset', 0, 0);
        Response::data($this->trades->list($request->user['id'], $accountId, $limit, $offset));
    }

    public function show(Request $request): void
    {
        $row = $this->trades->find($request->user['id'], $request->params['id']);
        if (!$row) {
            Response::error('NOT_FOUND', 'Trade not found.', 404);

            return;
        }
        Response::data($row);
    }

    public function store(Request $request): void
    {
        $v = Validator::validate($request->body, [
            'account_id' => 'required|string',
            'symbol' => 'required|string',
            'direction' => 'required|enum:buy,sell',
            'volume' => 'required|number',
            'entry_price' => 'required|number',
            'open_time' => 'required|string',
        ]);
        if (!$v['ok']) {
            Response::error('VALIDATION_ERROR', 'Invalid input.', 422, $v['errors']);

            return;
        }
        if (!$this->accounts->findForUser($request->user['id'], (string) $request->body['account_id'])) {
            Response::error('NOT_FOUND', 'Account not found.', 404);

            return;
        }
        $timeError = $this->timeError($request->body);
        if ($timeError) {
            Response::error('VALIDATION_ERROR', $timeError, 422);

            return;
        }

        $payload = $request->body;
        $payload['ticket'] = $payload['ticket'] ?? ('MAN-' . substr(Uuid::v4(), 0, 8));
        $meta = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $meta['manual'] = true;
        $payload['metadata'] = $meta;

        $row = $this->trades->create($request->user['id'], $payload);
        $dir = strtoupper((string) $row['direction']);
        $vol = $row['volume'];
        $symbol = (string) $row['symbol'];
        $status = (string) ($row['status'] ?? 'closed');
        $profit = (float) ($row['profit'] ?? 0);

        if ($status === 'open') {
            $this->notifications->create($request->user['id'], [
                'account_id' => $row['account_id'],
                'type' => 'TRADE_OPENED',
                'title' => 'Position opened',
                'message' => "{$symbol} {$dir} {$vol} logged as open.",
            ]);
        } else {
            $pnl = ($profit >= 0 ? '+' : '') . number_format($profit, 2);
            $this->notifications->create($request->user['id'], [
                'account_id' => $row['account_id'],
                'type' => 'TRADE_CLOSED',
                'title' => 'Trade closed',
                'message' => "{$symbol} {$dir} {$vol} closed at {$pnl}.",
            ]);
        }

        Response::data($row, 201);
    }

    public function update(Request $request): void
    {
        $before = $this->trades->find($request->user['id'], $request->params['id']);
        if (!$before) {
            Response::error('NOT_FOUND', 'Trade not found.', 404);

            return;
        }
        $timeError = $this->timeError($request->body);
        if ($timeError) {
            Response::error('VALIDATION_ERROR', $timeError, 422);

            return;
        }
        $row = $this->trades->update($request->user['id'], $request->params['id'], $request->body);
        if (!$row) {
            Response::error('NOT_FOUND', 'Trade not found.', 404);

            return;
        }

        $prev = (string) ($before['status'] ?? '');
        $next = (string) ($row['status'] ?? '');
        if ($prev !== 'closed' && $next === 'closed') {
            $dir = strtoupper((string) $row['direction']);
            $vol = $row['volume'];
            $symbol = (string) $row['symbol'];
            $profit = (float) ($row['profit'] ?? 0);
            $pnl = ($profit >= 0 ? '+' : '') . number_format($profit, 2);
            $this->notifications->create($request->user['id'], [
                'account_id' => $row['account_id'],
                'type' => 'TRADE_CLOSED',
                'title' => 'Trade closed',
                'message' => "{$symbol} {$dir} {$vol} closed at {$pnl}.",
            ]);
        }

        Response::data($row);
    }

    public function destroy(Request $request): void
    {
        if (!$this->trades->delete($request->user['id'], $request->params['id'])) {
            Response::error('NOT_FOUND', 'Trade not found.', 404);

            return;
        }
        Response::data(['ok' => true]);
    }

    /** @param array<string, mixed> $body */
    private function timeError(array $body): ?string
    {
        $now = new \DateTimeImmutable('now');
        $limit = $now->modify('+2 minutes');
        $open = null;
        if ($this->hasInstant($body, 'open_time')) {
            $open = $this->parseInstant($body['open_time']);
            if (!$open) {
                return 'Opened date is invalid.';
            }
            if ($open > $limit) {
                return "Opened time can't be in the future.";
            }
        }

        $status = strtolower((string) ($body['status'] ?? ''));
        $closeProvided = array_key_exists('close_time', $body);
        $hasClose = $this->hasInstant($body, 'close_time');
        if ($status === 'closed' && $closeProvided && !$hasClose) {
            return 'Enter a valid closed date.';
        }
        if ($hasClose) {
            $close = $this->parseInstant($body['close_time']);
            if (!$close) {
                return 'Enter a valid closed date.';
            }
            if ($open && $close < $open) {
                return 'Closed date must be on or after the Opened date.';
            }
            if ($close > $limit) {
                return "Closed time can't be in the future.";
            }
        }

        return null;
    }

    /** @param array<string, mixed> $body */
    private function hasInstant(array $body, string $key): bool
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return false;
        }

        return trim((string) $body[$key]) !== '';
    }

    private function parseInstant(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d/', $raw) === 1) {
            $raw = str_replace(' ', 'T', $raw);
        }
        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }
}
