const trimmedString = (value) => (typeof value === 'string' ? value.trim() : '');

const hasOwn = (value, key) => Object.prototype.hasOwnProperty.call(value || {}, key);

const isLegacyFallbackLogo = (logoUrl) => {
    const normalized = logoUrl.split(/[?#]/, 1)[0].replace(/\/+$/, '').toLowerCase();

    return normalized === 'customers/customer.png'
        || normalized.endsWith('/customers/customer.png');
};

export const resolveCompanyLogoUrl = (company) => {
    if (!company || typeof company !== 'object') {
        return '';
    }

    const logoUrl = trimmedString(company.custom_logo_url)
        || trimmedString(company.logo_url)
        || trimmedString(company.logoUrl);

    if (!logoUrl) {
        return '';
    }

    if (isLegacyFallbackLogo(logoUrl)) {
        return '';
    }

    if (hasOwn(company, 'has_custom_logo') && company.has_custom_logo !== true) {
        return '';
    }

    return logoUrl;
};

export const resolveCompanyBrand = (company) => {
    if (!company || typeof company !== 'object') {
        return null;
    }

    return {
        name: trimmedString(company.name),
        logoUrl: resolveCompanyLogoUrl(company),
    };
};

export const resolveCompanyBrandAccessibleLabel = (
    company,
    { fallback = false, linkLabel = '' } = {},
) => {
    const brand = resolveCompanyBrand(company);
    const requestedLabel = trimmedString(linkLabel) || brand?.name || '';

    if (!fallback) {
        return requestedLabel || 'Company';
    }

    if (!requestedLabel || requestedLabel.toLowerCase() === 'malikia pro') {
        return 'Malikia Pro';
    }

    return `${requestedLabel} · Malikia Pro`;
};

export const resolveContextualCompany = (account, explicitCompany = null, { impersonating = false } = {}) => {
    if (!account || typeof account !== 'object') {
        return explicitCompany;
    }

    const isPlatformContext = Boolean(account.is_superadmin || account.is_platform_admin);

    if (isPlatformContext && !impersonating) {
        return null;
    }

    return explicitCompany || account.company || null;
};

export const resolveAccountCompanyBrand = (account, options = {}) => (
    resolveCompanyBrand(resolveContextualCompany(account, null, options))
);
