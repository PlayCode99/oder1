import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import DeliveryCalendarDialog from '../DeliveryCalendarDialog';
import type { DeliveryCalendar } from '../DeliveryCalendarDialog';
import RescheduleDeliveryDialog, {
    loadsWithoutBill,
    thaiDate,
} from '../RescheduleDeliveryDialog';

const post = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: { get: vi.fn(), post: (...args: unknown[]) => post(...args) },
}));

const target = {
    id: 7,
    orderCode: '01-2026-00007',
    dueDate: '2026-10-28',
    quantity: 30,
    jobName: 'เสื้อกีฬาสี',
};

const renderDialog = (loads = [{ date: '2026-10-30', total_quantity: 390 }]) =>
    render(
        <RescheduleDeliveryDialog
            target={target}
            today="2026-10-24"
            loads={loads}
            dailyCapacity={400}
            onClose={vi.fn()}
        />,
    );

/** Opens the date picker inside the dialog and picks a day of October 2026. */
const pickDay = (day: number) => {
    fireEvent.click(
        screen.getByRole('button', { name: /เลือกวันที่รับสินค้า/ }),
    );
    const key = `2026-10-${String(day).padStart(2, '0')}`;
    const picker = screen.getAllByRole('dialog').at(-1)!;
    const cell = within(picker)
        .getAllByRole('button')
        .find((button) => button.getAttribute('title')?.startsWith(`${key}:`));
    fireEvent.click(cell!);
};

describe('the postpone dialog', () => {
    beforeEach(() => post.mockReset());

    it('writes dates the way the counter does', () => {
        expect(thaiDate('2026-10-28')).toBe('28/10/2569');
        expect(thaiDate(null)).toBe('-');
    });

    it('takes the bill off the day it is leaving, so it is never counted twice', () => {
        expect(
            loadsWithoutBill(
                [
                    { date: '2026-10-28', total_quantity: 100 },
                    { date: '2026-10-30', total_quantity: 50 },
                ],
                target,
            ),
        ).toEqual([
            { date: '2026-10-28', total_quantity: 70 },
            { date: '2026-10-30', total_quantity: 50 },
        ]);
    });

    it('asks for the new day and a reason before saving', () => {
        renderDialog();

        fireEvent.click(
            screen.getByRole('button', { name: 'ยืนยันเลื่อนส่ง' }),
        );

        expect(screen.getByText('กรุณาเลือกวันส่งใหม่')).toBeInTheDocument();
        expect(
            screen.getByText('กรุณากรอกเหตุผลที่เลื่อนส่ง'),
        ).toBeInTheDocument();
        expect(post).not.toHaveBeenCalled();
    });

    it('does not let a past day be picked', () => {
        renderDialog();
        fireEvent.click(
            screen.getByRole('button', { name: /เลือกวันที่รับสินค้า/ }),
        );
        const picker = screen.getAllByRole('dialog').at(-1)!;
        const yesterday = within(picker)
            .getAllByRole('button')
            .find((button) =>
                button.getAttribute('title')?.startsWith('2026-10-23:'),
            );

        expect(yesterday).toBeDisabled();
    });

    it('warns when the new day would go over the daily capacity, and still saves', () => {
        renderDialog();
        pickDay(30);

        expect(screen.getByRole('alert').textContent).toContain('420/400');

        fireEvent.change(screen.getByPlaceholderText(/ลูกค้าขอเลื่อน/), {
            target: { value: ' ลูกค้าขอเลื่อน ' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'ยืนยันเลื่อนส่ง' }),
        );

        expect(post).toHaveBeenCalledWith(
            '/orders/7/due-date',
            { due_date: '2026-10-30', reason: 'ลูกค้าขอเลื่อน' },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('refuses the day the bill is already due on', () => {
        renderDialog();
        pickDay(28);
        fireEvent.change(screen.getByPlaceholderText(/ลูกค้าขอเลื่อน/), {
            target: { value: 'ลอง' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'ยืนยันเลื่อนส่ง' }),
        );

        expect(screen.getByText('วันส่งใหม่ตรงกับวันเดิม')).toBeInTheDocument();
        expect(post).not.toHaveBeenCalled();
    });
});

describe('postponing from the delivery calendar', () => {
    const job = (over: Record<string, unknown> = {}) => ({
        id: 1,
        order_code: '01-2026-00001',
        customer_name: 'ลูกค้า',
        branch_name: 'หนองบัวลำภู',
        job_name: 'งาน',
        job_type: 'ปัก',
        delivery_method: 'pickup',
        delivery_label: 'รับที่ร้าน',
        order_status: 'in_production',
        status_label: 'ห้องปัก',
        is_closed: false,
        quantity: 10,
        due_date: '2026-10-24',
        can_reschedule: true,
        postponed_from: null,
        ...over,
    });
    const calendar: DeliveryCalendar = {
        month: '2026-10',
        today: '2026-10-24',
        days: {
            '2026-10-24': {
                count: 2,
                quantity: 20,
                orders: [
                    job(),
                    job({
                        id: 2,
                        order_code: '01-2026-00002',
                        is_closed: true,
                        can_reschedule: false,
                        postponed_from: '2026-10-20',
                    }),
                ],
            },
        },
    };

    it('offers เลื่อนส่ง only on jobs the user may move, and passes the job on', () => {
        const onReschedule = vi.fn();
        render(
            <DeliveryCalendarDialog
                open
                onOpenChange={vi.fn()}
                calendar={calendar}
                showBranch={false}
                onReschedule={onReschedule}
            />,
        );

        const buttons = screen.getAllByRole('button', { name: /เลื่อนส่ง/ });
        expect(buttons).toHaveLength(1);

        fireEvent.click(buttons[0]);
        expect(onReschedule).toHaveBeenCalledWith(
            expect.objectContaining({ id: 1, due_date: '2026-10-24' }),
        );
    });

    it('notes a job that was moved onto the day', () => {
        render(
            <DeliveryCalendarDialog
                open
                onOpenChange={vi.fn()}
                calendar={calendar}
                showBranch={false}
            />,
        );

        expect(screen.getByText(/เลื่อนจาก 20\/10\/2569/)).toBeInTheDocument();
        // Without a handler, as on the owner dashboard, nothing is offered.
        expect(screen.queryByRole('button', { name: /เลื่อนส่ง/ })).toBeNull();
    });
});
