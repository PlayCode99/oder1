import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { UserAccessRole, UserListItem } from '@/types/user-management';

export type UserFormData = {
    full_name: string;
    employee_code: string;
    role: UserAccessRole | '';
    branch_id: string;
    is_active: boolean;
    password: string;
    password_confirmation: string;
};

export function createBlankUserForm(): UserFormData {
    return {
        full_name: '',
        employee_code: '',
        role: '',
        branch_id: '',
        is_active: true,
        password: '',
        password_confirmation: '',
    };
}

export function useUserManagement() {
    const [editingUser, setEditingUser] = useState<UserListItem | null>(null);

    const createForm = useForm<UserFormData>(createBlankUserForm());
    const editForm = useForm<UserFormData>(createBlankUserForm());

    const openCreate = () => {
        const blankForm = createBlankUserForm();
        createForm.defaults(blankForm);
        createForm.setData(blankForm);
        createForm.clearErrors();
    };

    const submitCreate = () => {
        createForm.post('/settings/users', {
            preserveScroll: true,
            onSuccess: () => {
                createForm.reset();
                createForm.clearErrors();
            },
        });
    };

    const openEdit = (user: UserListItem) => {
        setEditingUser(user);
        editForm.setData({
            full_name: user.full_name,
            employee_code: user.employee_code,
            role: user.role,
            branch_id: String(user.branch_id),
            is_active: user.is_active,
            password: '',
            password_confirmation: '',
        });
        editForm.clearErrors();
    };

    const closeEdit = () => {
        setEditingUser(null);
        const blankForm = createBlankUserForm();
        editForm.defaults(blankForm);
        editForm.setData(blankForm);
        editForm.clearErrors();
    };

    const submitEdit = () => {
        if (editingUser === null) {
            return;
        }

        editForm.put(`/settings/users/${editingUser.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                closeEdit();
            },
        });
    };

    const toggleActive = (user: UserListItem) => {
        router.patch(
            `/settings/users/${user.id}/active`,
            {
                is_active: !user.is_active,
            },
            {
                preserveScroll: true,
            },
        );
    };

    const deleteUser = (user: UserListItem) => {
        const confirmed = window.confirm(`Delete user ${user.full_name}?`);

        if (!confirmed) {
            return;
        }

        router.delete(`/settings/users/${user.id}`, {
            preserveScroll: true,
        });
    };

    return {
        editingUser,
        createForm,
        editForm,
        openCreate,
        submitCreate,
        openEdit,
        closeEdit,
        submitEdit,
        toggleActive,
        deleteUser,
    };
}
