<?php
declare(strict_types=1);

final class ReviewService
{
    private array $completedStatuses = ['delivered', 'completed', 'done'];

    public function handle(string $action, array $data, ?array $user): array
    {
        if (!$user || empty($user['id'])) {
            return ['ok' => false, 'message' => 'Щоб залишити відгук, увійдіть у кабінет.'];
        }

        return match ($action) {
            'review_create_order' => $this->createOrderReview($data, $user),
            'review_create_product' => $this->createProductReview($data, $user),
            default => ['ok' => false, 'message' => 'Невідома дія відгуку.'],
        };
    }

    public function productReviews(int $productId, int $limit = 20): array
    {
        if ($productId <= 0) {
            return [];
        }
        $limit = max(1, min($limit, 50));

        return Database::fetchAll(
            "SELECT id,
                    COALESCE(customer_name, author_name, 'Клієнт RungoCraft') AS customer_name,
                    rating,
                    review_text,
                    created_at
             FROM rc_reviews
             WHERE product_id = :product_id
               AND NVL(is_active, is_approved) = 1
             ORDER BY created_at DESC, id DESC
             FETCH FIRST {$limit} ROWS ONLY",
            ['product_id' => $productId]
        );
    }

    public function canReviewProduct(?array $user, int $productId): bool
    {
        if (!$user || empty($user['id']) || $productId <= 0) {
            return false;
        }

        return (bool)$this->completedOrderWithProduct((int)$user['id'], $productId);
    }

    public function canReviewOrder(?array $user, int $orderId): bool
    {
        if (!$user || empty($user['id']) || $orderId <= 0) {
            return false;
        }

        return (bool)$this->completedOrder((int)$user['id'], $orderId) && !$this->alreadyReviewedService($user);
    }

    public function alreadyReviewedService(?array $user): bool
    {
        if (!$user || empty($user['id'])) {
            return false;
        }
        $row = Database::fetchOne(
            'SELECT id FROM rc_reviews WHERE user_id = :user_id AND product_id IS NULL FETCH FIRST 1 ROWS ONLY',
            ['user_id' => (int)$user['id']]
        );
        return (bool)$row;
    }

    public function alreadyReviewedOrder(?array $user, int $orderId): bool
    {
        if (!$user || empty($user['id']) || $orderId <= 0) {
            return false;
        }
        $row = Database::fetchOne(
            'SELECT id FROM rc_reviews WHERE user_id = :user_id AND order_id = :order_id AND product_id IS NULL FETCH FIRST 1 ROWS ONLY',
            ['user_id' => (int)$user['id'], 'order_id' => $orderId]
        );
        return (bool)$row;
    }

    private function createOrderReview(array $data, array $user): array
    {
        $orderId = (int)($data['order_id'] ?? 0);
        $rating = $this->rating($data['rating'] ?? 5);
        $text = trim((string)($data['review_text'] ?? ''));

        if ($orderId <= 0 || $text === '' || mb_strlen($text) < 10) {
            return ['ok' => false, 'message' => 'Вкажіть замовлення і текст відгуку мінімум 10 символів.'];
        }

        $order = $this->completedOrder((int)$user['id'], $orderId);
        if (!$order) {
            return ['ok' => false, 'message' => 'Відгук можна залишити тільки після завершеного/доставленого замовлення.'];
        }

        if ($this->alreadyReviewedService($user)) {
            return ['ok' => false, 'message' => 'Відгук про сервіс уже залишено. Один клієнт може залишити тільки один сервісний відгук.'];
        }

        return $this->insertReview((int)$user['id'], $orderId, null, (string)$user['name'], $rating, $text, 'order_completed');
    }

    private function createProductReview(array $data, array $user): array
    {
        $productId = (int)($data['product_id'] ?? 0);
        $orderId = (int)($data['order_id'] ?? 0);
        $rating = $this->rating($data['rating'] ?? 5);
        $text = trim((string)($data['review_text'] ?? ''));

        if ($productId <= 0 || $text === '' || mb_strlen($text) < 10) {
            return ['ok' => false, 'message' => 'Вкажіть товар і текст відгуку мінімум 10 символів.'];
        }

        $order = $orderId > 0
            ? $this->completedOrderWithProduct((int)$user['id'], $productId, $orderId)
            : $this->completedOrderWithProduct((int)$user['id'], $productId);

        if (!$order) {
            return ['ok' => false, 'message' => 'Відгук до товару можна залишити тільки після завершеного замовлення з цим товаром.'];
        }

        $existing = Database::fetchOne(
            'SELECT id FROM rc_reviews WHERE user_id = :user_id AND product_id = :product_id AND order_id = :order_id FETCH FIRST 1 ROWS ONLY',
            ['user_id' => (int)$user['id'], 'product_id' => $productId, 'order_id' => (int)$order['ID']]
        );
        if ($existing) {
            return ['ok' => false, 'message' => 'Ви вже залишали відгук до цього товару в цьому замовленні.'];
        }

        return $this->insertReview((int)$user['id'], (int)$order['ID'], $productId, (string)$user['name'], $rating, $text, 'product_completed');
    }

    private function insertReview(int $userId, int $orderId, ?int $productId, string $name, int $rating, string $text, string $source): array
    {
        $ok = Database::execute(
            "INSERT INTO rc_reviews (
                user_id, order_id, product_id, customer_name, author_name, review_text, rating,
                is_active, is_approved, source, created_at, updated_at
             ) VALUES (
                :user_id, :order_id, :product_id, :customer_name, :author_name, :review_text, :rating,
                1, 1, :source, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             )",
            [
                'user_id' => $userId,
                'order_id' => $orderId,
                'product_id' => $productId,
                'customer_name' => $name !== '' ? $name : 'Клієнт RungoCraft',
                'author_name' => $name !== '' ? $name : 'Клієнт RungoCraft',
                'review_text' => $text,
                'rating' => $rating,
                'source' => $source,
            ]
        );

        return [
            'ok' => $ok,
            'message' => $ok ? 'Дякуємо! Відгук опубліковано.' : 'Відгук не збережено: ' . (Database::lastError() ?? 'невідома помилка'),
        ];
    }

    private function completedOrder(int $userId, int $orderId): ?array
    {
        $statuses = "'" . implode("','", $this->completedStatuses) . "'";
        return Database::fetchOne(
            "SELECT id, status
             FROM rc_orders
             WHERE id = :order_id
               AND user_id = :user_id
               AND (status IN ({$statuses}) OR delivery_status IN ({$statuses}))",
            ['order_id' => $orderId, 'user_id' => $userId]
        );
    }

    private function completedOrderWithProduct(int $userId, int $productId, int $orderId = 0): ?array
    {
        $statuses = "'" . implode("','", $this->completedStatuses) . "'";
        $params = ['user_id' => $userId, 'product_id' => $productId];
        $extra = '';
        if ($orderId > 0) {
            $extra = ' AND o.id = :order_id';
            $params['order_id'] = $orderId;
        }

        return Database::fetchOne(
            "SELECT o.id, o.status
             FROM rc_orders o
             JOIN rc_order_items oi ON oi.order_id = o.id
             WHERE o.user_id = :user_id
               AND oi.product_id = :product_id
               {$extra}
               AND (o.status IN ({$statuses}) OR o.delivery_status IN ({$statuses}))
             ORDER BY o.created_at DESC, o.id DESC
             FETCH FIRST 1 ROWS ONLY",
            $params
        );
    }

    private function rating(mixed $value): int
    {
        return max(1, min(5, (int)$value));
    }
}
