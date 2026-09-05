export const companyIconPresets = [
    '/images/presets/company-1.svg',
    '/images/presets/company-2.svg',
    '/images/presets/company-3.svg',
    '/images/presets/company-4.svg',
];

export const avatarIconPresets = [
    '/images/presets/avatar-1.svg',
    '/images/presets/avatar-2.svg',
    '/images/presets/avatar-3.svg',
    '/images/presets/avatar-4.svg',
];

export const defaultCompanyIcon = companyIconPresets[0];
export const defaultAvatarIcon = avatarIconPresets[0];

export const customerIconPresetsForType = (clientType) => (
    clientType === 'company' ? companyIconPresets : avatarIconPresets
);

export const defaultCustomerIconForType = (clientType) => (
    clientType === 'company' ? defaultCompanyIcon : defaultAvatarIcon
);

export const isCustomerIconPreset = (value) => (
    [...companyIconPresets, ...avatarIconPresets].includes(value)
);
