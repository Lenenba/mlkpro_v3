export const isFeatureEnabled = (features, key) => {
    if (!features || typeof features !== 'object') {
        return true;
    }

    if (!Object.prototype.hasOwnProperty.call(features, key)) {
        return true;
    }

    return Boolean(features[key]);
};
