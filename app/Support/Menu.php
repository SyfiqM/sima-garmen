<?php

namespace App\Support;

class Menu
{
    public static function getForRole(string $role): array
    {
        $menus = [
            'dashboard' => ['label' => 'Dashboard', 'path' => '/dashboard', 'icon' => 'home'],
            'order' => ['label' => 'Order', 'path' => '/orders', 'icon' => 'shopping-cart'],
            'persetujuan' => ['label' => 'Persetujuan Order', 'path' => '/approvals', 'icon' => 'check-circle'],
            'work_order' => ['label' => 'Work Order', 'path' => '/work-orders', 'icon' => 'clipboard'],
            'kekurangan_bahan' => ['label' => 'Kekurangan Bahan', 'path' => '/material-shortages', 'icon' => 'alert-triangle'],
            'pembelian' => ['label' => 'Pembelian (PO)', 'path' => '/purchases', 'icon' => 'file-text'],
            'penerimaan' => ['label' => 'Penerimaan Bahan', 'path' => '/receipts', 'icon' => 'inbox'],
            'pengeluaran' => ['label' => 'Pengeluaran Bahan', 'path' => '/issues', 'icon' => 'package'],
            'stok' => ['label' => 'Stok Bahan', 'path' => '/stocks', 'icon' => 'database'],
            'opname' => ['label' => 'Stok Opname', 'path' => '/stock-opnames', 'icon' => 'edit-3'],
            'produksi' => ['label' => 'Input Produksi', 'path' => '/productions', 'icon' => 'scissors'],
            'repair' => ['label' => 'Antrean Repair', 'path' => '/repairs', 'icon' => 'tool'],
            'qc' => ['label' => 'Inspeksi QC', 'path' => '/qc', 'icon' => 'search'],
            'packing' => ['label' => 'Packing (Koli)', 'path' => '/packings', 'icon' => 'box'],
            'pengiriman' => ['label' => 'Pengiriman', 'path' => '/shipments', 'icon' => 'truck'],
            'master' => ['label' => 'Master Data', 'path' => '/master', 'icon' => 'server'],
            'laporan' => ['label' => 'Laporan', 'path' => '/reports', 'icon' => 'pie-chart'],
            'pengguna' => ['label' => 'Pengguna & Pengaturan', 'path' => '/settings', 'icon' => 'users'],
            'audit' => ['label' => 'Audit Log', 'path' => '/audit-logs', 'icon' => 'activity'],
        ];

        $roleMenus = [
            'admin' => ['dashboard', 'order', 'persetujuan', 'work_order', 'kekurangan_bahan', 'pembelian', 'penerimaan', 'pengeluaran', 'stok', 'opname', 'produksi', 'repair', 'qc', 'packing', 'pengiriman', 'master', 'laporan', 'pengguna', 'audit'],
            'manajer' => ['dashboard', 'order', 'persetujuan', 'work_order', 'kekurangan_bahan', 'pembelian', 'penerimaan', 'pengeluaran', 'stok', 'opname', 'repair', 'qc', 'packing', 'pengiriman', 'master', 'laporan', 'audit'],
            'staf_order' => ['dashboard', 'order', 'work_order', 'pengiriman', 'master', 'laporan'],
            'gudang' => ['dashboard', 'order', 'work_order', 'kekurangan_bahan', 'pembelian', 'penerimaan', 'pengeluaran', 'stok', 'opname', 'packing', 'pengiriman', 'master', 'laporan'],
            'supervisor' => ['dashboard', 'work_order', 'pengeluaran', 'produksi', 'repair', 'packing', 'laporan'],
            'qc' => ['dashboard', 'work_order', 'repair', 'qc', 'laporan'],
            'pimpinan' => ['dashboard', 'order', 'work_order', 'pembelian', 'pengeluaran', 'stok', 'pengiriman', 'laporan'],
        ];

        $allowedKeys = $roleMenus[$role] ?? [];
        return array_values(array_intersect_key($menus, array_flip($allowedKeys)));
    }
}