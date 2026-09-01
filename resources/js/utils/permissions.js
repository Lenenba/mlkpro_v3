export const accountPermissionList = (account = null) => (
    Array.isArray(account?.permissions)
        ? account.permissions
        : (Array.isArray(account?.team?.permissions) ? account.team.permissions : [])
);

export const hasAccountPermission = (account, permission) => (
    Boolean(account?.is_owner)
    || Boolean(account?.is_superadmin)
    || accountPermissionList(account).includes(permission)
);

export const hasAnyAccountPermission = (account, permissions = []) => (
    permissions.some((permission) => hasAccountPermission(account, permission))
);

export const hasAccountModuleAccess = (account, module) => {
    if (account?.module_access && Object.prototype.hasOwnProperty.call(account.module_access, module)) {
        return Boolean(account.module_access[module]);
    }

    return Boolean(account?.is_owner) || Boolean(account?.is_superadmin);
};
