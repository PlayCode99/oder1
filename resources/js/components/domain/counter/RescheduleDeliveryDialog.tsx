import { router } from '@inertiajs/react';
import { CalendarClock } from 'lucide-react';
import { useMemo, useState } from 'react';

import { DeliveryDatePicker } from '@/components/domain/orders/DeliveryDatePicker';
import type { DeliveryDateLoad } from '@/components/domain/orders/DeliveryDatePicker';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/** The bill being moved, as the counter and the calendar both know it. */
export type RescheduleTarget = {
    id: number;
    orderCode: string;
    /** Y-m-d */
    dueDate: string;
    /** Pieces on the bill, which leave the old day and land on the new one. */
    quantity: number;
    jobName?: string | null;
};

/** 2026-10-28 → 28/10/2569, the way the counter writes a date. */
export function thaiDate(value: string | null | undefined): string {
    const match = (value ?? '').match(/^(\d{4})-(\d{2})-(\d{2})/);

    return match ? `${match[3]}/${match[2]}/${Number(match[1]) + 543}` : '-';
}

/**
 * The day loads as this bill sees them: its own pieces are taken off the day
 * it is leaving, so moving it never counts it twice.
 */
export function loadsWithoutBill(
    loads: DeliveryDateLoad[],
    target: Pick<RescheduleTarget, 'dueDate' | 'quantity'>,
): DeliveryDateLoad[] {
    return loads.map((load) =>
        load.date === target.dueDate
            ? {
                  ...load,
                  total_quantity: Math.max(
                      load.total_quantity - target.quantity,
                      0,
                  ),
              }
            : load,
    );
}

/**
 * "เลื่อนส่ง": pick the new delivery day and say why. Every page reads the
 * bill's one date, so they all follow once this is saved.
 *
 * Render it keyed by the bill, so moving another bill starts afresh.
 */
export default function RescheduleDeliveryDialog({
    target,
    today,
    loads,
    dailyCapacity,
    onClose,
}: {
    target: RescheduleTarget | null;
    /** Y-m-d; an earlier day may be picked, a past one may not. */
    today: string;
    loads: DeliveryDateLoad[];
    dailyCapacity: number;
    onClose: () => void;
}) {
    const [dueDate, setDueDate] = useState('');
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    const ownLoads = useMemo(
        () => (target ? loadsWithoutBill(loads, target) : loads),
        [loads, target],
    );
    const landingLoad =
        ownLoads.find((load) => load.date === dueDate)?.total_quantity ?? 0;
    const overCapacity =
        dueDate !== '' &&
        target !== null &&
        landingLoad + target.quantity > dailyCapacity;

    const submit = () => {
        if (!target) {
            return;
        }

        const missing: Record<string, string> = {};

        if (dueDate === '') {
            missing.due_date = 'กรุณาเลือกวันส่งใหม่';
        } else if (dueDate === target.dueDate) {
            missing.due_date = 'วันส่งใหม่ตรงกับวันเดิม';
        }

        if (reason.trim() === '') {
            missing.reason = 'กรุณากรอกเหตุผลที่เลื่อนส่ง';
        }

        setErrors(missing);

        if (Object.keys(missing).length > 0) {
            return;
        }

        setSaving(true);
        router.post(
            `/orders/${target.id}/due-date`,
            { due_date: dueDate, reason: reason.trim() },
            {
                preserveScroll: true,
                onSuccess: () => onClose(),
                onError: (serverErrors) =>
                    setErrors(serverErrors as Record<string, string>),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <Dialog
            open={target !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <CalendarClock className="size-5 text-[#E21E26]" />
                        เลื่อนส่ง {target?.orderCode}
                    </DialogTitle>
                    <DialogDescription>
                        ใบรับงาน ใบงาน และปฏิทินจะเปลี่ยนเป็นวันใหม่ทั้งหมด
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-3 text-sm">
                    {target?.jobName ? (
                        <p className="text-slate-600">
                            งาน:{' '}
                            <span className="font-semibold text-slate-900">
                                {target.jobName}
                            </span>
                        </p>
                    ) : null}
                    <p className="text-slate-600">
                        วันส่งเดิม:{' '}
                        <span className="font-semibold text-slate-900">
                            {thaiDate(target?.dueDate)}
                        </span>{' '}
                        · {target?.quantity.toLocaleString('th-TH')} ตัว
                    </p>

                    <div className="grid gap-1.5">
                        <span className="text-xs font-semibold text-slate-600">
                            วันส่งใหม่
                        </span>
                        <DeliveryDatePicker
                            value={dueDate}
                            minDate={today}
                            dailyCapacity={dailyCapacity}
                            loads={ownLoads}
                            onChange={(date) => {
                                setDueDate(date);
                                setErrors((current) => ({
                                    ...current,
                                    due_date: '',
                                }));
                            }}
                        />
                        {dueDate !== '' ? (
                            <p className="text-xs text-slate-600">
                                เลื่อนเป็น{' '}
                                <span className="font-semibold text-slate-900">
                                    {thaiDate(dueDate)}
                                </span>
                            </p>
                        ) : null}
                        {overCapacity ? (
                            <p
                                role="alert"
                                className="rounded-md border border-amber-300 bg-amber-50 px-2.5 py-1.5 text-xs text-amber-800"
                            >
                                วันนี้มีงานแล้ว{' '}
                                {landingLoad.toLocaleString('th-TH')} ตัว
                                เมื่อรวมบิลนี้จะเป็น{' '}
                                {(
                                    landingLoad + (target?.quantity ?? 0)
                                ).toLocaleString('th-TH')}
                                /{dailyCapacity.toLocaleString('th-TH')} ตัว
                                เกินกำลังผลิตต่อวัน
                            </p>
                        ) : null}
                        <InputError message={errors.due_date} />
                    </div>

                    <label className="grid gap-1.5">
                        <span className="text-xs font-semibold text-slate-600">
                            เหตุผลที่เลื่อนส่ง
                        </span>
                        <textarea
                            value={reason}
                            maxLength={500}
                            rows={3}
                            onChange={(event) => {
                                setReason(event.target.value);
                                setErrors((current) => ({
                                    ...current,
                                    reason: '',
                                }));
                            }}
                            placeholder="เช่น ลูกค้าขอเลื่อน, ผ้ามาช้า"
                            className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-[#174395] focus:outline-none"
                        />
                        <InputError message={errors.reason} />
                    </label>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onClose}>
                        ยกเลิก
                    </Button>
                    <Button
                        type="button"
                        disabled={saving}
                        onClick={submit}
                        className="bg-[#E21E26] text-white hover:bg-[#C91820]"
                    >
                        ยืนยันเลื่อนส่ง
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
