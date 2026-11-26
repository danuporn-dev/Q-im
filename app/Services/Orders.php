<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Clock;
use App\Support\Database;
use App\Support\HttpError;
use App\Support\Money;
use PDOException;

/** Checkout and reads. State changes live in OrderFlow. */
final class Orders
{
    /** Largest DECIMAL(10,2), in satang. */
    private const MAX_TOTAL = 9_999_999_999;
    private const CUSTOMER_FIELDS = [
        'id', 'order_no', 'status', 'total', 'shop', 'queue', 'payment_status', 'payment_method', 'paid_at', 'reason', 'items',
        'created_at', 'accepted_at', 'preparing_at', 'ready_at', 'completed_at',
    ];
    public const PAYMENT_METHODS = ['PAY_AT_STORE', 'PROMPTPAY'];

    /** What a customer may choose: the shop's own PromptPay number (D38), or online PromptPay instead (D42). */
    public static function paymentMethods(): array
    {
        return \App\Payments\Payments::online() ? ['PAY_AT_STORE', OnlinePayments::METHOD] : self::PAYMENT_METHODS;
    }
    public const ACTIVE = "('PENDING','ACCEPTED','PREPARING','READY')";

    /** @return array{0: int, 1: bool} order id and whether it was created now */
    public static function create(int $userId, string $key, string $quote, string $method = 'PAY_AT_STORE'): array
    {
        if ($id = self::byKey($userId, $key)) {
            return [$id, false];
        }
        for ($attempt = 1; ; $attempt++) {
            try {
                return Database::tx(static fn (): array => self::checkout($userId, $key, $quote, $method));
            } catch (PDOException $e) {
                if (!Database::isDuplicate($e) || $attempt >= 3) {
                    throw $e;
                }
                // Either a concurrent retry with the same key won, or the random order number collided.
                if ($id = self::byKey($userId, $key)) {
                    return [$id, false];
                }
            }
        }
    }

    private static function checkout(int $userId, string $key, string $quote, string $method): array
    {
        // Lock order everywhere: cart, shop, order, counter.
        $cart = Database::row('SELECT id, shop_id FROM carts WHERE user_id = ? FOR UPDATE', [$userId]);
        if ($id = self::byKey($userId, $key)) {
            return [$id, false];
        }
        if ($cart === null) {
            throw HttpError::conflict('CART_EMPTY', 'ตะกร้าว่าง');
        }
        $shopId = (int) $cart['shop_id'];
        // Lock only the shop row: locking the joined food court would serialize every shop in it.
        Database::row('SELECT id FROM shops WHERE id = ? FOR UPDATE', [$shopId]);
        $shop = Database::row(
            'SELECT s.accepting_orders, s.active, f.active AS court_active, t.max_active_orders FROM shops s
             JOIN food_courts f ON f.id = s.food_court_id LEFT JOIN shop_settings t ON t.shop_id = s.id WHERE s.id = ?',
            [$shopId]
        );
        if (!$shop['active'] || !$shop['court_active'] || !$shop['accepting_orders'] || !Catalog::withinHours(Catalog::hours([$shopId])[$shopId] ?? [])) {
            throw HttpError::conflict('SHOP_CLOSED', 'ร้านปิดรับออเดอร์อยู่ในขณะนี้');
        }
        if ($method === 'PROMPTPAY' && PromptPay::numberOf($shopId) === null) {
            throw HttpError::conflict('PAYMENT_METHOD_UNAVAILABLE', 'ร้านนี้ยังไม่รับ PromptPay กรุณาเลือกชำระที่ร้าน');
        }
        if ($shop['max_active_orders'] !== null) {
            $active = (int) Database::value("SELECT COUNT(*) FROM orders WHERE shop_id = ? AND status IN ('PENDING','ACCEPTED','PREPARING')", [$shopId]);
            if ($active >= (int) $shop['max_active_orders']) {
                throw HttpError::conflict('SHOP_FULL', 'ร้านมีออเดอร์เต็มแล้ว กรุณาลองใหม่ภายหลัง');
            }
        }
        // Freeze prices and availability until the snapshots are written.
        Database::rows('SELECT m.id FROM menus m JOIN cart_items i ON i.menu_id = m.id WHERE i.cart_id = ? LOCK IN SHARE MODE', [$cart['id']]);
        $priced = Cart::price((int) $cart['id'], $shopId);
        if ($priced['items'] === []) {
            throw HttpError::conflict('CART_EMPTY', 'ตะกร้าว่าง');
        }
        if ($priced['has_problems']) {
            throw HttpError::conflict('ITEM_UNAVAILABLE', array_values(array_filter(array_column($priced['items'], 'problem')))[0]);
        }
        if (Money::toSatang($priced['total']) > self::MAX_TOTAL) {
            throw HttpError::invalid('ยอดรวมสูงเกินกว่าที่ระบบรองรับ');
        }
        if ($method === OnlinePayments::METHOD && Money::toSatang($priced['total']) < Money::toSatang(OnlinePayments::MIN_TOTAL)) {
            throw HttpError::conflict('ONLINE_MINIMUM', 'จ่ายออนไลน์ได้ตั้งแต่ ' . OnlinePayments::MIN_TOTAL . ' บาท กรุณาเลือกชำระที่ร้าน');
        }
        if (!hash_equals($priced['quote'], $quote)) {
            throw HttpError::conflict('PRICE_CHANGED', 'ราคาหรือรายการเปลี่ยนไป กรุณาตรวจสอบแล้วยืนยันใหม่', ['total' => $priced['total']]);
        }
        $orderId = Database::insert('orders', [
            'public_order_no' => self::orderNumber(), 'user_id' => $userId, 'shop_id' => $shopId, 'status' => 'PENDING',
            'total' => $priced['total'], 'idempotency_key' => $key, 'created_at' => Clock::now(),
        ]);
        foreach ($priced['items'] as $item) {
            $itemId = Database::insert('order_items', ['order_id' => $orderId, 'menu_id' => $item['menu_id'], 'name_snapshot' => $item['name'], 'unit_price' => $item['base_price'], 'quantity' => $item['quantity'], 'note' => $item['note']]);
            foreach ($item['options'] as $o) {
                Database::insert('order_item_options', ['order_item_id' => $itemId, 'option_id' => $o['id'], 'name_snapshot' => $o['name'], 'price_delta_snapshot' => $o['price_delta']]);
            }
        }
        Database::insert('payments', ['order_id' => $orderId, 'amount' => $priced['total'], 'method' => $method, 'requested_method' => $method]);
        Database::insert('order_status_logs', ['order_id' => $orderId, 'to_status' => 'PENDING', 'actor_user_id' => $userId, 'created_at' => Clock::now()]);
        Database::run('DELETE FROM carts WHERE id = ?', [$cart['id']]);
        return [$orderId, true];
    }

    private static function byKey(int $userId, string $key): int
    {
        return (int) Database::value('SELECT id FROM orders WHERE user_id = ? AND idempotency_key = ?', [$userId, $key]);
    }

    private static function orderNumber(): string
    {
        $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return 'QI' . Clock::bangkok()->format('ymd') . '-' . $code;
    }

    /** @return array{0: list<array>, 1: int} */
    public static function ofCustomer(int $userId, string $scope, int $limit, int $offset): array
    {
        $where = 'o.user_id = ?' . match ($scope) {
            'active' => ' AND o.status IN ' . self::ACTIVE,
            'completed' => " AND o.status = 'COMPLETED'",
            'cancelled' => " AND o.status IN ('CANCELLED','REJECTED')",
            default => '',
        };
        [$rows, $total] = self::page($where, [$userId], 'o.id DESC', $limit, $offset);
        return [array_map([self::class, 'forCustomer'], $rows), $total];
    }

    public static function customerOrder(int $userId, int $orderId): array
    {
        [$rows] = self::page('o.id = ? AND o.user_id = ?', [$orderId, $userId], 'o.id', 1, 0);
        $order = self::forCustomer($rows[0] ?? throw HttpError::notFound('ไม่พบออเดอร์'));
        $order += Eta::order($orderId, $order['shop']['id'], $order['status']);
        $order['can_cancel'] = $order['status'] === 'PENDING';
        $order['review'] = $order['status'] === 'COMPLETED' ? Reviews::ofOrder($orderId) : null;
        // Both QR codes appear only while the food waits at the shop.
        $order['pickup'] = $order['status'] === 'READY' ? PickupTokens::forCustomer($orderId, rtrim(str_replace('T', ' ', $order['ready_at']), 'Z')) : null;
        $order['promptpay'] = $order['status'] === 'READY' && $order['payment_status'] === 'UNPAID' && $order['payment_method'] === 'PROMPTPAY'
            ? PromptPay::forOrder($order['shop']['id'], $order['total']) : null;
        $online = OnlinePayments::forCustomer($orderId, $order['status'], $order['payment_status'], $order['payment_method']);
        if ($online !== null) {
            $order['online_payment'] = $online;
        }
        $order['timeline'] = array_map(
            static fn (array $l): array => ['status' => $l['to_status'], 'reason' => self::publicReason($l['to_status'], $l['reason']), 'at' => Clock::iso($l['created_at'])],
            Database::rows('SELECT to_status, reason, created_at FROM order_status_logs WHERE order_id = ? ORDER BY id', [$orderId])
        );
        return $order;
    }

    /** An allow-list, so that a field added for shops or admins never reaches customers by accident. */
    private static function forCustomer(array $order): array
    {
        $order['reason'] = self::publicReason($order['status'], $order['reason']);
        return array_intersect_key($order, array_flip(self::CUSTOMER_FIELDS));
    }

    /** Customers are told why an order was refused or cancelled; every other reason is internal to the shop. */
    private static function publicReason(string $status, ?string $reason): ?string
    {
        return in_array($status, ['REJECTED', 'CANCELLED'], true) ? $reason : null;
    }

    /** @return array{0: list<array>, 1: int} */
    public static function ofShop(int $shopId, string $scope, int $limit, int $offset): array
    {
        [$where, $sort] = $scope === 'closed'
            ? ["o.shop_id = ? AND o.status IN ('COMPLETED','REJECTED','CANCELLED')", 'o.id DESC']
            : ['o.shop_id = ? AND o.status IN ' . self::ACTIVE, 'q.business_date, q.queue_number, o.id'];
        [$rows, $total] = self::page($where, [$shopId], $sort, $limit, $offset);
        $due = OnlinePayments::refundDueOrders(array_column($rows, 'id'));
        return [array_map(static fn (array $o): array => self::forMerchant($o) + (isset($due[(int) $o['id']]) ? ['refund_due' => true] : []), $rows), $total];
    }

    public static function shopOrder(int $orderId): array
    {
        [$rows] = self::page('o.id = ?', [$orderId], 'o.id', 1, 0);
        return self::forMerchant($rows[0] ?? throw HttpError::notFound('ไม่พบออเดอร์'));
    }

    /**
     * The full order number is the fallback for the pickup QR, which only the customer may show. The privacy
     * notice promises that shops see the food and notes only, so the customer's name is left out.
     */
    private static function forMerchant(array $order): array
    {
        unset($order['customer_name']);
        $readyAt = $order['ready_at'] === null ? null : rtrim(str_replace('T', ' ', $order['ready_at']), 'Z');
        $order['can_no_show'] = $order['status'] === 'READY' && $order['payment_status'] === 'UNPAID' && OrderFlow::waitedLongEnough($readyAt);
        $order['order_no'] = substr($order['order_no'], 0, -4) . '••••';
        return $order;
    }

    /**
     * $where and $sort are trusted SQL fragments.
     *
     * @return array{0: list<array>, 1: int}
     */
    public static function page(string $where, array $params, string $sort, int $limit, int $offset): array
    {
        $from = 'FROM orders o JOIN shops s ON s.id = o.shop_id JOIN users u ON u.id = o.user_id
                 LEFT JOIN queues q ON q.order_id = o.id LEFT JOIN payments p ON p.order_id = o.id';
        $total = (int) Database::value("SELECT COUNT(*) $from WHERE $where", $params);
        $rows = Database::rows(
            "SELECT o.id, o.public_order_no, o.status, o.total, o.created_at, o.accepted_at, o.preparing_at, o.ready_at, o.completed_at,
                    o.shop_id, s.name AS shop_name, u.display_name AS customer_name, q.queue_number, q.business_date,
                    p.status AS payment_status, p.paid_at, IF(p.status = 'PAID', p.method, p.requested_method) AS method,
                    (SELECT l.reason FROM order_status_logs l WHERE l.order_id = o.id AND l.reason IS NOT NULL ORDER BY l.id DESC LIMIT 1) AS reason
             $from WHERE $where ORDER BY $sort LIMIT $limit OFFSET $offset",
            $params
        );
        $items = self::items(array_column($rows, 'id'));
        return [array_map(static fn (array $o): array => [
            'id' => $o['id'], 'order_no' => $o['public_order_no'], 'status' => $o['status'], 'total' => $o['total'],
            'shop' => ['id' => $o['shop_id'], 'name' => $o['shop_name']], 'customer_name' => $o['customer_name'],
            'queue' => $o['queue_number'] === null ? null : ['number' => $o['queue_number'], 'business_date' => $o['business_date']],
            'payment_status' => $o['payment_status'], 'payment_method' => $o['method'], 'paid_at' => Clock::iso($o['paid_at']),
            'reason' => $o['reason'], 'items' => $items[$o['id']] ?? [],
            'created_at' => Clock::iso($o['created_at']), 'accepted_at' => Clock::iso($o['accepted_at']), 'preparing_at' => Clock::iso($o['preparing_at']),
            'ready_at' => Clock::iso($o['ready_at']), 'completed_at' => Clock::iso($o['completed_at']),
        ], $rows), $total];
    }

    private static function items(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $orderIds));
        $options = [];
        foreach (Database::rows("SELECT o.order_item_id, o.name_snapshot AS name, o.price_delta_snapshot AS price_delta FROM order_item_options o JOIN order_items i ON i.id = o.order_item_id WHERE i.order_id IN ($in) ORDER BY o.id") as $o) {
            $options[$o['order_item_id']][] = ['name' => $o['name'], 'price_delta' => $o['price_delta']];
        }
        $items = [];
        $rows = Database::rows("SELECT id, order_id, menu_id, name_snapshot, unit_price, quantity, note FROM order_items WHERE order_id IN ($in) ORDER BY id");
        // The menu's picture today, not a snapshot: a retired menu shows its drawn picture (D41).
        $pictures = FoodArt::forMenus(array_column($rows, 'menu_id'));
        foreach ($rows as $i) {
            $chosen = $options[$i['id']] ?? [];
            $unit = Money::toSatang($i['unit_price']);
            foreach ($chosen as $o) {
                $unit += Money::toSatang($o['price_delta']);
            }
            $picture = $pictures[(int) $i['menu_id']] ?? ['image_url' => null, 'art' => FoodArt::of(null, $i['name_snapshot'])];
            $items[$i['order_id']][] = ['menu_id' => $i['menu_id'], 'name' => $i['name_snapshot'], 'quantity' => $i['quantity'], 'note' => $i['note'], 'unit_price' => $i['unit_price'], 'options' => $chosen, 'line_total' => Money::fromSatang($unit * $i['quantity'])] + $picture;
        }
        return $items;
    }
}
