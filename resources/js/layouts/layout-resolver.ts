import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';

export function resolvePageLayout(name: string) {
    if (name.startsWith('auth/')) {
        return AuthLayout;
    }

    if (name.startsWith('settings/data/')) {
        return AppLayout;
    }

    // User management intentionally bypasses SettingsLayout to keep the page full-width
    // and avoid showing the generic Settings header block.
    if (name === 'settings/users/index') {
        return AppLayout;
    }

    if (name.startsWith('settings/') || name.startsWith('teams/')) {
        return [AppLayout, SettingsLayout];
    }

    return AppLayout;
}
