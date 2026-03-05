export const isFeatureEnabled = (features, key) => {
    if (!features || typeof features !== 'object') {
        return false;
    }

    if (!Object.prototype.hasOwnProperty.call(features, key)) {
        return false;
    }

    return Boolean(features[key]);
};
