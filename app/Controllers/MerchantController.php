<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\Auth;
use App\Services\Catalog;
use App\Services\Images;
use App\Services\OrderFlow;
use App\Services\Orders;
use App\Services\ShopAdmin;
use App\Support\RateLimiter;
use App\Support\Request;

final class MerchantController
{
    public static function orders(Request $q, int $shopId): array
    {
        self::member($q, $shopId);
        $scope = $q->query('scope') === 'closed' ? 'closed' : 'active';
        [$page, $per, $offset] = $q->page($scope === 'active' ? 200 : 20);
        [$rows, $total] = Orders::ofShop($shopId, $scope, $per, $offset);
        return [$rows, 200, ['page' => $page, 'per_page' => $per, 'total' => $total]];
    }

    public static function order(Request $q, int $orderId): array
    {
        $user = Auth::user($q, 'merchant');
        $order = Orders::shopOrder($orderId);
        Auth::requireShop($user['id'], (int) $order['shop']['id']);
        return [$order, 200];
    }

    /** Handler for one state transition. */
    public static function transition(string $action): callable
    {
        return static function (Request $q, int $orderId) use ($action): array {
            $user = Auth::user($q, 'merchant');
            OrderFlow::byMerchant($user['id'], $orderId, $action, $q->json());
            return [Orders::shopOrder($orderId), 200];
        };
    }

    public static function settings(Request $q, int $shopId): array
    {
        self::member($q, $shopId);
        return [ShopAdmin::settings($shopId), 200];
    }

    public static function updateSettings(Request $q, int $shopId): array
    {
        $user = self::member($q, $shopId);
        return [ShopAdmin::updateSettings($user['id'], $shopId, $q->json()), 200];
    }

    public static function menus(Request $q, int $shopId): array
    {
        self::member($q, $shopId);
        return [Catalog::menus($shopId, true), 200];
    }

    public static function createMenu(Request $q, int $shopId): array
    {
        $user = self::member($q, $shopId);
        return [ShopAdmin::saveMenu($user['id'], $shopId, null, $q->json()), 201];
    }

    public static function updateMenu(Request $q, int $menuId): array
    {
        $user = Auth::user($q, 'merchant');
        $shopId = ShopAdmin::menuShop($menuId);
        Auth::requireShop($user['id'], $shopId);
        return [ShopAdmin::saveMenu($user['id'], $shopId, $menuId, $q->json()), 200];
    }

    public static function createCategory(Request $q, int $shopId): array
    {
        $user = self::member($q, $shopId);
        return [ShopAdmin::saveCategory($user['id'], $shopId, null, $q->json()), 201];
    }

    public static function updateCategory(Request $q, int $shopId, int $id): array
    {
        $user = self::member($q, $shopId);
        return [ShopAdmin::saveCategory($user['id'], $shopId, $id, $q->json()), 200];
    }

    public static function upload(Request $q, int $shopId): array
    {
        $user = self::member($q, $shopId);
        RateLimiter::hit('upload', (string) $user['id'], 60, 3600);
        return [['image_url' => 'image:' . Images::store($_FILES['image'] ?? null)], 201];
    }

    private static function member(Request $q, int $shopId): array
    {
        $user = Auth::user($q, 'merchant');
        Auth::requireShop($user['id'], $shopId);
        return $user;
    }
}
