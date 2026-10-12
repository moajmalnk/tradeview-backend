<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Repositories\NotificationRepository;

final class NotificationsController
{
    public function __construct(private NotificationRepository $notifications = new NotificationRepository())
    {
    }

    public function index(Request $request): void
    {
        $userId = $request->user['id'];
        $this->notifications->ensureWelcome($userId);

        $limit = $request->intQuery('limit', 50, 1, 100);
        $offset = $request->intQuery('offset', 0, 0);
        $unread = isset($request->query['unread']) ? (string) $request->query['unread'] : null;

        $rows = $this->notifications->list($userId, $limit, $offset, $unread);
        Response::data([
            'items' => $rows,
            'unread_count' => $this->notifications->countUnread($userId),
            'total' => $this->notifications->countAll($userId),
        ]);
    }

    public function markRead(Request $request): void
    {
        $row = $this->notifications->markRead($request->user['id'], $request->params['id']);
        if (!$row) {
            Response::error('NOT_FOUND', 'Notification not found.', 404);

            return;
        }
        Response::data($row);
    }

    public function markAllRead(Request $request): void
    {
        $count = $this->notifications->markAllRead($request->user['id']);
        Response::data([
            'updated' => $count,
            'unread_count' => 0,
        ]);
    }
}
