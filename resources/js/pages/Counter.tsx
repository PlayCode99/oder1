import { Head, router, usePage } from '@inertiajs/react';
import { useVirtualizer } from '@tanstack/react-virtual';
import JsBarcode from 'jsbarcode';
import { Calendar, CheckCircle2, Factory, FilePlus2, Package, Pencil, Printer, ScanFace, Search } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import PendingInvitationsModal from '@/components/pending-invitations-modal';
import { WorkReceiptBillHeader, WorkReceiptTopBar } from '@/components/domain/orders/WorkReceiptHeader';
import { DEFAULT_BRANCH_HEADER_COLOR, resolveBranchHeaderColor } from '@/lib/branchHeaderColor';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { DashboardInvitation } from '@/types';
import { deriveFloorStats } from './counterStats';

export interface FloorStats {
    print_room: { new_job: number; new_job_qty: number; printer_1: number; printer_2: number; printer_3: number; completed: number; completed_qty: number };
    cutting: { new_job: number; new_job_qty: number; assigned: number; completed: number; completed_qty: number };
    heat_press: { new_job: number; new_job_qty: number; assigned: number; revising: number; completed: number; completed_qty: number };
    sewing: { new_job: number; new_job_qty: number; assigned: number; completed: number; completed_qty: number };
    embroidery: { new_job: number; new_job_qty: number; assigned: number; completed: number; completed_qty: number };
    screen_flex: { new_job: number; new_job_qty: number; assigned: number; revising: number; completed: number; completed_qty: number };
    qc: { new_job: number; new_job_qty: number; pending_inspect: number; completed: number; completed_qty: number };
    shipping: { pending_ship: number; pending_ship_qty: number; store_pickup: number; courier: number; onsite_delivery: number; completed_qty: number };
}

type CounterFilters = {
    branch_id?: string | null;
    billing_date_from: string | null;
    billing_date_to: string | null;
    shipping_date_from: string | null;
    shipping_date_to: string | null;
    search?: string | null;
    department?: DepartmentFilter | null;
};

type DepartmentFilter = 'all' | 'design' | 'print_room' | 'cutting' | 'heat_press' | 'embroidery' | 'sewing' | 'screen_flex' | 'qc' | 'shipping';

export interface OrderTableRow {
    id: number;
    billing_date: string;
    due_date: string;
    order_code: string;
    order_item_count?: number;
    has_order_pdf?: boolean;
    branch_name: string;
    customer_name: string;
    job_type: string;
    order_status: string;
    status: 'design' | 'print_room' | 'cutting' | 'heat_press' | 'embroidery' | 'sewing' | 'screen_flex' | 'qc' | 'shipping' | 'completed';
    receipt_code?: string;
    payment_status: 'paid' | 'deposit' | 'pending';
    has_payment_pdf?: boolean;
    receiver_name: string;
    details?: {
        order_code: string;
        job_name: string;
        job_type: string;
        order_status: string;
        billing_date: string | null;
        due_date: string | null;
        branch_name: string | null;
        delivery_method: string | null;
        shipping_address: string | null;
        customer: {
            name: string | null;
            phone: string | null;
            line_fb: string | null;
        };
        pricing: {
            total_amount: number;
            discount_percent: number;
            discount_amount: number;
            net_amount: number;
            paid_amount: number;
        };
        specification: Record<string, string | number | null> | null;
        specification_display?: Array<{ label: string; value: string }>;
        spec_sections?: {
            shirt: Array<{ label: string; value: string }>;
            pants: Array<{ label: string; value: string }>;
        };
        items: Array<{
            item_type: string;
            size_group: string;
            size_label: string;
            quantity: number;
            unit_price: number;
            total_price: number;
        }>;
        routings: Array<{
            id: number;
            is_required: boolean;
            station_name: string;
            status: string;
            print_machine?: string | null;
            assigned_user: string | null;
            cutting_team_name?: string | null;
            sewing_team_name?: string | null;
            embroidery_team_name?: string | null;
            screen_team_name?: string | null;
            heat_press_machine_name?: string | null;
            rework_note?: string | null;
            created_at?: string | null;
            started_at: string | null;
            completed_at: string | null;
        }>;
        receipts: Array<{
            receipt_code: string;
            payment_date: string | null;
            payment_type: string;
            payment_method: string;
            amount_paid: number;
            note: string | null;
        }>;
        personalization_rows?: Array<{
            name: string;
            size: string;
            number: string;
            quantity: number;
            unit_price: number;
            total_price: number;
        }>;
        artwork_url: string | null;
        reference_designs: string[];
    };
}

type CounterProps = {
    pendingInvitations?: DashboardInvitation[];
    branches: Array<{ value: string; label: string }>;
    floorStats: FloorStats;
    filters: CounterFilters;
    orders: OrderTableRow[];
};

type DepartmentCardProps = {
    title: string;
    subtitle?: string;
    icon: React.ReactNode;
    rows: Array<{ label: string; value: number; quantity?: number; tone?: 'red' | 'blue' | 'neutral' }>;
    accent: 'red' | 'blue' | 'slate';
    surfaceClass?: string;
    darkSurface?: boolean;
    glowClass?: string;
    layerClass?: string;
};

function formatInput(value: string | null | undefined): string {
    return value ?? '';
}

function escapeHtml(value: string | null | undefined): string {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function createOrderCodeBarcodeSvg(orderCode: string): string {
    try {
        const svgNode = document.createElementNS('http://www.w3.org/2000/svg', 'svg');

        JsBarcode(svgNode, orderCode, {
            format: 'CODE128',
            width: 1.45,
            height: 36,
            margin: 0,
            displayValue: true,
            text: orderCode,
            font: 'monospace',
            fontSize: 12,
            textMargin: 1,
        });

        return svgNode.outerHTML;
    } catch {
        return '';
    }
}

function DepartmentCard({ title, subtitle, icon, rows, accent, surfaceClass, darkSurface = false, layerClass }: DepartmentCardProps) {
    const borderClass = darkSurface
        ? 'border-slate-700/80'
        : accent === 'red'
            ? 'border-white/10'
            : accent === 'blue'
                ? 'border-white/10'
                : 'border-white/10';
    const iconClass = darkSurface
        ? accent === 'red'
            ? 'text-[#E21E26]/90'
            : 'text-white'
        : accent === 'red'
            ? 'text-[#E21E26]'
            : accent === 'blue'
                ? 'text-white'
                : 'text-slate-700';

    const primaryRow = rows[0];
    const secondaryRows = rows.slice(1);
    const primaryValueClass =
        primaryRow.tone === 'red'
            ? darkSurface
                ? 'text-[#E21E26]/90'
                : 'text-[#E21E26]'
            : primaryRow.tone === 'blue'
                ? darkSurface
                    ? 'text-white'
                    : 'text-white'
                : darkSurface
                    ? 'text-slate-100'
                    : 'text-slate-900';

    return (
        <article className={`relative overflow-hidden rounded-2xl border ${borderClass} ${surfaceClass ?? 'bg-gradient-to-br from-[#071A33] via-[#0A2344] to-[#0E2B52]'} p-4 shadow-sm transition-all duration-200 ease-out will-change-transform hover:translate-y-1 hover:shadow-lg`}>
            {layerClass ? <div className={`pointer-events-none absolute inset-0 ${layerClass}`} /> : null}

            <div className="relative z-10 flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex items-center gap-2">
                        <div className={`flex size-5 items-center justify-center ${iconClass}`}>
                            {icon}
                        </div>
                        <h3 className={`text-sm font-semibold ${darkSurface ? 'text-slate-100' : 'text-white'}`}>{title}</h3>
                    </div>
                    <p className="mt-1 text-[10px] text-slate-300/80">{primaryRow.label}</p>
                </div>

                <div className="text-right">
                    <p className={`mt-1 text-3xl font-semibold leading-none tabular-nums ${primaryValueClass}`}>{primaryRow.value}</p>
                    <p className="mt-1 text-[10px] text-slate-300/80">{primaryRow.quantity?.toLocaleString('th-TH') ?? 0} ตัว</p>
                </div>
            </div>

            {secondaryRows.length > 0 ? (
                <div className="relative z-10 mt-4 flex items-center justify-between gap-3 border-t border-white/10 pt-3">
                    {secondaryRows.map((row) => {
                        const valueClass =
                            row.tone === 'red'
                                ? darkSurface
                                    ? 'text-[#E21E26]/90'
                                    : 'text-[#E21E26]'
                                : row.tone === 'blue'
                                    ? darkSurface
                                        ? 'text-white'
                                        : 'text-white'
                                    : darkSurface
                                        ? 'text-slate-200'
                                        : 'text-slate-600';

                        return (
                            <div key={row.label} className="flex items-center justify-between gap-3">
                                <span className="text-[10px] font-medium text-slate-300/80">{row.label}</span>
                                <div className="text-right">
                                    <span className={`block text-base font-semibold tabular-nums ${valueClass}`}>{row.value}</span>
                                    <span className="mt-0.5 block text-[10px] text-slate-300/80">{row.quantity?.toLocaleString('th-TH') ?? 0} ตัว</span>
                                </div>
                            </div>
                        );
                    })}
                </div>
            ) : null}
        </article>
    );
}

const tableDateFormatter = new Intl.DateTimeFormat('th-TH', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
});

const departmentOptions: Array<{ value: DepartmentFilter; label: string }> = [
    { value: 'all', label: 'ทุกห้องการผลิต' },
    { value: 'print_room', label: 'ห้องพิมพ์' },
    { value: 'heat_press', label: 'ห้องอัด' },
    { value: 'cutting', label: 'ห้องตัด' },
    { value: 'embroidery', label: 'ห้องปัก' },
    { value: 'sewing', label: 'ห้องเย็บ' },
    { value: 'screen_flex', label: 'สกรีน,เฟล็ค' },
    { value: 'qc', label: 'ตรวจสอบ' },
    { value: 'shipping', label: 'จัดส่ง' },
];

function formatTableDate(value: string): string {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '-';
    }

    return tableDateFormatter.format(date);
}

function formatShortDate(value: string): string {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '-';
    }

    return new Intl.DateTimeFormat('th-TH', {
        day: '2-digit',
        month: '2-digit',
        year: '2-digit',
    }).format(date);
}

function dateTime(value: string | null | undefined): string {
    if (!value) {
        return '-';
    }

    const normalized = value.trim();
    const isoLikeValue = normalized.includes(' ') ? normalized.replace(' ', 'T') : normalized;
    const hasTimezone = /([zZ]|[+-]\d{2}:\d{2})$/.test(isoLikeValue);
    const date = new Date(hasTimezone ? isoLikeValue : `${isoLikeValue}Z`);

    if (Number.isNaN(date.getTime())) {
        return '-';
    }

    return new Intl.DateTimeFormat('th-TH', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    }).format(date);
}

type CounterDisplayStatus = 'design' | 'in_progress' | 'qc' | 'shipping' | 'completed';

function toCounterDisplayStatus(row: OrderTableRow): CounterDisplayStatus {
    switch (row.status) {
        case 'qc':
            return 'qc';
        case 'shipping':
            return 'shipping';
        case 'completed':
            return 'completed';
        default:
            // Counter view intentionally hides room-level granularity,
            // but if any required routing already completed, show in-progress state.
            if (row.details?.routings?.some((routing) => routing.is_required && routing.status === 'completed')) {
                return 'in_progress';
            }

            return 'design';
    }
}

function statusLabel(row: OrderTableRow): string {
    switch (toCounterDisplayStatus(row)) {
        case 'design':
            return 'คอนเฟิร์มแบบ';
        case 'in_progress':
            return 'กำลังดำเนินการ';
        case 'qc':
            return 'ตรวจสอบ';
        case 'shipping':
            return 'จัดส่ง';
        case 'completed':
            return 'ปิดงาน';
    }
}

function deliveryMethodLabel(method: string | null | undefined): string {
    switch (method) {
        case 'shipping':
            return 'ขนส่ง';
        case 'onsite':
            return 'หน้างาน';
        case 'pickup':
            return 'รับหน้าร้าน';
        default:
            return '-';
    }
}

function statusClass(row: OrderTableRow): string {
    switch (toCounterDisplayStatus(row)) {
        case 'design':
            return '!border-sky-500 !bg-sky-500 !text-white';
        case 'in_progress':
            return '!border-orange-500 !bg-orange-500 !text-white';
        case 'qc':
            return '!border-red-500 !bg-red-500 !text-white';
        case 'shipping':
            return '!border-blue-600 !bg-blue-600 !text-white';
        case 'completed':
            return '!border-emerald-600 !bg-emerald-600 !text-white';
    }
}

function paymentLabel(status: OrderTableRow['payment_status']): string {
    switch (status) {
        case 'paid':
            return 'ชำระครบ';
        case 'deposit':
            return 'มัดจำ';
        case 'pending':
            return 'ค้างชำระ';
    }
}

function paymentClass(status: OrderTableRow['payment_status']): string {
    switch (status) {
        case 'paid':
            return 'border-emerald-200 bg-emerald-50 text-emerald-700';
        case 'deposit':
            return 'border-[#E21E26]/25 bg-[#E21E26]/10 text-[#E21E26]';
        case 'pending':
            return 'border-[#E21E26]/25 bg-[#E21E26]/10 text-[#E21E26]';
    }
}

function formatMoney(value: number): string {
    return value.toLocaleString('th-TH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

function stationLabel(station: string): string {
    const labels: Record<string, string> = {
        design: 'ออกแบบ',
        print: 'ห้องพิมพ์',
        embroidery: 'ห้องปัก',
        screen: 'ห้องอัด',
        flex: 'ห้องสกรีน เฟล็กซ์',
        cutting: 'ห้องตัด',
        sewing: 'ห้องเย็บ',
        qc: 'ตรวจสอบ',
        shipping: 'จัดส่ง',
    };

    return labels[station] ?? station;
}

function routingStatusLabel(status: string): string {
    const labels: Record<string, string> = {
        pending: 'งานเข้า',
        in_progress: 'กำลังทำ',
        rejected: 'แก้ไข',
        completed: 'เสร็จสิ้น',
        skipped: 'ข้าม',
    };

    return labels[status] ?? status;
}

export function canEditOrderStatus(row: { orderStatus?: string | null; hasProductionProgress?: boolean } | null | undefined): boolean {
    if (!row) {
        return false;
    }

    const normalizedOrderStatus = row.orderStatus?.trim().toLowerCase();

    return normalizedOrderStatus === 'confirmed' && !row.hasProductionProgress;
}

function OrderVirtualizedGrid({
    rows,
    onOpenDetail,
    onOpenTimeline,
    onOpenPdf,
}: {
    rows: OrderTableRow[];
    onOpenDetail: (row: OrderTableRow) => void;
    onOpenTimeline: (row: OrderTableRow) => void;
    onOpenPdf: (row: OrderTableRow) => void;
}) {
    const parentRef = useRef<HTMLDivElement | null>(null);

    const virtualizer = useVirtualizer({
        count: rows.length,
        getScrollElement: () => parentRef.current,
        estimateSize: () => 50,
        overscan: 10,
    });

    const virtualRows = virtualizer.getVirtualItems();

    return (
        <div ref={parentRef} className="h-[calc(100vh-280px)] min-h-[550px] w-full overflow-y-auto overflow-x-hidden rounded-b-xl border border-slate-200 bg-white shadow-xs">
            <table className="sticky top-0 z-10 w-full table-fixed divide-y divide-slate-200 bg-slate-50 text-left">
                <thead>
                    <tr className="text-xs font-bold text-slate-600">
                        <th className="w-[7%] whitespace-nowrap px-2 py-2.5">วันที่เปิดบิล</th>
                        <th className="w-[7%] whitespace-nowrap px-2 py-2.5">วันที่ส่งงาน</th>
                        <th className="w-[11%] whitespace-nowrap px-2 py-2.5">เลขที่ออเดอร์</th>
                        <th className="w-[8%] px-2 py-2.5">สาขา</th>
                        <th className="w-[14%] px-2 py-2.5">ชื่อลูกค้า</th>
                        <th className="w-[9%] px-2 py-2.5">ประเภทงาน</th>
                        <th className="w-[6%] whitespace-nowrap px-2 py-2.5 text-right">จำนวนตัว</th>
                        <th className="w-[11%] whitespace-nowrap px-2 py-2.5">สถานะงาน</th>
                        <th className="w-[9%] whitespace-nowrap px-2 py-2.5">ใบจัดส่ง</th>
                        <th className="w-[11%] whitespace-nowrap px-2 py-2.5">สถานะชำระเงิน</th>
                        <th className="w-[8%] px-2 py-2.5">ผู้รับงาน</th>
                        <th className="w-[7%] px-2 py-2.5 text-center">ไทม์ไลน์</th>
                        <th className="w-[4%] px-2 py-2.5 text-center">จัดการ</th>
                    </tr>
                </thead>
            </table>

            {rows.length === 0 ? (
                <div className="flex h-[500px] items-center justify-center text-sm text-slate-500">ไม่พบข้อมูลออเดอร์ตามเงื่อนไขที่เลือก</div>
            ) : (
                <div className="relative" style={{ height: `${virtualizer.getTotalSize()}px` }}>
                    {virtualRows.map((virtualRow) => {
                        const row = rows[virtualRow.index];
                        const canEdit = canEditOrderStatus({
                            orderStatus: row.order_status,
                            hasProductionProgress: row.details?.routings?.some((routing) => routing.is_required && ['in_progress', 'completed'].includes(routing.status)) ?? false,
                        });

                        return (
                            <table
                                key={row.id}
                                className="absolute left-0 top-0 w-full table-fixed divide-y divide-slate-200 text-left"
                                style={{
                                    transform: `translateY(${virtualRow.start}px)`,
                                }}
                            >
                                <tbody>
                                    <tr className="h-[50px] text-xs text-slate-700 transition-colors hover:bg-slate-100">
                                        <td className="w-[7%] whitespace-nowrap px-2 py-2.5 text-xs text-slate-600">{formatTableDate(row.billing_date)}</td>
                                        <td className="w-[7%] whitespace-nowrap px-2 py-2.5 text-xs font-semibold text-slate-800">{formatTableDate(row.due_date)}</td>
                                        <td className="w-[11%] whitespace-nowrap px-2 py-2.5">
                                            <button
                                                type="button"
                                                className="text-xs font-bold text-[#E21E26] underline-offset-2 hover:underline"
                                                onClick={() => onOpenDetail(row)}
                                                title="ดูรายละเอียดออเดอร์"
                                            >
                                                {row.order_code}
                                            </button>
                                        </td>
                                        <td className="w-[8%] px-2 py-2.5 text-xs text-slate-600">
                                            <span className="block truncate">{row.branch_name}</span>
                                        </td>
                                        <td className="w-[14%] px-2 py-2.5 text-xs" title={row.customer_name}>
                                            <span className="block truncate font-medium text-slate-900">{row.customer_name}</span>
                                            <span className="mt-0.5 block truncate text-[11px] text-slate-500">{row.details?.job_name || '-'}</span>
                                        </td>
                                        <td className="w-[9%] px-2 py-2.5 text-xs text-slate-600">
                                            <span className="block truncate">{row.job_type}</span>
                                        </td>
                                        <td className="w-[6%] whitespace-nowrap px-2 py-2.5 text-right font-mono text-xs font-semibold text-slate-900">
                                            {row.order_item_count ?? 0}
                                        </td>
                                        <td className="w-[11%] whitespace-nowrap px-2 py-2.5">
                                            <Badge variant="outline" className={`${statusClass(row)} px-1.5 py-0.5 text-[11px]`}>
                                                {statusLabel(row)}
                                            </Badge>
                                        </td>
                                        <td className="w-[9%] whitespace-nowrap px-2 py-2.5">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                className="h-7 border-red-200 bg-red-50 px-2 text-[11px] font-semibold text-red-700 transition-colors duration-150 ease-out hover:border-red-300 hover:bg-red-100 hover:text-red-800"
                                                onClick={() => onOpenPdf(row)}
                                            >
                                                เปิด PDF
                                            </Button>
                                        </td>
                                        <td className="w-[11%] whitespace-nowrap px-2 py-2.5">
                                            <Badge variant="outline" className={`${paymentClass(row.payment_status)} px-1.5 py-0.5 text-[11px]`}>
                                                {paymentLabel(row.payment_status)}
                                            </Badge>
                                        </td>
                                        <td className="w-[8%] px-2 py-2.5 text-xs text-slate-500">
                                            <span className="block truncate">{row.receiver_name}</span>
                                        </td>
                                        <td className="w-[7%] px-2 py-2.5 text-center">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                className="h-7 border-[#174395] bg-[#174395] px-2 text-[11px] text-white transition-colors duration-150 ease-out hover:border-[#12367A] hover:bg-[#12367A] hover:text-white"
                                                onClick={() => onOpenTimeline(row)}
                                            >
                                                ไทม์ไลน์
                                            </Button>
                                        </td>
                                        <td className="w-[4%] px-2 py-2.5 text-center">
                                            <Button
                                                type="button"
                                                size="icon"
                                                variant="ghost"
                                                disabled={!canEdit}
                                                onClick={() => {
                                                    if (canEdit) {
                                                        router.visit(`/orders/${row.id}/edit`);
                                                    }
                                                }}
                                                title={canEdit ? 'แก้ไขออเดอร์' : 'แก้ไขได้เฉพาะออเดอร์สถานะยืนยันแล้ว'}
                                                className={`size-7 ${canEdit ? 'text-slate-500 hover:text-[#E21E26]' : 'cursor-not-allowed text-slate-300'}`}
                                            >
                                                <Pencil className="size-3.5" />
                                            </Button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

export default function Counter({ pendingInvitations = [], branches, floorStats: _floorStats, filters, orders }: CounterProps) {
    const { currentTeam } = usePage<{ currentTeam?: { slug: string } | null }>().props;
    const [showInvitations, setShowInvitations] = useState(pendingInvitations.length > 0);
    const [branchId, setBranchId] = useState(formatInput(filters.branch_id) || 'all');
    const [billingDateFrom, setBillingDateFrom] = useState(formatInput(filters.billing_date_from));
    const [billingDateTo, setBillingDateTo] = useState(formatInput(filters.billing_date_to));
    const [shippingDateFrom, setShippingDateFrom] = useState(formatInput(filters.shipping_date_from));
    const [shippingDateTo, setShippingDateTo] = useState(formatInput(filters.shipping_date_to));
    const [search, setSearch] = useState(formatInput(filters.search));
    const [department, setDepartment] = useState<DepartmentFilter>((filters.department as DepartmentFilter | null) ?? 'all');
    const [isBillingRangeOpen, setIsBillingRangeOpen] = useState(false);
    const [isShippingRangeOpen, setIsShippingRangeOpen] = useState(false);
    const [selectedOrder, setSelectedOrder] = useState<OrderTableRow | null>(null);
    const [timelineOrder, setTimelineOrder] = useState<OrderTableRow | null>(null);
    const printRef = useRef<HTMLDivElement | null>(null);
    const billingRangeRef = useRef<HTMLDivElement | null>(null);
    const shippingRangeRef = useRef<HTMLDivElement | null>(null);

    useEffect(() => {
        setBranchId(formatInput(filters.branch_id) || 'all');
        setBillingDateFrom(formatInput(filters.billing_date_from));
        setBillingDateTo(formatInput(filters.billing_date_to));
        setShippingDateFrom(formatInput(filters.shipping_date_from));
        setShippingDateTo(formatInput(filters.shipping_date_to));
        setSearch(formatInput(filters.search));
        setDepartment((filters.department as DepartmentFilter | null) ?? 'all');
    }, [filters.branch_id, filters.billing_date_from, filters.billing_date_to, filters.shipping_date_from, filters.shipping_date_to, filters.search, filters.department]);

    const handleResetFilters = () => {
        setBranchId('all');
        setBillingDateFrom('');
        setBillingDateTo('');
        setShippingDateFrom('');
        setShippingDateTo('');
        setSearch('');
        setDepartment('all');
        setIsBillingRangeOpen(false);
        setIsShippingRangeOpen(false);
    };

    const branchOptions = useMemo(
        () => [{ value: 'all', label: 'ทุกสาขา' }, ...branches],
        [branches],
    );

    const derivedFloorStats = useMemo(() => deriveFloorStats(orders), [orders]);

    void _floorStats;

    const departmentCards = useMemo(
        () => [
            {
                title: 'ห้องตัด',
                subtitle: 'งานเข้าใหม่ / งานที่เสร็จสิ้น',
                icon: <Factory className="size-5" />,
                accent: 'red' as const,
                rows: [
                    { label: 'งานเข้าใหม่', value: derivedFloorStats.cutting.new_job, quantity: derivedFloorStats.cutting.new_job_qty, tone: 'red' as const },
                    { label: 'งานที่เสร็จสิ้น', value: derivedFloorStats.cutting.completed, quantity: derivedFloorStats.cutting.completed_qty, tone: 'blue' as const },
                ],
            },
            {
                title: 'ห้องพิมพ์',
                subtitle: 'งานเข้าใหม่ / งานที่เสร็จสิ้น',
                icon: <Printer className="size-5" />,
                accent: 'blue' as const,
                rows: [
                    { label: 'งานเข้าใหม่', value: derivedFloorStats.print_room.new_job, quantity: derivedFloorStats.print_room.new_job_qty, tone: 'red' as const },
                    { label: 'งานที่เสร็จสิ้น', value: derivedFloorStats.print_room.completed, quantity: derivedFloorStats.print_room.completed_qty, tone: 'blue' as const },
                ],
            },
            {
                title: 'ห้องอัด',
                subtitle: 'งานเข้าใหม่ / งานที่เสร็จสิ้น',
                icon: <CheckCircle2 className="size-5" />,
                accent: 'red' as const,
                rows: [
                    { label: 'งานเข้าใหม่', value: derivedFloorStats.heat_press.new_job, quantity: derivedFloorStats.heat_press.new_job_qty, tone: 'red' as const },
                    { label: 'งานที่เสร็จสิ้น', value: derivedFloorStats.heat_press.completed, quantity: derivedFloorStats.heat_press.completed_qty, tone: 'blue' as const },
                ],
            },
            {
                title: 'ห้องปัก',
                subtitle: 'งานเข้าใหม่ / งานที่เสร็จสิ้น',
                icon: <CheckCircle2 className="size-5" />,
                accent: 'blue' as const,
                rows: [
                    { label: 'งานเข้าใหม่', value: derivedFloorStats.embroidery.new_job, quantity: derivedFloorStats.embroidery.new_job_qty, tone: 'red' as const },
                    { label: 'งานที่เสร็จสิ้น', value: derivedFloorStats.embroidery.completed, quantity: derivedFloorStats.embroidery.completed_qty, tone: 'blue' as const },
                ],
            },
            {
                title: 'ห้องเย็บ',
                subtitle: 'งานเข้าใหม่ / งานที่เสร็จสิ้น',
                icon: <CheckCircle2 className="size-5" />,
                accent: 'red' as const,
                rows: [
                    { label: 'งานเข้าใหม่', value: derivedFloorStats.sewing.new_job, quantity: derivedFloorStats.sewing.new_job_qty, tone: 'red' as const },
                    { label: 'งานที่เสร็จสิ้น', value: derivedFloorStats.sewing.completed, quantity: derivedFloorStats.sewing.completed_qty, tone: 'blue' as const },
                ],
            },
            {
                title: 'ห้องสกรีน เฟล็ค',
                subtitle: 'งานเข้าใหม่ / งานที่เสร็จสิ้น',
                icon: <ScanFace className="size-5" />,
                accent: 'blue' as const,
                rows: [
                    { label: 'งานเข้าใหม่', value: derivedFloorStats.screen_flex.new_job, quantity: derivedFloorStats.screen_flex.new_job_qty, tone: 'red' as const },
                    { label: 'งานที่เสร็จสิ้น', value: derivedFloorStats.screen_flex.completed, quantity: derivedFloorStats.screen_flex.completed_qty, tone: 'blue' as const },
                ],
            },
            {
                title: 'ห้องตรวจ',
                subtitle: 'งานเข้าใหม่ / งานที่เสร็จสิ้น',
                icon: <CheckCircle2 className="size-5" />,
                accent: 'red' as const,
                rows: [
                    { label: 'งานเข้าใหม่', value: derivedFloorStats.qc.new_job, quantity: derivedFloorStats.qc.new_job_qty, tone: 'red' as const },
                    { label: 'งานที่เสร็จสิ้น', value: derivedFloorStats.qc.completed, quantity: derivedFloorStats.qc.completed_qty, tone: 'blue' as const },
                ],
            },
            {
                title: 'ห้องจัดส่ง',
                subtitle: 'งานเข้าใหม่ / งานที่เสร็จสิ้น',
                icon: <Package className="size-5" />,
                accent: 'blue' as const,
                rows: [
                    { label: 'งานเข้าใหม่', value: derivedFloorStats.shipping.pending_ship, quantity: derivedFloorStats.shipping.pending_ship_qty, tone: 'red' as const },
                    {
                        label: 'งานที่เสร็จสิ้น',
                        value: derivedFloorStats.shipping.courier + derivedFloorStats.shipping.onsite_delivery + derivedFloorStats.shipping.store_pickup,
                        quantity: derivedFloorStats.shipping.completed_qty,
                        tone: 'blue' as const,
                    },
                ],
            },
        ],
        [derivedFloorStats],
    );

    const selectedBranchLabel = branchOptions.find((option) => option.value === branchId)?.label ?? 'ทุกสาขา';
    const selectedDepartmentLabel = departmentOptions.find((option) => option.value === department)?.label ?? 'ทุกห้องการผลิต';
    const billingRangeLabel = billingDateFrom && billingDateTo ? `วันที่เปิดบิล: ${formatShortDate(billingDateFrom)} - ${formatShortDate(billingDateTo)}` : 'วันที่เปิดบิล: ทั้งหมด';
    const shippingRangeLabel = shippingDateFrom && shippingDateTo ? `วันที่จัดส่ง: ${formatShortDate(shippingDateFrom)} - ${formatShortDate(shippingDateTo)}` : 'วันที่จัดส่ง: ทั้งหมด';
    const detailImages = useMemo(() => {
        if (!selectedOrder?.details) {
            return [] as string[];
        }

        const list = [selectedOrder.details.artwork_url, ...selectedOrder.details.reference_designs].filter(
            (url): url is string => Boolean(url),
        );

        return Array.from(new Set(list));
    }, [selectedOrder]);
    const deliveryMethodValue = selectedOrder?.details?.delivery_method ?? null;
    const shippingAddressValue = selectedOrder?.details?.shipping_address ?? null;
    const personalizationRows = selectedOrder?.details?.personalization_rows ?? [];
    const isIndividualOrder = personalizationRows.length > 0;
    const specificationRows = selectedOrder?.details?.specification_display ?? [];
    const pantsLabels = useMemo(() => new Set(['แบบขา', 'ปลายขา']), []);
    const shirtSpecificationRows = useMemo(
        () => selectedOrder?.details?.spec_sections?.shirt ?? specificationRows.filter((row) => !pantsLabels.has(row.label)),
        [selectedOrder, specificationRows, pantsLabels],
    );
    const pantsSpecificationRows = useMemo(() => {
        return selectedOrder?.details?.spec_sections?.pants ?? specificationRows.filter((row) => pantsLabels.has(row.label));
    }, [selectedOrder, specificationRows, pantsLabels]);

    const timelineStatusClass = (status: string): string => {
        switch (status) {
            case 'completed':
                return 'border-emerald-200 bg-emerald-50 text-emerald-700';
            case 'in_progress':
                return 'border-[#E21E26]/25 bg-[#E21E26]/10 text-[#E21E26]';
            case 'rejected':
                return 'border-[#E21E26]/25 bg-[#E21E26]/10 text-[#E21E26]';
            case 'skipped':
                return 'border-slate-200 bg-slate-100 text-slate-500';
            default:
                return 'border-slate-200 bg-slate-100 text-slate-500';
        }
    };

    const timelineDetailLabel = (routing: NonNullable<OrderTableRow['details']>['routings'][number]): string | null => {
        if (routing.station_name === 'print' && routing.print_machine) {
            return routing.print_machine.replace('printer_', 'เครื่องพิมพ์ ');
        }

        if (routing.station_name === 'cutting') {
            return routing.cutting_team_name ?? null;
        }

        if (routing.station_name === 'sewing') {
            return routing.sewing_team_name ?? null;
        }

        if (routing.station_name === 'embroidery') {
            return routing.embroidery_team_name ?? null;
        }

        if (routing.station_name === 'screen' || routing.station_name === 'flex') {
            return routing.heat_press_machine_name ?? routing.screen_team_name ?? routing.assigned_user ?? null;
        }

        return routing.assigned_user ?? null;
    };

    useEffect(() => {
        const handleClickOutside = (event: MouseEvent) => {
            const target = event.target as Node;

            if (billingRangeRef.current && !billingRangeRef.current.contains(target)) {
                setIsBillingRangeOpen(false);
            }

            if (shippingRangeRef.current && !shippingRangeRef.current.contains(target)) {
                setIsShippingRangeOpen(false);
            }
        };

        document.addEventListener('mousedown', handleClickOutside);

        return () => {
            document.removeEventListener('mousedown', handleClickOutside);
        };
    }, []);

    useEffect(() => {
        const timeoutId = window.setTimeout(() => {
            const counterUrl = currentTeam ? `/${currentTeam.slug}/counter` : '/counter';

            router.get(
                counterUrl,
                {
                    branch_id: branchId === 'all' ? undefined : branchId,
                    billing_date_from: billingDateFrom || undefined,
                    billing_date_to: billingDateTo || undefined,
                    shipping_date_from: shippingDateFrom || undefined,
                    shipping_date_to: shippingDateTo || undefined,
                    search: search || undefined,
                    department: department === 'all' ? undefined : department,
                },
                {
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                    only: ['orders', 'filters'],
                },
            );
        }, 300);

        return () => window.clearTimeout(timeoutId);
    }, [branchId, billingDateFrom, billingDateTo, shippingDateFrom, shippingDateTo, search, department, currentTeam]);

    const handlePrintDocument = (orderRow?: OrderTableRow | null) => {
        const sourceOrder = orderRow?.details ? orderRow : selectedOrder;

        if (!sourceOrder?.details) {
            return;
        }

        const order = sourceOrder.details;
        const specificationRows = order.specification_display ?? [];
        const shirtRows = order.spec_sections?.shirt ?? specificationRows.filter((row) => !pantsLabels.has(row.label));
        const pantsRows = order.spec_sections?.pants ?? specificationRows.filter((row) => pantsLabels.has(row.label));
        const sourcePersonalizationRows = order.personalization_rows ?? [];
        const isIndividualPrint = sourcePersonalizationRows.length > 0;
        const billingDate = order.billing_date ?? '';
        const dueDate = order.due_date ?? '';
        const printImages = Array.from(new Set([order.artwork_url, ...(order.reference_designs ?? [])].filter((url): url is string => Boolean(url))));
        const customerName = order.customer.name || sourceOrder.customer_name || '-';
        const receiverName = sourceOrder.receiver_name || '-';
        const splitSpecRows = (rows: Array<{ label: string; value: string }>) => {
            const midpoint = Math.ceil(rows.length / 2);

            return [rows.slice(0, midpoint), rows.slice(midpoint)] as const;
        };

        const renderSpecTableRows = (rows: Array<{ label: string; value: string }>) => {
            if (rows.length === 0) {
                return '<tr><td colspan="2" class="empty-state">—</td></tr>';
            }

            return rows.map((row) => `<tr><td class="spec-label">${escapeHtml(row.label)}</td><td class="spec-values">${escapeHtml(row.value || '-')}</td></tr>`).join('');
        };

        const renderSpecSection = (title: string, rows: Array<{ label: string; value: string }>, width: '50%' | '100%') => {
            const [firstColumnRows, secondColumnRows] = splitSpecRows(rows);

            return `
                <td style="width: ${width};">
                    <div class="section-title">${title}</div>
                    <table class="spec-split">
                        <tr>
                            <td class="spec-col">
                                <table class="spec-table">
                                    ${renderSpecTableRows(firstColumnRows)}
                                </table>
                            </td>
                            <td class="spec-col">
                                <table class="spec-table">
                                    ${renderSpecTableRows(secondColumnRows)}
                                </table>
                            </td>
                        </tr>
                    </table>
                </td>
            `;
        };
        const personalizationPrintRows = sourcePersonalizationRows.map((row) => ({
            name: row.name || '-',
            number: row.number || '-',
            size_label: row.size || '-',
            quantity: row.quantity,
            unit_price: row.unit_price,
            total_price: row.total_price,
        }));
        const sizeRows = order.items?.length ? order.items : [];
        const printWindow = window.open('', '_blank', 'width=1200,height=900');
        if (!printWindow) {
            return;
        }

        const sizeRowsMarkup = isIndividualPrint
            ? personalizationPrintRows.length > 0
                ? personalizationPrintRows.map((row) => `
                <tr>
                    <td>${escapeHtml(row.name)}</td>
                    <td>${escapeHtml(row.number)}</td>
                    <td>${escapeHtml(row.size_label)}</td>
                    <td>${row.quantity}</td>
                    <td>฿ ${formatMoney(Number(row.unit_price || 0))}</td>
                    <td>฿ ${formatMoney(Number(row.total_price || 0))}</td>
                </tr>`).join('')
                : '<tr><td colspan="6" class="empty-state">ไม่มีข้อมูลรายตัว</td></tr>'
            : sizeRows.length > 0
            ? isIndividualPrint
                ? ''
                : sizeRows.map((row) => `
                <tr>
                    <td>${escapeHtml(row.size_label || '-')}</td>
                    <td>${row.quantity}</td>
                    <td>฿ ${formatMoney(Number(row.unit_price || 0))}</td>
                    <td>฿ ${formatMoney(Number(row.total_price || 0))}</td>
                </tr>`).join('')
            : `<tr><td colspan="${isIndividualPrint ? '6' : '4'}" class="empty-state">ไม่มีข้อมูลไซซ์</td></tr>`;

        const specColumnsMarkup = pantsRows.length > 0
            ? `${renderSpecSection('สเปกเสื้อ', shirtRows, '50%')}${renderSpecSection('สเปกกางเกง', pantsRows, '50%')}`
            : renderSpecSection('สเปกเสื้อ', shirtRows, '100%');
        const totalQuantity = (isIndividualPrint ? personalizationPrintRows : sizeRows).reduce((sum, row) => sum + Number(row.quantity || 0), 0);
        const totalAmount = (isIndividualPrint ? personalizationPrintRows : sizeRows).reduce((sum, row) => sum + Number(row.total_price || 0), 0);
        const sizeTableTitle = isIndividualPrint ? 'รายละเอียดรายตัว (Form 2)' : 'ขนาดผู้ใหญ่ มัธยมต้น/มัธยมปลาย';
        const branchHeaderColor = resolveBranchHeaderColor(order.branch_name, DEFAULT_BRANCH_HEADER_COLOR);
        const sizeTableHeadMarkup = isIndividualPrint
            ? `
                                <tr>
                                    <th>ชื่อสกรีน</th>
                                    <th>เบอร์</th>
                                    <th>ไซซ์</th>
                                    <th>จำนวน</th>
                                    <th>ราคา</th>
                                    <th>รวม</th>
                                </tr>
            `
            : `
                                <tr>
                                    <th>ไซซ์</th>
                                    <th>จำนวน</th>
                                    <th>ราคา</th>
                                    <th>รวม</th>
                                </tr>
            `;
        const sizeTableTotalRowMarkup = isIndividualPrint
            ? `
                                <tr>
                                    <td colspan="3" style="text-align: right; font-weight: 700;">ยอดรวม</td>
                                    <td style="font-weight: 700; color: #174395;">${totalQuantity.toLocaleString('th-TH')} ตัว</td>
                                    <td style="font-weight: 700;">-</td>
                                    <td style="font-weight: 700; color: #E21E26;">฿ ${formatMoney(totalAmount)}</td>
                                </tr>
            `
            : `
                                <tr>
                                    <td style="text-align: right; font-weight: 700;">ยอดรวม</td>
                                    <td style="font-weight: 700; color: #174395;">${totalQuantity.toLocaleString('th-TH')} ตัว</td>
                                    <td style="font-weight: 700;">-</td>
                                    <td style="font-weight: 700; color: #E21E26;">฿ ${formatMoney(totalAmount)}</td>
                                </tr>
            `;
        const barcodeMarkup = createOrderCodeBarcodeSvg(order.order_code);

        const html = `
            <html>
                <head>
                    <meta charset="utf-8" />
                    <title>ใบรับงาน ${escapeHtml(order.order_code)}</title>
                    <style>
                        @page { size: A4 portrait; margin: 4mm; }
                        * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                        html, body { margin: 0; padding: 0; background: #ffffff; color: #111827; }
                        body { font-family: 'TH Sarabun New', 'Prompt', 'Noto Sans Thai', Arial, sans-serif; font-size: 11px; line-height: 1.2; }
                        .page { width: 100%; max-width: 202mm; margin: 0 auto; padding: 0; }
                        .blue-banner { background: ${branchHeaderColor}; color: #ffffff; font-size: 18px; font-weight: 700; text-align: center; padding: 5px 8px; letter-spacing: 0.03em; }
                        .job-hero { border: 1px solid ${branchHeaderColor}; border-top: 0; padding: 5px 7px; display: grid; grid-template-columns: 1.5fr 1fr 1fr; gap: 6px; align-items: center; }
                        .job-hero-block { min-width: 0; }
                        .job-hero-label { font-size: 10px; color: ${branchHeaderColor}; font-weight: 700; line-height: 1.1; }
                        .job-hero-value { font-size: 16px; color: #111827; font-weight: 800; line-height: 1.1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
                        .job-hero-value.is-date { color: #E21E26; }
                        .header-table { width: 100%; border-collapse: collapse; margin-top: 4px; }
                        .header-table td { border: 1px solid #000000; vertical-align: middle; padding: 4px; }
                        .logo-cell { width: 28%; text-align: center; }
                        .logo-cell img { max-height: 36px; width: auto; }
                        .company-cell { width: 46%; text-align: center; }
                        .company-title { font-size: 20px; font-weight: 800; color: #E21E26; line-height: 1; }
                        .subtitle { font-size: 10px; color: #374151; margin-top: 1px; line-height: 1.15; }
                        .branch-cell { width: 26%; font-size: 11px; line-height: 1.25; }
                        .branch-cell .branch-label { color: #E21E26; font-weight: 700; }
                        .barcode-wrap { margin-top: 3px; border: 1px solid #000000; padding: 2px; text-align: center; }
                        .barcode-wrap svg { display: block; width: 100%; height: 12mm; }
                        .barcode-fallback { margin-top: 2px; font-size: 10px; font-weight: 700; letter-spacing: 0.06em; }
                        .image-gallery { margin-top: 6px; display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 8px; }
                        .image-card { border: 1px solid #d1d5db; background: #f9fafb; padding: 6px; min-height: 140px; display: flex; align-items: center; justify-content: center; overflow: hidden; }
                        .image-card img { display: block; width: 100%; max-width: 100%; height: auto; max-height: 220px; object-fit: contain; border-radius: 4px; margin: 0 auto; }
                        .detail-grid { width: 100%; border-collapse: collapse; margin-top: 4px; }
                        .detail-grid td { border: 1px solid #000000; vertical-align: top; padding: 4px; }
                        .section-title { font-size: 12px; font-weight: 700; margin-bottom: 3px; line-height: 1.1; }
                        .detail-list { font-size: 11px; line-height: 1.18; }
                        .detail-list > div { margin-bottom: 1px; }
                        .detail-list > div:last-child { margin-bottom: 0; }
                        .detail-list .label { font-weight: 700; }
                        .detail-list .red { color: #E21E26; font-weight: 700; }
                        .detail-list .green { color: #16a34a; font-weight: 700; }
                        .detail-list .blue { color: #174395; font-weight: 700; }
                        .detail-list .balance { color: #E21E26; font-weight: 800; font-size: 12px; }
                        .spec-split { width: 100%; border-collapse: separate; border-spacing: 3px 0; table-layout: fixed; }
                        .spec-col { width: 50%; padding: 0; vertical-align: top; }
                        .spec-table { width: 100%; border-collapse: collapse; font-size: 9.5px; }
                        .spec-table td { border: 1px solid #000000; padding: 2px 3px; vertical-align: top; line-height: 1.1; }
                        .spec-label { font-weight: 700; width: 36%; }
                        .spec-values { width: 64%; }
                        .table-title { background: #174395; color: #ffffff; font-weight: 700; text-align: center; padding: 4px; font-size: 11px; margin-top: 4px; }
                        .size-table { width: 100%; border-collapse: collapse; margin-top: 4px; font-size: 11px; }
                        .size-table th, .size-table td { border: 1px solid #000000; padding: 3px 4px; text-align: center; }
                        .size-table th { background: #e0f2fe; font-weight: 700; }
                        .size-table td { background: #ffffff; }
                        .empty-state { color: #6b7280; font-style: italic; }
                        .footer-table { width: 100%; border-collapse: collapse; margin-top: 4px; font-size: 10px; }
                        .footer-table td { border: 1px solid #000000; padding: 4px; vertical-align: top; }
                        .footer-table .section-title { margin-bottom: 2px; font-size: 11px; }
                        .signature-name { font-size: 10px; font-weight: 700; text-align: center; margin-top: 1px; }
                        .signature-box { min-height: 7mm; display: flex; align-items: flex-end; justify-content: center; padding-bottom: 0; }
                        .signature-line { width: 58%; border-bottom: 1px solid #000000; }
                        .warning-banner { margin-top: 5px; background: #E21E26; color: #ffffff; text-align: center; padding: 4px 5px; font-size: 10px; font-weight: 700; line-height: 1.15; }
                        .small { font-size: 10px; }
                        tr, td, th, .detail-grid, .size-table, .spec-table, .footer-table, .banner-box { page-break-inside: avoid; }
                        @media print {
                            .job-hero-value { font-size: 17px; }
                        }
                    </style>
                </head>
                <body>
                    <div class="page">
                        <div class="blue-banner">ใบรับงาน</div>
                        <div class="job-hero">
                            <div class="job-hero-block">
                                <div class="job-hero-label">ชื่องาน</div>
                                <div class="job-hero-value">${escapeHtml(order.job_name || '-')}</div>
                            </div>
                            <div class="job-hero-block">
                                <div class="job-hero-label">ประเภทงาน</div>
                                <div class="job-hero-value">${escapeHtml(order.job_type || '-')}</div>
                            </div>
                            <div class="job-hero-block">
                                <div class="job-hero-label">วันที่ต้องส่ง</div>
                                <div class="job-hero-value is-date">${escapeHtml(formatTableDate(dueDate))}</div>
                            </div>
                        </div>
                        <table class="header-table">
                            <tr>
                                <td class="logo-cell"><img src="/images/logo/logo.png" alt="logo" /></td>
                                <td class="company-cell">
                                    <div class="company-title">เจ.เอส.สปอร์ต</div>
                                    <div class="subtitle">ก่อนเข้ารับสินค้ากรุณาโทรสอบถามก่อนเพื่อความสะดวก</div>
                                </td>
                                <td class="branch-cell">
                                    <div><span class="branch-label">สาขา</span> ${escapeHtml(order.branch_name || '-')}</div>
                                    <div class="small">โทร: ${escapeHtml(order.customer.phone || '-')}</div>
                                    <div class="barcode-wrap">
                                        ${barcodeMarkup || `<div class="barcode-fallback">${escapeHtml(order.order_code)}</div>`}
                                    </div>
                                </td>
                            </tr>
                        </table>
                        <div class="image-gallery">
                            ${printImages.length > 0
                                ? printImages.map((imageUrl) => `
                                    <div class="image-card">
                                        <img src="${imageUrl}" alt="งานแนบ" />
                                    </div>
                                `).join('')
                                : '<div class="image-card" style="grid-column: 1 / -1; color: #6b7280;">[ ไม่มีรูปภาพแนบ ]</div>'}
                        </div>

                        <table class="detail-grid">
                            <tr>
                                <td style="width: 60%;">
                                    <div class="section-title">ข้อมูลงาน</div>
                                    <div class="detail-list">
                                        <div><span class="label">ชื่องาน:</span> ${escapeHtml(order.job_name || '-')}</div>
                                        <div><span class="label">ประเภทงาน:</span> ${escapeHtml(order.job_type || '-')}</div>
                                        <div><span class="label">วันที่สั่งสินค้า:</span> ${escapeHtml(formatTableDate(billingDate))}</div>
                                        <div><span class="label">วันที่รับสินค้า:</span> <span class="red">${escapeHtml(formatTableDate(dueDate))}</span></div>
                                        <div><span class="label">ชื่อลูกค้า:</span> ${escapeHtml(order.customer.name || '-')}</div>
                                        <div><span class="label">ช่องทางติดต่อ:</span> ${escapeHtml(order.customer.line_fb || '-')}</div>
                                        <div><span class="label">จัดส่ง:</span> ${escapeHtml(order.delivery_method ? (order.delivery_method === 'shipping' ? 'ขนส่ง' : order.delivery_method === 'onsite' ? 'หน้างาน' : order.delivery_method === 'pickup' ? 'รับหน้าร้าน' : order.delivery_method) : '-')}</div>
                                        ${order.delivery_method && ['shipping', 'onsite'].includes(order.delivery_method) ? `<div><span class="label">รายละเอียด:</span> ${escapeHtml(order.shipping_address || '-')}</div>` : ''}
                                    </div>
                                </td>
                                <td style="width: 40%;">
                                    <div class="section-title">ข้อมูลการชำระเงิน</div>
                                    <div class="detail-list">
                                        <div><span class="label">รวมเป็นเงิน:</span> ${formatMoney(order.pricing.total_amount)}</div>
                                        <div><span class="label">ส่วนลด %:</span> ${order.pricing.discount_percent}%</div>
                                        <div><span class="label">ส่วนลด:</span> ${formatMoney(order.pricing.discount_amount)}</div>
                                        <div><span class="label">ยอดรวมหลังลด:</span> <span class="green">${formatMoney(order.pricing.net_amount)}</span></div>
                                        <div><span class="label">ชำระแล้ว:</span> <span class="blue">${formatMoney(order.pricing.paid_amount)}</span></div>
                                        <div><span class="label">ยอดคงเหลือ:</span> <span class="balance">${formatMoney(Math.max(order.pricing.net_amount - order.pricing.paid_amount, 0))}</span></div>
                                    </div>
                                </td>
                            </tr>
                        </table>

                        <table class="detail-grid" style="margin-top: 4px;">
                            <tr>
                                ${specColumnsMarkup}
                            </tr>
                        </table>

                        <div class="table-title">${sizeTableTitle}</div>
                        <table class="size-table">
                            <thead>
                                ${sizeTableHeadMarkup}
                            </thead>
                            <tbody>
                                ${sizeRowsMarkup}
                                ${sizeTableTotalRowMarkup}
                            </tbody>
                        </table>

                        <table class="footer-table">
                            <tr>
                                <td style="width:50%;">
                                    <div class="section-title">ลงชื่อผู้สั่งสินค้า</div>
                                    <div class="signature-name">${escapeHtml(customerName)}</div>
                                    <div class="signature-box"><div class="signature-line"></div></div>
                                </td>
                                <td style="width:50%;">
                                    <div class="section-title">ลงชื่อผู้รับงาน</div>
                                    <div class="signature-name">${escapeHtml(receiverName)}</div>
                                    <div class="signature-box"><div class="signature-line"></div></div>
                                </td>
                            </tr>
                        </table>

                        <div class="warning-banner">*** หมายเหตุ งานเพิ่มจำนวนไม่ถึง 20 ตัว ไม่ลด % และต้องชำระค่าชุด ที่เพิ่มเติมทั้งหมดก่อนเปิดออเดอร์ ***</div>
                    </div>
                </body>
            </html>
        `;

        printWindow.document.open();
        printWindow.document.write(html);
        printWindow.document.close();
        printWindow.focus();
        setTimeout(() => printWindow.print(), 250);
    };


    return (
        <>
            <Head title="เคาน์เตอร์" />

            <PendingInvitationsModal
                invitations={pendingInvitations}
                open={pendingInvitations.length > 0 && showInvitations}
                onOpenChange={setShowInvitations}
            />

            <div className="min-h-screen bg-slate-100 text-slate-900">
                <div className="mx-auto flex w-full max-w-[1700px] flex-col gap-3 px-4 py-4 md:px-6 md:py-5">
                    <section className="pt-1">
                        <div className="flex flex-col gap-2.5 rounded-t-xl border border-slate-200 border-b-0 bg-white p-3 shadow-xs sm:p-3.5">
                            <div className="flex w-full items-center justify-between gap-3 border-b border-slate-100 pb-2.5">
                                <div className="relative w-full sm:w-80 md:w-96">
                                    <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                                    <Input
                                        value={search}
                                        onChange={(event) => setSearch(event.target.value)}
                                        placeholder="ค้นหาเลขที่ออเดอร์, ใบเสร็จ, ชื่อลูกค้า..."
                                        className="h-9 w-full rounded-lg border-gray-100 bg-slate-50 pl-9 text-xs text-slate-900 focus:bg-white focus-visible:border-[#E21E26] focus-visible:ring-[#E21E26]/40"
                                    />
                                </div>

                                <div className="flex flex-wrap items-center gap-2">
                                    <Button
                                        type="button"
                                        onClick={() => router.visit('/orders/create')}
                                        className="h-9 shrink-0 rounded-lg bg-[#E21E26] px-4 text-xs font-semibold text-white shadow-sm transition-colors duration-150 ease-out hover:bg-[#C91820]"
                                    >
                                        <FilePlus2 className="size-4" />
                                        + เปิดบิลใหม่
                                    </Button>
                                    <div className="inline-flex flex-wrap gap-2 rounded-2xl p-1">
                                        <Button
                                            type="button"
                                            className="h-9 rounded-lg border border-gray-100 bg-white px-4 text-xs font-semibold text-slate-500 shadow-sm transition-colors duration-150 ease-out hover:bg-slate-50 hover:text-slate-900"
                                            onClick={() => router.visit('/orders/create')}
                                        >
                                            เปิดบิลงานเพิ่ม
                                        </Button>
                                        <Button
                                            type="button"
                                            className="h-9 rounded-lg border border-gray-100 bg-white px-4 text-xs font-semibold text-slate-500 shadow-sm transition-colors duration-150 ease-out hover:bg-slate-50 hover:text-slate-900"
                                            onClick={() => router.visit('/orders')}
                                        >
                                            เปิดบิลงานเก่า
                                        </Button>
                                    </div>
                                </div>
                            </div>

                            <div className="flex w-full flex-wrap items-center gap-2 text-xs">
                                <Select value={branchId} onValueChange={setBranchId}>
                                    <SelectTrigger className="h-8 w-full border-gray-100 bg-slate-50 text-xs text-slate-500 sm:w-[210px]">
                                        <span className="truncate">สาขา: {selectedBranchLabel}</span>
                                    </SelectTrigger>
                                    <SelectContent>
                                        {branchOptions.map((option) => (
                                            <SelectItem key={option.value} value={option.value}>
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>

                                <Select value={department} onValueChange={(value: DepartmentFilter) => setDepartment(value)}>
                                    <SelectTrigger className="h-8 w-full border-gray-100 bg-slate-50 text-xs text-slate-500 sm:w-[230px]">
                                        <span className="truncate">ห้อง: {selectedDepartmentLabel}</span>
                                    </SelectTrigger>
                                    <SelectContent>
                                        {departmentOptions.map((option) => (
                                            <SelectItem key={option.value} value={option.value}>
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>

                                <div ref={billingRangeRef} className="relative">
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setIsBillingRangeOpen((prev) => !prev);
                                            setIsShippingRangeOpen(false);
                                        }}
                                        className="flex h-8 cursor-pointer items-center gap-1.5 rounded-md border border-gray-100 bg-slate-50 px-2.5 text-xs text-slate-500 transition-colors duration-150 ease-out hover:bg-white hover:text-slate-900"
                                    >
                                        <Calendar className="size-4 shrink-0 text-[#E21E26]" />
                                        <span>{billingRangeLabel}</span>
                                    </button>

                                    {isBillingRangeOpen ? (
                                        <div className="absolute top-9 left-0 z-30 w-[300px] rounded-md border border-gray-100 bg-white p-2.5 shadow-md">
                                            <div className="grid gap-2">
                                                <label htmlFor="billing-date-from" className="grid gap-1 text-[11px] text-slate-600">
                                                    วันที่เริ่มต้น
                                                    <Input
                                                        id="billing-date-from"
                                                        type="date"
                                                        value={billingDateFrom}
                                                        onChange={(event) => setBillingDateFrom(event.target.value)}
                                                        className="h-8 border-slate-300 text-xs"
                                                    />
                                                </label>
                                                <label htmlFor="billing-date-to" className="grid gap-1 text-[11px] text-slate-600">
                                                    วันที่สิ้นสุด
                                                    <Input
                                                        id="billing-date-to"
                                                        type="date"
                                                        value={billingDateTo}
                                                        onChange={(event) => setBillingDateTo(event.target.value)}
                                                        className="h-8 border-slate-300 text-xs"
                                                    />
                                                </label>
                                            </div>
                                        </div>
                                    ) : null}
                                </div>

                                <div ref={shippingRangeRef} className="relative">
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setIsShippingRangeOpen((prev) => !prev);
                                            setIsBillingRangeOpen(false);
                                        }}
                                        className="flex h-8 cursor-pointer items-center gap-1.5 rounded-md border border-gray-100 bg-slate-50 px-2.5 text-xs text-slate-500 transition-colors duration-150 ease-out hover:bg-white hover:text-slate-900"
                                    >
                                        <Package className="size-4 shrink-0 text-[#E21E26]" />
                                        <span>{shippingRangeLabel}</span>
                                    </button>

                                    {isShippingRangeOpen ? (
                                        <div className="absolute top-9 left-0 z-30 w-[300px] rounded-md border border-gray-100 bg-white p-2.5 shadow-md">
                                            <div className="grid gap-2">
                                                <label htmlFor="shipping-date-from" className="grid gap-1 text-[11px] text-slate-600">
                                                    วันที่เริ่มต้น
                                                    <Input
                                                        id="shipping-date-from"
                                                        type="date"
                                                        value={shippingDateFrom}
                                                        onChange={(event) => setShippingDateFrom(event.target.value)}
                                                        className="h-8 border-slate-300 text-xs"
                                                    />
                                                </label>
                                                <label htmlFor="shipping-date-to" className="grid gap-1 text-[11px] text-slate-600">
                                                    วันที่สิ้นสุด
                                                    <Input
                                                        id="shipping-date-to"
                                                        type="date"
                                                        value={shippingDateTo}
                                                        onChange={(event) => setShippingDateTo(event.target.value)}
                                                        className="h-8 border-slate-300 text-xs"
                                                    />
                                                </label>
                                            </div>
                                        </div>
                                    ) : null}
                                </div>

                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={handleResetFilters}
                                    className="ml-auto flex h-8 items-center gap-1 rounded-md px-2 text-xs text-slate-500 transition-colors duration-150 ease-out hover:bg-[#E21E26]/10 hover:text-[#E21E26] sm:ml-0"
                                >
                                    ✕ ล้างค่า
                                </Button>
                            </div>
                        </div>
                        
                        <div className="py-3">
                            <div className="overflow-x-auto [scrollbar-width:none] [-ms-overflow-style:none] [&::-webkit-scrollbar]:hidden">
                            <div className="flex min-w-max gap-3">
                                {departmentCards.map((card) => (
                                    <div key={card.title} className="w-[220px] shrink-0">
                                        <DepartmentCard
                                            title={card.title}
                                            icon={card.icon}
                                            rows={card.rows}
                                            accent={card.accent}
                                        />
                                    </div>
                                ))}
                            </div>
                            </div>
                        </div>

                        <OrderVirtualizedGrid
                            rows={orders}
                            onOpenDetail={setSelectedOrder}
                            onOpenTimeline={setTimelineOrder}
                            onOpenPdf={handlePrintDocument}
                        />
                    </section>
                </div>
            </div>

            {selectedOrder?.details ? (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" onClick={() => setSelectedOrder(null)}>
                    <div
                        className="max-h-[94vh] w-full max-w-6xl overflow-y-auto rounded-2xl border border-slate-200 bg-white p-0 shadow-2xl"
                        onClick={(event) => event.stopPropagation()}
                    >
                        <div className="sticky top-0 z-20">
                            <WorkReceiptTopBar
                                orderCode={selectedOrder.details.order_code}
                                onPrint={handlePrintDocument}
                                onClose={() => setSelectedOrder(null)}
                            />
                        </div>

                        <div ref={printRef} className="space-y-3 p-3 md:p-4">
                            <WorkReceiptBillHeader branchName={selectedOrder.details.branch_name} phone={selectedOrder.details.customer.phone} />

                            <div className="grid gap-3 md:grid-cols-[1fr_320px]">
                                <section className="rounded-lg border border-slate-300 bg-white p-3">
                                    <h3 className="mb-2 border-b border-slate-200 pb-1 text-sm font-bold text-slate-800">รูปแบบงาน / รูปที่แนบ</h3>
                                    {detailImages.length > 0 ? (
                                        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                            {detailImages.map((url, index) => (
                                                <div key={`${url}-${index}`} className="overflow-hidden rounded-md border border-slate-200 bg-slate-50">
                                                    <img
                                                        src={url}
                                                        alt={`attachment-${index + 1}`}
                                                        className="h-44 w-full object-contain bg-slate-50"
                                                        onError={(event) => {
                                                            event.currentTarget.style.display = 'none';
                                                        }}
                                                    />
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <div className="flex h-64 items-center justify-center rounded-md border border-dashed border-slate-300 bg-slate-50 text-sm text-slate-400">
                                            ไม่มีรูป Artwork
                                        </div>
                                    )}
                                </section>

                                <aside className="space-y-3">
                                    <section className="rounded-lg border border-slate-300 bg-white p-3">
                                        <h3 className="mb-2 border-b border-slate-200 pb-1 text-sm font-bold text-slate-800">ข้อมูลบิล</h3>
                                        <div className="space-y-1 text-xs text-slate-700">
                                            <p>ชื่องาน: <span className="font-semibold text-[#174395]">{selectedOrder.details.job_name || '-'}</span></p>
                                            <p>ชื่อลูกค้า: {selectedOrder.details.customer.name || '-'}</p>
                                            <p>ประเภทงาน: {selectedOrder.details.job_type || '-'}</p>
                                            <p>วันที่เปิดบิล: {selectedOrder.details.billing_date || '-'}</p>
                                            <p>วันที่รับสินค้า: <span className="font-semibold text-[#E21E26]">{selectedOrder.details.due_date || '-'}</span></p>
                                            <p>ช่องทางติดต่อ: {selectedOrder.details.customer.line_fb || '-'}</p>
                                        </div>
                                    </section>

                                    {deliveryMethodValue && ['shipping', 'onsite'].includes(deliveryMethodValue) ? (
                                        <section className="rounded-lg border border-[#E21E26]/30 bg-[#E21E26]/10 p-3">
                                            <h3 className="mb-2 border-b border-[#E21E26]/20 pb-1 text-sm font-bold text-[#E21E26]">ข้อมูลการจัดส่ง</h3>
                                            <div className="space-y-1 text-xs text-slate-700">
                                                <p>
                                                    วิธีจัดส่ง: <span className="font-semibold text-[#E21E26]">{deliveryMethodLabel(deliveryMethodValue)}</span>
                                                </p>
                                                <p>
                                                    ข้อมูลที่บันทึกไว้: <span className="font-medium text-slate-900">{shippingAddressValue || '—'}</span>
                                                </p>
                                            </div>
                                        </section>
                                    ) : null}

                                    <section className="rounded-lg border border-[#E21E26]/30 bg-[#E21E26]/10 p-3">
                                        <h3 className="mb-2 border-b border-[#E21E26]/20 pb-1 text-sm font-bold text-slate-800">สรุปการเงิน</h3>
                                        <div className="space-y-1.5 text-sm">
                                            <div className="flex justify-between"><span>รวมเป็นเงิน</span><span className="font-bold text-[#E21E26]">{formatMoney(selectedOrder.details.pricing.total_amount)}</span></div>
                                            <div className="flex justify-between"><span>ส่วนลด %</span><span className="font-semibold">{selectedOrder.details.pricing.discount_percent}%</span></div>
                                            <div className="flex justify-between"><span>ส่วนลด</span><span>{formatMoney(selectedOrder.details.pricing.discount_amount)}</span></div>
                                            <div className="flex justify-between border-t border-[#E21E26]/20 pt-1"><span>ยอดรวมหลังลด</span><span className="font-bold text-[#E21E26]">{formatMoney(selectedOrder.details.pricing.net_amount)}</span></div>
                                            <div className="flex justify-between"><span>ชำระแล้ว</span><span>{formatMoney(selectedOrder.details.pricing.paid_amount)}</span></div>
                                            <div className="flex justify-between border-t border-[#E21E26]/20 pt-1"><span>คงเหลือ</span><span className="font-bold text-[#E21E26]">{formatMoney(Math.max(selectedOrder.details.pricing.net_amount - selectedOrder.details.pricing.paid_amount, 0))}</span></div>
                                        </div>
                                    </section>
                                </aside>
                            </div>

                            <section className="rounded-lg border border-slate-300 bg-white p-3">
                                <h3 className="mb-2 text-xs font-bold text-slate-700">รายการไซซ์และราคา</h3>
                                {isIndividualOrder ? (
                                    <div className="overflow-x-auto">
                                        <table className="w-full min-w-[760px] text-left text-xs">
                                            <thead>
                                                <tr className="text-slate-600">
                                                    <th className="px-2 py-1">สกรีนชื่อ (Name)</th>
                                                    <th className="px-2 py-1">ไซซ์</th>
                                                    <th className="px-2 py-1">เบอร์</th>
                                                    <th className="px-2 py-1 text-right">จำนวน</th>
                                                    <th className="px-2 py-1 text-right">ราคาต่อหน่วย</th>
                                                    <th className="px-2 py-1 text-right">รวม</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {personalizationRows.map((item, index) => (
                                                    <tr key={`${item.name}-${item.number}-${index}`} className="border-t border-slate-200">
                                                        <td className="px-2 py-1.5 font-medium text-slate-900">{item.name || '-'}</td>
                                                        <td className="px-2 py-1.5 text-slate-700">{item.size || '-'}</td>
                                                        <td className="px-2 py-1.5 text-slate-700">{item.number || '-'}</td>
                                                        <td className="px-2 py-1.5 text-right">{item.quantity}</td>
                                                        <td className="px-2 py-1.5 text-right">฿ {formatMoney(item.unit_price)}</td>
                                                        <td className="px-2 py-1.5 text-right">฿ {formatMoney(item.total_price)}</td>
                                                    </tr>
                                                ))}
                                                <tr className="border-t border-slate-300 bg-slate-50">
                                                    <td colSpan={3} className="px-2 py-1.5 text-right font-bold text-slate-800">รวม</td>
                                                    <td className="px-2 py-1.5 text-right font-bold text-slate-900">
                                                        {personalizationRows.reduce((sum, item) => sum + Number(item.quantity || 0), 0)}
                                                    </td>
                                                    <td className="px-2 py-1.5" />
                                                    <td className="px-2 py-1.5 text-right font-bold text-[#E21E26]">
                                                        ฿ {formatMoney(personalizationRows.reduce((sum, item) => sum + Number(item.total_price || 0), 0))}
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                ) : (
                                    <div className="overflow-x-auto">
                                        <table className="w-full min-w-[680px] text-left text-xs">
                                            <thead>
                                                <tr className="text-slate-600">
                                                    <th className="px-2 py-1">กลุ่มไซซ์</th>
                                                    <th className="px-2 py-1">ไซซ์</th>
                                                    <th className="px-2 py-1 text-right">จำนวน</th>
                                                    <th className="px-2 py-1 text-right">ราคาต่อหน่วย</th>
                                                    <th className="px-2 py-1 text-right">รวม</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {selectedOrder.details.items.map((item, index) => (
                                                    <tr key={`${item.size_label}-${index}`} className="border-t border-slate-200">
                                                        <td className="px-2 py-1.5">{item.size_group === 'adults' ? 'ไซซ์ผู้ใหญ่' : item.size_group === 'kids' ? 'ไซซ์เด็ก' : item.size_group}</td>
                                                        <td className="px-2 py-1.5">{item.size_label}</td>
                                                        <td className="px-2 py-1.5 text-right">{item.quantity}</td>
                                                        <td className="px-2 py-1.5 text-right">฿ {formatMoney(item.unit_price)}</td>
                                                        <td className="px-2 py-1.5 text-right">฿ {formatMoney(item.total_price)}</td>
                                                    </tr>
                                                ))}
                                                <tr className="border-t border-slate-300 bg-slate-50">
                                                    <td colSpan={2} className="px-2 py-1.5 text-right font-bold text-slate-800">รวม</td>
                                                    <td className="px-2 py-1.5 text-right font-bold text-slate-900">
                                                        {selectedOrder.details.items.reduce((sum, item) => sum + Number(item.quantity || 0), 0)}
                                                    </td>
                                                    <td className="px-2 py-1.5" />
                                                    <td className="px-2 py-1.5 text-right font-bold text-[#E21E26]">
                                                        ฿ {formatMoney(selectedOrder.details.items.reduce((sum, item) => sum + Number(item.total_price || 0), 0))}
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </section>

                            <section className="rounded-lg border-2 border-[#174395]/25 bg-gradient-to-br from-[#174395]/10 to-[#174395]/5 p-3 shadow-sm">
                                <div className="mb-2 border-b border-[#174395]/25 pb-1.5">
                                    <h3 className="text-sm font-extrabold text-[#174395]">สเปกแบบเสื้อ</h3>
                                </div>

                                {shirtSpecificationRows.length === 0 ? (
                                    <div className="rounded-md border border-dashed border-[#174395]/35 bg-white/70 px-3 py-2 text-xs text-slate-500">
                                        ยังไม่มีข้อมูลสเปกเสื้อที่บันทึก
                                    </div>
                                ) : (
                                    <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                        {shirtSpecificationRows.map((row) => (
                                            <div key={row.label} className="rounded-md border border-[#174395]/25 bg-white px-2.5 py-2">
                                                <p className="text-[11px] font-semibold tracking-wide text-slate-500">{row.label}</p>
                                                <p className="mt-1 break-words text-sm font-bold text-slate-900">{row.value || '-'}</p>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </section>

                            {pantsSpecificationRows.length > 0 ? (
                                <section className="rounded-lg border-2 border-[#E21E26]/30 bg-gradient-to-br from-[#E21E26]/10 to-[#E21E26]/5 p-3 shadow-sm">
                                    <div className="mb-2 border-b border-[#E21E26]/25 pb-1.5">
                                        <h3 className="text-sm font-extrabold text-[#E21E26]">สเปกแบบกางเกง</h3>
                                    </div>

                                    <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                        {pantsSpecificationRows.map((row) => (
                                            <div key={row.label} className="rounded-md border border-[#E21E26]/30 bg-white px-2.5 py-2">
                                                <p className="text-[11px] font-semibold tracking-wide text-slate-500">{row.label}</p>
                                                <p className="mt-1 break-words text-sm font-bold text-slate-900">{row.value || '-'}</p>
                                            </div>
                                        ))}
                                    </div>
                                </section>
                            ) : null}
                        </div>
                    </div>
                </div>
            ) : null}

            <Dialog open={timelineOrder !== null} onOpenChange={(open) => {
                if (!open) {
                    setTimelineOrder(null);
                }
            }}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                    <DialogHeader>
                        <DialogTitle>Timeline ออเดอร์ {timelineOrder?.details?.order_code}</DialogTitle>
                    </DialogHeader>

                    <div className="space-y-4">
                        <div className="rounded-2xl border border-slate-200 bg-gradient-to-r from-slate-50 to-white p-4">
                            <div className="grid gap-2 text-sm text-slate-600 md:grid-cols-3">
                                <p>ลูกค้า: <span className="font-semibold text-slate-900">{timelineOrder?.details?.customer.name || '-'}</span></p>
                                <p>ชื่องาน: <span className="font-semibold text-slate-900">{timelineOrder?.details?.job_name || '-'}</span></p>
                                <p>ประเภทงาน: <span className="font-semibold text-slate-900">{timelineOrder?.details?.job_type || '-'}</span></p>
                                <p>กำหนดส่ง: <span className="font-semibold text-slate-900">{formatTableDate(timelineOrder?.due_date ?? '')}</span></p>
                            </div>
                        </div>

                        <div className="space-y-3">
                            {[...(timelineOrder?.details?.routings ?? [])]
                                .filter((routing) => routing.is_required)
                                .sort((a, b) => a.id - b.id)
                                .map((routing, index, routings) => {
                                const detailLabel = timelineDetailLabel(routing);
                                const isFuture = routing.status === 'pending';
                                const nextRoom = routings[index + 1];
                                const incomingDate = isFuture ? '-' : dateTime(routing.created_at);

                                return (
                                    <div key={`${routing.station_name}-${index}`} className="relative pl-8">
                                        {index < routings.length - 1 ? (
                                            <div className={`absolute top-8 left-[14px] h-[calc(100%-8px)] w-px ${isFuture ? 'bg-slate-200' : 'bg-slate-300'}`} />
                                        ) : null}
                                        <div className={`absolute top-1.5 left-0 flex size-7 items-center justify-center rounded-full border ${timelineStatusClass(routing.status)}`}>
                                            <span className="text-[10px] font-bold">{index + 1}</span>
                                        </div>

                                        <div className={`rounded-2xl border p-4 shadow-sm ${isFuture ? 'border-slate-200 bg-slate-50' : 'border-slate-200 bg-white'}`}>
                                            <div className="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                                                <div className="space-y-2">
                                                    <div className="flex items-center gap-2">
                                                        <h3 className={`text-sm font-bold ${isFuture ? 'text-slate-500' : 'text-slate-900'}`}>{stationLabel(routing.station_name)}</h3>
                                                        <span className={`inline-flex rounded-full border px-2 py-0.5 text-[11px] font-semibold ${timelineStatusClass(routing.status)}`}>
                                                            {routingStatusLabel(routing.status)}
                                                        </span>
                                                    </div>
                                                    <div className="grid gap-1 text-xs text-slate-600 md:grid-cols-2">
                                                        <p>วันที่งานเข้า: <span className="font-medium text-slate-900">{incomingDate}</span></p>
                                                        <p>วันที่เริ่ม: <span className="font-medium text-slate-900">{dateTime(routing.started_at)}</span></p>
                                                        <p>วันที่เสร็จ: <span className="font-medium text-slate-900">{dateTime(routing.completed_at)}</span></p>
                                                        <p>
                                                            {routing.station_name === 'print' ? 'เครื่องพิมพ์' : 'ทีม/ผู้รับผิดชอบ'}: <span className="font-medium text-slate-900">{detailLabel || '-'}</span>
                                                        </p>
                                                    </div>
                                                    {routing.rework_note ? (
                                                        <p className="rounded-xl border border-[#E21E26]/25 bg-[#E21E26]/10 px-3 py-2 text-xs text-[#E21E26]">หมายเหตุแก้ไข: {routing.rework_note}</p>
                                                    ) : null}
                                                </div>

                                                <div className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-500">
                                                    ห้องต่อไป: <span className={`font-semibold ${nextRoom ? 'text-slate-900' : 'text-slate-400'}`}>{nextRoom ? stationLabel(nextRoom.station_name) : 'จบกระบวนการ'}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </DialogContent>
            </Dialog>

        </>
    );
}

Counter.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'เคาน์เตอร์',
            href: props.currentTeam ? `/${props.currentTeam.slug}/counter` : '/counter',
        },
    ],
});