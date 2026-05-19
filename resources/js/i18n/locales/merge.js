const isPlainObject = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);

export const deepMerge = (target, source) => {
    if (! isPlainObject(target) || ! isPlainObject(source)) {
        return source;
    }

    const output = { ...target };
    Object.keys(source).forEach((key) => {
        const sourceValue = source[key];
        const targetValue = output[key];

        if (isPlainObject(sourceValue) && isPlainObject(targetValue)) {
            output[key] = deepMerge(targetValue, sourceValue);
            return;
        }

        output[key] = sourceValue;
    });

    return output;
};

export const mergeLocaleModules = (modules) => (
    Object.keys(modules)
        .sort((left, right) => left.localeCompare(right))
        .reduce((accumulator, path) => deepMerge(accumulator, modules[path]), {})
);
