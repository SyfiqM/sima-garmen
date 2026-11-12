<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /** Dashboard sesuai role pemanggil (bagian 7.1 rancangan). Field `tipe` menentukan bentuk datanya. */
    public function index(Request $request, ReportService $service): JsonResponse
    {
        return response()->json(['data' => $service->dashboard($request->user())]);
    }
}
