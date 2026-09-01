import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import {
    accountPermissionList,
    hasAccountModuleAccess,
    hasAccountPermission,
    hasAnyAccountPermission,
} from '@/utils/permissions';

export function usePermissions() {
    const page = usePage();
    const account = computed(() => page.props.auth?.account || null);
    const permissions = computed(() => accountPermissionList(account.value));

    const hasPermission = (permission) => hasAccountPermission(account.value, permission);
    const hasAnyPermission = (expected = []) => hasAnyAccountPermission(account.value, expected);
    const hasModuleAccess = (module) => hasAccountModuleAccess(account.value, module);
    const hasPlatformPermission = (permission) => {
        const platformPermissions = account.value?.platform?.permissions || [];

        return Boolean(account.value?.is_superadmin) || platformPermissions.includes(permission);
    };

    return {
        permissions,
        hasPermission,
        hasAnyPermission,
        hasModuleAccess,
        hasPlatformPermission,
    };
}
