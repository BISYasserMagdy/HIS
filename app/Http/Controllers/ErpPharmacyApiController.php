<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ErpPharmacyApiController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return response()->json([], 200);
        }

        if ($request->isMethod('POST') && !$request->filled('action')) {
            return $this->login($request);
        }

        $action = trim((string) $request->input('action', $request->query('action', '')));

        return match ($action) {
            'products' => $this->products($request),
            'sales' => $this->sales($request),
            'sales_data' => $this->salesData($request),
            'monthly_summary' => $this->monthlySummary($request),
            'trend_data' => $this->trendData($request),
            'dashboard_stats' => $this->dashboardStats($request),
            'near_expiry_medicines' => $this->nearExpiryMedicines($request),
            'get_suppliers' => $this->getSuppliers($request),
            'submit_sale' => $this->submitSale($request),
            'add_supplier' => $this->addSupplier($request),
            'edit_supplier' => $this->editSupplier($request),
            'delete_supplier' => $this->deleteSupplier($request),
            default => response()->json([
                'success' => false,
                'message' => 'This pharmacy action has not been migrated to Laravel yet.',
            ], 501),
        };
    }

    private function login(Request $request): JsonResponse
    {
        $userId = trim((string) $request->input('user_id', $request->input('pharmacist_id', $request->input('employee_id', ''))));
        $password = (string) $request->input('password', '');
        $userType = strtolower(trim((string) $request->input('user_type', '')));

        if ($userType === '') {
            $userType = str_starts_with(strtoupper($userId), 'EMP') ? 'employee' : 'pharmacist';
        }

        if (!in_array($userType, ['pharmacist', 'employee'], true) || $userId === '' || $password === '') {
            return response()->json(['success' => false, 'message' => 'User ID and password are required'], 400);
        }

        $table = $userType === 'employee' ? 'employees' : 'pharmacists';
        $idColumn = $userType === 'employee' ? 'employee_id' : 'pharmacist_id';
        $account = DB::connection('pharmacy')->table($table)->where($idColumn, $userId)->first();

        if (!$account || empty($account->password_hash) || !password_verify($password, $account->password_hash)) {
            return response()->json(['success' => false, 'message' => 'Invalid user ID or password'], 401);
        }

        if ($account->status !== 'active') {
            return response()->json(['success' => false, 'message' => 'Your account is inactive. Contact administrator.'], 401);
        }

        $request->session()->regenerate();
        $request->session()->put([
            'user_type' => $userType,
            'user_id' => $userId,
            'user_name' => $account->name,
            'login_time' => time(),
        ]);

        $redirect = $userType === 'employee' ? 'ERP_POS_System.html' : 'ERP_Dashboard.html';

        return response()->json([
            'success' => true,
            'message' => 'Login successful! Welcome ' . $account->name,
            'name' => $account->name,
            'user_type' => $userType,
            'redirect' => $redirect,
        ]);
    }

    private function products(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request)) {
            return $denied;
        }

        $language = strtolower((string) $request->query('lang', 'en')) === 'ar' ? 'ar' : 'en';
        $nameColumn = $language === 'ar' ? 'name_ar' : 'name_en';
        $products = DB::connection('pharmacy')->table('medicines')
            ->select('id', 'medicine_code', "$nameColumn as name", 'category', 'quantity_in_stock', 'unit_price')
            ->orderBy('id')
            ->get()
            ->map(fn (object $product): array => [
                'id' => (int) $product->id,
                'code' => $product->medicine_code,
                'name' => $product->name,
                'category' => $product->category,
                'quantity_in_stock' => (int) $product->quantity_in_stock,
                'unit_price' => (float) $product->unit_price,
            ])->all();

        return response()->json(['success' => true, 'products' => $products]);
    }

    private function sales(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request)) {
            return $denied;
        }

        $sales = DB::connection('pharmacy')->table('sales')
            ->select('invoice_number', 'user_id', 'user_name', 'items', 'total_amount', 'status', 'created_at')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (object $sale): array => [
                'invoice_number' => $sale->invoice_number,
                'user_id' => $sale->user_id,
                'user_name' => $sale->user_name,
                'items' => json_decode($sale->items, true) ?: [],
                'total_amount' => (float) $sale->total_amount,
                'status' => $sale->status,
                'created_at' => $sale->created_at,
            ])->all();

        return response()->json(['success' => true, 'sales' => $sales]);
    }

    private function dashboardStats(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request)) {
            return $denied;
        }

        $database = DB::connection('pharmacy');
        $transactions = $database->table('sales')
            ->select('invoice_number', 'user_id', 'user_name', 'items', 'total_amount', 'status', 'created_at')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(function (object $sale): array {
                $items = json_decode($sale->items, true) ?: [];

                return [
                    'invoice_number' => $sale->invoice_number,
                    'user_id' => $sale->user_id,
                    'user_name' => $sale->user_name,
                    'item_count' => count($items),
                    'total_amount' => (float) $sale->total_amount,
                    'status' => $sale->status,
                    'created_at' => $sale->created_at,
                ];
            })->all();

        return response()->json([
            'success' => true,
            'total_stock' => (int) ($database->table('medicines')->sum('quantity_in_stock') ?: 0),
            'low_stock_count' => (int) $database->table('medicines')->whereColumn('quantity_in_stock', '<', 'reorder_level')->count(),
            'todays_sales' => (float) $database->table('sales')->whereDate('created_at', today())->sum('total_amount'),
            'transactions' => $transactions,
        ]);
    }

    private function salesData(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request)) {
            return $denied;
        }

        [$start, $end] = $this->dateRange($request);
        $sales = DB::connection('pharmacy')->table('sales')
            ->whereDate('created_at', '>=', $start)
            ->whereDate('created_at', '<=', $end)
            ->orderBy('created_at')
            ->get()
            ->map(fn (object $sale): array => [
                'invoice_number' => $sale->invoice_number,
                'user_id' => $sale->user_id,
                'user_name' => $sale->user_name,
                'items' => json_decode($sale->items, true) ?: [],
                'total_amount' => (float) $sale->total_amount,
                'status' => $sale->status,
                'created_at' => $sale->created_at,
            ])->all();

        return response()->json(['success' => true, 'sales' => $sales]);
    }

    private function monthlySummary(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request)) {
            return $denied;
        }

        [$start, $end] = $this->dateRange($request);
        $database = DB::connection('pharmacy');
        $totalSales = (float) $database->table('sales')
            ->whereDate('created_at', '>=', $start)
            ->whereDate('created_at', '<=', $end)
            ->sum('total_amount');
        $totalPurchases = Schema::connection('pharmacy')->hasTable('purchases')
            ? (float) $database->table('purchases')->whereDate('created_at', '>=', $start)->whereDate('created_at', '<=', $end)->sum('total_amount')
            : 0.0;
        $totalReturns = Schema::connection('pharmacy')->hasTable('returns')
            ? (float) $database->table('returns')->whereDate('created_at', '>=', $start)->whereDate('created_at', '<=', $end)->sum('total_amount')
            : 0.0;

        return response()->json([
            'success' => true,
            'start_date' => $start,
            'end_date' => $end,
            'total_sales' => $totalSales,
            'total_purchases' => $totalPurchases,
            'total_returns' => $totalReturns,
            'net_profit' => $totalSales - $totalPurchases - $totalReturns,
        ]);
    }

    private function trendData(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request)) {
            return $denied;
        }

        [$start, $end] = $this->dateRange($request, now()->subDays(7)->toDateString(), today()->toDateString());
        if (now()->parse($start)->diffInDays(now()->parse($end)) > 366) {
            return response()->json(['success' => false, 'message' => 'Date range cannot exceed 366 days.'], 422);
        }

        $totals = DB::connection('pharmacy')->table('sales')
            ->selectRaw('DATE(created_at) as day, SUM(total_amount) as total')
            ->whereDate('created_at', '>=', $start)
            ->whereDate('created_at', '<=', $end)
            ->groupByRaw('DATE(created_at)')
            ->pluck('total', 'day');

        $trend = [];
        $day = now()->parse($start)->startOfDay();
        $lastDay = now()->parse($end)->startOfDay();
        while ($day->lte($lastDay)) {
            $key = $day->toDateString();
            $trend[] = ['day' => $key, 'total' => (float) ($totals[$key] ?? 0)];
            $day->addDay();
        }

        return response()->json(['success' => true, 'trend' => $trend]);
    }

    private function nearExpiryMedicines(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request)) {
            return $denied;
        }

        if (!Schema::connection('pharmacy')->hasColumn('medicines', 'expiry_date')) {
            return response()->json(['success' => true, 'medicines' => []]);
        }

        $medicines = DB::connection('pharmacy')->table('medicines')
            ->select('medicine_code as code', 'name_en as name', 'category', 'quantity_in_stock', 'expiry_date')
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '>=', today())
            ->whereDate('expiry_date', '<=', today()->addDays(60))
            ->orderBy('expiry_date')
            ->get();

        return response()->json(['success' => true, 'medicines' => $medicines]);
    }

    private function getSuppliers(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['pharmacist'])) {
            return $denied;
        }

        if (!Schema::connection('pharmacy')->hasTable('suppliers')) {
            return response()->json(['success' => true, 'suppliers' => []]);
        }

        return response()->json([
            'success' => true,
            'suppliers' => DB::connection('pharmacy')->table('suppliers')->orderBy('id')->get(),
        ]);
    }

    private function addSupplier(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['pharmacist'])) {
            return $denied;
        }

        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            return response()->json(['success' => false, 'message' => 'Supplier name is required'], 400);
        }

        $id = DB::connection('pharmacy')->table('suppliers')->insertGetId([
            'name' => $name,
            'contact_person' => $request->input('contact_person'),
            'address' => $request->input('address'),
            'phone' => $request->input('phone'),
            'email' => $request->input('email'),
            'city' => $request->input('city'),
            'status' => $request->input('status') === 'Inactive' ? 'Inactive' : 'Active',
            'created_at' => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Supplier added successfully', 'id' => $id]);
    }

    private function editSupplier(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['pharmacist'])) {
            return $denied;
        }

        $id = (int) $request->input('id', 0);
        if ($id < 1) {
            return response()->json(['success' => false, 'message' => 'Supplier id is required'], 400);
        }

        $fields = ['name', 'contact_person', 'address', 'phone', 'email', 'city', 'status'];
        $updates = [];
        foreach ($fields as $field) {
            if ($request->exists($field)) {
                $updates[$field] = trim((string) $request->input($field));
            }
        }
        if (!$updates) {
            return response()->json(['success' => false, 'message' => 'No fields to update'], 400);
        }

        DB::connection('pharmacy')->table('suppliers')->where('id', $id)->update($updates);

        return response()->json(['success' => true, 'message' => 'Supplier updated successfully']);
    }

    private function deleteSupplier(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request, ['pharmacist'])) {
            return $denied;
        }

        $id = (int) $request->input('id', 0);
        if ($id < 1) {
            return response()->json(['success' => false, 'message' => 'Supplier id is required'], 400);
        }

        DB::connection('pharmacy')->table('suppliers')->where('id', $id)->delete();

        return response()->json(['success' => true, 'message' => 'Supplier deleted successfully']);
    }

    private function submitSale(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request)) {
            return $denied;
        }

        $sale = $request->input('sale_data');
        if (is_string($sale)) {
            $sale = json_decode($sale, true);
        }
        if (!is_array($sale)) {
            $sale = $request->json()->all();
        }

        $items = $sale['items'] ?? [];
        if (!is_array($items) || $items === []) {
            return response()->json(['success' => false, 'message' => 'Incomplete sale data'], 400);
        }

        $database = DB::connection('pharmacy');
        $normalizedItems = [];
        $total = 0.0;

        try {
            $invoiceNumber = trim((string) ($sale['invoice_number'] ?? '')) ?: 'INV-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(4));
            $database->transaction(function () use ($database, $items, $request, $invoiceNumber, &$normalizedItems, &$total): void {
                foreach ($items as $item) {
                    $medicineId = (int) ($item['id'] ?? 0);
                    $quantity = (int) ($item['quantity'] ?? 0);
                    if ($medicineId < 1 || $quantity < 1) {
                        throw new \InvalidArgumentException('Incomplete sale data');
                    }

                    $medicine = $database->table('medicines')->where('id', $medicineId)->lockForUpdate()->first();
                    if (!$medicine || (int) $medicine->quantity_in_stock < $quantity) {
                        throw new \DomainException('Insufficient stock or invalid product for sale');
                    }

                    $database->table('medicines')->where('id', $medicineId)->update([
                        'quantity_in_stock' => (int) $medicine->quantity_in_stock - $quantity,
                    ]);
                    $lineTotal = (float) $medicine->unit_price * $quantity;
                    $total += $lineTotal;
                    $normalizedItems[] = [
                        'id' => $medicineId,
                        'code' => $medicine->medicine_code,
                        'name' => $medicine->name_en,
                        'quantity' => $quantity,
                        'unit_price' => (float) $medicine->unit_price,
                        'subtotal' => $lineTotal,
                    ];
                }

                $database->table('sales')->insert([
                    'invoice_number' => $invoiceNumber,
                    'user_id' => (string) $request->session()->get('user_id'),
                    'user_name' => (string) $request->session()->get('user_name', ''),
                    'items' => json_encode($normalizedItems, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'total_amount' => $total,
                    'status' => 'completed',
                    'created_at' => now(),
                ]);
            });
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 400);
        } catch (\DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Sale recorded successfully',
            'invoice_number' => $invoiceNumber,
            'total_amount' => $total,
        ]);
    }

    private function dateRange(Request $request, ?string $defaultStart = null, ?string $defaultEnd = null): array
    {
        $start = (string) $request->query('start_date', $defaultStart ?? today()->startOfMonth()->toDateString());
        $end = (string) $request->query('end_date', $defaultEnd ?? today()->endOfMonth()->toDateString());

        foreach ([$start, $end] as $date) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
                abort(422, 'Dates must use YYYY-MM-DD format.');
            }
        }

        if ($start > $end) {
            abort(422, 'Start date must not be after end date.');
        }

        return [$start, $end];
    }

    private function authorize(Request $request, array $roles = ['pharmacist', 'employee']): ?JsonResponse
    {
        $userType = $request->session()->get('user_type');
        if (!$request->session()->get('user_id') || !$userType) {
            return response()->json([
                'success' => false,
                'status' => 'Unauthorized access',
                'message' => 'You must be logged in to perform this action.',
            ], 401);
        }

        if (!in_array($userType, $roles, true)) {
            return response()->json([
                'success' => false,
                'status' => 'Unauthorized access',
                'message' => 'Your account type (' . $userType . ') does not have permission for this action.',
            ], 403);
        }

        return null;
    }
}