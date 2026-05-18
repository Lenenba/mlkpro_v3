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
