<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\Auth;
use App\Services\Cart;
use App\Services\OrderFlow;
use App\Services\Orders;
use App\Support\HttpError;
use App\Support\RateLimiter;
use App\Support\Request;

final class CustomerController
{
    public static function cart(Request $q): array
    {
        return [Cart::view(Auth::user($q, 'customer')['id']), 200];
    }

    public static function updateCart(Request $q): array
    {
        return [Cart::replace(Auth::user($q, 'customer')['id'], $q->json()), 200];
    }

    public static function checkout(Request $q): array
    {
        $user = Auth::user($q, 'customer');
        RateLimiter::hit('checkout', (string) $user['id'], 20, 60);
        $in = $q->json();
        $key = $in['idempotency_key'] ?? '';
        $quote = $in['quote'] ?? '';
        if (!is_string($key) || !preg_match('/^[A-Za-z0-9-]{16,64}$/D', $key) || !is_string($quote) || $quote === '') {
            throw HttpError::invalid('ข้อมูลยืนยันออเดอร์ไม่ครบ');
        }
        [$id, $created] = Orders::create($user['id'], $key, $quote);
        return [Orders::customerOrder($user['id'], $id), $created ? 201 : 200];
    }

    public static function orders(Request $q): array
    {
        $user = Auth::user($q, 'customer');
        [$page, $per, $offset] = $q->page(20);
        [$rows, $total] = Orders::ofCustomer($user['id'], $q->query('scope'), $per, $offset);
        return [$rows, 200, ['page' => $page, 'per_page' => $per, 'total' => $total]];
    }

    public static function order(Request $q, int $id): array
    {
        return [Orders::customerOrder(Auth::user($q, 'customer')['id'], $id), 200];
    }

    public static function cancel(Request $q, int $id): array
    {
        $user = Auth::user($q, 'customer');
        OrderFlow::cancelByCustomer($user['id'], $id);
        return [Orders::customerOrder($user['id'], $id), 200];
    }
}
