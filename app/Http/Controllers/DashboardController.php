<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
// هنا بنستدعي أداة التعامل مع قاعدة البيانات
use Illuminate\Support\Facades\DB; 

class DashboardController extends Controller
{
public function index()
{
    // 1. إجمالي المخزن
    $totalItems = \DB::table('medicines')->count();

    // 2. حساب الأدوية القريبة من الانتهاء (أقل من 14 يوم مثلاً)
    // لو لسه جدول medicines مافيهوش تاريخ صلاحية حقيقي، سيب الـ 5 مؤقتاً كدة:
    $nearExpiryCount = 5; 
    
    // هنقرا الأدوية نفسها عشان نعرضها في الجدول تحت
//    $nearExpiryMedicines = \DB::table('medicines')
  //      ->whereRaw('DATEDIFF(expiry_date, NOW()) <= 14')
    //    ->get();
        
    // لو الجدول لسه مش جاهز بالتواريخ، ممكن نبعت كل الأدوية مؤقتاً للتجربة:
     $nearExpiryMedicines = \DB::table('medicines')->take(5)->get();

    $lowStock = 5;
    $todaySales = 1200;

    return view('dashboard', compact('totalItems', 'lowStock', 'todaySales', 'nearExpiryCount', 'nearExpiryMedicines'));
}
}