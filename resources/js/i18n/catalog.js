export const supportedLocales = ['fr', 'en', 'es'];

export const normalizeLocale = (locale) =>
    supportedLocales.includes(locale) ? locale : 'fr';

const isPlainObject = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);

export const deepMerge = (target, source) => {
    if (!isPlainObject(target) || !isPlainObject(source)) {
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

const localeModuleFiles = import.meta.glob('./modules/*/*.json', {
    eager: true,
    import: 'default',
});

const localeModulePaths = (locale) => (
    Object.keys(localeModuleFiles)
        .filter((path) => path.startsWith(`./modules/${locale}/`))
        .sort((left, right) => left.localeCompare(right))
);

export const localeMessages = Object.fromEntries(
    supportedLocales.map((locale) => [
        locale,
        localeModulePaths(locale).reduce(
            (accumulator, path) => deepMerge(accumulator, localeModuleFiles[path]),
            {},
        ),
    ]),
);

export const getLocaleMessages = (locale) => localeMessages[normalizeLocale(locale)];
