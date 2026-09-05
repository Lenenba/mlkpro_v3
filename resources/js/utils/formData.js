const isFile = (value) => typeof File !== 'undefined' && value instanceof File;

const appendFormDataValue = (formData, key, value) => {
    if (value === undefined) {
        return;
    }

    if (isFile(value)) {
        formData.append(key, value);
        return;
    }

    if (Array.isArray(value)) {
        value.forEach((item, index) => {
            const itemKey = item !== null && typeof item === 'object' && !isFile(item)
                ? `${key}[${index}]`
                : `${key}[]`;
            appendFormDataValue(formData, itemKey, item);
        });
        return;
    }

    if (value !== null && typeof value === 'object') {
        Object.entries(value).forEach(([childKey, childValue]) => {
            appendFormDataValue(formData, `${key}[${childKey}]`, childValue);
        });
        return;
    }

    if (typeof value === 'boolean') {
        formData.append(key, value ? '1' : '0');
        return;
    }

    formData.append(key, value === null ? '' : String(value));
};

export const toFormData = (payload) => {
    const formData = new FormData();

    Object.entries(payload).forEach(([key, value]) => {
        appendFormDataValue(formData, key, value);
    });

    return formData;
};
